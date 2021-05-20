<?php


namespace Elgentos\Masquerade\DataProcessor;

use Elgentos\Masquerade\Output;
use Illuminate\Database\Connection;

class TableServiceFactory
{
    /**
     * @var Connection
     */
    private $database;

    /**
     * @var TableService[]
     */
    private $serviceCache = [];

    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * Creates a new table service by table name
     *
     * @param string $tableName
     * @return TableService
     */
    public function create(string $tableName): TableService
    {
        if (!$this->database->getSchemaBuilder()->hasTable($tableName)) {
            throw new TableDoesNotExistsException($tableName);
        }

        if (!isset($this->serviceCache[$tableName])) {
            $this->serviceCache[$tableName] = new TableService(
                $tableName,
                $this->database,
                $this->fetchTableColumns($tableName)
            );
        }

        return $this->serviceCache[$tableName];
    }

    private function fetchTableColumns(string $tableName): array
    {
        $query = $this->database->query()
            ->from('information_schema.columns')
            ->select(['column_name as column_name', 'column_key as column_key'])
            ->where('table_name', '=', $this->database->getTablePrefix() . $tableName)
            ->whereRaw('table_schema = DATABASE()');

        $query->grammar = clone $query->grammar;
        $query->grammar->setTablePrefix('');

        return $query->pluck('column_key', 'column_name')->toArray();
    }
}
