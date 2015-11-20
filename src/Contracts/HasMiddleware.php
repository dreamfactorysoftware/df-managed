<?php namespace DreamFactory\Managed\Contracts;

use Illuminate\Foundation\Http\Kernel;

/**
 * Allows services to be identified as having middleware
 *
 * @package DreamFactory\Managed\Contracts
 */
interface HasMiddleware
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Allows any management service to push middleware onto the stack before the request is processed
     *
     * @param \Illuminate\Foundation\Http\Kernel $kernel
     */
    public function pushMiddleware(Kernel $kernel);
}
