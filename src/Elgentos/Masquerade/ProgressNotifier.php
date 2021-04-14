<?php


namespace Elgentos\Masquerade;

interface ProgressNotifier
{
    /**
     * Updates progress status
     *
     * @param string $status
     * @return void
     */
    public function updateStatus(string $status): void;

    /**
     * Advances progress by processed rows count
     *
     * @param int $processedRows
     */
    public function advanceBy(int $processedRows): void;

    /**
     * Specifies total number of rows to progress on
     *
     * @param int $totalRows
     */
    public function adjustTotal(int $totalRows): void;

    /**
     * Notifies of completion of progress
     */
    public function complete(): void;
}
