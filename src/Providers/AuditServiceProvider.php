<?php namespace DreamFactory\Managed\Providers;

use DreamFactory\Library\Utility\Providers\BaseServiceProvider;
use DreamFactory\Managed\Services\AuditingService;

/**
 * Register the auditing service
 */
class AuditServiceProvider extends BaseServiceProvider
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The name of the service in the IoC
     */
    const IOC_NAME = 'dfe.audit';

    //********************************************************************************
    //* Public Methods
    //********************************************************************************

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        //  Register object into instance container
        $this->app->singleton(static::IOC_NAME,
            function ($app){
                return new AuditingService($app);
            });
    }

    public function boot()
    {
        \Route::controller('instance', 'DreamFactory\Managed\Http\Controllers\InstanceController');
    }
}
