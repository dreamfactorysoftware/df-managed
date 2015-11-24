<?php namespace DreamFactory\Managed\Contracts;

use Illuminate\Routing\Controller;

/**
 * Allows services to be identified as having route middleware
 *
 * @package DreamFactory\Managed\Contracts
 */
interface HasRouteMiddleware
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Allows services to add route middleware before the request is processed
     *
     * @param \Illuminate\Routing\Controller $controller
     *
     * @return $this
     */
    public function pushRouteMiddleware(Controller $controller);
}
