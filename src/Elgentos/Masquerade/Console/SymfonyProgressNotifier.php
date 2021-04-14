<?php


namespace Elgentos\Masquerade\Console;

use Elgentos\Masquerade\ProgressNotifier;
use Symfony\Component\Console\Helper\ProgressBar;

class SymfonyProgressNotifier implements ProgressNotifier
{
    /**
     * @var ProgressBar
     */
    private $progressBar;

    public function __construct(ProgressBar $progressBar)
    {
        $this->progressBar = $progressBar;
    }

    /** {@inheritDoc} */
    public function updateStatus(string $status): void
    {
        $this->progressBar->setMessage($status);
        $this->progressBar->advance(0);
    }

    /** {@inheritDoc} */
    public function advanceBy(int $processedRows): void
    {
        $this->progressBar->advance($processedRows);
    }

    /** {@inheritDoc} */
    public function complete(): void
    {
        $this->progressBar->setMessage('Complete');
        $this->progressBar->finish();
    }

    /** {@inheritDoc} */
    public function adjustTotal(int $totalRows): void
    {
        $this->progressBar->setMaxSteps($totalRows);
    }
}
