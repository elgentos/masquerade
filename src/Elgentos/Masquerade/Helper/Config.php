<?php

namespace Elgentos\Masquerade\Helper;

use Elgentos\Parser\Context;
use Elgentos\Parser\Matcher\IsRegExp;
use Elgentos\Parser\Matcher\IsString;
use Elgentos\Parser\Matcher\MatchAll;
use Elgentos\Parser\Rule\Glob;
use Elgentos\Parser\Rule\Import;
use Elgentos\Parser\Rule\Iterate;
use Elgentos\Parser\Rule\LoopAll;
use Elgentos\Parser\Rule\MergeDown;
use Elgentos\Parser\Rule\NoLogic;
use Elgentos\Parser\Rule\Yaml;
use Phar;

class Config {

    protected $configDirs = [
        'src/config/',
        '~/.masquerade/config',
        'config'
    ];

    /**
     * @param $file
     * @return array
     */
    public function readYamlFile($rootDir, $file)
    {
        $data = [
            '@glob' => $file
        ];

        $context = new Context($data);

        $rule = new LoopAll(
            new Import($rootDir),
            new Yaml(),
            new MergeDown(true),
            new NoLogic(false)
        );

        $rule->parse($context);

        return $data;
    }

    /**
     * @param $rootDir
     * @param $dir
     * @return array
     */
    public function readYamlDir($rootDir, $dir)
    {
        $data = [
            '@glob' => $dir
        ];

        $context = new Context($data);

        $rule = new LoopAll(
            new Glob($rootDir),
            new Iterate(
                new LoopAll(
                    new Import(
                        $rootDir,
                        new MatchAll(
                            new IsString(),
                            new IsRegExp('#.yaml$#')
                        )
                    ),
                    new Yaml(),
                    new MergeDown(true)
                ),
                false
            ),
            new MergeDown(true),
            new NoLogic(false)
        );

        $rule->parse($context);

        return $data;
    }

    public function getConfig($platformName)
    {
        // Get config
        $config = [];
        $dirs = array_filter($this->configDirs, function ($dir) {
            return file_exists($dir) && is_dir($dir);
        });

        foreach ($dirs as $dir) {
            $content = $this->readYamlDir($dir, $platformName);
            $config = array_merge($config, $content);
        }

        return $config;
    }


    /**
     * @return bool
     */
    private function isPhar() {
        return strlen(Phar::running()) > 0 ? true : false;
    }
}