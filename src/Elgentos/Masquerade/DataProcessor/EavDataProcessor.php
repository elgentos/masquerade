<?php


namespace Elgentos\Masquerade\DataProcessor;

use Elgentos\Masquerade\DataProcessor;
use Elgentos\Masquerade\Output;

/**
 * Processor for EAV data
 */
class EavDataProcessor implements DataProcessor
{
    /**
     * @var Output
     */
    private $output;

    /**
     * Processor configuration for a table
     *
     * @var array
     */
    private $configuration;

    /**
     * Table service for main EAV table
     *
     * @var TableService
     */
    private $mainTableService;

    /**
     * Factory for accessing other tables
     *
     * @var TableServiceFactory
     */
    private $tableServiceFactory;

    /**
     * EavDataProcessor constructor.
     *
     * @param Output $output
     * @param TableService $tableService
     * @param TableServiceFactory $tableServiceFactory
     * @param array $configuration
     */
    public function __construct(
        Output $output,
        TableService $tableService,
        TableServiceFactory $tableServiceFactory,
        array $configuration
    ) {
        $this->output = $output;
        $this->configuration = $configuration;
        $this->mainTableService = $tableService;
        $this->tableServiceFactory = $tableServiceFactory;
    }

    /** {@inheritDoc} */
    public function truncate(): void
    {
        $this->mainTableService->truncate();
    }

    /** {@inheritDoc} */
    public function delete(): void
    {
        $this->mainTableService->delete($this->configuration['provider']['where'] ?? '');
    }

    /** {@inheritDoc} */
    public function updateTable(int $batchSize, callable $generator): void
    {
        $mainColumns = $this->mainColumns();

        $primaryKey = $this->configuration['pk'] ?? $this->mainTableService->getPrimaryKey();
        $condition = $this->configuration['provider']['where'] ?? '';

        if ($mainColumns) {
            $this->mainTableService->updateTable(
                $mainColumns,
                $condition,
                $primaryKey,
                $this->output,
                $generator,
                $batchSize
            );
        }

        $attributesByTable = $this->attributesByTable();

        if (!$attributesByTable) {
            $this->output->warning('Skipping EAV attribute processing for [%s]', $this->configuration['name']);
            return;
        }

        $this->output->info('Processing EAV data for [%s]', $this->configuration['name']);

        $eavRanges = $this->mainTableService->tableRanges($primaryKey, $batchSize, $condition);

        foreach ($attributesByTable as $tableName => $attributes) {
            $eavTableService = $this->tableServiceFactory->create($tableName);
            $eavValueId = $eavTableService->getPrimaryKey();
            $progress = $this->output->progressNotifier(sprintf('Updating EAV data in [%s]', $tableName), 0);
            $progress->updateStatus('Calculate total records');

            $attributesToUpdate = $this->mainTableService
                ->queryWithJoinAndCondition($tableName, $primaryKey, $condition)
                ->select($eavValueId, 'attribute_id')
                ->whereIn('joined.attribute_id', array_keys($attributes));

            $progress->adjustTotal((clone $attributesToUpdate)->count());

            $insertStatement = $eavTableService->createInsertOnUpdateStatement($batchSize, [$eavValueId, 'attribute_id', 'value']);

            $parameters = [];
            $currentRows = 0;

            $progress->updateStatus('Generating records');
            foreach ($eavRanges as $min => $max) {
                $attributePairs = (clone $attributesToUpdate)
                    ->whereBetween('main.' . $primaryKey, [$min, $max])
                    ->pluck('attribute_id', $eavTableService->getPrimaryKey());

                foreach ($attributePairs as $rowId => $attributeId) {
                    $currentRows ++;
                    $parameters[] = $rowId;
                    $parameters[] = $attributeId;
                    $parameters[] = iterator_to_array($generator([$attributes[$attributeId]]))[0];

                    if ($currentRows === $batchSize) {
                        $progress->updateStatus('Importing into database');
                        $insertStatement->execute($parameters);
                        $progress->advanceBy($currentRows);
                        $progress->updateStatus('Generating records');
                        $currentRows = 0;
                        $parameters = [];
                    }
                }
            }

            if ($currentRows) {
                $insertStatement = $eavTableService->createInsertOnUpdateStatement(
                    $currentRows,
                    [$eavValueId, 'attribute_id', 'value']
                );

                $progress->updateStatus('Importing into database');
                $insertStatement->execute($parameters);
                $progress->advanceBy($currentRows);
            }

            $progress->complete();
        }
    }

    /**
     * @return array
     */
    private function mainColumns(): array
    {
        return $this->mainTableService->filterColumns($this->configuration['columns'] ?? []);
    }

    /**
     * Attributes by table
     *
     * @return array
     */
    private function attributesByTable(): array
    {
        $mainColumns = $this->mainColumns();

        $entity = $this->tableServiceFactory->create('eav_entity_type')->query()
            ->select(['entity_table', 'value_table_prefix', 'entity_type_id'])
            ->where('entity_table', '=', $this->configuration['name'])
            ->first(['entity_type_id', 'value_table_prefix']);

        $attributeCodes = [];
        foreach ($this->configuration['columns'] ?? [] as $columnName => $configuration) {
            if (isset($mainColumns[$columnName])) {
                continue;
            }

            $attributeCodes[] = $columnName;
        }

        if (empty($attributeCodes)) {
            return [];
        }

        $attributes = $this->tableServiceFactory->create('eav_attribute')
            ->query()
            ->select(['attribute_id', 'is_unique', 'backend_type', 'attribute_code'])
            ->where('entity_type_id', '=', $entity->entity_type_id)
            ->whereIn('attribute_code', $attributeCodes)
            ->get(['attribute_id', 'is_unique', 'backend_type', 'attribute_code']);

        $attributeByTable = [];
        $baseTable = $entity->value_table_prefix ?: $this->configuration['name'];

        foreach ($attributes as $attribute) {
            $attributeTable = $baseTable . '_' . $attribute->backend_type;
            $configuration = $this->configuration['columns'][$attribute->attribute_code];
            if ($attribute->backend_type === 'static') {
                continue;
            }

            if ($attribute->is_unique) {
                $configuration['unique'] = true;
            }

            $attributeByTable[$attributeTable][$attribute->attribute_id] = $configuration;
        }

        return $attributeByTable;
    }
}
