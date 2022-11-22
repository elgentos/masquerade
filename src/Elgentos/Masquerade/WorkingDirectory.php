<?php

namespace Elgentos\Masquerade;

use Symfony\Component\Console\Input\InputInterface;
use Webmozart\Assert\Assert;

final class WorkingDirectory
{
    const OPTION_NAME = 'working-dir';
    const DESCRIPTION = 'Set the working directory';

    public static function change(InputInterface $input)
    {
        $workingDir = $input->getOption(self::OPTION_NAME) ?? null;

        if (null === $workingDir) {
            return;
        }

        Assert::directory($workingDir, 'Could not change the working directory to %s: directory does not exists or file is not a directory.');

        if (false === chdir($workingDir)) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to change the working directory to "%s" from "%s".',
                    $workingDir,
                    getcwd(),
                ),
            );
        }
    }
}