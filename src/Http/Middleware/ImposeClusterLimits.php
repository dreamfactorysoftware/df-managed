<?php namespace DreamFactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Managed\Contracts\ProvidesManagedLimits;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
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
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure                 $next
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

        $this->testing = config('api_limits_test', 'testing' == env('APP_ENV'));

        if (!empty($limits)) {
            $userName = $this->getUser(Session::getCurrentUserId());
            $userRole = $this->getRole(Session::getRoleId());
            $apiName = $this->getApiKey(Session::getApiKey());
            $clusterName = $_cluster->getClusterId();
            $instanceName = $_cluster->getInstanceName();
            $serviceName = $this->getServiceName($request);

            $limits = json_encode($limits);

            //TODO: Update dfe-console to properly set this, but right now, we want to touch as few files as possible
            if (!$this->testing) {
                $limits =
                    str_replace(['cluster.default', 'instance.default'],
                        [$clusterName, $clusterName . '.' . $instanceName],
                        $limits);
            }

            //  Convert to an array
            $limits = json_decode($limits, true);

            //  Build the list of API Hits to check
            $apiKeysToCheck = [$clusterName . '.' . $instanceName => 0];

            $serviceKeys = [];

            if ($serviceName) {
                $serviceKeys[$serviceName] = 0;
                $userRole && $serviceKeys[$serviceName . '.' . $userRole] = 0;
                $userName && $serviceKeys[$serviceName . '.' . $userName] = 0;
            }

            if ($apiName) {
                $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $apiName] = 0;

                $userRole && $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $apiName . '.' . $userRole] = 0;
                $userName && $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $apiName . '.' . $userName] = 0;

                foreach ($serviceKeys as $key => $value) {
                    $apiKeysToCheck[$apiName . '.' . $key] = $value;
                }
            }

            if ($clusterName) {
                $apiKeysToCheck[$clusterName] = 0;

                $userRole && $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $userRole] = 0;
                $userName && $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $userName] = 0;

                foreach ($serviceKeys as $key => $value) {
                    $apiKeysToCheck[$clusterName . '.' . $instanceName . '.' . $key] = $value;
                }
            }

            /* Per Ben, we want to increment every limit they hit, not stop after the first one */
            $overLimit = [];

            try {
                /** @noinspection PhpUndefinedMethodInspection */
                /** @type \Illuminate\Cache\Repository $_cache */
                $_cache = \Cache::store(env('DF_LIMITS_CACHE_STORE', ManagedDefaults::DEFAULT_LIMITS_STORE));

                foreach (array_keys($apiKeysToCheck) as $key) {
                    foreach ($this->periods as $period) {
                        $_checkKey = $key . '.' . $period;

                        /** @noinspection PhpUndefinedMethodInspection */
                        if (array_key_exists($_checkKey, $limits['api'])) {

                            // For any cache drivers that make use of the cache prefix, we need to make sure we use
                            // a prefix that every instance can see.  But first, grab the current value

                            $dfCachePrefix = env('DF_CACHE_PREFIX');
                            putenv('DF_CACHE_PREFIX' . '=' . 'df_limits');
                            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = 'df_limits';

                            /* There's a very good and valid reason why Cache::increment was not used.  If people
                             * would return the favor of asking why a particular section of code was done the way
                             * it was instead of assuming that I was just an idiot, they would have known that
                             * Cache::increment can not be used with file or database based caches, and the way that
                             * I had coded it was guaranteed to work across all cache drivers.  They would have also
                             * discovered that values are stored in the cache as integers, so I really don't understand
                             * why the limit was cast to a double
                             */
                            $cacheValue = $_cache->get($_checkKey, 0);
                            $cacheValue++;

                            if ($cacheValue > $limits['api'][$_checkKey]['limit']) {
                                // Push the name of the rule onto the over-limit array so we can give the name in the
                                // 429 error message
                                $overLimit[] = $limits['api'][$_checkKey]['name'];
                            } else {
                                // Only increment the counter if we are not over the limit.  Fixes DFE-205
                                $_cache->put($_checkKey, $cacheValue, $limits['api'][$_checkKey]['period']);
                            }

                            // And now set it back
                            putenv('DF_CACHE_PREFIX' . '=' . $dfCachePrefix);
                            $_ENV['DF_CACHE_PREFIX'] = $_SERVER['DF_CACHE_PREFIX'] = $dfCachePrefix;
                        }
                    }
                }
            } catch (\Exception $_ex) {
                return ResponseFactory::getException(new InternalServerErrorException('Unable to update cache: ' .
                    $_ex->getMessage()),
                    $request);
            }

            if ($overLimit) {
                /* Per Ben, we want to increment every limit they hit, not stop after the first one */
                return ResponseFactory::getException(new TooManyRequestsException('API limit(s) exceeded: ' .
                    implode(', ', $overLimit)),
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
        /*
         * $request->input('service') does not have the service name.  Because we support both
         * /rest/service-name and /api/v2/service-name, we need to adjust what segment we actually use
         *
         */
        $index = 3;

        if ($request->segment(1) == 'rest') {
            $index = 2;
        }

        /*
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
     * @param string      $stub
     * @param string      $testName
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
}
