<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Library\Utility\Providers\BaseServiceProvider;
use DreamFactory\Managed\Enums\ManagedPlatforms;
use DreamFactory\Managed\Services\BluemixService;

/**
 * Register the virtual db config manager as a Laravel provider
 */
class BluemixServiceProvider extends BaseServiceProvider
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'df.bluemix';

    //********************************************************************************
    //* Methods
    //********************************************************************************

    /** @inheritdoc */
    public function register()
    {
        $this->app->singleton(static::IOC_NAME,
            function ($app) {
                return new BluemixService($app, ManagedPlatforms::BLUEMIX);
            });
    }
}
