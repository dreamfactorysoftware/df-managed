<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Disk;
use DreamFactory\Library\Utility\JsonFile;
use DreamFactory\Managed\Contracts\ProvidesManagedConfig;
use DreamFactory\Managed\Contracts\ProvidesManagedStorage;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Facades\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A service that returns various configuration data that are common across managed
 * and unmanaged instances. See the VirtualConfigProvider contract
 */
class ManagedService implements ProvidesManagedConfig, ProvidesManagedStorage
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
     * @type array
     */
    protected $config = [];
    /**
     * @type string The host name of this managed instance
     */
    protected $hostName = null;
    /**
     * @type bool The instance is managed
     */
    protected $managed = false;
    /**
     * @type array The storage paths
     */
    protected $paths = [];
    /**
     * @type string
     */
    protected $privatePathName;
    /**
     * @type string The root storage directory
     */
    protected $storageRoot = null;
    /**
     * @type bool If true, log files will be written to the temp space
     */
    protected $logToTemp = true;

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Initialization for hosted DSPs
     *
     * @return array
     * @throws \RuntimeException
     */
    public function boot()
    {
        $this->initializeDefaults();

        if ($this->loadCachedValues()) {
            return true;
        }

        //  Discover where I am
        if (!$this->getClusterConfiguration()) {
            //  Unmanaged instance, ignoring
            return $this->reset();
        }

        try {
            //  Discover our secret powers...
            return $this->interrogateCluster();
        } catch (\RuntimeException $_ex) {
            /** @noinspection PhpUndefinedMethodInspection */
            Log::error('Error interrogating console: ' . $_ex->getMessage());

            return $this->reset();
        }
    }

    /**
     * @param \Illuminate\Http\Request $request     The original request
     * @param array|null               $sessionData Optional session data
     */
    public function auditRequest(Request $request, $sessionData = null)
    {
        $this->isManaged() && Audit::auditRequest($this, $request, $sessionData);
    }

    /** @inheritdoc */
    public function getInstanceName()
    {
        return $this->getConfig('instance-name');
    }

    /** @inheritdoc */
    public function getClusterId()
    {
        return $this->isManaged() ? $this->getClusterEnvironment('cluster-id') : null;
    }

    /** @inheritdoc */
    public function getInstanceId()
    {
        return $this->isManaged() ? $this->getClusterEnvironment('instance-id') : null;
    }

    /** @inheritdoc */
    public function getClusterName()
    {
        return $this->getClusterId();
    }

    /** @inheritdoc */
    public function getLogPath()
    {
        return $this->logToTemp
            ? Disk::path([sys_get_temp_dir(), '.df-log'])
            : Disk::path([
                array_get($this->paths,
                    'log-path'),
            ],
                true);
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
    public function getStoragePath($append = null)
    {
        return Disk::path([array_get($this->paths, 'storage-path'), $append], true);
    }

    /** @inheritdoc */
    public function getPrivatePath($append = null)
    {
        return Disk::path([array_get($this->paths, 'private-path'), $append], true);
    }

    /** @inheritdoc */
    public function getOwnerPrivatePath($append = null)
    {
        return Disk::path([array_get($this->paths, 'owner-private-path'), $append], true);
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
        return Disk::path([$this->getCacheRoot(), $this->getHostName(true)]);
    }

    /** @inheritdoc */
    public function getDatabaseConfig()
    {
        return $this->isManaged()
            ? $this->getConfig('db')
            : config('database.connections.' . config('database.default'),
                []);
    }

    /**
     * Return the limits for this instance or an empty array if none.
     *
     * @param string|null $limitKey A key within the limits to retrieve. If omitted, all limits are returned
     * @param array       $default  The default value to return if $limitKey was not found
     *
     * @return array|mixed
     */
    public function getLimits($limitKey = null, $default = [])
    {
        $_limits = $this->getConfig('limits', []);

        return null === $limitKey ? $_limits : array_get($_limits, $limitKey, $default);
    }

    /**
     * Return the Console API Key hash or null
     *
     * @return string|null
     */
    public function getConsoleApiKey()
    {
        return $this->isManaged() ? $this->getIdentifyingKey(true) : null;
    }

    /**
     * Returns the storage root path
     *
     * @return string
     */
    public function getStorageRoot()
    {
        return $this->storageRoot;
    }

    /** Returns cache root */
    public function getCacheRoot()
    {
        return Disk::path([sys_get_temp_dir(), '.df-cache']);
    }

    /** @inheritdoc */
    public function getSnapshotPath()
    {
        return $this->getOwnerPrivatePath(config('df.snapshot-path-name', ManagedDefaults::SNAPSHOT_PATH_NAME));
    }

    /** @inheritdoc */
    public function getPrivatePathName()
    {
        return $this->privatePathName;
    }

    /** @inheritdoc */
    public function isManaged()
    {
        empty($this->hostName) && $this->boot();

        return $this->managed;
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
            $this->reset();
            throw new \RuntimeException('This instance is not configured properly for your system environment.');
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
        $_paths['storage-root'] = $this->storageRoot = $this->getConfig('storage-root', storage_path());

        //  Clean up the paths accordingly
        $_paths['log-path'] = Disk::segment([
            array_get($_paths, 'private-path', ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME),
            ManagedDefaults::PRIVATE_LOG_PATH_NAME,
        ],
            false);

        //  Prepend real base directory to all collected paths and cache statically
        foreach (array_except($_paths, ['storage-root', 'storage-map']) as $_key => $_path) {
            $_paths[$_key] = Disk::path([$this->storageRoot, $_path], true, 0777, true);
        }

        $this->setConfig([
            //  Storage root is the top-most directory under which all instance storage lives
            'storage-root'  => $this->storageRoot,
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

        //  Set up auditing...
        $this->initializeAuditing();

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
                /** @noinspection PhpUndefinedMethodInspection */
                Log::error('Invalid configuration: No "console-api-url" or "console-api-key" in cluster manifest.');

                return false;
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
                    /** @noinspection PhpUndefinedMethodInspection */
                    Log::error('Invalid "default-domain" for host "' . $_host . '"');

                    return false;
                }

                $this->setConfig('default-domain', $_defaultDomain);
            }

            //  Make sure we have a storage root
            if (empty($storageRoot = $this->getConfig('storage-root'))) {
                /** @noinspection PhpUndefinedMethodInspection */
                Log::error('No "storage-root" found.');

                return false;
            }

            //  Set them cleanly into our config
            $this->setConfig([
                'storage-root'  => rtrim($storageRoot, ' ' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                'instance-name' => str_replace($_defaultDomain, null, $_host),
            ]);

            //  It's all good!
            return true;
        } catch (\Exception $_ex) {
            //  The file is bogus or not there
            return false;
        }
    }

    /**
     * Initialize the auditing service from the config if available
     */
    protected function initializeAuditing()
    {
        $_env = $this->getClusterEnvironment();

        if (!empty($_env) && isset($_env['audit-host'], $_env['audit-port'])) {
            Audit::setHost($_env['audit-host']);
            Audit::setPort($_env['audit-port']);

            //  Set metadata (if available) only when auditing...
            !empty($_audit = $this->getConfig('audit')) && Audit::setMetadata($_audit);
        }
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $payload
     * @param array  $curlOptions
     *
     * @return bool|\stdClass|array
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
            /** @noinspection PhpUndefinedMethodInspection */
            Log::error('DFE Console API Error: ' . $_ex->getMessage());

            return false;
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
        /** @noinspection PhpUndefinedMethodInspection */
        /** @type array $_cached */
        $_cached = Cache::get($this->getCacheKey());

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
        $this->paths = $this->getConfig('paths');
        $this->hostName = $this->getConfig('managed.host-name');

        //  Initialize auditing
        $this->initializeAuditing();

        return true;
    }

    /**
     * Refreshes the cache with fresh values
     */
    protected function freshenCache()
    {
        //  Put host name in config
        $this->hostName && $this->setConfig('managed.host-name', $this->hostName);

        /** @noinspection PhpUndefinedMethodInspection */
        Cache::put($this->getCacheKey(), $this->config, static::CACHE_TTL);
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
        $_host = $this->hostName
            ?: $this->hostName = $this->getConfig('managed.host-name',
                ((isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : gethostname()));

        return $hashed ? hash(ManagedDefaults::DEFAULT_ALGORITHM, $_host) : $_host;
    }

    /**
     * Returns a key prefixed for use in \Cache
     *
     * @return string
     */
    protected function getCacheKey()
    {
        return $this->cacheKey
            ?: $this->cacheKey = hash(ManagedDefaults::DEFAULT_ALGORITHM,
                $this->getClusterId() . $this->getInstanceId() . $this->getHostName(true));
    }

    /**
     * Initialize defaults for a stand-alone instance and sets the cache key
     *
     * @param string|null $storagePath A storage path to use instead of storage_path()
     */
    protected function initializeDefaults($storagePath = null)
    {
        $this->reset();
        $this->getCacheKey();

        $this->privatePathName =
            Disk::segment(config('df.private-path-name', ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME));

        $_storagePath = Disk::path([
            $storagePath ?: storage_path(),
        ]);

        $this->paths = [
            'storage-root'       => $_storagePath,
            'storage-path'       => $_storagePath,
            'private-path'       => Disk::path([$_storagePath, $this->privatePathName]),
            'owner-private-path' => Disk::path([$_storagePath, $this->privatePathName]),
            'log-path'           => Disk::path([$_storagePath, ManagedDefaults::PRIVATE_LOG_PATH_NAME]),
            'snapshot-path'      => Disk::path([
                $_storagePath,
                $this->privatePathName,
                ManagedDefaults::SNAPSHOT_PATH_NAME,
            ]),
        ];
    }

    /**
     * Clears out any settings from a prior managed thing
     *
     * @return bool
     */
    protected function reset()
    {
        $this->config = $this->paths = [];
        $this->cacheKey = $this->signature = $this->hostName = $this->storageRoot = null;

        return $this->managed = false;
    }

    /**
     * Returns a string that is unique to the cluster-instance
     *
     * @param bool $hashed If true, value will be returned pre-hashed for your convenience
     *
     * @return null|string
     */
    protected function getIdentifyingKey($hashed = false)
    {
        $_key = hash(ManagedDefaults::DEFAULT_ALGORITHM, $this->getIdentifyingKey());

        return $hashed ? hash(ManagedDefaults::DEFAULT_ALGORITHM, $_key) : $_key;
    }
}
