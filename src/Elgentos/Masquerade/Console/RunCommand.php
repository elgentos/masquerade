<?php

namespace Elgentos\Masquerade\Console;

use Elgentos\Masquerade\DataProcessor\DefaultDataProcessorFactory;
use Elgentos\Masquerade\DataProcessor\TableDoesNotExistsException;
use Elgentos\Masquerade\DataProcessor\TableServiceFactory;
use Elgentos\Masquerade\DataProcessorFactory;
use Elgentos\Masquerade\Helper\Config;
use Elgentos\Masquerade\Output;
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

    const VERSION = '1.2.0';

    const DEFAULT_DATA_PROCESSOR_FACTORY = DefaultDataProcessorFactory::class;

    protected $config;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var Output
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
     * @var TableServiceFactory
     */
    private $tableServiceFactory;

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
            ->addOption('with-integrity', null, InputOption::VALUE_NONE, 'Run with foreign key checks enabled')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size to use for anonymization', 500);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = SymfonyOutput::createFromSymfonyOutput($output);

        $this->setup();

        $output->writeln(self::LOGO);
        $output->writeln('                        v' . self::VERSION);

        $startTime = new \DateTime();

        foreach ($this->config as $groupName => $tables) {
            if (!empty($this->group) && !in_array($groupName, $this->group)) {
                continue;
            }
            foreach ($tables as $tableName => $table) {
                $table['name'] = $tableName;
                $this->fakeData($table);
            }
        }

        $this->output->success('Anonymization complete in [%s]', $startTime->diff(new \DateTime())->format('%h:%i:%s'));

        return 0;
    }

    /**
     * @param array $table
     */
    private function fakeData(array $table) : void
    {
        $table['provider'] = $table['provider'] ?? [];

        if (is_string($table['provider'])) {
            $this->output->errorAndExit(
                'Provided configuration "%s" is not compatible with new version of masquerade, please use processor_factory instead.',
                $table['provider']
            );
        }

        $dataProcessorFactoryClass = $table['processor_factory'] ?? self::DEFAULT_DATA_PROCESSOR_FACTORY;

        if (!class_exists($dataProcessorFactoryClass)) {
            $this->output->errorAndExit(
                'Provided %s class does not exists.',
                $dataProcessorFactoryClass
            );
        }

        if (!in_array(DataProcessorFactory::class, class_implements($dataProcessorFactoryClass))) {
            $this->output->errorAndExit(
                'Provided %s class does not implement required %s interface.',
                $dataProcessorFactoryClass,
                DataProcessorFactory::class
            );
        }

        /** @var DataProcessorFactory $dataProcessorFactory */
        $dataProcessorFactory = new $dataProcessorFactoryClass();
        $tableName = $table['name'];
        try {
            $dataProcessor = $dataProcessorFactory->create($this->output, $this->tableServiceFactory, $table);
        } catch (TableDoesNotExistsException $exception) {
            $this->output->info('Table %s does not exists. Skipping...', $tableName);
            return;
        }


        $this->output->debug(
            'Updating table using the following data',
            [
                'processor' => get_class($dataProcessor),
                'configuration' => $table
            ]
        );

        $isIntegrityImportant = $this->input->hasOption('with-integrity') || $table['provider']['where'] ?? '';
        $isDelete = $table['provider']['delete'] ?? false;
        $isTruncate = $table['provider']['truncate'] ?? false;

        if ($isIntegrityImportant && $isDelete) {
            $this->output->info('Deleting records from %s table', $tableName);
            $dataProcessor->delete();
            $this->output->success('Records have been deleted from %s table', $tableName);
            return;
        } elseif ($isDelete || $isTruncate) {
            $this->output->info('Truncating records from %s table', $tableName);
            $dataProcessor->truncate();
            $this->output->success('Records have been truncated from %s table', $tableName);
            return;
        }

        try {
            $dataProcessor->updateTable((int)$this->input->getOption('batch-size'), \Closure::fromCallable([$this, 'generateRecord']));
        } catch (\Exception $e) {
            $this->output->errorAndExit($e->getMessage());
        }

        $this->output->info('');
    }

    private function generateRecord(iterable $columns): \Generator
    {
        foreach ($columns as $columnData) {
            $formatter = $columnData['formatter']['name'] ?? null;
            $formatterData = $columnData['formatter'] ?? [];
            $providerClassName = $columnData['provider'] ?? false;

            if (!$formatter) {
                $formatter = $formatterData;
                $options = [];
            } else {
                $options = array_values(array_slice($formatterData, 1));
            }

            if (!$formatter) {
                yield null;
                continue;
            }

            if ($formatter == 'fixed') {
                yield Arr::first($options);
                continue;
            }

            try {
                $fakerInstance = $this->getFakerInstance($columnData, $providerClassName);
                if ($columnData['unique'] ?? false) {
                    $fakerInstance = $fakerInstance->unique();
                } elseif ($columnData['optional'] ?? false) {
                    $fakerInstance = $fakerInstance->optional();
                }

                yield $this->asScalar($fakerInstance->{$formatter}(...$options));
            } catch (\InvalidArgumentException $e) {
                // If InvalidArgumentException is thrown, formatter is not found, use null instead
                yield null;
            }
        }
    }

    /**
     * Transforms non scalar values to scalar ones
     *
     * @return scalar
     */
    private function asScalar($value)
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \DateTime) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $this->output->debug('Unexpected type', ['value' => $value]);
        $this->output->errorAndExit('Unknown type has been provided from generator');
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
            $this->output->errorAndExit('No platformName set, use option --platform or set it in ' . Config::CONFIG_YAML);
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
            $this->output->errorAndExit(implode(PHP_EOL, $errors));
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
            $this->output->info('[Foreign key constraint checking is off - deletions will not affect linked tables]');
            $this->db->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        $this->db->statement("SET SESSION sql_mode=''");

        $this->tableServiceFactory = new TableServiceFactory($this->db);

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
                $this->output->errorAndExit('Class ' . get_class($provider) . ' is not an instance of \Faker\Provider\Base');
            }
            $fakerInstance->addProvider($provider);
        }

        $this->fakerInstanceCache[$key] = $fakerInstance;

        return $fakerInstance;
    }
}
