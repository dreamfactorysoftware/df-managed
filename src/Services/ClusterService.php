<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Disk;
use DreamFactory\Library\Utility\JsonFile;
use DreamFactory\Managed\Contracts\ProvidesManagedConfig;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Exceptions\ManagedInstanceException;
use DreamFactory\Managed\Support\ClusterManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A service that interacts with the DFE console
 *
 * NOTE: Environment variables take precedence to cluster manifest in some instances (i.e. getLogPath())
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

        //  Get the manifest
        $_manifest = new ClusterManifest($this);

        //  Seed our config with the manifest
        $this->config = $_manifest->toArray();

        try {
            //  Now let's discover our secret powers...
            return $this->interrogateCluster();
        } catch (\Exception $_ex) {
            $this->reset();

            throw new ManagedInstanceException('Error interrogating console: ' . $_ex->getMessage(),
                $_ex->getCode(),
                $_ex);
        }
    }

    /**
     * Reload the cache
     */
    protected function loadCachedValues()
    {
        $_cached = null;
        $_cacheFile = Disk::path([$this->getCachePath(true), $this->getCacheKey()]);

        if (file_exists($_cacheFile)) {
            $_cached = JsonFile::decodeFile($_cacheFile);

            if (isset($_cached, $_cached['.expires']) && $_cached['.expires'] < time()) {
                $_cached = null;
            }

            array_forget($_cached, '.expires');
        }

        //  These must exist otherwise we need to phone home
        if (empty($_cached) ||
            !is_array($_cached) ||
            !isset($_cached['paths'], $_cached['env'], $_cached['db'], $_cached['audit'])
        ) {
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
        $_cacheFile = Disk::path($this->getCachePath(), true, 0775) . DIRECTORY_SEPARATOR . $this->getCacheKey();
        $this->config['.expires'] = time() + (static::CACHE_TTL * 60);

        return JsonFile::encodeFile($_cacheFile, $this->config);
    }

    /**
     * Retrieves an instance's status and caches the shaped result
     *
     * @return array|bool
     * @throws \DreamFactory\Managed\Exceptions\ManagedInstanceException
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

        //  Validate domain of this host
        $_host = $this->getHostName();

        //  Ensure leading dot on default-domain
        $_defaultDomain = $this->getConfig('default-domain');

        //	If this isn't an enterprise instance, bail
        if (false === strpos($_host, $_defaultDomain)) {
            throw new ManagedInstanceException('Invalid "default-domain" for host "' . $_host . '"');
        }

        //  Stuff all the unadulterated data into the config
        $_paths = (array)data_get($_status, 'response.metadata.paths', []);
        $_paths['storage-root'] = $_storageRoot = $this->getConfig('storage-root');

        //  Clean up the paths accordingly
        $_paths['log-path'] = env('DF_MANAGED_LOG_PATH',
            Disk::segment([
                $_storageRoot,
                array_get($_paths, 'private-path', ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME),
                ManagedDefaults::PRIVATE_LOG_PATH_NAME,
            ],
                false));

        $_paths['private-log-path'] = Disk::segment([
            array_get($_paths, 'private-path', ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME),
            ManagedDefaults::PRIVATE_LOG_PATH_NAME,
        ],
            false);

        //  Prepend real base directory to all collected paths and cache statically
        foreach (array_except($_paths, ['log-path', 'trash-path', 'storage-root', 'storage-map']) as $_key => $_path) {
            $_paths[$_key] = Disk::path([$_storageRoot, $_path], true, 0777, true);
        }

        $this->setConfig([
            //  Storage root is the top-most directory under which all instance storage lives
            'storage-root'  => $_storageRoot,
            //  The storage map defines where exactly under $storageRoot the instance's storage resides
            'storage-map'   => (array)data_get($_status, 'response.metadata.storage-map', []),
            'home-links'    => (array)data_get($_status, 'response.home-links'),
            'host-name'     => $_host,
            'instance-name' => str_replace($_defaultDomain, null, $_host),
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
    public function getHostName($hashed = false)
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
        return env('DF_MANAGED_LOG_PATH', $this->getConfig('log-path', Disk::path([sys_get_temp_dir(), '.df-log'])));
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

    /**
     * @param string|array $key
     * @param mixed|null   $value
     *
     * @return array
     */
    public function setConfig($key, $value = null)
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
     * @param bool $create    If true, path will be created
     * @param int  $mode      The mode if creating
     * @param bool $recursive Create recursively?
     *
     * @return string A path (un-ensured!) which is unique to the instance-level
     */
    public function getCachePath($create = false, $mode = 0777, $recursive = true)
    {
        $_host = $this->getHostName(true);

        // With a host hash of "0123456789", make a path like "/cache/root/01/23/0123456789/"
        return Disk::path([
            $this->getCacheRoot(),
            substr($_host, 0, 2),
            substr($_host, 2, 2),
            $_host,
        ],
            $create,
            $mode,
            $recursive);
    }

    /** @inheritdoc */
    public function getDatabaseConfig()
    {
        return $this->getConfig('db');
    }

    /**
     * @return string
     */
    public function getConsoleKey()
    {
        return hash(ManagedDefaults::DEFAULT_ALGORITHM, $this->getClusterId() . $this->getInstanceId());
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
        return env('DF_MANAGED_CACHE_PATH',
            $this->getConfig('cache-root', Disk::path([sys_get_temp_dir(), '.df-cache', '.cluster'])));
    }

    /**
     * @return string
     */
    public function getCachePrefix()
    {
        return $this->getIdentifyingKey();
    }

    /**
     * Return the limits for this instance or an empty array if none.
     *
     * @param string|null $key     A key within the limits to retrieve. If omitted, all limits are returned
     * @param array       $default The default value to return if $key was not found
     *
     * @return array|null
     */
    public function getLimits($key = null, $default = [])
    {
        return $this->getConfig((null === $key) ? 'limits' : 'limits.' . $key, $default);
    }
}
