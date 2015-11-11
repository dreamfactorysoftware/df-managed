<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Disk;
use DreamFactory\Library\Utility\JsonFile;
use DreamFactory\Managed\Contracts\ProvidesManagedConfig;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Exceptions\ManagedInstanceException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A service that interacts with the DFE console
 */
class ClusterService implements ProvidesManagedConfig
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type int The number of minutes to keep managed instance data cached
     */
    const CACHE_TTL = ManagedDefaults::CONFIG_CACHE_TTL;

    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type string Our API signature
     */
    protected $signature = null;
    /**
     * @type string
     */
    protected $cacheKey = null;
    /**
     * @type array The cluster configuration
     */
    protected $config = [];

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * ClusterService constructor
     */
    public function __construct()
    {
        $this->boot();
    }

    /**
     * Initialization for managed instances
     *
     * @return bool
     * @throws \DreamFactory\Managed\Exceptions\ManagedInstanceException
     */
    public function boot()
    {
        if ($this->loadCachedValues()) {
            return false;
        }

        //  Discover where I am
        if (!$this->getClusterConfiguration()) {
            throw new ManagedInstanceException('Invalid cluster configuration file.');
        }

        try {
            //  Discover our secret powers...
            return $this->interrogateCluster();
        } catch (\Exception $_ex) {
            $this->reset();

            throw new ManagedInstanceException('Error interrogating console: ' . $_ex->getMessage(),
                $_ex->getCode(),
                $_ex);
        }
    }

    /** @inheritdoc */
    public function getInstanceName()
    {
        return $this->getConfig('instance-name');
    }

    /** @inheritdoc */
    public function getClusterId()
    {
        return $this->getClusterEnvironment('cluster-id');
    }

    /** @inheritdoc */
    public function getInstanceId()
    {
        return $this->getClusterEnvironment('instance-id');
    }

    /** @inheritdoc */
    public function getLogPath()
    {
        return $this->getConfig('log-path', env('DF_MANAGED_LOG_PATH', Disk::path([sys_get_temp_dir(), '.df-log'])));
    }

    /** @inheritdoc */
    public function getLogFile($name = null)
    {
        return Disk::path([
            $this->getLogPath(),
            ($name ?: 'dfe-' . $this->getHostName() . '.log'),
        ]);
    }

    /** @inheritdoc */
    public function getConfig($key = null, $default = null)
    {
        if (null === $key) {
            return $this->config;
        }

        return array_get($this->config, $key, $default);
    }

    /** @inheritdoc */
    public function getCachePath()
    {
        $_host = $this->getHostName(true);

        return Disk::path([
            $this->getCacheRoot(),
            'cluster',
            substr($_host, 0, 2),
            substr($_host, 2, 2),
            $_host,
        ]);
    }

    /** @inheritdoc */
    public function getDatabaseConfig()
    {
        return $this->getConfig('db');
    }

    /**
     * Return the Console API Key hash or null
     *
     * @return string|null
     */
    public function getConsoleApiKey()
    {
        return $this->getIdentifyingKey(true);
    }

    /**
     * Returns the storage root path
     *
     * @return string
     */
    public function getStorageRoot()
    {
        return $this->getConfig('storage-root');
    }

    /** Returns cache root */
    public function getCacheRoot()
    {
        return env('DF_MANAGED_CACHE_PATH', Disk::path([sys_get_temp_dir(), '.df-cache']));
    }

    /**
     * @return string
     */
    public function getCachePrefix()
    {
        return $this->getIdentifyingKey();
    }

    /**
     * @param string|array $key
     * @param mixed|null   $value
     *
     * @return array
     */
    protected function setConfig($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $_key => $_value) {
                array_set($this->config, $_key, $_value);
            }

            return $this->config;
        }

        return array_set($this->config, $key, $value);
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return array|mixed
     * @throws \DreamFactory\Managed\Exceptions\ManagedInstanceException
     */
    protected function getClusterConfiguration($key = null, $default = null)
    {
        $configFile = $this->locateClusterEnvironmentFile(ManagedDefaults::CLUSTER_MANIFEST_FILE);

        if (!$configFile || !file_exists($configFile)) {
            return false;
        }

        try {
            $this->config = JsonFile::decodeFile($configFile);

            //  Empty, non-array, or bogus...
            if (empty($this->config) || !is_array($this->config) || !$this->validateClusterConfig()) {
                return false;
            }
        } catch (\Exception $_ex) {
            throw new ManagedInstanceException('This instance is not configured properly for your system environment.',
                $_ex->getCode(),
                $_ex);
        }

        return null === $key ? $this->config : $this->getConfig($key, $default);
    }

    /**
     * Retrieves an instance's status and caches the shaped result
     *
     * @return array|bool
     */
    protected function interrogateCluster()
    {
        //  Generate a signature for signing payloads...
        $this->signature = $this->generateSignature();

        //  Get my config from console
        $_status = $this->callConsole('status', ['id' => $_id = $this->getInstanceName()]);

        if (!($_status instanceof \stdClass) || !data_get($_status, 'response.metadata')) {
            throw new \RuntimeException('Corrupt response during status query for "' . $_id . '".',
                Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$_status->success) {
            throw new \RuntimeException('Unmanaged instance detected.', Response::HTTP_NOT_FOUND);
        }

        if (data_get($_status, 'response.archived', false) || data_get($_status, 'response.deleted', false)) {
            throw new \RuntimeException('Instance "' . $_id . '" has been archived and/or deleted.',
                Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        //  Stuff all the unadulterated data into the config
        $_paths = (array)data_get($_status, 'response.metadata.paths', []);
        $_paths['storage-root'] = $_storageRoot = $this->getConfig('storage-root', storage_path());

        //  Clean up the paths accordingly
        $_paths['log-path'] = Disk::segment([
            array_get($_paths, 'private-path', ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME),
            ManagedDefaults::PRIVATE_LOG_PATH_NAME,
        ],
            false);

        //  Prepend real base directory to all collected paths and cache statically
        foreach (array_except($_paths, ['storage-root', 'storage-map']) as $_key => $_path) {
            $_paths[$_key] = Disk::path([$_storageRoot, $_path], true, 0777, true);
        }

        $this->setConfig([
            //  Storage root is the top-most directory under which all instance storage lives
            'storage-root'  => $_storageRoot,
            //  The storage map defines where exactly under $storageRoot the instance's storage resides
            'storage-map'   => (array)data_get($_status, 'response.metadata.storage-map', []),
            'home-links'    => (array)data_get($_status, 'response.home-links'),
            'managed-links' => (array)data_get($_status, 'response.managed-links'),
            'env'           => (array)data_get($_status, 'response.metadata.env', []),
            'audit'         => (array)data_get($_status, 'response.metadata.audit', []),
            'paths'         => (array)$_paths,
            //  Get the database config plucking the first entry if one.
            'db'            => (array)head((array)data_get($_status, 'response.metadata.db', [])),
            'limits'        => (array)data_get($_status, 'response.metadata.limits', []),
        ]);

        //  Freshen the cache...
        $this->freshenCache();

        return true;
    }

    /**
     * Validates that the required values are in $this->config from .dfe.cluster.json
     *
     * @return bool
     */
    protected function validateClusterConfig()
    {
        try {
            $_url = $this->getConfig('console-api-url');

            //  Can we build the API url
            if (empty($_url) || !$this->getConfig('console-api-key')) {
                throw new ManagedInstanceException('Invalid configuration: No "console-api-url" or "console-api-key" in cluster manifest.');
            }

            //  Ensure trailing slash on console-api-url
            $this->setConfig('console-api-url', rtrim($_url, '/ ') . '/');

            //  And default domain
            $_host = $this->getHostName();

            //  Ensure leading dot on default-domain
            if (!empty($_defaultDomain = ltrim($this->getConfig('default-domain'), '. '))) {
                $_defaultDomain = '.' . $_defaultDomain;

                //	If this isn't an enterprise instance, bail
                if (false === strpos($_host, $_defaultDomain)) {
                    throw new ManagedInstanceException('Invalid "default-domain" for host "' . $_host . '"');
                }

                $this->setConfig('default-domain', $_defaultDomain);
            }

            //  Make sure we have a storage root
            if (empty($storageRoot = $this->getConfig('storage-root'))) {
                throw new ManagedInstanceException('No "storage-root" found.');
            }

            //  Set them cleanly into our config
            $this->setConfig([
                'storage-root'  => rtrim($storageRoot, ' ' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                'instance-name' => str_replace($_defaultDomain, null, $_host),
                'host-name'     => $_host,
            ]);

            //  It's all good!
            return true;
        } catch (\Exception $_ex) {
            //  The file is bogus or not there
            return false;
        }
    }

    /**
     * @param string $uri
     * @param array  $payload
     * @param array  $curlOptions
     * @param string $method
     *
     * @return array|bool|\stdClass
     * @throws \DreamFactory\Managed\Exceptions\ManagedInstanceException
     */
    protected function callConsole($uri, $payload = [], $curlOptions = [], $method = Request::METHOD_POST)
    {
        try {
            //  Strip leading double-slash
            ('//' == substr($uri, 0, 2)) && $uri = substr($uri, 2);
            //  Allow full URIs or manufacture one...
            ('http' != substr($uri, 0, 4)) && $uri = $this->config['console-api-url'] . ltrim($uri, '/ ');

            if (false === ($_result = Curl::request($method, $uri, $this->signPayload($payload), $curlOptions))) {
                throw new \RuntimeException('Failed to contact DFE console');
            }

            if (!($_result instanceof \stdClass)) {
                if (is_string($_result) && (false === json_decode($_result) || JSON_ERROR_NONE !== json_last_error())) {
                    throw new \RuntimeException('Invalid response received from DFE console');
                }
            }

            return $_result;
        } catch (\Exception $_ex) {
            throw new ManagedInstanceException('DFE Console API Error: ' . $_ex->getMessage(), $_ex->getCode(), $_ex);
        }
    }

    /**
     * @param array $payload
     *
     * @return array
     */
    protected function signPayload(array $payload)
    {
        return array_merge([
            'client-id'    => $this->config['client-id'],
            'access-token' => $this->signature,
        ],
            $payload ?: []);
    }

    /**
     * @return string
     */
    protected function generateSignature()
    {
        return hash_hmac($this->config['signature-method'], $this->config['client-id'], $this->config['client-secret']);
    }

    /**
     * Reload the cache
     */
    protected function loadCachedValues()
    {
        $_cached = null;
        $_cachePath = Disk::path($this->getCachePath(), true, 2775);
        $_cacheFile = $_cachePath . DIRECTORY_SEPARATOR . $this->getCacheKey();

        if (file_exists($_cacheFile)) {
            $_cached = JsonFile::decodeFile($_cacheFile);
            if (isset($_cached, $_cached['.expires']) && $_cached['.expires'] < time()) {
                $_cached = null;
            }

            array_forget($_cached, '.expires');
        }

        //  Check the basics
        if (empty($_cached) || !is_array($_cached) || !$this->validateClusterConfig()) {
            return false;
        }

        //  Deeper check, these must exist otherwise we need to phone home
        if (!isset($_cached['paths'], $_cached['env'], $_cached['db'], $_cached['audit'])) {
            return false;
        }

        //  Cool, we got's what we needs's
        $this->config = $_cached;

        return true;
    }

    /**
     * Refreshes the cache with fresh values
     */
    protected function freshenCache()
    {
        $_cacheFile = Disk::path($this->getCachePath(), true, 2775) . DIRECTORY_SEPARATOR . $this->getCacheKey();
        $this->config['.expires'] = time() + (static::CACHE_TTL * 60);

        return JsonFile::encodeFile($_cacheFile, $this->config);
    }

    /**
     * Locate the configuration file for DFE, if any
     *
     * @param string $file
     *
     * @return bool|string
     */
    protected function locateClusterEnvironmentFile($file)
    {
        $_path = isset($_SERVER, $_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : getcwd();

        while (true) {
            if (file_exists($_path . DIRECTORY_SEPARATOR . $file)) {
                return $_path . DIRECTORY_SEPARATOR . $file;
            }

            $_parentPath = dirname($_path);

            if ($_parentPath == $_path || empty($_parentPath) || $_parentPath == DIRECTORY_SEPARATOR) {
                return false;
            }

            $_path = $_parentPath;
        }

        return false;
    }

    /**
     * Returns the 'env' portion of the cluster configuration
     *
     * @param string|array|null $key
     * @param mixed|null        $default
     *
     * @return array|mixed
     */
    protected function getClusterEnvironment($key = null, $default = null)
    {
        $_env = $this->getConfig('env', []);

        if (null === $key) {
            return $_env;
        }

        return array_get($_env, $key, $default);
    }

    /**
     * Gets my host name
     *
     * @param bool $hashed If true, an md5 hash of the host name will be returned
     *
     * @return string
     */
    protected function getHostName($hashed = false)
    {
        $_host =
            $this->getConfig('host-name',
                ((isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : gethostname()));

        return $hashed ? hash('sha1', $_host) : $_host;
    }

    /**
     * Returns a key prefixed for use in \Cache
     *
     * @return string
     */
    protected function getCacheKey()
    {
        return $this->cacheKey
            ?: $this->cacheKey = hash('sha1', $this->getIdentifyingKey());
    }

    /**
     * Initialize defaults for a stand-alone instance and sets the cache key
     *
     * @param string|null $storagePath A storage path to use instead of storage_path()
     */
    protected function initializeDefaults($storagePath = null)
    {
        $this->reset();
    }

    /**
     * Clears out any settings from a prior managed thing
     *
     * @return bool
     */
    protected function reset()
    {
        $this->cacheKey = $this->signature = null;
        $this->config = [];

        return false;
    }

    /**
     * Returns a string that is unique to the cluster-instance
     *
     * @param bool   $hashed    If true, value will be returned pre-hashed for your convenience
     * @param string $delimiter The delimiter between key segments
     *
     * @return null|string
     */
    protected function getIdentifyingKey($hashed = false, $delimiter = '.')
    {
        $_key = implode($delimiter, [$this->getClusterId(), $this->getHostName(),]);

        return $hashed ? hash('sha1', $_key) : $_key;
    }
}
