<?php

namespace Elgentos\Masquerade;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends SymfonyApplication
{
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        $this->registerAutoloader();
        return parent::run($input, $output);
    }

    private function registerAutoloader()
    {
        spl_autoload_register(function ($class) {
            $class = str_replace('\\', '/', $class);

            $pathPrefixToLookForCustomFormatters = [
                '',
                __DIR__. '/',
                $_SERVER['HOME'] . '/'
            ];

            foreach ($pathPrefixToLookForCustomFormatters as $path) {
                $file = $path . '.masquerade/' . $class . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    break;
                }
            }
        });
    }
}
