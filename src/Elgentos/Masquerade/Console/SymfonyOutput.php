<?php

namespace Elgentos\Masquerade\Console;

use Elgentos\Masquerade\Output;
use Elgentos\Masquerade\ProgressNotifier;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SymfonyOutput implements Output
{
    /**
     * @var OutputInterface
     */
    private $symfonyOutput;

    /** @var FormatterHelper */
    private $formatHelper;

    public function __construct(OutputInterface $symfonyOutput)
    {
        $this->symfonyOutput = $symfonyOutput;
        $this->formatHelper = new FormatterHelper();
    }

    /**
     * Creates instance from Symfony output object
     *
     * @param OutputInterface $symfonyOutput
     * @return $this
     */
    public static function createFromSymfonyOutput(OutputInterface $symfonyOutput): self
    {
        return new self($symfonyOutput);
    }

    /** {@inheritDoc} */
    public function errorAndExit(string $text, ...$args): void
    {
        $this->error($text, ...$args);
        exit(1);
    }

    /** {@inheritDoc} */
    public function error(string $text, ...$args): void
    {
        $text = explode(PHP_EOL, $this->formatText($text, $args));
        array_unshift($text, 'ERROR:');
        $this->symfonyOutput->writeln($this->formatHelper->formatBlock($text, 'error'), OutputInterface::VERBOSITY_QUIET);
    }

    /** {@inheritDoc} */
    public function warning(string $text, ...$args): void
    {
        $text = $this->formatText($text, $args);
        $this->symfonyOutput->writeln(sprintf('<comment>%s</comment>', $text), OutputInterface::VERBOSITY_NORMAL);
    }

    /** {@inheritDoc} */
    public function info(string $text, ...$args): void
    {
        $text = $this->formatText($text, $args);
        $this->symfonyOutput->writeln($text, OutputInterface::VERBOSITY_NORMAL);
    }

    /** {@inheritDoc} */
    public function debug(string $text, array $data = []): void
    {
        $this->symfonyOutput->writeln(sprintf('<comment>%s</comment>', $text), OutputInterface::VERBOSITY_VERBOSE);
        $this->symfonyOutput->writeln(var_export($data, true), OutputInterface::VERBOSITY_VERBOSE);
    }

    /** {@inheritDoc} */
    public function success(string $text, ...$args): void
    {
        $text = $this->formatText($text, $args);
        $this->symfonyOutput->writeln(sprintf('<info>%s</info>', $text), OutputInterface::VERBOSITY_NORMAL);
    }

    /**
     * Formats conditionally text in the message
     *
     * @param string $text
     * @param array $args
     * @return string
     */
    private function formatText(string $text, array $args): string
    {
        if ($args) {
            $text = vsprintf($text, $args);
        }

        return $text;
    }

    public function progressNotifier(string $label, int $totalRows): ProgressNotifier
    {
        $this->symfonyOutput->writeln(['']);

        $this->symfonyOutput->writeln(sprintf('<comment>%s</comment>', $label), OutputInterface::VERBOSITY_NORMAL);

        $progressBar = new ProgressBar($this->symfonyOutput, $totalRows);
        $formatId = uniqid('progress_notifier');
        $this->setupFormatBasedOnVerbosity($formatId, $this->symfonyOutput->getVerbosity());
        $progressBar->setFormat($formatId);

        return new SymfonyProgressNotifier($progressBar);
    }

    private function setupFormatBasedOnVerbosity(string $formatName, int $verbosityLevel)
    {
        $verbosityFormat = [
            ConsoleOutputInterface::VERBOSITY_VERBOSE => 'verbose',
            ConsoleOutputInterface::VERBOSITY_VERY_VERBOSE => 'very_verbose',
            ConsoleOutputInterface::VERBOSITY_DEBUG => 'debug',
        ];

        $originalFormat = $verbosityFormat[$verbosityLevel] ?? 'verbose';
        ProgressBar::setFormatDefinition(
            $formatName,
            ProgressBar::getFormatDefinition($originalFormat) . " %message%"
        );
        ProgressBar::setFormatDefinition(
            $formatName . "_nomax",
            ProgressBar::getFormatDefinition($originalFormat . "_nomax") . " %message%"
        );
    }
}
