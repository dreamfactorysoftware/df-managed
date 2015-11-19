<?php namespace DreamFactory\Managed\Facades;

use DreamFactory\Library\Utility\Facades\BaseFacade;
use DreamFactory\Managed\Providers\AuditServiceProvider;
use DreamFactory\Managed\Services\AuditingService;
use DreamFactory\Managed\Support\GelfLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * AuditingService facade
 *
 * @method static void setHost($host = GelfLogger::DEFAULT_HOST, $port = GelfLogger::DEFAULT_PORT)
 * @method static void setPort($port = GelfLogger::DEFAULT_PORT)
 * @method static AuditingService setMetadata(array $metadata)
 * @method static bool auditRequest(Request $request, $sessionData = null)
 * @method static GelfLogger getLogger()
 * @method static AuditingService setLogger(LoggerInterface $logger)
 */
class Audit extends BaseFacade
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /** @inheritdoc */
    protected static function getFacadeAccessor()
    {
        return AuditServiceProvider::IOC_NAME;
    }
}
