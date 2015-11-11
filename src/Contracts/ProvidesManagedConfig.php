<?php namespace DreamFactory\Managed\Contracts;

interface ProvidesManagedConfig extends ProvidesManagedDatabase, ProvidesManagedLogs
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @return string
     */
    public function getInstanceName();

    /**
     * @return string
     */
    public function getInstanceId();

    /**
     * @return string
     */
    public function getClusterId();

    /**
     * Retrieve a config value or the entire array
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return array|mixed
     */
    public function getConfig($key = null, $default = null);
}
