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
        //  Kick off the management interrogation
        Managed::initialize();
        $_dbConfig = Managed::getDatabaseConfig();

        /*************************************************************************************************************
         *
         * To be efficient laravel DatabaseManager only creates connection once and it happens earlier during the
         * db bootstrap process. So, by now it has already created a connection using the connection that's set in
         * database.default config. Therefore, there is no point making changes in that connection (specified in
         * database.default) config. Rather create a new connection and insert it into the database.connections
         * array and set the database.default to this new connection.
         */

        //Creating new connection key using the md5 hash of the connection's database name.
        $connectionKey = md5($_dbConfig['database']);

        //Grabbing all available connections that's in database.connections config.
        $allConnections = config(static::DATABASE_ALL_CONNECTIONS_KEY);

        //Inserting the new connection config we got from enterprise console into list of all available connections.
        $allConnections[$connectionKey] = $_dbConfig;

        //Updating the database.connections config array.
        config([static::DATABASE_ALL_CONNECTIONS_KEY => $allConnections]);

        //Setting the default connection to use the newly inserted connection from enterprise console.
        config([static::DATABASE_DEFAULT_CONNECTION_KEY => $connectionKey]);

        /**
         *************************************************************************************************************/

        if (env('APP_DEBUG', false)) {
            logger('DB Config from Managed Service: Host = ' .
                $_dbConfig['host'] .
                ' DataBase = ' .
                $_dbConfig['database']);
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
