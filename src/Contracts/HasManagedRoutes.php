<?php namespace DreamFactory\Managed\Contracts;

use Illuminate\Routing\Router;

/**
 * Allows services to be identified as having managed routes
 */
interface HasManagedRoutes
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Allows services to add additional routes before the request is processed
     *
     * @param \Illuminate\Routing\Router $router
     *
     * @return $this
     */
    public function addManagedRoutes(Router $router);
}
