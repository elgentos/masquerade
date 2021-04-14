<?php


namespace Elgentos\Masquerade;

/**
 * Data processor interface to be implemented for various data types
 */
interface DataProcessor
{
    /**
     * Truncate all data in target table
     */
    public function truncate(): void;

    /**
     * Deletes data in table
     */
    public function delete(): void;

    /**
     * Loads source data in specified chunks and updates processed data by mapper
     *
     * @param int $batchSize
     * @param callable $generator
     */
    public function updateTable(int $batchSize, callable $generator): void;
}
