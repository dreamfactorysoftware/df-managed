<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Managed\Services\ManagedService;
use DreamFactory\Managed\Support\Managed;
use Illuminate\Support\ServiceProvider;

/**
 * Register the virtual config manager service as a Laravel provider
 */
class ManagedServiceProvider extends ServiceProvider
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'df.managed.config';
    /**
     * @type string The key in database config that holds all connections.
     */
    const DATABASE_ALL_CONNECTIONS_KEY = 'database.connections';
    /**
     * @type string The key in database config that specifies default connection.
     */
    const DATABASE_DEFAULT_CONNECTION_KEY = 'database.default';

    //********************************************************************************
    //* Public Methods
    //********************************************************************************

    /** @inheritdoc */
    public function boot()
    {
        //  Kick off the management interrogation
        Managed::initialize();

        //  Insert the managed configuration, if any, into the app config
        $_dbConfig = Managed::getDatabaseConfig();
        $_configs = config(static::DATABASE_ALL_CONNECTIONS_KEY);
        $_key = md5($_dbConfig['database']);
        $_configs[$_key] = $_dbConfig;
        config([static::DATABASE_ALL_CONNECTIONS_KEY => $_configs]);

//        logger('DB Config from Managed Service: Host = ' .
//            $_dbConfig['host'] .
//            ' DataBase = ' .
//            $_dbConfig['database']);
    }

    /** @inheritdoc */
    public function register()
    {
        $this->app->singleton(static::IOC_NAME,
            function ($app){
                return new ManagedService($app);
            });
    }
}
