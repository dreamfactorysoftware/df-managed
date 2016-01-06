<?php namespace DreamFactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Managed\Contracts\ProvidesManagedLimits;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;

class ImposeClusterLimits
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type bool
     */
    protected $testing = false;
    /**
     * @type array The available periods
     */
    protected $periods = [
        'minute',
        'hour',
        'day',
        '7-day',
        '30-day',
    ];

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Get the limits cache
     *
     * @return \Illuminate\Cache\Repository
     */
    static function cache()
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
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /**
         * It is assumed, if you get this far, that ClusterServiceProvider was registered via
         * the ManagedInstance bootstrapper. If not, you're in a world of shit.
         *
         * We use provider's service() method because Facades are not loaded yet
         */
        $_cluster = ClusterServiceProvider::service();

        //  Get limits or bail
        if (!($_cluster instanceof ProvidesManagedLimits) || empty($limits = $_cluster->getLimits())) {
            return $next($request);
        }

        // Bail if this is the instance controller

        if ($request->segment(1) == "instance") {
            return $next($request);
        }

        $this->testing = config('api_limits_test', 'testing' == env('APP_ENV'));

        if (!empty($limits['api'])) {
            $userId = $this->getUser(Session::getCurrentUserId());
            $clusterName = $_cluster->getClusterId();
            $instanceName = $_cluster->getInstanceName();

            // Convert the array to a json string to make things easier

            $limits = json_encode($limits);

            /**
             * dfe-console now saves the new style limits, but just in case something didn't get updated, replace it
             *
             * While we're at it, replace any occurance of each_instance with each_instance|instance_name and each_user
             * with each_user|user:userID.  A pipe | was used because the user id already has a colon in it, just in
             * case the individual tokens need to be tokenized further
             */

            if (!$this->testing) {
                $limits = str_replace(['cluster.default', 'instance.default', 'each_instance', 'each_user'],
                    [$clusterName, $clusterName . '.' . $instanceName, 'each_instance|' . $instanceName, 'each_user|' . $userId],
                    $limits);
            }

            //  Convert it back to an array
            $limits = json_decode($limits, true);

            /**
             * Keys needed:
             *
             * cluster_name
             * cluster_name.instance_name
             * cluster_name.instance_name.user_id
             */

            //  Build the list of API Hits to check
            $apiKeysToCheck = [$clusterName => 1, $clusterName . '.' . $instanceName => 1];

            $userId && $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $userId] = 1;

            /**
             * Deal with each_instance and each_user options.  Only check for these if there is not an instance specific
             * or user specific limit set.
             */

            !$this->preg_array_key_exists($limits['api'],
                '/^' . $clusterName . '\.' . $instanceName . '\.' . implode('|', $this->periods) . '/'
            ) && $apiKeysToCheck[$clusterName . '.each_instance|' . $instanceName] = 1;

            if (!$this->preg_array_key_exists($limits['api'], '/^' . $clusterName . '\.' . $instanceName . '\.' . $userId . '/')) {
                $apiKeysToCheck[$clusterName . '.each_instance|' . $instanceName . '.' . 'each_user|' . $userId] = 1;
                $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . 'each_user|' . $userId] = 1;
            }

            /* Per Ben, we want to increment every limit they hit, not stop after the first one */
            $overLimit = [];

            try {
                foreach (array_keys($apiKeysToCheck) as $key) {
                    foreach ($this->periods as $period) {
                        $_checkKey = $key . '.' . $period;

                        /** @noinspection PhpUndefinedMethodInspection */
                        if (array_key_exists($_checkKey, $limits['api'])) {
                            $_limit = $limits['api'][$_checkKey];

                            // For any cache drivers that make use of the cache prefix, we need to make sure we use
                            // a prefix that every instance can see.  But first, grab the current value

                            $dfCachePrefix = env('DF_CACHE_PREFIX');
                            putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
                            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

                            //  Increment counter
                            $cacheValue = $this->cache()->get($_checkKey, 0);

                            $cacheValue++;

                            if ($cacheValue > $_limit['limit']) {
                                // Push the name of the rule onto the over-limit array so we can give the name in the 429 error message
                                $overLimit[] = array_get($_limit, 'name', $_checkKey);
                            } else {
                                // Only increment the counter if we are not over the limit.  Fixes DFE-205
                                $this->cache()->put($_checkKey, $cacheValue, $_limit['period']);
                            }

                            // And now set it back
                            putenv('DF_CACHE_PREFIX' . '=' . $dfCachePrefix);
                            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $dfCachePrefix;
                        }
                    }
                }
            } catch (\Exception $_ex) {
                return ResponseFactory::getException(new InternalServerErrorException('Unable to update cache: ' . $_ex->getMessage()),
                    $request);
            }

            if ($overLimit) {
                /* Per Ben, we want to increment every limit they hit, not stop after the first one */
                return ResponseFactory::getException(new TooManyRequestsException('API limit(s) exceeded: ' . implode(', ', $overLimit)),
                    $request);
            }
        }

        return $next($request);
    }

    /**
     * Return the User ID from the authenticated session prepended with user_ or null if there is no authenticated user
     *
     * @param int $userId
     *
     * @return null|string
     */
    protected function getUser($userId)
    {
        return $this->makeKey('user', '1', $userId);
    }

    /**
     * Return the Role ID from the authenticated session prepended with role_ or null if there is no authenticated user
     * or the user has no roles assigned
     *
     * @param int $roleId
     *
     * @return null|string
     */
    protected function getRole($roleId)
    {
        return $this->makeKey('role', '2', $roleId);
    }

    /**
     * Return the API Key if set or null
     *
     * @param string string $apiKey
     *
     * @return null|string
     */

    protected function getApiKey($apiKey)
    {
        return $this->makeKey('api_key', 'apiName', $apiKey);
    }

    /**
     * Return the service name.  May return null if a list of services has been requested
     *
     * @param Request $request
     *
     * @return null|string
     */
    protected function getServiceName(Request $request)
    {
        /**
         * $request->input('service') does not have the service name.  Because we support both
         * /rest/service-name and /api/v2/service-name, we need to adjust what segment we actually use
         */
        $index = 3;

        if ($request->segment(1) == 'rest') {
            $index = 2;
        }

        /**
         * If we don't have at least 1 more segment than the value of index, there is no service.  Segments are
         * 1 based while count is 0 based
         */

        $value = null;

        if (count($request->segments()) >= $index) {
            $value = strtolower($request->segment($index));
        }

        return $this->makeKey('service', 'serviceName', $value);
    }

    /**
     * @param string $stub
     * @param string $testName
     * @param string|null $value
     * @param string|null $default
     *
     * @return null|string
     */
    protected function makeKey($stub, $testName, $value, $default = null)
    {
        if ($this->testing) {
            return $stub . ':' . $testName;
        }

        return null === $value ? $default : $stub . ':' . $value;
    }

    /**
     * Return true if at least one array_key exits and matches the supplied pattern
     *
     * @param $array Array to check
     * @param $pattern Perl Regex Pattern
     */
    protected function preg_array_key_exists($array, $pattern)
    {
        $matches = preg_grep($pattern, array_keys($array));

        return !empty($matches);
    }

}
