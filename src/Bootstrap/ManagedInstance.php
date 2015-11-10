<?php namespace DreamFactory\Managed\Bootstrap;

use DreamFactory\Managed\Providers\AuditServiceProvider;
use DreamFactory\Managed\Providers\ManagedServiceProvider;
use Illuminate\Contracts\Foundation\Application;

class ManagedInstance
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type array Our managed service providers
     */
    protected $providers = [
        ManagedServiceProvider::class,
        AuditServiceProvider::class,
    ];

    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @param Application $app
     */
    public function bootstrap(Application $app)
    {
        foreach ($this->providers as $_provider) {
            $app['config']->push('app.providers', $_provider);
        }
    }
}
