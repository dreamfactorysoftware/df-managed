<?php namespace DreamFactory\Managed\Providers;

use Illuminate\Support\ServiceProvider;

use DreamFactory\Managed\Enums\ManagedPlatforms;
use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Contracts\Foundation\Application;


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
            function ($app) {
                return new ClusterService($app, ManagedPlatforms::DREAMFACTORY);
            });
    }

    /**
     * @param Application|null $app
     *
     * @return mixed
     */
    public static function service(Application $app = null)
    {
        $app = $app ?: app();
        return $app->make(static::IOC_NAME);
    }


}
