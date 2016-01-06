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
     * Get the limits cache
     *
     * @return \Illuminate\Cache\Repository
     */
    protected function cache()
    {
        static $repository;

        if (!$repository) {
            $_store = env('DF_LIMITS_CACHE_STORE', ManagedDefaults::DEFAULT_LIMITS_STORE);

            //  If no config defined, make one
            if (empty(config('cache.stores.' . $_store))) {
                config([
                    'cache.stores.' . $_store => [
                        'driver' => 'file',
                        'path' => env('DF_LIMITS_CACHE_PATH', storage_path('framework/cache')),
                    ],
                ]);
            }

            //  Create the cache
            $repository = app('cache')->store($_store);
        }

        return $repository;
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
        logger('Instance configuration refresh initiated by console');

        return ['success' => $_cluster->setup()];
    }

    /**
     * Respond to /instance/clearcounter/<cache-key>
     *
     * Where <cache-key> is a string value such as cluster-dfelocal.test1.minute or cluster-dfelocal.hour
     */

    public function deleteClearcounter($cacheKey)
    {

        $dfCachePrefix = env('DF_CACHE_PREFIX');
        putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
        $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

        try {
            $cache = $this->cache();
            logger('Current value of ' . $cacheKey . ' : ' . $cache->get($cacheKey));
            $cache->put($cacheKey, 0, $this->periods[end(explode('.',$cacheKey))]);
            logger('New value of ' . $cacheKey . ' : ' . $cache->get($cacheKey));

        } catch (\Exception $e) {
            logger('Error : ' . print_r($e->getMessage(), true));
        } finally {
            //  Ensure the cache prefix is restored
            putenv('DF_CACHE_PREFIX' . '=' . $dfCachePrefix);
            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $dfCachePrefix;
            return ['success' => true];
        }
    }
}