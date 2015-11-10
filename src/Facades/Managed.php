<?php namespace DreamFactory\Managed\Facades;

use DreamFactory\Managed\Providers\ManagedServiceProvider;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Request;

/**
 * ManagedService facade
 *
 * @method static bool isManaged()
 * @method static string getPrivatePathName()
 * @method static string getConsoleApiKey()
 * @method static bool auditRequest(Request $request, $sessionData = null)
 * @method static array|mixed getLimits($limitKey = null, $default = [])
 * @method static string getInstanceName()
 * @method static string getClusterId()
 * @method static string getInstanceId()
 * @method static string getClusterName()
 * @method static string getLogPath()
 * @method static string getLogFile()
 * @method static string getStoragePath($append = null)
 * @method static string getPrivatePath($append = null)
 * @method static string getOwnerPrivatePath($append = null)
 * @method static array getDatabaseConfig()
 * @method static string getStorageRoot()
 * @method static string getCacheRoot()
 * @method static string getSnapshotPath()
 * @method static mixed|array getConfig($key = null, $default = null)
 */
class Managed extends Facade
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /** @inheritdoc */
    protected static function getFacadeAccessor()
    {
        return ManagedServiceProvider::IOC_NAME;
    }
}
