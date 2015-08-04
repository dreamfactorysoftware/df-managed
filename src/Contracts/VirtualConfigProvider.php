<?php namespace DreamFactory\Managed\Contracts;

interface VirtualConfigProvider
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Returns the root of all an instance's storage
     *
     * @return string
     */
    public function getRootStoragePath();

    /**
     * Returns the absolute /path/to/app/storage
     *
     * @param string|null $append If supplied, added to the end of the path
     *
     * @return string
     */
    public function getStoragePath($append = null);

    /**
     * Returns the name of the "private-path" directory. Usually this is ".private"
     *
     * @return string
     */
    public function getPrivatePathName();

    /**
     * Returns the absolute path to an instance's private path/area
     *
     * @param string|null $append If supplied, added to the end of the path
     *
     * @return string
     */
    public function getPrivatePath($append = null);

    /**
     * @param string|null $append If supplied, added to the end of the path
     *
     * @return string
     */
    public function getOwnerPrivatePath($append = null);

    /**
     * Returns the instance's private path, relative to storage-path
     *
     * @return string
     */
    public function getSnapshotPath();

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
