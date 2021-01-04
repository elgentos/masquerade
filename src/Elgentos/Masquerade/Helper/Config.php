<?php

namespace Elgentos\Masquerade\Helper;

use Elgentos\Parser;
use Elgentos\Parser\Standard;
use Elgentos\Parser\Stories\Reader\Complex;
use Phar;

class Config
{
    protected $configDirs = [
        __DIR__ . '/../../../config',
        'src/config/',
        '~/.masquerade/config',
        '~/.config/masquerade',
        'config',
    ];

    public function __construct($options = [])
    {
        if ($options['path']) {
            $this->configDirs[] = $options['path'];
        }
    }

    /**
     * @param string $rootDir
     * @param string $file
     * @return array
     */
    const CONFIG_YAML = 'config.yaml';

    public function readConfigFile()
    {
        $dirs = $this->getExistingConfigDirs();
        $dirs = array_merge(['.'], $dirs);

        $config = [];
        foreach ($dirs as $dir) {
            if (file_exists($dir . '/' . self::CONFIG_YAML)) {
                $content =  Parser::readFile(self::CONFIG_YAML, $dir);
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
        $data = [['@import-dir' => $dir]];

        $story = new Complex($rootDir);
        (new Standard)->parse($data, $story);

        return $data;
    }

    public function getConfig($platformName)
    {
        // Get config
        $config = [];
        $dirs = $this->getExistingConfigDirs();

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
    public function normalizePath(string $path, string $separator = '\\/'): string
    {
        // Remove any kind of funky unicode whitespace
        $normalized = preg_replace('#\p{C}+|^\./#u', '', $path);

        // Path remove self referring paths ("/./").
        $normalized = preg_replace('#/\.(?=/)|^\./|\./$#', '', $normalized);

        // Replace ~ with full HOME path
        $normalized = preg_replace('#^~#', $_SERVER['HOME'], $normalized);

        // Regex for resolving relative paths
        $regex = '#\/*[^/\.]+/\.\.#Uu';

        while (preg_match($regex, $normalized)) {
            $normalized = preg_replace($regex, '', $normalized);
        }

        if (preg_match('#/\.{2}|\.{2}/#', $normalized)) {
            throw new \Exception('Path is outside of the defined root, path: [' . $path . '], resolved: [' . $normalized . ']');
        }

        return rtrim($normalized, $separator);
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
