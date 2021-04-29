<?php


namespace Elgentos\Masquerade\DataProcessor;

use Elgentos\Masquerade\Output;
use Elgentos\Masquerade\ProgressNotifier;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

class TableService
{
    /**
     * Table name for this service
     *
     * @var string
     */
    private $tableName;

    /**
     * Database connection
     * @var Connection
     */
    private $database;

    /**
     * Column list information
     *
     * @var array[]
     */
    private $columnList;

    /**
     * TableService constructor.
     *
     * @param string $tableName
     * @param Connection $database
     * @param array $columnList
     */
    public function __construct(string $tableName, Connection $database, array $columnList)
    {
        $this->tableName = $tableName;
        $this->database = $database;
        $this->columnList = $columnList;
    }

    /**
     * Create a query with base select from this table
     *
     * @param bool $withoutAlias do not use alias for a main table
     * @return Builder
     */
    public function query(bool $withoutAlias = false): Builder
    {
        $tableExpression = $withoutAlias ? $this->tableName : sprintf("%s as main", $this->tableName);

        return $this->database->query()->from($tableExpression);
    }

    /**
     * Returns base select query with attached condition
     *
     * @param string $condition
     * @param bool $withoutAlias do not use alias for a main table
     * @return Builder
     */
    public function queryWithCondition(string $condition, bool $withoutAlias = false): Builder
    {
        $query = $this->query($withoutAlias);

        if ($condition) {
            $query->whereRaw($condition);
        }

        return $query;
    }

    /**
     * Returns base select query with attached condition
     *
     * @param string $table
     * @param string $joinColumn
     * @param string $condition
     * @return Builder
     */
    public function queryWithJoinAndCondition(string $table, string $joinColumn, string $condition): Builder
    {
        $query = $this->query();
        $query->join(
            sprintf("%s as joined", $table),
            sprintf('main.%s', $joinColumn),
            '=',
            sprintf('joined.%s', $joinColumn)
        );

        if ($condition) {
            $query->whereRaw($condition);
        }

        return $query;
    }


    /**
     * Deletes table data with provided condition
     *
     * @param string $condition
     */
    public function delete(string $condition): void
    {
        $this->queryWithCondition($condition, true)->delete();
    }

    /**
     * Nullifies provided columns in the table by using specified condition
     *
     * @param array $columns
     * @param ProgressNotifier $notifier
     * @param string $primaryKey
     * @param string $condition
     */
    public function nullify(array $columns, ProgressNotifier $notifier, string $primaryKey, string $condition): void
    {
        $updateStatements = array_fill_keys($columns, null);

        $notifier->updateStatus('Calculating required batches');
        $ranges = $this->tableRanges($primaryKey, 5000, $condition);
        $notifier->adjustTotal(count($ranges));
        $notifier->advanceBy(0);

        foreach ($ranges as $min => $max) {
            $notifier->updateStatus('Process 5000 rows to nullify');
            $this->queryWithCondition($condition)
                ->whereBetween($primaryKey, [$min, $max])
                ->update($updateStatements);
            $notifier->advanceBy(1);
        }

        $notifier->complete();
    }

    /**
     * Truncates a table
     */
    public function truncate(): void
    {
        $this->query(true)->truncate();
    }

    /**
     * Table primary key
     *
     * @return string|null
     */
    public function getPrimaryKey(): ?string
    {
        $primaryKey = array_search('PRI', $this->columnList, true);
        return $primaryKey ?: null;
    }

