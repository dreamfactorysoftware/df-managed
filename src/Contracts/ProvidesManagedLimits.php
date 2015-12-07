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

    /**
     * @param bool $create    If true, path will be created
     * @param int  $mode      The mode if creating
     * @param bool $recursive Create recursively?
     *
     * @return string A path (optionally ensured via $create!) for the caching instance limit data
     */
    public function getLimitsCachePath($create = false, $mode = 0777, $recursive = true);
}
