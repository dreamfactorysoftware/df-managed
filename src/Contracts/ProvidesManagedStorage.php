<?php namespace DreamFactory\Managed\Contracts;

interface ProvidesManagedStorage
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Returns the overall root of an instance owner's storage
     *
     * @return string
     */
    public function getRootStoragePath();

    /**
     * Returns the absolute path to an instance's storage
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
}
