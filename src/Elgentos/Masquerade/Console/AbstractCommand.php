<?php

namespace Elgentos\Masquerade\Console;

use Elgentos\Masquerade\Helper\Config;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Command\Command;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class AbstractCommand
 * @package Elgentos\Masquerade\Console
 */
abstract class AbstractCommand extends Command
{
    protected $config;
    protected $platformName;
    protected $locale;
    protected $group = [];
    protected $table = [];

    /** @var Connection */
    protected $db;

    /** @var Config */
    protected $configHelper;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @throws \Exception
     */
    protected function setup()
    {
        $this->configHelper = new Config();
        $databaseConfig = $this->configHelper->readConfigFile();

        $this->platformName = $this->input->getOption('platform') ?? $databaseConfig['platform'];

        if (!$this->platformName) {
            throw new \Exception('No platformName set, use option --platform or set it in ' . Config::CONFIG_YAML);
        }

        $this->config = $this->configHelper->getConfig($this->platformName);

        if (!$this->needsDbConnection()) {
            return;
        }

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
        $connectionData = [
            'driver' => $driver,
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'prefix' => $prefix,
            'charset' => $charset,
        ];
        $capsule->addConnection($connectionData);

        //$this->output->writeln('<options=bold>Selected database: ' . $connectionData['database'] . ' on host ' . $connectionData['host'] . '</>');

        $this->prefix = $prefix;

        $this->db = $capsule->getConnection();
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        if ($this->input->hasOption('locale')) {
            $this->locale = $this->input->getOption('locale') ?? $databaseConfig['locale'] ?? 'en_US';
        }

        if ($this->input->hasOption('group')) {
            $this->group = array_filter(array_map('trim', explode(',', $this->input->getOption('group'))));
        }

        if ($this->input->hasOption('table')) {
            $this->table = array_filter(array_map('trim', explode(',', $this->input->getOption('table'))));
        }
    }

    /**
     * @return bool
     */
    protected function needsDbConnection()
    {
        if ($this->getName() === 'groups') {
            return false;
        }

        return true;
    }
}
