<?php namespace DreamFactory\Managed\Services;

use DreamFactory\Managed\Components\GelfMessage;
use DreamFactory\Managed\Contracts\ProvidesDataCollection;
use DreamFactory\Managed\Contracts\ProvidesManagedConfig;
use DreamFactory\Managed\Enums\AuditLevels;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Providers\AuditServiceProvider;
use DreamFactory\Managed\Support\GelfLogger;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Contains auditing methods for DFE
 */
class AuditingService implements ProvidesDataCollection
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type GelfLogger
     */
    protected $gelfLogger = null;
    /**
     * @type array
     */
    protected $metadata;
    /**
     * @type Application
     */
    protected $app;

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

    /** @inheritdoc */
    public function auditRequest(ProvidesManagedConfig $manager, Request $request, $sessionData = [])
    {
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            (empty($sessionData) || !is_array($sessionData)) && $sessionData = Session::all();
            array_forget($sessionData, ['_token', 'token', 'api_key', 'session_token', 'metadata']);

            //  Add in stuff for API request logging
            $this->log([
                'facility' => AuditServiceProvider::IOC_NAME,
                'dfe'      => $this->prepareMetadata($manager->getInstanceName(), $request, $manager->getConfig('audit', [])),
                'user'     => $sessionData,
            ],
                AuditLevels::INFO,
                $request,
                $manager->getClusterName());
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
     * @param string     $type    Optional type
     *
     * @return bool
     */
    protected function log($data = [], $level = AuditLevels::INFO, $request = null, $type = null)
    {
        $_message = null;

        try {
            $_request = $request ?: Request::createFromGlobals();
            $_data = array_merge($this->buildBasicEntry($_request), $data);
            $type && $_data['type'] = $type;

            $_message = new GelfMessage($_data);
            $_message
                ->setLevel($level)
                ->setShortMessage($_request->getMethod() . ' ' . $_request->getRequestUri())
                ->setFullMessage('DFE Audit | ' .
                    implode(', ',
                        $_data['source_ip']) .
                    ' | ' .
                    date('Y-m-d H-i-s', $_data['request_timestamp']));

            $_result = $this->getLogger()->send($_message);
        } catch (\Exception $_ex) {
            //  Completely ignore any issues
            $_result = false;
        }

        logger('audit ' . ($_result ? 'success' : 'failure') . ': ' . ($_message ? $_message->toJson() : 'no message made'));
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
        return $this->metadata
            ?: [
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
            'request_timestamp' => (double)$request->server->get('REQUEST_TIME_FLOAT', microtime(true)),
            'user_agent'        => $request->headers->get('user-agent', 'None'),
            'source_ip'         => $request->getClientIps(),
            'content_type'      => $request->getContentType(),
            'content_length'    => (int)$request->headers->get('Content-Length', 0),
            'api_key'           => $request->query->get('api_key', $request->headers->get(ManagedDefaults::DF_API_KEY)),
            'dfe'               => [],
            'host'              => $request->getHost(),
            'method'            => $request->getMethod(),
            'path_info'         => $request->getPathInfo(),
            'path_translated'   => $request->server->get('PATH_TRANSLATED'),
            'query'             => $request->query->all() ?: [],
        ];
    }

    /**
     * @return GelfLogger
     */
    public function getLogger()
    {
        return $this->gelfLogger;
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
        $this->gelfLogger = $logger ?: new GelfLogger();

        return $this;
    }

    /**
     * @param array $metadata
     *
     * @return $this
     */
    public function setMetadata(array $metadata)
    {
        $this->metadata = [];

        foreach ($metadata as $_key => $_value) {
            $this->metadata[str_replace('-', '_', $_key)] = $_value;
        }

        return $this;
    }
}
