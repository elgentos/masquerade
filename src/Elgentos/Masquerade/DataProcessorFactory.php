<?php


namespace Elgentos\Masquerade;

use Elgentos\Masquerade\DataProcessor\TableServiceFactory;
use Illuminate\Database\Connection;

/**
 * Factory for a custom data processor
 */
interface DataProcessorFactory
{
    /**
     * Creates an instance of data processor
     * for data processing
     *
     * @param Output $output
     * @param TableServiceFactory $tableServiceFactory
     * @param array $tableConfiguration
     * @return DataProcessor
     */
    public function create(
        Output $output,
        TableServiceFactory $tableServiceFactory,
        array $tableConfiguration
    ): DataProcessor;
}
