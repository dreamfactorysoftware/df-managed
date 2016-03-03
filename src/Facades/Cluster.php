<?php namespace DreamFactory\Managed\Facades;

use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Library\Utility\Facades\BaseFacade;
use DreamFactory\Managed\Contracts\HasMiddleware;
use DreamFactory\Managed\Contracts\HasRouteMiddleware;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * ClusterService facade
 *
 * @see \DreamFactory\Managed\Services\ClusterService
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
 * @method static HasMiddleware pushMiddleware(Kernel $kernel)
 * @method static HasRouteMiddleware pushRouteMiddleware(Controller $controller)
 * @method static bool deleteManagedDataCache()
 * @method static void validateRoleAccess($userId)
 * @method static Response|RestException handleLoginRequest(Request $request)
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
