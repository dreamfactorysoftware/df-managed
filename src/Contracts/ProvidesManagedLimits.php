<?php namespace DreamFactory\Managed\Contracts;

interface ProvidesManagedLimits
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Get any limits to be imposed on instances
     *
     * @param string|null $key     A key within the limits to retrieve. If omitted, all limits are returned
     * @param array       $default The default value to return if $key was not found
     *
     * @return array|null
     */
    public function getLimits($key = null, $default = []);
}
