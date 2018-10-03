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
        __DIR__ . '/../../../config',
        'src/config/',
        '~/.masquerade/config',
        'config'
    ];

    /**
     * @param string $rootDir
     * @param string $file
     * @return array
     */
    const CONFIG_YAML = 'config.yaml';

    public function readYamlFile(string $rootDir, string $file) : array
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

    public function readConfigFile() {
        $dirs = $this->getExistingConfigDirs();
        $dirs = array_merge(['.'], $dirs);

        $config = [];
        foreach ($dirs as $dir) {
            if (file_exists($dir . '/' . self::CONFIG_YAML)) {
                $content = $this->readYamlFile($dir, self::CONFIG_YAML);
                $config = array_merge($config, $content);
            }
        }

        return $config;
    }

    /**
     * @param string $rootDir
     * @param string $dir
     * @return array
     */
    public function readYamlDir(string $rootDir, string $dir) : array
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
        $dirs = $this->getExistingConfigDirs($platformName);

        $dirs = array_filter($dirs, function ($dir) use ($platformName) {
            return file_exists($dir . '/' . $platformName)
                && is_dir($dir . '/' . $platformName);
        });

        foreach ($dirs as $dir) {
            $content = $this->readYamlDir($dir, $platformName);
            $config = array_merge($config, $content);
        }

        return $config;
    }

    /**
     * @param $path
     * @param string $separator
     * @return string
     */
    public function normalizePath($path, $separator = '\\/')
    {
        // Remove any kind of funky unicode whitespace
        $normalized = preg_replace('#\p{C}+|^\./#u', '', $path);

        // Path remove self referring paths ("/./").
        $normalized = preg_replace('#/\.(?=/)|^\./|\./$#', '', $normalized);

        // Regex for resolving relative paths
        $regex = '#\/*[^/\.]+/\.\.#Uu';

        while (preg_match($regex, $normalized)) {
            $normalized = preg_replace($regex, '', $normalized);
        }

        if (preg_match('#/\.{2}|\.{2}/#', $normalized)) {
            throw new \Exception('Path is outside of the defined root, path: [' . $path . '], resolved: [' . $normalized . ']');
        }

        return trim($normalized, $separator);
    }

    /**
     * @return array
     */
    protected function getExistingConfigDirs(): array
    {
        $dirs = array_map([$this, 'normalizePath'], $this->configDirs);
        $dirs = array_filter($dirs, function ($dir) {
            return file_exists($dir)
                && is_dir($dir);
        });
        return $dirs;
    }
}