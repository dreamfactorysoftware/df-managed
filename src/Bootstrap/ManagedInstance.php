<?php namespace DreamFactory\Managed\Bootstrap;

use DreamFactory\Managed\Providers\ClusterServiceProvider;
use Illuminate\Contracts\Foundation\Application;

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
        if ('managed' != $app->environment()) {
            return;
        }

        $app->register(new ClusterServiceProvider($app));
        $_cluster = ClusterServiceProvider::service($app);

        $_vars = [
            'DF_CACHE_PREFIX' => $_cluster->getCachePrefix(),
            'DF_CACHE_PATH'   => $_cluster->getCachePath(),
        ];

        //  Get the cluster database information
        foreach ($_cluster->getDatabaseConfig() as $_key => $_value) {
            $_vars['DB_' . strtoupper($_key)] = $_value;
        }

        //  Throw in some paths
        if (!empty($_paths = $_cluster->getConfig('paths', []))) {
            foreach ($_paths as $_key => $_value) {
                $_vars['DF_MANAGED_' . strtoupper($_key)] = $_value;
            }
        }

        //  Now jam everything into the environment
        foreach ($_vars as $_key => $_value) {
            putenv($_key . '=' . $_value);
            $_ENV[$_key] = $_value;
            $_SERVER[$_key] = $_value;
        }
    }
}
