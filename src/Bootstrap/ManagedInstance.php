<?php namespace DreamFactory\Managed\Bootstrap;

use DreamFactory\Managed\Contracts\HasMiddleware;
use DreamFactory\Managed\Enums\BlueMixDefaults;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Enums\ManagedPlatforms;
use DreamFactory\Managed\Providers\BluemixServiceProvider;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use DreamFactory\Library\Utility\Disk;
use Symfony\Component\HttpFoundation\JsonResponse;
use Illuminate\Http\Response;
use Exception;
use Log;

class ManagedInstance
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type int Our current platform
     */
    protected $platform = null;

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @param Application $app
     */
    public function bootstrap(Application $app)
    {
        //  Detect the type of managed platform
        $this->platform = $this->detectPlatform();
        if (null !== ($this->platform = $this->detectPlatform())) {
            switch ($this->platform) {
                case ManagedPlatforms::DREAMFACTORY:
                    $this->bootstrapDreamFactory($app);
                    break;

                case ManagedPlatforms::BLUEMIX:
                    $this->bootstrapBluemix($app);
                    break;
            }
        }

        return;
    }

    /**
     * Detect the type of managed platform we are on
     *
     * @return bool|int The platform type or false if undetected
     */
    protected function detectPlatform()
    {
        if (true === env('DF_MANAGED', false)) {
            return ManagedPlatforms::DREAMFACTORY;
        }

        if (!empty(env('VCAP_SERVICES', []))) {
            return ManagedPlatforms::BLUEMIX;
        }

        return null;
    }

    /**
     * @param Application $app
     *
     * @return bool
     */
    protected function bootstrapDreamFactory($app)
    {
        //  Get an instance of the cluster service
        $app->register(new ClusterServiceProvider($app));

        try {
            /** @type ClusterService $_cluster */
            $_cluster = ClusterServiceProvider::service($app);
        } catch (Exception $_ex) {
            //  Cluster service not available, or misconfigured. No logger yet so just bail...
            $response = Response::create(json_encode([
                'error' => [
                    'code' => $_ex->getCode(),
                    'message' => $_ex->getMessage()
                ]
            ], true));
            $response->send();
        }

        $_vars = [
            'DF_CACHE_PREFIX'         => $_cluster->getCachePrefix(),
            'DF_CACHE_PATH'           => $_cluster->getCachePath(),
            // 'DF_LIMITS_CACHE_STORE'   => ManagedDefaults::DEFAULT_LIMITS_STORE,
            // 'DF_LIMITS_CACHE_PATH'    => Disk::path([$_cluster->getCacheRoot(), '.limits'], true),
            'DF_MANAGED_SESSION_PATH' => Disk::path([$_cluster->getCacheRoot(), '.sessions'], true),
            'DF_MANAGED_LOG_FILE'     => $_cluster->getHostName() . '.log',
            'DF_MANAGED'              => true,
            'DB_DRIVER'               => 'mysql',
            'DF_PACKAGE_PATH'         => $_cluster->getPackagePath(),

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

        $_vars['DF_MANAGED_LOG_PATH'] = rtrim($_vars['DF_MANAGED_LOG_PATH'], '/') .
            DIRECTORY_SEPARATOR .
            $_cluster->getHostName();

        //  If this is a console request, denote it as such
        $_vars['DF_CONSOLE_KEY'] = $_cluster->getConsoleKey();

        //  Is it a console request? Validate
        /** @type Request $_request */
        $_request = $app->make('request');
        $_vars['DF_IS_VALID_CONSOLE_REQUEST'] =
            ($_vars['DF_CONSOLE_KEY'] == $_request->header(ManagedDefaults::CONSOLE_X_HEADER,
                    $_request->query('console_key')));

        //  If this is a FastTrack redirect, denote as such
        (null !== ($_guid = $_request->get('fastTrackGuid'))) && $_vars['DF_FAST_TRACK_GUID'] = $_guid;

        /** For DreamFactory limits, if enabled, need to override limit_cache path */
        if (class_exists('DreamFactory\Core\Limit\ServiceProvider')) {
            $_vars['LIMIT_CACHE_PATH'] = Disk::path([$_cluster->getStoragePrivatePath(), '.limit_cache'], true);
            $_vars['LIMIT_CACHE_PREFIX'] = $_cluster->getHostName(true);
        }

        //  Now jam everything into the environment
        foreach ($_vars as $_key => $_value) {
            error_log($_key . '=' . print_r($_value, true));
            putenv($_key . '=' . $_value);
            $_ENV[$_key] = $_value;
            $_SERVER[$_key] = $_value;
        }

        //  Finally, let the cluster service push some middleware onto the stack
        if ($_cluster instanceof HasMiddleware) {
            $_cluster->pushMiddleware($app->make('Illuminate\Contracts\Http\Kernel'));
        }

        return true;
    }

    /**
     * @param Application $app
     */
    protected function bootstrapBluemix($app)
    {
        $_vars = [];

        //  Get an instance of the cluster service
        $app->register(new BluemixServiceProvider($app));
        $_service = BluemixServiceProvider::service($app);

        // Only need the DB info if the db driver isn't sqlite

        if ('sqlite' != env('DB_DRIVER', 'pgsql')) {

            //  Get the Bluemix database information
            $_config = $_service->getDatabaseConfig(
                env('BM_DB_SERVICE_KEY', BlueMixDefaults::BM_DB_SERVICE_KEY),
                env('BM_DB_INDEX', BlueMixDefaults::BM_DB_INDEX),
                env('BM_DB_CREDS_KEY', BlueMixDefaults::BM_CREDS_KEY)
            );

            foreach ($_config as $_key => $_value) {
                $_vars['DB_' . strtr(strtoupper($_key), '-', '_')] = $_value;
            }
        }

        //  Get any Bluemix Redis information
        if ('redis' == env('CACHE_DRIVER', 'file')) {
            $_config = $_service->getRedisConfig(
                env('BM_REDIS_SERVICE_KEY', BlueMixDefaults::BM_REDIS_SERVICE_KEY),
                env('BM_REDIS_INDEX', BlueMixDefaults::BM_REDIS_INDEX),
                env('BM_CREDS_KEY', BlueMixDefaults::BM_CREDS_KEY));

            foreach ($_config as $_key => $_value) {
                $_vars['REDIS_' . strtr(strtoupper($_key), '-', '_')] = $_value;
            }
        }

        //  Now jam everything into the environment
        foreach ($_vars as $_key => $_value) {
            putenv($_key . '=' . $_value);
            $_ENV[$_key] = $_value;
            $_SERVER[$_key] = $_value;
        }

        //  Finally, let the cluster service push some middleware onto the stack
        if ($_service instanceof HasMiddleware) {
            $_service->pushMiddleware($app->make('Illuminate\Contracts\Http\Kernel'));
        }
    }
}
