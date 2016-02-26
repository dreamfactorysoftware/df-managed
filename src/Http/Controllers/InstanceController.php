<?php namespace DreamFactory\Managed\Http\Controllers;

use DreamFactory\Core\Models\User;
use DreamFactory\Managed\Exceptions\ManagedInstanceException;
use DreamFactory\Managed\Facades\Cluster;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Http\Middleware\ImposeClusterLimits;
use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;

class InstanceController extends BaseController
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /*** Constructor */
    public function __construct()
    {
        $this->middleware('access_check', ['except' => ['getFastTrack']]);
    }

    /**
     * Tell an instance to contact the console and get a fresh copy of it's configuration.
     * Endpoint handler for PUT /instance/refresh
     *
     * @return array
     */
    public function putRefresh()
    {
        $_result = true;

        // Get an instance of the Cluster Service Provider
        /** @type ClusterService $_cluster */
        $_cluster = ClusterServiceProvider::service();

        try {
            // Force the instance to pull the config from the console
            logger('[df-managed.instance-controller] instance configuration refresh initiated by console');

            $_cluster->setup();
        } catch (ManagedInstanceException $_ex) {
            $_result = false;
        }

        /** @noinspection PhpUndefinedMethodInspection */
        return Response::json(['success' => $_result]);
    }

    /**
     * Validates and processes a FastTrack login request. An HTTP redirect is performed at the end of the method.
     * Endpoint handler for GET /instance/fast-track
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return RedirectResponse
     */
    public function getFastTrack(Request $request)
    {
        //  Valid request?
        if (null !== ($_guid = env('DF_FAST_TRACK_GUID'))) {
            /** @noinspection PhpUndefinedMethodInspection */
            if (null !== ($_user = User::whereRaw('SHA1(CONCAT(email,first_name,last_name)) = :guid', [':guid' => $_guid])->first())) {
                logger('[df-managed.instance-controller.fast-track] received guid "' . $_guid . '"/"' . $_user->email . '" user id#' . $_user->id);

                //  Ok, now we have a user, we need to log their buttocks in...
                /** @noinspection PhpUndefinedMethodInspection */
                Auth::login($_user);

                logger('[df-managed.instance-controller.fast-track] login "' . $_user->email . '"');

                /** @noinspection PhpUndefinedMethodInspection */
                return Redirect::to('/');
            } else {
                logger('[df-managed.instance-controller.fast-track] invalid guid received "' . $_guid . '"');
            }
        }

        /** @noinspection PhpUndefinedMethodInspection */
        Log::error('[df-managed.instance-controller.fast-track] invalid fast-track request received');

        /** @noinspection PhpUndefinedMethodInspection */
        return Redirect::to('/auth/login');
    }

    /**
     * Tell an instance to delete a single limits counter from the cache
     * Endpoint handler for /instance/clear-limits-counter/<cache-key>
     *
     * @param string $cacheKey The cache key to delete (i.e., "cluster-dfelocal.test1.minute", "cluster-dfelocal.hour", etc.)
     *
     * @return Response
     */
    public function deleteClearLimitsCounter($cacheKey)
    {
        $_cachePrefix = env('DF_CACHE_PREFIX');
        putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
        $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

        try {
            ImposeClusterLimits::cache()->forget($cacheKey);
            logger('[df-managed.instance-controller] "' . $cacheKey . '" limit count reset by console');
        } catch (\Exception $_ex) {
            /** @noinspection PhpUndefinedMethodInspection */
            Log::error('[df-managed.instance-controller] exception clearing limit count: ' . $_ex->getMessage());
        }
        finally {
            //  Ensure the cache prefix is restored
            putenv('DF_CACHE_PREFIX' . '=' . $_cachePrefix);
            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $_cachePrefix;

            /** @noinspection PhpUndefinedMethodInspection */
            return Response::json(['success' => true]);
        }
    }

    /**
     * @return array
     */
    public function deleteClearLimitsCache()
    {
        $_cachePrefix = env('DF_CACHE_PREFIX');
        putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
        $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

        try {
            ImposeClusterLimits::cache()->flush();
            logger('[df-managed.instance-controller] limit cache flushed by console');
        } catch (\Exception $_ex) {
            /** @noinspection PhpUndefinedMethodInspection */
            Log::error('[df-managed.instance-controller] exception clearing limit cache: ' . $_ex->getMessage());
        }
        finally {
            //  Ensure the cache prefix is restored
            putenv('DF_CACHE_PREFIX' . '=' . $_cachePrefix);
            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $_cachePrefix;

            /** @noinspection PhpUndefinedMethodInspection */
            return Response::json(['success' => true]);
        }
    }

    /**
     * Deletes all cached managed data for instance
     *
     * @return Response
     */
    public function deleteManagedDataCache()
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return Response::json(['success' => Cluster::deleteManagedDataCache()]);
    }
}
