<?php

namespace Elgentos\Masquerade\Console;

use Exception;
use Faker\Generator;
use Faker\Provider\Base;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Faker\Factory as FakerFactory;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Process\Process;
use Jack\Symfony\ProcessManager;

/**
 * Class RunCommand
 * @package Elgentos\Masquerade\Console
 */
class RunCommand extends AbstractCommand
{

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
            ->addOption('platform', 'p', InputOption::VALUE_OPTIONAL)
            ->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Database driver [mysql]')
            ->addOption('database', 'db', InputOption::VALUE_OPTIONAL)
            ->addOption('username', 'u', InputOption::VALUE_OPTIONAL)
            ->addOption('password', 'pw', InputOption::VALUE_OPTIONAL)
            ->addOption('host', 'hs', InputOption::VALUE_OPTIONAL, 'Database host [localhost]')
            ->addOption('prefix', 'pf', InputOption::VALUE_OPTIONAL, 'Database prefix [empty]')
            ->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, 'Locale for Faker data [en_US]')
            ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Which groups to run masquerade on [all]')
            ->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Which table to run masquerade on [all]')
            ->addOption('charset', 'c', InputOption::VALUE_OPTIONAL, 'Database charset [utf8]')
            ->addOption('parallel', null, InputOption::VALUE_OPTIONAL, 'Started as parallel process? [false]', false);
    }

    /**
     * Execute the console command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->setup();

        ProgressBar::setFormatDefinition('custom', 'Anonymizing group %message% [%bar%]    %current%/%max% %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        if ($this->input->getOption('parallel')) {
            // Do the actual work: fake data for this parallel process
            foreach ($this->config as $groupName => $tables) {
                if (!empty($this->group) && !in_array($groupName, $this->group)) {
                    continue;
                }
                foreach ($tables as $tableName => $table) {
                    if (!empty($this->table) && !in_array($tableName, $this->table)) {
                        continue;
                    }
                    $table['name'] = $tableName;
                    $this->fakeData($table);
                }
            }
            return;
        }

        $this->spawnSubProcesses();
    }

    /**
     *
     */
    protected function spawnSubProcesses(): void
    {
        $subProcesses = [];
        $progressBars = [];

        $longestKeyLength = 0;
        foreach ($this->config as $groupName => $tables) {
            foreach ($tables as $tableName => $table) {
                $longestKeyLength = max($longestKeyLength, strlen($groupName . '_' . $tableName));
            }
        }

        foreach ($this->config as $groupName => $tables) {
            if (!empty($this->group) && !in_array($groupName, $this->group)) {
                continue;
            }

            // Make a list of all sub processes that need to be spawned
            // We abuse the options parameter to set the group name so we can update the correct progress bars in the callback
            foreach ($tables as $tableName => $table) {
                $totalRowsForGroupTable = 0;
                if ($this->db->getSchemaBuilder()->hasTable($tableName)) {
                    $totalRowsForGroupTable = $this->db->table($tableName)->count();
                }

                // Skip groups with no rows/tables
                if ($totalRowsForGroupTable === 0) {
                    continue;
                }

                // Define sub process
                $subProcesses[$groupName.'_'.$tableName] = new Process('bin/masquerade run --parallel=true --group=' . $groupName . ' --table=' . $tableName,
                    null,
                    null,
                    null,
                    null,
                    ['group' => $groupName, 'table' => $tableName]
                );

                // Create progressbar for this sub process and add it to the progress bars array
                $section = $this->output->section();
                $progressBar = new ProgressBar($section, $totalRowsForGroupTable);
                $progressBar->setMessage(str_pad($groupName . '.' . $tableName, $longestKeyLength + 2));
                $progressBar->setFormat('custom');
                $progressBar->setRedrawFrequency($this->calculateRedrawFrequency($totalRowsForGroupTable));
                $progressBar->start();
                $progressBars[$groupName.'_'.$tableName] = $progressBar;
            }
        }

        // Create a process manager and start all sub processes
        $processManager = new ProcessManager();
        $processManager->runParallel(
            $subProcesses,
            count($this->config),
            1000,
            function ($type, $output, $process) use ($progressBars) {
                /** @var $process Process */
                $groupName = $process->getOptions()['group'];
                $tableName = $process->getOptions()['table'];

                //$this->output->write($output);

                /** @var ProgressBar $progressBar */
                $progressBar = $progressBars[$groupName.'_'.$tableName];
                $progressBar->advance();
            }
        );
    }

    /**
     * @param array $table
     */
    private function fakeData(array $table) : void
    {
        // Check if table exists, skip if not
        if (!$this->db->getSchemaBuilder()->hasTable($table['name'])) {
            return;
        }

        // Check if table exists, if not, remove it from the table columns array
        foreach ($table['columns'] as $columnName => $columnData) {
            if (!$this->db->getSchemaBuilder()->hasColumn($table['name'], $columnName)) {
                unset($table['columns'][$columnName]);
            }
        }

        // Fetch primary key from the table
        $primaryKey = Arr::get($table, 'pk', 'entity_id');

        // Null columns before run to avoid integrity constrains errors
        foreach ($table['columns'] as $columnName => $columnData) {
            if (Arr::get($columnData, 'nullColumnBeforeRun', false)) {
                $this->db->table($table['name'])->update([$columnName => null]);
            }
        }

        // Loop in chunks over the table to anonymize data
        $this->db->table($table['name'])->orderBy($primaryKey)->chunk(100, function ($rows) use ($table, $primaryKey) {
            foreach ($rows as $row) {
                $updates = [];
                foreach ($table['columns'] as $columnName => $columnData) {
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
                    } catch (InvalidArgumentException $e) {
                        // If InvalidArgumentException is thrown, formatter is not found, use null instead
                        $updates[$columnName] = null;
                    }
                }
                $this->db->table($table['name'])->where($primaryKey, $row->{$primaryKey})->update($updates);
                $this->output->writeln('.');
            }
        });
    }

    /**
     * @param array $columnData
     * @param bool $providerClassName
     * @return Generator
     * @throws Exception
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
            if (!$provider instanceof Base) {
                throw new Exception('Class ' . get_class($provider) . ' is not an instance of \Faker\Provider\Base');
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

        if ($totalRows < 10000) {
            $percentage = 0.1;
        } elseif ($totalRows < 100000) {
            $percentage = 0.01;
        } elseif ($totalRows < 1000000) {
            $percentage = 0.001;
        }

        return (int) ceil($totalRows * $percentage);
    }
}
