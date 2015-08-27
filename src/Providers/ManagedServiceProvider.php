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
    //* Public Methods
    //********************************************************************************

    /**
     * Start up the dependency
     */
    public function boot()
    {
        //  Get our source db config
        $_dbConfig = Managed::getDatabaseConfig();

        /*************************************************************************************************************
         *
         * To be efficient laravel DatabaseManager only creates connection once and it happens earlier during the
         * db bootstrap process. So, by now it has already created a connection using the connection that's set in
         * database.default config. Therefore, there is no point making changes in that connection (specified in
         * database.default) config. Rather create a new connection and insert it into the database.connections
         * array and set the database.default to this new connection.
         */

        //  Create a new connection with a key using the md5 hash of the database name.
        $_key = md5($_dbConfig['database']);
        $_configs = config(static::DATABASE_ALL_CONNECTIONS_KEY);
        $_configs[$_key] = $_dbConfig;
        config([static::DATABASE_ALL_CONNECTIONS_KEY => $_configs]);

        //  Set the default connection to our new hashed key
        config([static::DATABASE_DEFAULT_CONNECTION_KEY => $_key]);

        /**
         *************************************************************************************************************/

//        logger('DB Config from Managed Service: Host = ' .
//            $_dbConfig['host'] .
//            ' DataBase = ' .
//            $_dbConfig['database']);
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
