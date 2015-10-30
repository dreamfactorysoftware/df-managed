<?php namespace DreamFactory\Managed\Support;

use DreamFactory\Library\Utility\Json;
use DreamFactory\Managed\Enums\ManagedDefaults;
use Illuminate\Support\Facades\Cache;

/** Methods for interfacing with the Bluemix environment */
final class Bluemix
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @type string The environment variable that holds the credentials for the database
     */
    const BM_ENV_KEY = 'VCAP_SERVICES';
    /**
     * @type string The name of the key containing the database
     */
    const BM_DB_SERVICE_KEY = 'mysql-5.5';
    /**
     * @type int The index of the database to use
     */
    const BM_DB_INDEX = 0;
    /**
     * @type string The name of the key containing the credentials
     */
    const BM_DB_CREDS_KEY = 'credentials';
    /**
     * @type string Cache key in the config
     */
    const CACHE_CONFIG_KEY = 'cache.stores.file.path';
    /**
     * @type string Prepended to the cache keys of this object
     */
    const CACHE_KEY_PREFIX = 'df.bluemix.database.';
    /**
     * @type int The number of minutes to keep managed instance data cached
     */
    const CACHE_TTL = ManagedDefaults::CONFIG_CACHE_TTL;

    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type string Our cache key
     */
    protected static $cacheKey;
    /**
     * @type array
     */
    protected static $dbConfig;

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * @param string          $service The service to retrieve
     * @param int             $index   Which index to return if multiple. NULL returns all
     * @param string|int|null $subkey  Subkey under index to use instead of "credentials"
     *
     * @return array|bool
     */
    public static function getDatabaseConfig($service = self::BM_DB_SERVICE_KEY, $index = self::BM_DB_INDEX, $subkey = self::BM_DB_CREDS_KEY)
    {
        static::$cacheKey = static::CACHE_KEY_PREFIX . gethostname();

        //  Return the cached version if we have it
        /** @noinspection PhpUndefinedMethodInspection */
        if (null !== ($_db = Cache::get(static::$cacheKey))) {
            return $_db;
        }

        //  Decode and examine
        try {
            /** @type string $_envData */
            $_envData = getenv(ENV_KEY);

            if (!empty($_availableServices = Json::decode($_envData, true))) {
                $_serviceSet = array_get($_availableServices, $service);

                //  Get credentials environment data
                $_config = array_get(isset($_serviceSet[$index]) ? $_serviceSet[$index] : [], $subkey, []);

                if (empty($_config)) {
                    throw new \RuntimeException('DB credentials not found in env: ' . print_r($_serviceSet, true));
                }

                $_db = [
                    'driver'    => 'mysql',
                    //  Check for 'host', then 'hostname', default to 'localhost'
                    'host'      => array_get($_config, 'host', array_get($_config, 'hostname', 'localhost')),
                    'database'  => $_config['name'],
                    'username'  => $_config['username'],
                    'password'  => $_config['password'],
                    'port'      => $_config['port'],
                    'charset'   => 'utf8',
                    'collation' => 'utf8_unicode_ci',
                    'prefix'    => '',
                    'strict'    => false,
                ];

                unset($_envData, $_config, $_serviceSet);

                /** @noinspection PhpUndefinedMethodInspection */
                Cache::put(static::$cacheKey, $_db, static::CACHE_TTL);

                return $_db;
            }
        } catch (\InvalidArgumentException $_ex) {
            //  Environment not set correctly for this deployment
        }

        //  Database configuration not found for bluemix
        return config('database.connections.' . config('database.default'), []);
    }

}
