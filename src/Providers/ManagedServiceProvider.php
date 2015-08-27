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
     * @type string The key in database config that holds all connections.
     */
    const DATABASE_ALL_CONNECTIONS_KEY = 'database.connections';
    /**
     * @type string The key in database config that specifies default connection.
     */
    const DATABASE_DEFAULT_CONNECTION_KEY = 'database.default';
    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'managed.config';

    //********************************************************************************
    //* Methods
    //********************************************************************************

    /**
     * Start up the dependency
     */
    public function boot()
    {
        if (Managed::isManagedInstance()) {
            //  Supplant the database connection for managed instances
            config([
                'database.connections.' . config('database.default', 'dreamfactory') => Managed::getDatabaseConfig(),
            ]);

            logger('db config set from managed service');
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(static::IOC_NAME,
            function ($app){
                return new ManagedService($app);
            });
    }
}
