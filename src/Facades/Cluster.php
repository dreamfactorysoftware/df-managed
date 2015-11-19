<?php namespace DreamFactory\Managed\Facades;

use DreamFactory\Library\Utility\Facades\BaseFacade;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use Illuminate\Contracts\Http\Kernel;

/**
 * ClusterService facade
 *
 * @method static string getHostName($hashed = false)
 * @method static string getInstanceName()
 * @method static string getClusterId()
 * @method static string getInstanceId()
 * @method static string getLogPath()
 * @method static string getLogFile($name = null)
 * @method static array|mixed getConfig($key = null, $default = null)
 * @method static array setConfig($key, $value = null)
 * @method static string getCachePath($create = false, $mode = 0777, $recursive = true)
 * @method static array getDatabaseConfig()
 * @method static string getConsoleKey()
 * @method static string getStorageRoot()
 * @method static string getCacheRoot()
 * @method static string getCachePrefix()
 * @method static array|null getLimits($key = null, $default = [])
 * @method static void pushMiddleware(Kernel $kernel)
 */
class Cluster extends BaseFacade
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /** @inheritdoc */
    protected static function getFacadeAccessor()
    {
        return ClusterServiceProvider::IOC_NAME;
    }
}
