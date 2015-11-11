<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Register the virtual config manager service as a Laravel provider
 */
class ClusterServiceProvider extends ServiceProvider
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'df.cluster';

    //********************************************************************************
    //* Methods
    //********************************************************************************

    /** @inheritdoc */
    public function register()
    {
        $this->app->singleton(static::IOC_NAME,
            function ($app){
                return new ClusterService($app);
            });
    }

    /**
     * @param \Illuminate\Contracts\Foundation\Application $app
     *
     * @return \DreamFactory\Managed\Services\ClusterService
     */
    public static function service(Application $app)
    {
        return $app ? $app[static::IOC_NAME] : null;
    }
}
