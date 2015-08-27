<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Library\Utility\Disk;
use DreamFactory\Managed\Contracts\ProvidesManagedConfig;
use DreamFactory\Managed\Contracts\ProvidesManagedStorage;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Support\Managed;
use Illuminate\Contracts\Foundation\Application;

/**
 * A service that returns various configuration data that are common across managed
 * and unmanaged instances. See the VirtualConfigProvider contract
 */
class ManagedService implements ProvidesManagedConfig, ProvidesManagedStorage
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type Application No underscore so it matches ServiceProvider class...
     */
    protected $app;
    /**
     * @type string
     */
    protected $privatePathName;

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Constructor
     *
     * @param Application $app
     */
    public function __construct(Application $app = null)
    {
        $this->app = $app;
    }

    /**
     * Perform any service initialization
     */
    public function boot()
    {
        Managed::initialize();

        $this->privatePathName = Disk::segment(config('df.private-path-name',
            ManagedDefaults::DEFAULT_PRIVATE_PATH_NAME));
    }

    /**
     * @return string
     */
    public function getRootStoragePath()
    {
        return Managed::getStorageRoot();
    }

    /**
     * @param string|null $append Optional path to append
     *
     * @return string
     */
    public function getStoragePath($append = null)
    {
        return Managed::getStoragePath($append);
    }

    /**
     * We want the private path of the instance to point to the user's area. Instances have no "private path" per se.
     *
     * @param string|null $append Optional path to append
     *
     * @return mixed
     */
    public function getPrivatePath($append = null)
    {
        return Managed::getPrivatePath($append);
    }

    /**
     * We want the private path of the instance to point to the user's area. Instances have no "private path" per se.
     *
     * @param string|null $append Optional path to append
     *
     * @return mixed
     */
    public function getOwnerPrivatePath($append = null)
    {
        return Managed::getOwnerPrivatePath($append);
    }

    /**
     * @return string
     */
    public function getSnapshotPath()
    {
        return $this->getOwnerPrivatePath(config('df.snapshot-path-name',
            ManagedDefaults::SNAPSHOT_PATH_NAME));
    }

    /**
     * @return string
     */
    public function getPrivatePathName()
    {
        return $this->privatePathName;
    }

    /**
     * Returns the instance's absolute /path/to/logs
     *
     * @return string
     */
    public function getLogPath()
    {
        return Managed::getLogPath();
    }

    /**
     * Returns the absolute /path/to/log/file
     *
     * @param string|null $name The name of the log file, instance name used by default
     *
     * @return string
     */
    public function getLogFile($name = null)
    {
        return Managed::getLogFile($name);
    }

    /**
     * Returns the database configuration for an instance
     *
     * @return array
     */
    public function getDatabaseConfig()
    {
        return Managed::getDatabaseConfig();
    }

    /**
     * Returns the instance's private cache path
     *
     * @return string
     */
    public function getCachePath()
    {
        return Managed::getCachePath();
    }
}