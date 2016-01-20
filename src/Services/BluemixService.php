<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Library\Utility\Json;
use DreamFactory\Managed\Contracts\ProvidesManagedDatabase;
use DreamFactory\Managed\Enums\ManagedDefaults;
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
    public function getDatabaseConfig($service = BlueMixDefaults::BM_DB_SERVICE_KEY, $index = BlueMixDefaults::BM_DB_INDEX, $subkey = BlueMixDefaults::BM_DB_CREDS_KEY)
    {
        //  Decode and examine
        try {
            /** @type string $_envData */
            $_envData = getenv(BlueMixDefaults::BM_ENV_KEY);

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

                return $_db;
            }
        } catch (\InvalidArgumentException $_ex) {
            //  Environment not set correctly for this deployment
        }

        //  Database configuration not found for bluemix
        throw new ManagedEnvironmentException('Bluemix platform detected but no database services are available.');
    }
}