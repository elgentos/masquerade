<?php

namespace Elgentos\Masquerade\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Noodlehaus\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    protected $config;
    protected $input;
    protected $output;
    protected $platformName;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List of tables (and columns) to be faked';

    protected function configure()
    {
        $this
            ->setName($this->name)
            ->setDescription($this->description)
            ->addOption('platform', null, InputOption::VALUE_REQUIRED);
    }

    /**
     * Execute the console command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return mixed
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->setup();

        $outputTable = new Table($output);
        $outputTable->setHeaders(['Platform', 'Group', 'Table', 'Column', 'Formatter']);

        $rows = [];

        foreach ($this->config as $groupName => $tables) {
            foreach ($tables as $tableName => $table) {
                $table['name'] = $tableName;
                foreach ($table['columns'] as $columnName => $column) {
                    $rows[] = [$this->platformName, $groupName, $tableName, $columnName, $column['formatter']];
                }
            }
        }

        $outputTable->setRows($rows);
        $outputTable->render();
    }

    private function setup()
    {
        $this->platformName = $this->input->getOption('platform');
        $config = new Config(sprintf(__DIR__ . '/../../../config/%s', $this->platformName));
        $this->config = $config->all();
    }
}
