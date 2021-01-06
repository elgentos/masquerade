<?php

namespace Elgentos\Masquerade\Console;

use Elgentos\Masquerade\Helper\Config;
use Faker\Documentor;
use Faker\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Illuminate\Database\Capsule\Manager as Capsule;

class IdentifyCommand extends Command
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
    protected $name = 'identify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify columns to be faked';

    /**
     * @var \Illuminate\Database\Connection
     */
    protected $db;

    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @var array
     */
    protected $identifiers;

    /**
     * @var string
     */
    protected $prefix;

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
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Database host [localhost]')
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Database prefix [empty]')
            ->addOption('charset', null, InputOption::VALUE_OPTIONAL, 'Database charset [utf8]');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->setup();

        $this->identifiers = [
            'firstName',
            'lastName',
            'address',
            'suffix',
            'city',
            'state',
            'zipcode',
            'country',
            'address',
            'street',
            'latitude',
            'longitude',
            'phone',
            'fax',
            'email',
            'company',
            'remote_ip',
            'ip_address',
            'creditCard',
            'transaction'
        ];

        $tableNames = $this->getTableNames();

        $candidates = [];
        foreach ($tableNames as $tableName) {
            $columns = $this->db->getSchemaBuilder()->getColumnListing($tableName);
            foreach ($columns as $columnName) {
                if ($formatter = $this->strposa($columnName, $this->identifiers)) {
                    $exampleValues = array_map(function ($exampleValue) use ($columnName) {
                        $string = $exampleValue->{$columnName};
                        if (strlen($string) > 30) {
                            $string = substr($string, 0, 30) . '...';
                        }
                        return $string;
                    }, $this->db->table($tableName)->whereNotNull($columnName)->distinct()->inRandomOrder()->limit(3)->get([$columnName])->toArray());
                    $candidates[] = [$tableName, $columnName, $formatter, implode(', ', $exampleValues)];
                }
            }
        }

        $candidatesTable = new Table($output);
        $candidatesTable->setHeaders(['Table', 'Column', 'Suggested formatter', 'Example values']);
        $candidatesTable->setRows($candidates);
        $candidatesTable->render();
    }

    /**
     * @throws \Exception
     */
    private function setup()
    {
        $this->configHelper = new Config($this->input->getOptions());
        $databaseConfig = $this->configHelper->readConfigFile();

        $this->platformName = $this->input->getOption('platform') ?? $databaseConfig['platform'];

        if (!$this->platformName) {
            throw new \Exception('No platformName set, use option --platform or set it in ' . Config::CONFIG_YAML);
        }

        $this->config = $this->configHelper->getConfig($this->platformName);

        $host = $this->input->getOption('host') ?? $databaseConfig['host'] ?? 'localhost';
        $driver = $this->input->getOption('driver') ?? $databaseConfig['driver'] ?? 'mysql';
        $database = $this->input->getOption('database') ?? $databaseConfig['database'];
        $username = $this->input->getOption('username') ?? $databaseConfig['username'];
        $password = $this->input->getOption('password') ?? $databaseConfig['password'];
        $prefix = $this->input->getOption('prefix') ?? $databaseConfig['prefix'] ?? '';
        $charset = $this->input->getOption('charset') ?? $databaseConfig['charset'] ?? 'utf8';

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

        $this->prefix = $prefix;

        $this->db = $capsule->getConnection();
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');
    }

    /**
     * @return array
     */
    protected function getTableNames(): array
    {
        // @TODO get this dynamically from the YAML files
        // @TODO not just exclude the whole table but take given column names into account

        $excludedTableNames = [
            'sales_shipment',
            'sales_shipment_comment',
            'sales_shipment_grid',
            'review_detail',
            'quote',
            'quote_address',
            'sales_order',
            'sales_order_grid',
            'sales_order_address',
            'newsletter_subscriber',
            'sales_invoice',
            'sales_invoice_comment',
            'sales_invoice_grid',
            'email_contact',
            'email_automation',
            'email_campaign',
            'customer_entity',
            'customer_address_entity',
            'customer_grid_flat',
            'sales_creditmemo',
            'sales_creditmemo_comment',
            'sales_creditmemo_grid',
            'admin_user'
        ];

        $tables = $this->db->select('SHOW TABLES');

        $tableNames = [];
        foreach ($tables as $table) {
            $object_vars = get_object_vars($table);
            $tableNames[] = array_pop($object_vars);
        }

        $tableNames = array_map(function ($tableName) {
            return $this->str_replace_first($this->prefix, null, $tableName);
        }, $tableNames);

        $tableNames = array_diff($tableNames, $excludedTableNames);

        return $tableNames;
    }

    /**
     * @param $haystack
     * @param $needle
     * @param int $offset
     * @return bool
     */
    protected function strposa($haystack, $needle, $offset = 0)
    {
        if (!is_array($needle)) {
            $needle = array($needle);
        }
        foreach ($needle as $query) {
            if (strpos($haystack, $query, $offset) !== false) {
                return $query;
            }
        }
        return false;
    }

    /**
     * @param $from
     * @param $to
     * @param $content
     * @return null|string|string[]
     */
    public function str_replace_first($from, $to, $content)
    {
        $from = '/'.preg_quote($from, '/').'/';

        return preg_replace($from, $to, $content, 1);
    }
}
