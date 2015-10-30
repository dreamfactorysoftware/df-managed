<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Managed\Support\Bluemix;
use Illuminate\Support\ServiceProvider;

/**
 * Register the virtual db config manager service as a Laravel provider
 */
class BluemixServiceProvider extends ServiceProvider
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'bluemix.database.config';
    /**
     * @type string The key in database config that holds all connections.
     */
    const DATABASE_ALL_CONNECTIONS_KEY = 'database.connections';
    /**
     * @type string The key in database config that specifies default connection.
     */
    const DATABASE_DEFAULT_CONNECTION_KEY = 'database.default';

    //********************************************************************************
    //* Methods
    //********************************************************************************

    /** Start up the dependency */
    public function boot()
    {
        $_dbConfig = Bluemix::getDatabaseConfig();

        //  Insert our config into the array and set the default connection
        $_key = md5($_dbConfig['database']);
        $_connections = config(static::DATABASE_ALL_CONNECTIONS_KEY);
        $_connections[$_key] = $_dbConfig;

        config([
            static::DATABASE_ALL_CONNECTIONS_KEY    => $_connections,
            static::DATABASE_DEFAULT_CONNECTION_KEY => $_key,
        ]);
    }

    /** @inheritdoc */
    public function register()
    {
        //  Here because it's required. Does nothing on purpose.
    }
}
