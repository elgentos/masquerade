<?php

namespace Elgentos\Masquerade\Console;

use Symfony\Component\Console\Command\Command;
use Noodlehaus\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Faker\Factory as FakerFactory;
use Symfony\Component\Console\Helper\ProgressBar;
use Illuminate\Database\Capsule\Manager as Capsule;

class RunCommand extends Command
{
    protected $config;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    protected $platformName;
    protected $locale;

    protected $logo = '                              
._ _  _. _ _.    _ .__. _| _  
| | |(_|_>(_||_|(/_|(_|(_|(/_ 
            |
                   by elgentos';

    protected $version = '0.1.0';

    /**
     * @var \Illuminate\Database\Connection
     */
    protected $db;
    protected $group = [];
    protected $fakerInstances = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List of tables (and columns) to be faked';

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addOption('platform', null, InputOption::VALUE_OPTIONAL)
            ->addOption('database', null, InputOption::VALUE_OPTIONAL)
            ->addOption('username', null, InputOption::VALUE_OPTIONAL)
            ->addOption('password', null, InputOption::VALUE_OPTIONAL)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL)
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL)
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL)
            ->addOption('group', null, InputOption::VALUE_OPTIONAL);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->setup();

        $this->output->writeln($this->logo);
        $this->output->writeln('                        v' . $this->version);

        foreach ($this->config as $groupName => $tables) {
            if (!empty($this->group) && !in_array($groupName, $this->group)) {
                continue;
            }
            foreach ($tables as $tableName => $table) {
                $table['name'] = $tableName;
                $this->fakeData($table);
            }
        }

        $this->output->writeln('Done anonymizing');
    }

    /**
     * @param $table
     */
    private function fakeData($table)
    {
        $this->output->writeln('');
        $this->output->writeln('Updating ' . $table['name']);

        $rows = $this->db->table($table['name'])->get();

        $progressBar = new ProgressBar($this->output, $rows->count());
        $progressBar->start();

        // Null columns before run to avoid integrity constrains errors
        foreach ($table['columns'] as $columnName => $columnData) {
            if (array_get($columnData, 'nullColumnBeforeRun', false)) {
                $this->db->table($table['name'])->update([$columnName => null]);
            }
        }

        foreach ($rows as $row) {
            $updates = [];
            foreach ($table['columns'] as $columnName => $columnData) {
                $updates[$columnName] = $this->getFakerInstance($columnName, $columnData)->{$columnData['formatter']};
            }
            $primaryKey = array_get($table, 'pk', 'entity_id');
            $this->db->table($table['name'])->where($primaryKey, $row->{$primaryKey})->update($updates);
            $progressBar->advance();
        }

        $progressBar->finish();

        $this->output->writeln('');
    }

    /**
     * @throws \Exception
     */
    private function setup()
    {
        if (file_exists('config.yaml')) {
            $databaseConfig = new Config('config.yaml');
        }

        $this->platformName = $databaseConfig['platform'] ?? $this->input->getOption('platform');

        if (!$this->platformName) {
            throw new \Exception('No platformName set, use option --platform or set it in config.yaml');
        }

        // Get default config
        $config = new Config(sprintf(__DIR__ . '/../../../config/%s', $this->platformName));
        $this->config = $config->all();

        // Get custom config
        if (file_exists('config')) {
            $customConfig = new Config(sprintf('config/%s', $this->platformName));
            $this->config = array_merge($config->all(), $customConfig->all());
        }

        $host = $databaseConfig['host'] ?? $this->input->getOption('host') ?? 'localhost';
        $database = $databaseConfig['database'] ?? $this->input->getOption('database');
        $username = $databaseConfig['username'] ?? $this->input->getOption('username');
        $password = $databaseConfig['password'] ?? $this->input->getOption('password');
        $prefix = $databaseConfig['prefix'] ?? $this->input->getOption('prefix');

        $errors = [];
        if (!$host) {
            $errors[] = 'No host defined';
        }
        if (!$database) {
            $errors[] = 'No database defined';
        }
        if (!$username) {
            $errors[] = 'No username defined';
        }
        if (count($errors) > 0) {
            throw new \Exception(implode(PHP_EOL, $errors));
        }

        $capsule = new Capsule;
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => $host,
            'database'  => $database,
            'username'  => $username,
            'password'  => $password,
            'prefix'    => $prefix,
        ]);

        $this->db = $capsule->getConnection();
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        $this->locale = $databaseConfig['locale'] ?? $this->input->getOption('locale') ?? 'en_US';

        $this->group = array_filter(array_map('trim', explode(',', $this->input->getOption('group'))));
    }

    /**
     * @param $columnName
     * @param $columnData
     * @return mixed
     */
    private function getFakerInstance($columnName, $columnData)
    {
        if (isset($this->fakerInstances[$columnName])) {
            if (array_get($columnData, 'unique', false)) {
                return $this->fakerInstances[$columnName]->unique();
            }
            if (array_get($columnData, 'optional', false)) {
                return $this->fakerInstances[$columnName]->optional();
            }
            if (array_get($columnData, 'valid', false)) {
                return $this->fakerInstances[$columnName]->valid();
            }
            return $this->fakerInstances[$columnName];
        }

        $fakerInstance = FakerFactory::create($this->locale);

        $this->fakerInstances[$columnName] = $fakerInstance;

        return $this->fakerInstances[$columnName];
    }
}
