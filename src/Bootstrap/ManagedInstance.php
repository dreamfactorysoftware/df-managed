<?php namespace DreamFactory\Managed\Bootstrap;

use DreamFactory\Library\Utility\Disk;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

class ManagedInstance
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @param Application $app
     */
    public function bootstrap(Application $app)
    {
        if (!env('DF_MANAGED', false)) {
            return;
        }

        $app->register(new ClusterServiceProvider($app));
        /** @type ClusterService $_cluster */
        $_cluster = ClusterServiceProvider::service();

        $_vars = [
            'DF_CACHE_PREFIX'         => $_cluster->getCachePrefix(),
            'DF_CACHE_PATH'           => $_cluster->getCachePath(),
            'DF_MANAGED_SESSION_PATH' => Disk::path([$_cluster->getCacheRoot(), '.sessions'], true),
            'DF_MANAGED_LOG_FILE'     => $_cluster->getHostName() . '.log',
            'DF_MANAGED'              => true,
            'DB_DRIVER'               => 'mysql',
        ];

        //  Get the cluster database information
        foreach ($_cluster->getDatabaseConfig() as $_key => $_value) {
            $_vars['DB_' . strtr(strtoupper($_key), '-', '_')] = $_value;
        }

        //  Throw in some paths
        if (!empty($_paths = $_cluster->getConfig('paths', []))) {
            foreach ($_paths as $_key => $_value) {
                $_vars['DF_MANAGED_' . strtr(strtoupper($_key), '-', '_')] = $_value;
            }
        }

        //  If this is a console request, denote it as such
        $_vars['DF_CONSOLE_KEY'] = $_cluster->getConsoleKey();

        //  Is it a console request? Validate
        /** @type Request $_request */
        $_request = $app->make('request');
        $_vars['DF_IS_VALID_CONSOLE_REQUEST'] =
            ($_vars['DF_CONSOLE_KEY'] ==
                $_request->header(ManagedDefaults::CONSOLE_X_HEADER, $_request->query('console_key')));

        //  Now jam everything into the environment
        foreach ($_vars as $_key => $_value) {
            putenv($_key . '=' . $_value);
            $_ENV[$_key] = $_value;
            $_SERVER[$_key] = $_value;
        }

        //  Finally, push our middleware onto the stack
        $app->make('Illuminate\Contracts\Http\Kernel')
            ->pushMiddleware('DreamFactory\Managed\Http\Middleware\ImposeClusterLimits')
            ->pushMiddleware('DreamFactory\Managed\Http\Middleware\ClusterAuditor');
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public static function getConsoleApiKey($request)
    {
        //  Check for Console API key in request parameters.
        $consoleApiKey = $request->query('console_key');
        if (empty($consoleApiKey)) {
            //Check for API key in request HEADER.
            $consoleApiKey = $request->header(ManagedDefaults::CONSOLE_X_HEADER);
        }

        return $consoleApiKey;
    }
}
