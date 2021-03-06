<?php

/*
 * This file is part of the awurth/config package.
 *
 * (c) Alexis Wurth <awurth.dev@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AWurth\Config;

use AWurth\Config\Loader\JsonFileLoader;
use AWurth\Config\Loader\PhpFileLoader;
use AWurth\Config\Loader\YamlFileLoader;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Config\Resource\FileResource;

/**
 * Configuration Loader.
 *
 * @author Alexis Wurth <awurth.dev@gmail.com>
 */
class ConfigurationLoader
{
    /**
     * @var ConfigCacheInterface
     */
    protected $cache;

    /**
     * @var array
     */
    protected $configurations;

    /**
     * @var LoaderInterface
     */
    protected $loader;

    /**
     * @var LoaderInterface[]
     */
    protected $loaders;

    /**
     * @var Options
     */
    protected $options;

    /**
     * @var array
     */
    protected $parameters;

    /**
     * @var FileResource[]
     */
    protected $resources;

    /**
     * Constructor.
     *
     * @param string $cachePath
     * @param bool   $debug
     */
    public function __construct($cachePath = null, $debug = false)
    {
        $this->configurations = [];
        $this->resources = [];
        $this->parameters = [];
        $this->options = new Options();

        if (null !== $cachePath) {
            $this->cache = new ConfigCache($cachePath, $debug);
        }
    }

    /**
     * Loads the configuration from a cache file if it exists, or parses a configuration file if not.
     *
     * @param string $file
     *
     * @return array
     */
    public function load($file)
    {
        if (null !== $this->cache) {
            if (!$this->cache->isFresh()) {
                $configuration = $this->loadFile($file);
                $this->export($configuration);

                return $configuration;
            }

            return self::requireFile($this->cache->getPath());
        }

        return $this->loadFile($file);
    }

    /**
     * Loads the configuration from a file.
     *
     * @param string $file
     *
     * @return array
     */
    public function loadFile($file)
    {
        $this->initLoader();

        $this->parseFile($file);

        $configuration = $this->mergeConfiguration();

        if ($this->options->areParametersEnabled()) {
            if (isset($configuration[$this->options->getParametersKey()])) {
                $this->mergeParameters($configuration[$this->options->getParametersKey()]);
            }

            $this->replacePlaceholders($configuration);
        }

        return $configuration;
    }

    /**
     * Exports the configuration to a cache file.
     *
     * @param array $configuration
     */
    public function export(array $configuration)
    {
        $content = '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($configuration, true).';'.PHP_EOL;

        $this->cache->write($content, $this->resources);
    }

