<?php namespace DreamFactory\Managed\Contracts;

interface ProvidesManagedLogs
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Returns the instance's absolute /path/to/logs
     *
     * @return string
     */
    public function getLogPath();

    /**
     * Returns the absolute /path/to/log/file
     *
     * @param string|null $name The name of the log file, instance name used by default
     *
     * @return string
     */
    public function getLogFile($name = null);
}
