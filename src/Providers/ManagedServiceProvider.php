<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Managed\Services\ManagedService;
use DreamFactory\Managed\Support\Managed;
use Illuminate\Support\Facades\Artisan;
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
     * @type string The key in config() to place the un/managed db config
     */
    const DATABASE_CONFIG_KEY = 'database.connections.dreamfactory';
    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'managed.config';

    //********************************************************************************
    //* Public Methods
    //********************************************************************************

    /**
     * Start up the dependency
     */
    public function boot()
    {
        //  Kick off the management interrogation
        Managed::initialize();
        $_dbConfig = Managed::getDatabaseConfig();
        //  Stuff the db config into the config array
        config([static::DATABASE_CONFIG_KEY => $_dbConfig]);
        logger('DB Config from Managed Service: ' . print_r($_dbConfig, true));
        Artisan::call('config:cache');

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(static::IOC_NAME,
            function ($app) {
                return new ManagedService($app);
            });
    }
}