    /**
     * Gets the configuration cache.
     *
     * @return ConfigCacheInterface
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Sets the configuration cache.
     *
     * @param ConfigCacheInterface $cache
     */
    public function setCache(ConfigCacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Gets the file loaders.
     *
     * @return LoaderInterface[]
     */
    public function getLoaders()
    {
        return $this->loaders;
    }

    /**
     * Adds a file loader.
     *
     * @param LoaderInterface $loader
     *
     * @return self
     */
    public function addLoader(LoaderInterface $loader)
    {
        $this->loaders[] = $loader;

        return $this;
    }

    /**
     * Sets the file loaders.
     *
     * @param LoaderInterface[] $loaders
     */
    public function setLoaders(array $loaders)
    {
        $this->loaders = $loaders;
    }

    /**
     * Gets the parameters.
     *
     * @return array
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Sets a parameter's value.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return self
     */
    public function setParameter($name, $value)
    {
        $this->parameters[$name] = $value;

        return $this;
    }

    /**
     * Sets the parameters.
     *
     * @param array $parameters
     */
    public function setParameters(array $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Gets the options.
     *
     * @return Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Sets the options.
     *
     * @param Options $options
     */
    public function setOptions(Options $options)
    {
        $this->options = $options;
    }

    /**
     * Initializes the file loader.
     */
    protected function initLoader()
    {
        if (null === $this->loader) {
            $this->addLoader(new PhpFileLoader());
            $this->addLoader(new YamlFileLoader());
            $this->addLoader(new JsonFileLoader());

            $this->loader = new DelegatingLoader(new LoaderResolver($this->loaders));
        }
    }

    /**
     * Returns whether the file path is an absolute path.
     *
     * @param string $file
     *
     * @return bool
     */
    protected function isAbsolutePath($file)
    {
        if ('/' === $file[0] || '\\' === $file[0]
            || (strlen($file) > 3 && ctype_alpha($file[0])
                && ':' === $file[1]
                && ('\\' === $file[2] || '/' === $file[2])
            )
            || null !== parse_url($file, PHP_URL_SCHEME)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Loads an imported file.
     *
     * @param string      $path
     * @param string      $originalFile
     * @param string|null $key
     */
    protected function loadImport($path, $originalFile, $key = null)
    {
        if ($this->options->areParametersEnabled()) {
            $this->replaceStringPlaceholders($path);
        }

        if ($this->isAbsolutePath($path) && file_exists($path)) {
            $this->parseFile($path, $key);
        } else {
            $this->parseFile(dirname($originalFile).DIRECTORY_SEPARATOR.$path, $key);
        }
    }

    /**
     * Loads file imports recursively.
     *
     * @param array       $values
     * @param string|null $originalFile
     */
    protected function loadImports(&$values, $originalFile = null)
    {
        if (isset($values[$this->options->getImportsKey()])) {
            $imports = $values[$this->options->getImportsKey()];

            if (is_string($imports)) {
                $this->loadImport($imports, $originalFile);
            } elseif (is_array($imports)) {
                foreach ($imports as $key => $file) {
                    $this->loadImport($file, $originalFile, is_string($key) ? $key : null);
                }
            }
        }

        unset($values[$this->options->getImportsKey()]);
    }

    /**
     * Merges all loaded configurations into a single array.
     *
     * @return array
     */
    protected function mergeConfiguration()
    {
        if (count($this->configurations) > 1) {
            return call_user_func_array('array_replace_recursive', $this->configurations);
        }

        return $this->configurations[0];
    }

    /**
     * Merges new parameters with existing ones.
     *
     * @param array $parameters
     */
    protected function mergeParameters(array $parameters)
    {
        $this->parameters = array_replace_recursive($this->parameters, $parameters);
    }

    /**
     * Parses a configuration file.
     *
     * @param string $file
     * @param string $key
     */
    protected function parseFile($file, $key = null)
    {
        $values = $this->loader->load($file);

        if (!empty($values)) {
            if ($this->options->areParametersEnabled() && isset($values[$this->options->getParametersKey()])) {
                $this->replacePlaceholders($values[$this->options->getParametersKey()]);
                $this->mergeParameters($values[$this->options->getParametersKey()]);
            }

            if ($this->options->areImportsEnabled()) {
                $this->loadImports($values, $file);
            }

            $this->configurations[] = null !== $key ? [$key => $values] : $values;
            $this->resources[] = new FileResource($file);
        }
    }

    /**
     * Parses the configuration and replaces placeholders with the corresponding parameters values.
     *
     * @param array $configuration
     */
    protected function replacePlaceholders(array &$configuration)
    {
        array_walk_recursive($configuration, [$this, 'replaceStringPlaceholders']);
    }

    /**
     * Replaces configuration placeholders with the corresponding parameters values.
     *
     * @param string $string
     */
    protected function replaceStringPlaceholders(&$string)
    {
        if (is_string($string)) {
            if (preg_match('/^%([0-9A-Za-z._-]+)%$/', $string, $matches)) {
                if (isset($this->parameters[$matches[1]])) {
                    $string = $this->parameters[$matches[1]];
                }
            } else {
                $string = preg_replace_callback('/%([0-9A-Za-z._-]+)%/', function ($matches) {
                    if (isset($this->parameters[$matches[1]]) && !in_array(gettype($this->parameters[$matches[1]]), ['object', 'array'])) {
                        return $this->parameters[$matches[1]];
                    } else {
                        return $matches[0];
                    }
                }, $string);
            }
        }
    }

    /**
     * Includes a PHP file.
     *
     * @param string $file
     *
     * @return array
     */
    private static function requireFile($file)
    {
        return require $file;
    }
}
