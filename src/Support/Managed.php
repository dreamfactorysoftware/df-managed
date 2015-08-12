<?php namespace DreamFactory\Managed\Support;

use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\IfSet;
use DreamFactory\Library\Utility\Json;
use DreamFactory\Library\Utility\JsonFile;
use DreamFactory\Managed\Enums\ManagedDefaults;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Request;

/**
 * Methods for interfacing with DreamFactory Enterprise (DFE)
 *
 * This class discovers if this instance is a DFE cluster participant. When the DFE
 * console provisions an instance, the cluster configuration file is used to determine
 * the necessary information to operate in a managed environment.
 */
final class Managed
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string Prepended to the cache keys of this object
     */
    const CACHE_KEY_PREFIX = 'df.managed.config.';
    /**
     * @type int The number of minutes to keep managed instance data cached
     */
    const CACHE_TTL = ManagedDefaults::CONFIG_CACHE_TTL;
    /** cache path key in the config */
    const CACHE_CONFIG_KEY = 'cache.stores.file.path';

    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type string Our API access token
     */
    protected static $accessToken;
    /**
     * @type string
     */
    protected static $cacheKey;
    /**
     * @type array
     */
    protected static $config = [];
    /**
     * @type bool
     */
    protected static $managed = false;
    /**
     * @type string The root storage directory
     */
    protected static $storageRoot;
    /**
     * @type array The storage paths
     */
    protected static $paths = [];

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Initialization for hosted DSPs
     *
     * @return array
     * @throws \RuntimeException
     */
    public static function initialize()
    {
        static::getCacheKey();

        if (!static::loadCachedValues()) {

            //  Discover where I am
            if (!static::getClusterConfiguration()) {
                // Set sane unmanaged defaults
                static::$paths = [
                    'storage-path'       => storage_path(),
                    'private-path'       => storage_path() . '/.private',
                    'owner-private-path' => storage_path() . '/.owner'
                ];
                logger('Unmanaged instance, ignoring.');
                return false;
            }

            //  Discover our secret powers...
            try {
                static::interrogateCluster();
            } catch (\RuntimeException $e) {
                logger('cluster unreachable or in disarray.');

                return false;
            }
        }

        logger('managed instance bootstrap complete.');

        return static::$managed = true;
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return array|mixed
     */
    protected static function getClusterConfiguration($key = null, $default = null)
    {
        $configFile = static::locateClusterEnvironmentFile(ManagedDefaults::CLUSTER_MANIFEST_FILE);

        if (!$configFile || !file_exists($configFile)) {
            return false;
        }

        try {
            static::$config = JsonFile::decodeFile($configFile);

            logger('cluster config read: ' . json_encode(static::$config));

            //  Cluster validation determines if an instance is managed or not
            if (!static::validateConfiguration()) {
                return false;
            }
        } catch (\Exception $_ex) {
            static::$config = [];

            logger('Cluster configuration file is not in a recognizable format.');

            throw new \RuntimeException('This instance is not configured properly for your system environment.');
        }

        return null === $key ? static::$config : static::getConfig($key, $default);
    }

    /**
     * Retrieves an instance's status and caches the shaped result
     *
     * @return array|bool
     */
    protected static function interrogateCluster()
    {
        //  Generate a signature for signing payloads...
        static::$accessToken = static::generateSignature();

        //  Get my config from console
        $_status = static::callConsole('status', ['id' => $_id = static::getInstanceName()]);

        logger('ops/status response: ' . (Json::encode($_status) ?: print_r($_status, true)));

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
        $_paths['storage-root'] = static::$storageRoot = static::getConfig('storage-root', storage_path());

        static::setConfig([
            //  Storage root is the top-most directory under which all instance storage lives
            'storage-root'  => static::$storageRoot,
            //  The storage map defines where exactly under $storageRoot the instance's storage resides
            'storage-map'   => (array)data_get($_status, 'response.metadata.storage-map', []),
            'home-links'    => (array)data_get($_status, 'response.home-links'),
            'managed-links' => (array)data_get($_status, 'response.managed-links'),
            'env'           => (array)data_get($_status, 'response.metadata.env', []),
            'audit'         => (array)data_get($_status, 'response.metadata.audit', []),
        ]);

        //  Clean up the paths accordingly
        $_paths['log-path'] =
            Disk::segment([array_get($_paths, 'private-path', ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME), ManagedDefaults::PRIVATE_LOG_PATH_NAME],
                false);

        //  prepend real base directory to all collected paths and cache statically
        foreach (array_except($_paths, ['storage-root', 'storage-map']) as $_key => $_path) {
            $_paths[$_key] = Disk::path([static::$storageRoot, $_path], true, 0777, true);
        }

        //  Now place our paths into the config
        static::setConfig('paths', (array)$_paths);

        //  Get the database config plucking the first entry if one.
        static::setConfig('db', (array)head((array)data_get($_status, 'response.metadata.db', [])));

        if (!empty($_limits = (array)data_get($_status, 'response.metadata.limits', []))) {
            static::setConfig('limits', $_limits);
        }

        static::freshenCache();

        return true;
    }

    /**
     * Validates that the required values are in static::$config
     *
     * @return bool
     */
    protected static function validateConfiguration()
    {
        try {
            //  Can we build the API url
            if (!isset(static::$config['console-api-url'], static::$config['console-api-key'])) {
                logger('Invalid configuration: No "console-api-url" or "console-api-key" in cluster manifest.');

                return false;
            }

            //  Make it ready for action...
            static::setConfig('console-api-url', rtrim(static::getConfig('console-api-url'), '/') . '/');

            //  And default domain
            $_host = static::getHostName();

            if (!empty($_defaultDomain = ltrim(static::getConfig('default-domain'), '. '))) {
                $_defaultDomain = '.' . $_defaultDomain;

                //	If this isn't an enterprise instance, bail
                if (false === strpos($_host, $_defaultDomain)) {
                    logger('Invalid "default-domain" for host "' . $_host . '"');

                    return false;
                }

                static::setConfig('default-domain', $_defaultDomain);
            }

            if (empty($storageRoot = static::getConfig('storage-root'))) {
                logger('No "storage-root" found.');

                return false;
            }

            static::setConfig([
                'storage-root'  => rtrim($storageRoot, ' ' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                'instance-name' => str_replace($_defaultDomain, null, $_host),
            ]);

            //  It's all good!
            return true;
        } catch (\InvalidArgumentException $_ex) {
            //  The file is bogus or not there
            return false;
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
    protected static function callConsole($uri, $payload = [], $curlOptions = [], $method = Request::METHOD_POST)
    {
        try {
            //  Allow full URIs or manufacture one...
            if ('http' != substr($uri, 0, 4)) {
                $uri = static::$config['console-api-url'] . ltrim($uri, '/ ');
            }

            if (false === ($_result = Curl::request($method, $uri, static::signPayload($payload), $curlOptions))) {
                throw new \RuntimeException('Failed to contact API server.');
            }

            if (!($_result instanceof \stdClass)) {
                if (is_string($_result) && (false === json_decode($_result) || JSON_ERROR_NONE !== json_last_error())) {
                    throw new \RuntimeException('Invalid response received from DFE console.');
                }
            }

            return $_result;
        } catch (\Exception $_ex) {
            logger('api error: ' . $_ex->getMessage());

            return false;
        }
    }

    /**
     * @param array $payload
     *
     * @return array
     */
    protected static function signPayload(array $payload)
    {
        return array_merge([
            'client-id'    => static::$config['client-id'],
            'access-token' => static::$accessToken,
        ],
            $payload ?: []);
    }

    /**
     * @return string
     */
    protected static function generateSignature()
    {
        return hash_hmac(static::$config['signature-method'],
            static::$config['client-id'],
            static::$config['client-secret']);
    }

    /**
     * @return boolean
     */
    public static function isManagedInstance()
    {
        return static::$managed;
    }

    /**
     * @return string
     */
    public static function getInstanceName()
    {
        return static::getConfig('instance-name');
    }

    /**
     * @param string|null $append Optional path to append
     *
     * @return string
     */
    public static function getStoragePath($append = null)
    {
        return Disk::segment([array_get(static::$paths, 'storage-path'), $append]);
    }

    /**
     * @param string|null $append Optional path to append
     *
     * @return string
     */
    public static function getPrivatePath($append = null)
    {
        return Disk::segment([array_get(static::$paths, 'private-path'), $append]);
    }

    /**
     * @return string Absolute /path/to/logs
     */
    public static function getLogPath()
    {
        return Disk::path([static::getPrivatePath(), ManagedDefaults::PRIVATE_LOG_PATH_NAME], true, 2775);
    }

    /**
     * @param string|null $name
     *
     * @return string The absolute /path/to/log/file
     */
    public static function getLogFile($name = null)
    {
        return Disk::path([static::getLogPath(), ($name ?: static::getInstanceName() . '.log')]);
    }

    /**
     * @param string|null $append Optional path to append
     *
     * @return string
     */
    public static function getOwnerPrivatePath($append = null)
    {
        return Disk::segment([array_get(static::$paths, 'owner-private-path'), $append]);
    }

    /**
     * Retrieve a config value or the entire array
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return array|mixed
     */
    public static function getConfig($key = null, $default = null)
    {
        if (null === $key) {
            return static::$config;
        }

        $_value = array_get(static::$config, $key, $default);

        //  Add value to array if defaulted
        $_value === $default && static::setConfig($key, $_value);

        return $_value;
    }

    /**
     * Retrieve a config value or the entire array
     *
     * @param string|array $key A single key to set or an array of KV pairs to set at once
     * @param mixed        $value
     *
     * @return array|mixed
     */
    protected static function setConfig($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $_key => $_value) {
                array_set(static::$config, $_key, $_value);
            }

            return static::$config;
        }

        return array_set(static::$config, $key, $value);
    }

    /**
     * Reload the cache
     */
    protected static function loadCachedValues()
    {
        //@todo does a successful Cache::get extend TTL? Need to find out.

        // Need to set the cache path before every cache operation to make sure the cache does not get
        // shared between instances
        config([static::CACHE_CONFIG_KEY => static::getCachePath()]);
        logger('Cache Path set to ' . static::getCachePath());

        /** @noinspection PhpUndefinedMethodInspection */
        $_cache = Cache::get(static::$cacheKey);

        if (!empty($_cache) && is_array($_cache)) {
            static::$config = $_cache;
            static::$paths = static::getConfig('paths');

            return static::validateConfiguration();
        }

        return false;
    }

    /**
     * Refreshes the cache with fresh values
     */
    protected static function freshenCache()
    {
        // Need to set the cache path before every cache operation to make sure the cache does not get
        // shared between instances
        config([static::CACHE_CONFIG_KEY => static::getCachePath()]);
        logger('Cache Path set to ' . static::getCachePath());

        /** @noinspection PhpUndefinedMethodInspection */
        Cache::put(static::getCacheKey(), static::$config, static::CACHE_TTL);
        static::$paths = static::getConfig('paths', []);
    }

    /**
     * Locate the configuration file for DFE, if any
     *
     * @param string $file
     *
     * @return bool|string
     */
    protected static function locateClusterEnvironmentFile($file)
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
     * Gets my host name
     *
     * @return string
     */
    protected static function getHostName()
    {
        return static::getConfig('managed.host-name', app('request')->server->get('HTTP_HOST', gethostname()));
    }

    /**
     * Returns a key prefixed for use in \Cache
     *
     * @return string
     */
    protected static function getCacheKey()
    {
        return static::$cacheKey = static::$cacheKey ?: static::CACHE_KEY_PREFIX . static::getHostName();
    }

    /**
     * Return a database configuration as specified by the console if managed, or config() otherwise.
     *
     * @return array
     */
    public static function getDatabaseConfig()
    {
        return static::isManagedInstance() ? static::getConfig('db')
            : config('database.connections.' . config('database.default'), []);
    }

    /**
     * Return the limits for this instance or an empty array if none.
     *
     * @param string|null $limitKey A key within the limits to retrieve. If omitted, all limits are returned
     * @param array       $default  The default value to return if $limitKey was not found
     *
     * @return array|mixed
     */
    public static function getLimits($limitKey = null, $default = [])
    {
        return null === $limitKey
            ? static::getConfig('limits', [])
            : array_get(static::getConfig('limits', []),
                $limitKey,
                $default);
    }

    /**
     * Return the Console API Key hash or null
     *
     * @return string|null
     */
    public static function getConsoleKey()
    {
        return static::isManagedInstance() ? hash(ManagedDefaults::DEFAULT_SIGNATURE_METHOD,
            IfSet::getDeep(static::$config, 'env', 'cluster-id') . IfSet::getDeep(static::$config,
                'env',
                'instance-id')) : null;
    }

    /**
     * Returns the storage root path
     *
     * @return string
     */
    public static function getStorageRoot()
    {
        if (!static::$config) {
            static::initialize();
        }

        return static::$storageRoot;
    }

    /** Returns cache path qualified by hostname */
    public static function getCachePath()
    {
        $hostname = md5(((isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : gethostname()));

        return sys_get_temp_dir() . "/.df/" . $hostname;

    }
}
