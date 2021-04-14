<?php


namespace Elgentos\Masquerade\DataProcessor;

use Elgentos\Masquerade\DataProcessor;
use Elgentos\Masquerade\Output;

/**
 * Regular processor for a table
 */
class RegularTableProcessor implements DataProcessor
{
    /**
     * Console output
     *
     * @var Output
     */
    private $output;

    /**
     * Table configuration options
     *
     * @var array
     */
    private $configuration;

    /**
     * Table service
     *
     * @var TableService
     */
    private $tableService;

    /**
     * RegularTableProcessor constructor.
     *
     * @param Output $output
     * @param TableService $tableService
     * @param array $configuration
     */
    public function __construct(Output $output, TableService $tableService, array $configuration)
    {
        $this->output = $output;
        $this->tableService = $tableService;
        $this->configuration = $configuration;
    }

    /** {@inheritDoc} */
    public function truncate(): void
    {
        $this->tableService->truncate();
    }

    /** {@inheritDoc} */
    public function delete(): void
    {
        $this->tableService->delete($this->configuration['provider']['where'] ?? '');
    }

    /** {@inheritDoc} */
    public function updateTable(int $batchSize, callable $generator): void
    {
        $columns = $this->configuredColumns();
        $primaryKey = $this->configuration['pk'] ?? $this->tableService->getPrimaryKey();

        $this->tableService->updateTable(
            $columns,
            $this->configuration['provider']['where'] ?? '',
            $primaryKey,
            $this->output,
            $generator,
            $batchSize
        );
    }

    /**
     * @return array
     */
    private function configuredColumns(): array
    {
        return $this->tableService->filterColumns($this->configuration['columns'] ?? []);
    }
}
