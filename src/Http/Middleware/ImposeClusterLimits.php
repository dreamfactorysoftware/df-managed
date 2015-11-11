<?php namespace Dreamfactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Services\ClusterService;
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
        '7-day'  => DateTimeIntervals::SECONDS_PER_DAY * 7,
        '30-day' => DateTimeIntervals::SECONDS_PER_DAY * 30,
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
    public function handle($request, Closure $next)
    {
        /** @type ClusterService $_cluster */
        $_cluster = ClusterServiceProvider::service();

        //  Get limits or bail
        if (empty($limits = $_cluster->getLimits()) || !is_array($limits)) {
            return $next($request);
        }

        //  Convert to an array
        $limits = json_decode(json_encode($limits), true);
        $this->testing = config('api_limits_test', 'testing' == env('APP_ENV'));

        if (!empty($limits) && null !== ($serviceName = $this->getServiceName())) {
            $userName = $this->getUser(Session::getCurrentUserId());
            $userRole = $this->getRole(Session::getRoleId());
            $apiName = $this->getApiKey(Session::getApiKey());
            $clusterName = $_cluster->getClusterId();

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

            try {
                foreach (array_keys(array_merge($apiKeysToCheck, $serviceKeys)) as $key) {
                    foreach ($this->periods as $period => $minutes) {
                        $_checkKey = $key . '.' . $period;

                        /** @noinspection PhpUndefinedMethodInspection */
                        if (array_key_exists($_checkKey, $limits['api']) &&
                            Cache::increment($_checkKey) > (double)$limits['api'][$_checkKey]['limit']
                        ) {
                            return ResponseFactory::getException(new TooManyRequestsException('Specified connection limit exceeded'),
                                $request);
                        }
                    }
                }
            } catch (\Exception $_ex) {
                return ResponseFactory::getException(new InternalServerErrorException('Unable to update cache'),
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
     * @return null|string
     */
    protected function getServiceName()
    {
        if ($this->testing) {
            return 'service:serviceName';
        }

        if (empty($_service = app('router')->input('service'))) {
            return null;
        }

        return 'service:' . $_service;
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
