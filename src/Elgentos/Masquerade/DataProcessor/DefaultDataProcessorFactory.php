<?php

namespace Elgentos\Masquerade\DataProcessor;

use Elgentos\Masquerade\DataProcessor;
use Elgentos\Masquerade\DataProcessorFactory;
use Elgentos\Masquerade\Output;
use Illuminate\Database\Connection;

class DefaultDataProcessorFactory implements DataProcessorFactory
{
    public function create(
        Output $output,
        TableServiceFactory $tableServiceFactory,
        array $tableConfiguration
    ): DataProcessor {
        try {
            $processor = $this->instantiateProcessor($tableServiceFactory, $tableConfiguration, $output);
        } catch (TableDoesNotExistsException $e) {
            throw $e;
        } catch (\Exception $e) {
            $output->errorAndExit($e->getMessage());
        }

        $whereCondition = $tableConfiguration['where'] ?? '';

        $columns = $tableConfiguration['columns'] ?? [];
        foreach ($columns as $column => $columnData) {
            if ($columnData['nullColumnBeforeRun'] ?? false) {
                if (strpos($whereCondition, $column) !== false) {
                    $output->warning(
                        'Table %1$s configuration mentions a field in where condition which is set to nullColumnBeforeRun. '
                        . 'Ensure your condition does not include `%2$s` or `%2$s` is null',
                        $tableConfiguration['name'],
                        $column
                    );
                }
            }
        }

        return $processor;
    }

    /**
     * @param TableServiceFactory $tableServiceFactory
     * @param array $tableConfiguration
     * @param Output $output
     * @return DataProcessor
     */
    private function instantiateProcessor(
        TableServiceFactory $tableServiceFactory,
        array $tableConfiguration,
        Output $output
    ): DataProcessor {
        $tableService = $tableServiceFactory->create($tableConfiguration['name']);

        if ($tableConfiguration['eav'] ?? false) {
            return new EavDataProcessor($output, $tableService, $tableServiceFactory, $tableConfiguration);
        }

        return new RegularTableProcessor($output, $tableService, $tableConfiguration);
    }
}
