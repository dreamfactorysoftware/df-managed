<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Library\Utility\Providers\BaseServiceProvider;
use DreamFactory\Managed\Services\ClusterService;

/**
 * Register the virtual config manager service as a Laravel provider
 */
class ClusterServiceProvider extends BaseServiceProvider
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
}
