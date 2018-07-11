<?php

namespace Elgentos\Masquerade\Console;

use Phar;
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
            ->addOption('driver', null, InputOption::VALUE_OPTIONAL, 'Database driver [mysql]')
            ->addOption('database', null, InputOption::VALUE_OPTIONAL)
            ->addOption('username', null, InputOption::VALUE_OPTIONAL)
            ->addOption('password', null, InputOption::VALUE_OPTIONAL)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Database host [localhost]')
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Database prefix [empty]')
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL, 'Locale for Faker data [en_US]')
            ->addOption('group', null, InputOption::VALUE_OPTIONAL, 'Which groups to run masquerade on [all]')
            ->addOption('charset', null, InputOption::VALUE_OPTIONAL, 'Database charset [utf8]');

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

        $totalRows = $this->db->table($table['name'])->count();
        $progressBar = new ProgressBar($this->output, $totalRows);
        $progressBar->setRedrawFrequency($this->calculateRedrawFrequency($totalRows));
        $progressBar->start();

        $primaryKey = array_get($table, 'pk', 'entity_id');

        $this->db->table($table['name'])->orderBy($primaryKey)->chunk(100, function ($rows) use ($table, $progressBar, $primaryKey) {
            // Null columns before run to avoid integrity constrains errors
            foreach ($table['columns'] as $columnName => $columnData) {
                if (array_get($columnData, 'nullColumnBeforeRun', false)) {
                    $this->db->table($table['name'])->update([$columnName => null]);
                }
            }

            foreach ($rows as $row) {
                $updates = [];
                foreach ($table['columns'] as $columnName => $columnData) {
                    $formatter = array_get($columnData, 'formatter.name');
                    $formatterData = array_get($columnData, 'formatter');

                    if (!$formatter) {
                        $formatter = $formatterData;
                        $options = [];
                    } else {
                        $options = array_values(array_slice($formatterData, 1));
                    }

                    if (!$formatter) continue;

                    if ($formatter == 'fixed') {
                        $updates[$columnName] = array_first($options);
                        continue;
                    }

                    try {
                        $updates[$columnName] = $this->getFakerInstance($columnName, $columnData)->{$formatter}(...$options);
                    } catch (\InvalidArgumentException $e) {
                        // If InvalidArgumentException is thrown, formatter is not found, use null instead
                        $updates[$columnName] = null;
                    }
                }
                $this->db->table($table['name'])->where($primaryKey, $row->{$primaryKey})->update($updates);
                $progressBar->advance();
            }
        });

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
        $config = new Config($this->getConfigFiles($this->platformName));
        $this->config = $config->all();

        // Get custom config
        if (file_exists('config') && is_dir('config')) {
            $customConfig = new Config(sprintf('config/%s', $this->platformName));
            $this->config = array_merge($config->all(), $customConfig->all());
        }

        $host = $databaseConfig['host'] ?? $this->input->getOption('host') ?? 'localhost';
        $driver = $databaseConfig['driver'] ?? $this->input->getOption('driver') ?? 'mysql';
        $database = $databaseConfig['database'] ?? $this->input->getOption('database');
        $username = $databaseConfig['username'] ?? $this->input->getOption('username');
        $password = $databaseConfig['password'] ?? $this->input->getOption('password');
        $prefix = $databaseConfig['prefix'] ?? $this->input->getOption('prefix');
        $charset = $databaseConfig['charset'] ?? $this->input->getOption('charset') ?? 'utf8';

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
            'driver'    => $driver,
            'host'      => $host,
            'database'  => $database,
            'username'  => $username,
            'password'  => $password,
            'prefix'    => $prefix,
            'charset'   => $charset,
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

    /**
     * @return bool
     */
    private function isPhar() {
        return strlen(Phar::running()) > 0 ? true : false;
    }

    /**
     * @param $platformName
     * @return array
     */
    private function getConfigFiles($platformName)
    {
        if (!$this->isPhar()) {
            return glob(__DIR__ . '/../../../config/' . $platformName . '/*.*');
        }

        // Unfortunately, glob() does not work when using a phar and hassankhan/config relies on glob.
        // Therefore, we have to explicitly pass all config files back when using the phar
        if ($platformName == 'magento2') {
            $files = [
                'config/magento2/invoice.yaml',
                'config/magento2/creditmemo.yaml',
                'config/magento2/review.yaml',
                'config/magento2/newsletter.yaml',
                'config/magento2/order.yaml',
                'config/magento2/quote.yaml',
                'config/magento2/admin.yaml',
                'config/magento2/email.yaml',
                'config/magento2/customer.yaml',
                'config/magento2/shipment.yaml'
            ];

            return array_map(function ($file) {
                return 'phar://masquerade.phar/src/' . $file;
            }, $files);
        }

        // No other platforms supported by default right now
        return [];
    }

    private function calculateRedrawFrequency($totalRows)
    {
        $percentage = 10;

        if ($totalRows < 100) {
            $percentage = 10;
        } elseif ($totalRows < 1000) {
            $percentage = 1;
        } elseif ($totalRows < 10000) {
            $percentage = 0.1;
        } elseif ($totalRows < 100000) {
            $percentage = 0.01;
        } elseif ($totalRows < 1000000) {
            $percentage = 0.001;
        }

        return ceil($totalRows * $percentage);
    }
}