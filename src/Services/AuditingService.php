<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Managed\Contracts\ProvidesDataCollection;
use DreamFactory\Managed\Enums\AuditLevels;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Facades\Cluster;
use DreamFactory\Managed\Support\GelfLogger;
use DreamFactory\Managed\Support\GelfMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Contains auditing methods for DFE
 */
class AuditingService extends BaseService implements ProvidesDataCollection
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type string The first part of the short message
     */
    const MESSAGE_TAG = 'DFE Audit';

    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type GelfLogger The output logger
     */
    protected $gelfLogger = null;
    /**
     * @type array Raw metadata used as default when none specified with message
     */
    protected $metadata;

    //********************************************************************************
    //* Public Methods
    //********************************************************************************

    /**
     * @param string $host
     * @param int    $port
     *
     * @return $this
     */
    public function setHost($host = GelfLogger::DEFAULT_HOST, $port = GelfLogger::DEFAULT_PORT)
    {
        $this->getLogger()->setHost($host);
        $this->setPort($port);

        return $this;
    }

    /**
     * @param int $port
     *
     * @return $this
     */
    public function setPort($port = GelfLogger::DEFAULT_PORT)
    {
        $this->getLogger()->setPort($port);

        return $this;
    }

    /**
     * Logs an API request
     *
     * @param \Illuminate\Http\Request $request     The request
     * @param array                    $sessionData User session data, if any
     *
     * @return bool
     */
    public function logRequest(Request $request, $sessionData = [])
    {
        try {
            //  Get some session data if none given, then remove any unmentionables...
            if ((empty($sessionData) || !is_array($sessionData))) {
                /** @noinspection PhpUndefinedMethodInspection */
                $sessionData = Session::all();
            }

            array_forget($sessionData, ['_token', 'token', 'api_key', 'session_token', 'metadata']);

            $_cluster = Cluster::service();

            //  Add in stuff for API request logging
            $this->log([
                'dfe'  => $this->prepareMetadata($_cluster->getInstanceName(),
                    $request,
                    $_cluster->getConfig('audit', [])),
                'user' => $sessionData,
            ],
                AuditLevels::INFO,
                $request,
                $_cluster->getClusterId());
        } catch (\Exception $_ex) {
            //  Completely ignore any issues
        }
    }

    /**
     * Logs API requests to logging system
     *
     * @param array      $data    The data to log
     * @param int|string $level   The level, defaults to INFO
     * @param Request    $request The request, if available
     * @param string     $type    Optional type -- DFE fills with the source "cluster-id"
     *
     * @return bool
     */
    protected function log($data = [], $level = AuditLevels::INFO, $request = null, $type = null)
    {
        try {
            $_request = $request ?: Request::createFromGlobals();
            $_data = array_merge($this->buildBasicEntry($_request), $data);
            $type && $_data['type'] = $type;

            $_message = GelfMessage::make($_data,
                $level,
                $_request->getMethod() . ' ' . $_request->getRequestUri(),
                implode(' | ',
                    [
                        static::MESSAGE_TAG,
                        implode(', ', $_data['source_ip']),
                        date('Y-m-d H-i-s', $_data['request_timestamp']),
                    ]));

            $_result = $this->getLogger()->send($_message);
        } catch (\Exception $_ex) {
            //  Completely ignore any issues
            $_result = false;
        }

        if (env('DF_MANAGED_ENABLE_AUDIT_LOGGING', false)) {
            logger('audit ' .
                ($_result ? 'success' : 'failure') .
                ': ' .
                (isset($_message) ? $_message->toJson() : 'no message made'));
        }
    }

    /**
     * @param string                   $instanceId
     * @param \Illuminate\Http\Request $request
     * @param array                    $metadata
     *
     * @return array
     */
    protected function prepareMetadata($instanceId, Request $request, array $metadata = [])
    {
        $metadata = empty($metadata) ? $this->metadata : $metadata;

        return [
            'instance_id'       => $instanceId,
            'instance_owner_id' => array_get($metadata, 'owner-email-address'),
            'cluster_id'        => array_get($metadata, 'cluster-id', $request->server->get('DFE_CLUSTER_ID')),
            'app_server_id'     => array_get($metadata,
                'app-server-id',
                $request->server->get('DFE_APP_SERVER_ID')),
            'db_server_id'      => array_get($metadata, 'db-server-id', $request->server->get('DFE_DB_SERVER_ID')),
            'web_server_id'     => array_get($metadata,
                'web-server-id',
                $request->server->get('DFE_WEB_SERVER_ID')),
        ];
    }

    /**
     * @param Request|\Symfony\Component\HttpFoundation\Request $request
     *
     * @return array
     */
    protected function buildBasicEntry($request)
    {
        return [
            'api_key'           => $request->query->get('api_key', $request->headers->get(ManagedDefaults::DF_API_KEY)),
            'content_type'      => $request->getContentType(),
            'content_length'    => (int)$request->headers->get('Content-Length', 0),
            'dfe'               => [],
            'host'              => $request->getHost(),
            'method'            => $request->getMethod(),
            'path_info'         => $request->getPathInfo(),
            'path_translated'   => $request->server->get('PATH_TRANSLATED'),
            'query'             => $request->query->all() ?: [],
            'request_timestamp' => (double)$request->server->get('REQUEST_TIME_FLOAT', microtime(true)),
            'source_ip'         => $request->getClientIps(),
            'user_agent'        => $request->headers->get('user-agent', 'None'),
        ];
    }

    /**
     * @return GelfLogger
     */
    public function getLogger()
    {
        return $this->gelfLogger ?: $this->gelfLogger = new GelfLogger();
    }

    /**
     * Sets a logger instance on the object
     *
     * @param GelfLogger $logger
     *
     * @return $this
     */
    public function setLogger(GelfLogger $logger = null)
    {
        //  If none given, get a freshie from the getter
        $this->gelfLogger = $logger ?: $this->getLogger();

        return $this;
    }

    /**
     * @param array $metadata An array of RAW meta data points
     *
     * @return $this
     */
    public function setMetadata(array $metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }
}
