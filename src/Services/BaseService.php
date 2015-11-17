<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Managed\Enums\ManagedDefaults;
use Illuminate\Contracts\Foundation\Application;

/**
 * A base service class
 */
abstract class BaseService
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type int The number of minutes to keep managed instance data cached
     */
    const CACHE_TTL = ManagedDefaults::SERVICE_CACHE_TTL;

    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type \Illuminate\Contracts\Foundation\Application
     */
    protected $app;
    /**
     * @type string A key used to retrieve this service's config
     */
    protected $cacheKey;

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * constructor
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function __construct(Application $app = null)
    {
        $this->app = $app;
        $this->boot();
    }

    /**
     * Initialization the service
     */
    public function boot()
    {
        //  Stub
    }

    /**
     * @return string The http host name
     */
    protected function getHttpHost()
    {
        try {
            if (null !== ($_host = app('request')->getHttpHost())) {
                return $_host;
            };
        } catch (\Exception $_ex) {
            //  No request
        }

        //  Return the regular host name if we have no request or http host
        return gethostname();
    }

    /**
     * Returns a key prefixed for use in \Cache
     *
     * @return string
     */
    protected function getCacheKey()
    {
        return $this->cacheKey;
    }

    /**
     * @param string $cacheKey
     *
     * @return BaseService
     */
    protected function setCacheKey($cacheKey)
    {
        $this->cacheKey = $cacheKey;

        return $this;
    }

    /**
     * Clears out any settings from a prior managed thing
     *
     * @return bool
     */
    protected function reset()
    {
        $this->cacheKey = null;

        return false;
    }
}
