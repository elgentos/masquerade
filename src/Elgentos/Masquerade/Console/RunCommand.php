<?php

namespace Elgentos\Masquerade\Console;

use Symfony\Component\Console\Command\Command;
use Noodlehaus\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'masquerade:run';

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
            ->addOption('platform', null, InputOption::VALUE_REQUIRED)
            ->addOption('dbname', null, InputOption::VALUE_REQUIRED)
            ->addOption('username', null, InputOption::VALUE_REQUIRED)
            ->addOption('password', null, InputOption::VALUE_OPTIONAL)
            ->addOption('host', null, InputOption::VALUE_REQUIRED)
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL)
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $masqueradeConfig = new Config(__DIR__ . sprintf('/config/%s/', $input->getOption('platform')));

        print_r($masqueradeConfig);
    }
}
