<?php

namespace Elgentos\Masquerade\Console;

use Phar;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Noodlehaus\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GroupsCommand extends Command
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
    protected $name = 'groups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List of groups (tables and columns) to be faked';

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
                    $formatter = $column['formatter'];
                    if (is_array($formatter)) { $formatter = implode(', ', $formatter); }
                    $rows[] = [$this->platformName, $groupName, $tableName, $columnName, $formatter];
                }
            }
        }

        $outputTable->setRows($rows);
        $outputTable->render();
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
}
