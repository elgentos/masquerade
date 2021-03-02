<?php

namespace Elgentos\Masquerade\Console;

use Elgentos\Masquerade\Helper\Config;
use Faker\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Faker\Factory as FakerFactory;
use Symfony\Component\Console\Helper\ProgressBar;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Arr;

class RunCommand extends Command
{
    const LOGO = '
._ _  _. _ _.    _ .__. _| _
| | |(_|_>(_||_|(/_|(_|(_|(/_
            |
                   by elgentos';

    const VERSION = '0.2.5';

    const DEFAULT_QUERY_PROVIDER = \Elgentos\Masquerade\Provider\Table\Simple::class;

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
    protected $description = 'Run masquerade for a specific platform and group(s)';

    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @var array
     */
    protected $fakerInstanceCache;

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addOption('config', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'One or more extra config directories for config.yaml or platform configs')
            ->addOption('platform', null, InputOption::VALUE_OPTIONAL)
            ->addOption('driver', null, InputOption::VALUE_OPTIONAL, 'Database driver [mysql]')
            ->addOption('database', null, InputOption::VALUE_OPTIONAL)
            ->addOption('username', null, InputOption::VALUE_OPTIONAL)
            ->addOption('password', null, InputOption::VALUE_OPTIONAL)
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Database port [3306]')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Database host [localhost]')
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Database prefix [empty]')
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL, 'Locale for Faker data [en_US]')
            ->addOption('group', null, InputOption::VALUE_OPTIONAL, 'Which groups to run masquerade on [all]')
            ->addOption('charset', null, InputOption::VALUE_OPTIONAL, 'Database charset [utf8]')
            ->addOption('with-integrity', null, InputOption::VALUE_NONE, 'Run with foreign key checks enabled');
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

        $this->output->writeln(self::LOGO);
        $this->output->writeln('                        v' . self::VERSION);

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
     * @param array $table
     */
    private function fakeData(array $table) : void
    {
        $tableProviderData = Arr::get($table, 'provider', []);
        if (is_string($tableProviderData)) {
            $tableProviderData = ['class' => $tableProviderData]; // just a class rather than array of options
        }
        $tableProviderClass = Arr::get($tableProviderData, 'class', self::DEFAULT_QUERY_PROVIDER);
        $tableProvider = new $tableProviderClass($this->input, $this->output, $this->db, $table, $tableProviderData);

        $this->output->writeln('');
        $this->output->writeln('Updating ' . $table['name'] . ' using '. $tableProviderClass);

        try {
            $tableProvider->setup();

            $totalRows = $tableProvider->count();
            $progressBar = new ProgressBar($this->output, $totalRows);
            $progressBar->setRedrawFrequency($this->calculateRedrawFrequency($totalRows));
            $progressBar->start();

            $primaryKey = $tableProvider->getPrimaryKey();

            $tableProvider->query()->chunk(
                100,
                function ($rows) use ($table, $progressBar, $primaryKey, $tableProvider) {
                    foreach ($rows as $row) {
                        $updates = [];
                        foreach ($tableProvider->columns() as $columnName => $columnData) {
                            $formatter = Arr::get($columnData, 'formatter.name');
                            $formatterData = Arr::get($columnData, 'formatter');
                            $providerClassName = Arr::get($columnData, 'provider', false);

                            if (!$formatter) {
                                $formatter = $formatterData;
                                $options = [];
                            } else {
                                $options = array_values(array_slice($formatterData, 1));
                            }

                            if (!$formatter) {
                                continue;
                            }

                            if ($formatter == 'fixed') {
                                $updates[$columnName] = Arr::first($options);
                                continue;
                            }

                            try {
                                $fakerInstance = $this->getFakerInstance($columnData, $providerClassName);
                                if (Arr::get($columnData, 'unique', false)) {
                                    $updates[$columnName] = $fakerInstance->unique()->{$formatter}(...$options);
                                } elseif (Arr::get($columnData, 'optional', false)) {
                                    $updates[$columnName] = $fakerInstance->optional()->{$formatter}(...$options);
                                } else {
                                    $updates[$columnName] = $fakerInstance->{$formatter}(...$options);
                                }
                            } catch (\InvalidArgumentException $e) {
                                // If InvalidArgumentException is thrown, formatter is not found, use null instead
                                $updates[$columnName] = null;
                            }
                        }
                        $tableProvider->update($row->{$primaryKey}, $updates);
                        $progressBar->advance();
                    }
                }
            );

            $progressBar->finish();
        } catch (\Exception $e) {
            $this->output->writeln($e->getMessage());
        }

        $this->output->writeln('');
    }

    /**
     * @throws \Exception
     */
    private function setup()
    {
        $this->configHelper = new Config($this->input->getOptions());

        $databaseConfig = $this->configHelper->readConfigFile();

        $this->platformName = $this->input->getOption('platform') ?? $databaseConfig['platform'] ?? null;

        if (!$this->platformName) {
            throw new \Exception('No platformName set, use option --platform or set it in ' . Config::CONFIG_YAML);
        }

        $this->config = $this->configHelper->getConfig($this->platformName);

        $host = $this->input->getOption('host') ?? $databaseConfig['host'] ?? 'localhost';
        $driver = $this->input->getOption('driver') ?? $databaseConfig['driver'] ?? 'mysql';
        $database = $this->input->getOption('database') ?? $databaseConfig['database'] ?? null;
        $username = $this->input->getOption('username') ?? $databaseConfig['username'] ?? null;
        $password = $this->input->getOption('password') ?? $databaseConfig['password'] ?? null;
        $prefix = $this->input->getOption('prefix') ?? $databaseConfig['prefix'] ?? '';
        $charset = $this->input->getOption('charset') ?? $databaseConfig['charset'] ?? 'utf8';
        $port = $this->input->getOption('port') ?? $databaseConfig['port'] ?? 3306;

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
            'port'      => $port
        ]);

        $this->db = $capsule->getConnection();
        if (!$this->input->getOption('with-integrity')) {
            $this->output->writeln('[Foreign key constraint checking is off - deletions will not affect linked tables]');
            $this->db->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        $this->locale = $this->input->getOption('locale') ?? $databaseConfig['locale'] ?? 'en_US';

        $this->group = array_filter(array_map('trim', explode(',', $this->input->getOption('group'))));
    }

    /**
     * @param array $columnData
     * @param bool $providerClassName
     * @return Generator
     * @throws \Exception
     * @internal param bool $provider
     */
    private function getFakerInstance(array $columnData, $providerClassName = false) : Generator
    {
        $key = md5(serialize($columnData) . $providerClassName);
        if (isset($this->fakerInstanceCache[$key])) {
            return $this->fakerInstanceCache[$key];
        }

        $fakerInstance = FakerFactory::create($this->locale);

        $provider = false;
        if ($providerClassName) {
            $provider = new $providerClassName($fakerInstance);
        }

        if (is_object($provider)) {
            if (!$provider instanceof \Faker\Provider\Base) {
                throw new \Exception('Class ' . get_class($provider) . ' is not an instance of \Faker\Provider\Base');
            }
            $fakerInstance->addProvider($provider);
        }

        $this->fakerInstanceCache[$key] = $fakerInstance;

        return $fakerInstance;
    }

    /**
     * @param int $totalRows
     * @return int
     */
    private function calculateRedrawFrequency(int $totalRows) : int
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

        return (int) ceil($totalRows * $percentage);
    }
}
