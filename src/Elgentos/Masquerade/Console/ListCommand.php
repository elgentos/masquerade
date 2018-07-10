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
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'masquerade:list';

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
     * @return mixed
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $masqueradeConfig = new Config(__DIR__ . sprintf('/config/%s/', $input->getOption('platform')));

        print_r($masqueradeConfig);exit;

        $outputTable = new Table($this->getOutput());

        $outputTable->setHeaders(['Platform', 'Group', 'Table', 'Column', 'Formatter']);
        $rows = [];
        foreach ($masqueradeConfig as $platformName => $groups) {
            if (isset($groups['groups'])) {
                foreach ($groups['groups'] as $groupName => $tables) {
                    foreach ($tables as $tableName => $table) {
                        $table['name'] = $tableName;
                        foreach ($table['columns'] as $columnName => $column) {
                            $rows[] = [$platformName, $groupName, $tableName, $columnName, $column['formatter']];
                        }
                    }
                }
            }
        }
        $outputTable->setRows($rows);
        $outputTable->render();
    }
}
