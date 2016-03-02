<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Library\Utility\Json;
use DreamFactory\Managed\Contracts\ProvidesManagedDatabase;
use DreamFactory\Managed\Enums\BlueMixDefaults;
use DreamFactory\Managed\Exceptions\ManagedEnvironmentException;

/**
 * A service that interacts with bluemix
 *
 * NOTE: Environment variables take precedence to cluster manifest in some instances (i.e. getLogPath())
 */
class BluemixService extends BaseService implements ProvidesManagedDatabase
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type array The cluster configuration
     */
    protected $config = [];

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @param string          $service The service to retrieve
     * @param int             $index   Which index to return if multiple. NULL returns all
     * @param string|int|null $subkey  Subkey under index to use instead of "credentials"
     *
     * @return array|bool
     * @throws \DreamFactory\Managed\Exceptions\ManagedEnvironmentException
     */
    public function getDatabaseConfig(
        $service = BlueMixDefaults::BM_DB_SERVICE_KEY,
        $index = BlueMixDefaults::BM_DB_INDEX,
        $subkey = BlueMixDefaults::BM_CREDS_KEY
    ) {
        //  Decode and examine
        try {

            if (!empty( $_config = $this->getServiceConfig($service, $index, $subkey) )) {

                return [
                    'driver'    => array_get($_config, 'driver', 'pgsql'),
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
            }
        } catch ( \InvalidArgumentException $_ex ) {
            //  Environment not set correctly for this deployment
        }

        //  Database configuration not found for bluemix
        return [];
    }

    /**
     * @param string          $service The service to retrieve
     * @param int             $index   Which index to return if multiple. NULL returns all
     * @param string|int|null $subkey  Subkey under index to use instead of "credentials"
     *
     * @return array|bool
     * @throws \DreamFactory\Managed\Exceptions\ManagedEnvironmentException
     */
    public function getRedisConfig(
        $service = BlueMixDefaults::BM_REDIS_SERVICE_KEY,
        $index = BlueMixDefaults::BM_REDIS_INDEX,
        $subkey = BlueMixDefaults::BM_CREDS_KEY
    ) {
        //  Decode and examine
        try {

            if (!empty( $_config = $this->getServiceConfig($service, $index, $subkey) )) {

                return [
                    //  Check for 'host', then 'hostname', default to '127.0.0.1'
                    'host'     => array_get($_config, 'host', array_get($_config, 'hostname', '127.0.0.1')),
                    'database' => 0,
                    'password' => $_config['password'],
                    'port'     => $_config['port'],
                ];
            }
        } catch ( \InvalidArgumentException $_ex ) {
            //  Environment not set correctly for this deployment
        }

        //  Database configuration not found for bluemix
        return [];
    }

    /**
     * @param string          $service The service to retrieve
     * @param int             $index   Which index to return if multiple. NULL returns all
     * @param string|int|null $subkey  Subkey under index to use instead of "credentials"
     *
     * @return array|bool
     * @throws \DreamFactory\Managed\Exceptions\ManagedEnvironmentException
     */
    protected function getServiceConfig($service, $index, $subkey)
    {
        //  Decode and examine
        try {
            /** @type string $_envData */
            $_envData = getenv(BlueMixDefaults::BM_ENV_KEY);

            if (!empty( $_availableServices = Json::decode($_envData, true) )) {

                if (!empty( $_serviceSet = array_get($_availableServices, $service, []) )) {

                    //  Get credentials environment data
                    $_config = array_get(isset( $_serviceSet[$index] ) ? $_serviceSet[$index] : [], $subkey, []);

                    if (empty( $_config )) {
                        return [];
                    }

                    unset( $_envData, $_serviceSet );

                    if (env('BM_USE_URI', false) == true) {
                        return $this->getServiceConfigFromUri($_config['uri']);
                    }

                    return $_config;
                }
            }
        } catch ( \InvalidArgumentException $_ex ) {
            //  Environment not set correctly for this deployment
        }

        //  Database configuration not found for bluemix
        return [];
    }

    protected function getServiceConfigFromUri($uri)
    {
        // Strip any params that might be at the end of the URI string
        if ($parmPos = strrpos($uri, '?')) {
            $uri = substr($uri, 0, $parmPos);
        }

        // Get the driver type
        list( $driver, $remainder ) = explode('://', $uri);

        // Get the login credentials
        list( $userAndPassword, $remainder ) = explode('@', $remainder);
        list( $userName, $password ) = explode(':', $userAndPassword);

        // Get the hostname, port and dbname
        list( $hostAndPort, $name ) = explode('/', $remainder);
        list( $hostname, $port ) = explode(':', $hostAndPort);

        return [
            'driver'   => $driver,
            'name'     => $name,
            'hostname' => $hostname,
            'port'     => $port,
            'username' => $userName,
            'password' => $password
        ];
    }
}
