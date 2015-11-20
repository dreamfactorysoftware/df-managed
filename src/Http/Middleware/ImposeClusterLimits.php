<?php namespace DreamFactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;
use DreamFactory\Managed\Contracts\ProvidesManagedLimits;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        'minute' => DateTimeIntervals::SECONDS_PER_MINUTE,
        'hour'   => DateTimeIntervals::SECONDS_PER_HOUR,
        'day'    => DateTimeIntervals::SECONDS_PER_DAY,
        '7-day'  => 604800,
        '30-day' => 2592000,
    ]; // Why?  We have no need to know the number of seconds in each of these intervals!

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
    public function handle($request, Closure $next)
    {
        $_debug = env('APP_DEBUG', false);

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

        //  Convert to an array
        $limits = json_decode(json_encode($limits), true);
        $_debug && \Log::debug('Limits: ' . print_r($limits, true));

        $this->testing = config('api_limits_test', 'testing' == env('APP_ENV'));

        $_debug && \Log::debug('Service Name: ' . $this->getServiceName($request));

        if (!empty($limits) && null !== ($serviceName = $this->getServiceName($request))) {
            $userName = $this->getUser(Session::getCurrentUserId());
            $userRole = $this->getRole(Session::getRoleId());
            $apiName = $this->getApiKey(Session::getApiKey());
            $clusterName = $_cluster->getClusterId();

            $_debug && \Log::debug('User Name: ' . $userName . ' / User Role: ' . $userRole . ' / API Name: ' . $apiName . ' / Cluster Name: ' . $clusterName);

            //  Build the list of API Hits to check
            $apiKeysToCheck = ['cluster.default' => 0, 'instance.default' => 0];

            $serviceKeys[$serviceName] = 0;
            $userRole && $serviceKeys[$serviceName . '.' . $userRole] = 0;
            $userName && $serviceKeys[$serviceName . '.' . $userName] = 0;

            if ($apiName) {
                $apiKeysToCheck[$apiName] = 0;

                $userRole && $apiKeysToCheck[$apiName . '.' . $userRole] = 0;
                $userName && $apiKeysToCheck[$apiName . '.' . $userName] = 0;

                foreach ($serviceKeys as $key => $value) {
                    $apiKeysToCheck[$apiName . '.' . $key] = $value;
                }
            }

            if ($clusterName) {
                $apiKeysToCheck[$clusterName] = 0;

                $userRole && $apiKeysToCheck[$clusterName . '.' . $userRole] = 0;
                $userName && $apiKeysToCheck[$clusterName . '.' . $userName] = 0;

                foreach ($serviceKeys as $key => $value) {
                    $apiKeysToCheck[$clusterName . '.' . $key] = $value;
                }
            }

            $userName && $apiKeysToCheck[$userName] = 0;
            $userRole && $apiKeysToCheck[$userRole] = 0;

            /* Per Ben, we want to increment every limit they hit, not stop after the first one */
            $overLimit = false;

            $_debug && \Log::debug('Keys to check: ' . print_r(array_merge($apiKeysToCheck, $serviceKeys), true));

            try {
                foreach (array_keys(array_merge($apiKeysToCheck, $serviceKeys)) as $key) {
                    foreach ($this->periods as $period => $minutes) {
                        $_checkKey = $key . '.' . $period;

                        /** @noinspection PhpUndefinedMethodInspection */
                        if (array_key_exists($_checkKey, $limits['api'])) {
                            /* There's a very good and valid reason why Cache::increment was not used.  If people
                             * would return the favor of asking why a particular section of code was done the way
                             * it was instead of assuming that I was just an idiot, they would have known that
                             * Cache::increment can not be used with file or database based caches, and the way that
                             * I had coded it was guaranteed to work across all cache drivers.  They would have also
                             * discovered that values are stored in the cache as integers, so I really don't understand
                             * why the limit was cast to a double
                             */
                            $cacheValue = Cache::get($_checkKey, 0);
                            $cacheValue++;
                            Cache::put($_checkKey, $cacheValue, $limits['api'][$_checkKey]['period']);
                            if ($cacheValue > $limits['api'][$_checkKey]['limit']) {
                                $overLimit = true;
                            }
                        }
                    }
                }
            } catch (\Exception $_ex) {
                return ResponseFactory::getException(new InternalServerErrorException('Unable to update cache'),
                    $request);
            }

            if ($overLimit) {
                /* Per Ben, we want to increment every limit they hit, not stop after the first one */
                return ResponseFactory::getException(new TooManyRequestsException('Specified connection limit exceeded'),
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
        return $this->makeKey('service', 'serviceName', $request->input('service'));
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