    /**
     * Filters input columns with real ones
     *
     * @param array $inputColumns
     * @return array
     */
    public function filterColumns(array $inputColumns): array
    {
        return array_filter(
            $inputColumns,
            function ($column) {
                return isset($this->columnList[$column]);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Ranged creates auto-fetched batched column values generator from database
     *
     * @param string $columnName
     * @param int $batchSize
     * @param string $condition
     * @return \Generator
     */
    public function rangedColumnValues(string $columnName, int $batchSize, string $condition): \Generator
    {
        $ranges = $this->tableRanges($columnName, $batchSize, $condition);

        yield from $this->queryByRanges($ranges, [$columnName], function (Builder $query) use ($condition) {
            if ($condition) {
                $query->whereRaw($condition);
            }
        });
    }

    /**
     * Table ranges of the tables by condition and column
     *
     * @param string $columnName
     * @param int $batchSize
     * @param string $condition
     * @return array
     */
    public function tableRanges(string $columnName, int $batchSize, string $condition): array
    {
        $expression = sprintf('CEIL(`%1$s` / %2$d) * %2$d', $columnName, $batchSize);

        $minColumn = new Expression(sprintf('MIN(`%s`) as min', $columnName));
        $maxColumn = new Expression(sprintf('MAX(`%s`) as max', $columnName));

        return $this->queryWithCondition($condition)
            ->select([$minColumn, $maxColumn])
            ->groupByRaw($expression)
            ->pluck('max', 'min')
            ->toArray();
    }

    /**
     * @param array $columns
     * @param string $condition
     * @param string|null $primaryKey
     * @param Output $output
     * @param callable $generator
     * @param int $batchSize
     */
    public function updateTable(
        array $columns,
        string $condition,
        ?string $primaryKey,
        Output $output,
        callable $generator,
        int $batchSize
    ) {
        $nullifyColumns = [];
        foreach ($columns as $columnName => $config) {
            if ($config['nullColumnBeforeRun'] ?? false) {
                $nullifyColumns[] = $columnName;
            }
        }

        if (!$primaryKey) {
            $output->errorAndExit(
                'Table %s does not have primary key configured, which makes impossible table anonymization.',
                $this->tableName
            );
        }

        if ($nullifyColumns) {
            $progress = $output->progressNotifier(
                sprintf('Nullifying columns in batches for [%s]', $this->tableName),
                0
            );

            $this->nullify(
                $nullifyColumns,
                $progress,
                $primaryKey,
                $condition
            );
        }

        unset($columns[$primaryKey]);

        $progress = $output->progressNotifier(
            sprintf('Updating [%s]', $this->tableName),
            0
        );

        $statementColumns = array_merge([$primaryKey], array_keys($columns));
        $batchStatement = $this->createInsertOnUpdateStatement($batchSize, $statementColumns);

        $progress->updateStatus('Calculating total rows to process');
        $progress->adjustTotal(
            $this->queryWithCondition($condition)->count()
        );

        $progress->updateStatus('Collecting most efficient ranges for batches');

        $rowIds = $this->rangedColumnValues(
            $primaryKey,
            $batchSize,
            $condition
        );

        $currentRows = 0;
        $parameters = [];

        $progress->updateStatus('Generate data');
        foreach ($rowIds as $rowId) {
            $currentRows ++;

            $parameters[] = $rowId;
            foreach ($generator($columns) as $value) {
                $parameters[] = $value;
            }

            if ($currentRows === $batchSize) {
                $progress->updateStatus('Updating database');
                $batchStatement->execute($parameters);
                $progress->advanceBy($currentRows);
                $progress->updateStatus('Generate data');
                $currentRows = 0;
                $parameters = [];
            }
        }

        if ($currentRows > 0) {
            $progress->updateStatus('Updating database');
            $batchStatement = $this->createInsertOnUpdateStatement($currentRows, $statementColumns);
            $batchStatement->execute($parameters);
        }

        $progress->complete();
    }

    /**
     * Queries table by using provided ranges and returns back provided columns
     *
     * @param iterable $ranges
     * @param string[] $columns
     * @param callable $condition
     * @return \Generator
     */
    public function queryByRanges(
        iterable $ranges,
        array $columns,
        callable $condition
    ): \Generator {
        $filterColumn = $columns[0];
        $valueColumn = $columns[1] ?? $columns[0];
        $idColumn = $columns[2] ?? $columns[1] ?? $columns[0];

        foreach ($ranges as $min => $max) {
            $query = $this->query()
                ->select($columns)
                ->whereBetween($filterColumn, [$min, $max]);

            $condition($query);

            foreach ($query->pluck($valueColumn, $idColumn) as $rowId => $value) {
                yield $rowId => $value;
            }
        }
    }

    /**
     * Creates PDO statement for inserting data in batches for update
     *
     * @param int $totalRows
     * @param string[] $columns
     * @return \PDOStatement
     */
    public function createInsertOnUpdateStatement(int $totalRows, array $columns): \PDOStatement
    {
        if ($totalRows <= 0) {
            throw new \RuntimeException('Statement must be generated for at least 1 row');
        }

        if (count($columns) < 2) {
            throw new \RuntimeException('There must be at least 2 columns specified for update columns (one pk and one regular)');
        }

        $primaryKey = $this->getPrimaryKey();
        $onUpdate = [];
        $placeholders = sprintf("(%s?)", str_repeat("?,", count($columns) - 1));
        $columnNames = [];

        foreach ($columns as $column) {
            $columnNames[] = sprintf('`%s`', $column);
            if ($primaryKey && $column != $primaryKey) {
                $onUpdate[] = sprintf('`%1$s` = VALUES(`%1$s`)', $column);
            }
        }

        return $this->database->getPdo()->prepare(sprintf(
            'INSERT INTO `%s` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            $this->database->getTablePrefix() . $this->tableName,
            implode(",", $columnNames),
            str_repeat("$placeholders,", $totalRows - 1) . $placeholders,
            implode(",", $onUpdate)
        ));
    }
}
