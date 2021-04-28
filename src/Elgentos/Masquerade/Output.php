<?php


namespace Elgentos\Masquerade;

use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Output interface for data processor to use
 *
 * Uses separate methods for different verbosity of the output
 */
interface Output
{
    /**
     * Output error to a console and stop execution of the process
     *
     * @param string $text
     * @param mixed ...$args
     */
    public function errorAndExit(string $text, ...$args): void;

    /**
     * Output error message to a console, but continue the execution
     *
     * @param string $text
     * @param mixed ...$args
     */
    public function error(string $text, ...$args): void;

    /**
     * Output warning message to a console
     *
     * @param string $text
     * @param mixed ...$args
     */
    public function warning(string $text, ...$args): void;

    /**
     * Output info message to a console
     *
     * @param string $text
     * @param mixed ...$args
     */
    public function info(string $text, ...$args): void;

    /**
     * Outputs success message to a console
     *
     * @param string $text
     * @param mixed ...$args
     */
    public function success(string $text, ...$args): void;

    /**
     * Outputs debug message to a console when verbosity is set
     *
     * @param string $text
     * @param array $data
     */
    public function debug(string $text, array $data = []): void;

    /**
     * Creates progress bar with a specified title
     *
     * @param string $label
     * @param int $totalRows
     * @return ProgressNotifier
     */
    public function progressNotifier(string $label, int $totalRows): ProgressNotifier;
}
