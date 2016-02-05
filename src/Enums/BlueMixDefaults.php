<?php namespace DreamFactory\Managed\Enums;

use DreamFactory\Library\Utility\Enums\FactoryEnum;

/**
 * Constants for BlueMix instances
 */

class BlueMixDefaults extends FactoryEnum
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The environment variable that holds the Bluemix service configurations
     */
    const BM_ENV_KEY = 'VCAP_SERVICES';
    /**
     * @type string The name of the key containing the database configuration
     */
    const BM_DB_SERVICE_KEY = 'user-provided';
    /**
     * @type int The index of the database config to use
     */
    const BM_DB_INDEX = 0;
    /**
     * @type string The name of the key containing the credentials
     */
    const BM_CREDS_KEY = 'credentials';
    /**
     * @type string The name of the key containing the redis configuration
     */
    const BM_REDIS_SERVICE_KEY = 'rediscloud';
    /**
     * @type int The index of the redis config to use
     */
    const BM_REDIS_INDEX = 0;
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
}