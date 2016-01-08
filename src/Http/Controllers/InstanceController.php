<?php namespace DreamFactory\Managed\Http\Controllers;

use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Http\Middleware\ImposeClusterLimits;

class InstanceController extends Controller
{

    function __construct()
    {
        $this->middleware('access_check');
    }

    /**
     * Respond to /instance/refresh-config
     */

    public function getIndex()
    {
        echo "Instance Controller";
    }

    /**
     * Tell an instance to contact the console and get a fresh copy of it's configuration
     *
     * Responds to /instance/refresh
     *
     * @return array
     */
    public function putRefresh()
    {
        // Get an instance of the Cluster Service Provider
        $_cluster = ClusterServiceProvider::service();

        // Debug
        logger('Current Limits : ' . print_r($_cluster->getLimits(), true));

        // Force the instance to pull the config from the console
        logger('Instance configuration refresh initiated by console');

        $retval = $_cluster->setup();

        //Debug
        logger('New Limits : ' . print_r($_cluster->getLimits(), true));

        return ['success' => $retval];
    }

    /**
     * Tell an instance to delete a single limits counter from the cache
     *
     * Respond to /instance/clearlimitscounter/<cache-key>
     *
     * Where <cache-key> is a string value such as cluster-dfelocal.test1.minute or cluster-dfelocal.hour
     *
     * @return array
     */

    public function deleteClearlimitscounter($cacheKey)
    {

        $dfCachePrefix = env('DF_CACHE_PREFIX');
        putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
        $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

        try {
            $cache = ImposeClusterLimits::cache();
            $cache->forget($cacheKey);
            logger('Limit count for ' . $cacheKey . ' reset initiated by console');

        } catch (\Exception $e) {
            logger('Error clearing limit count : ' . print_r($e->getMessage(), true));
        } finally {
            //  Ensure the cache prefix is restored
            putenv('DF_CACHE_PREFIX' . '=' . $dfCachePrefix);
            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $dfCachePrefix;
            return ['success' => true];
        }
    }


    public function deleteClearlimitscache()
    {
        $dfCachePrefix = env('DF_CACHE_PREFIX');
        putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
        $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

        try {
            $cache = ImposeClusterLimits::cache();
            $cache->flush();
            logger('Limits cache clear initiated by console');

        } catch (\Exception $e) {
            logger('Error clearing limits cache : ' . print_r($e->getMessage(), true));
        } finally {
            //  Ensure the cache prefix is restored
            putenv('DF_CACHE_PREFIX' . '=' . $dfCachePrefix);
            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $dfCachePrefix;
            return ['success' => true];
        }
    }
}