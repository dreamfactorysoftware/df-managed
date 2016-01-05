<?php namespace DreamFactory\Managed\Http\Controllers;

use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Http\Middleware\ImposeClusterLimits;

class InstanceController extends Controller
{
    private $periods = [
        'minute' => 1,
        'hour' => 60,
        'day' => 1440,
        '7-day' => 10080,
        '30-day' => 43200,
    ];

    function __construct()
    {
        // Once initial testing has been completed, put console check here

    }

    /**
     * Respond to /instance/refresh-config
     */

    public function getIndex()
    {
        echo "Instance Controller";
    }

    public function getRefresh()
    {
        // Get an instance of the Cluster Service Provider
        $_cluster = ClusterServiceProvider::service();

        // Force the instance to pull the config from the console
        print "calling cluster setup\n";
        $_cluster->setup();

        return ['success' => true];
    }

    /**
     * Respond to /instance/clear-counter/<cache-key>
     *
     * Where <cache-key> is a string value such as cluster-dfelocal.test1.minute or cluster-dfelocal.hour
     */

    public function deleteClearCounter($cacheKey)
    {

        $dfCachePrefix = env('DF_CACHE_PREFIX');
        putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
        $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

        try {
            ImposeClusterLimits::cache()->put($cacheKey, 0, $this->periods[end(explode('.',$cacheKey))]);

        } catch (\Exception $e) {

        } finally {
            //  Ensure the cache prefix is restored
            putenv('DF_CACHE_PREFIX' . '=' . $dfCachePrefix);
            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $dfCachePrefix;
        }
    }
}