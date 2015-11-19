<?php namespace DreamFactory\Managed\Facades;

use DreamFactory\Library\Utility\Facades\BaseFacade;
use DreamFactory\Managed\Providers\AuditServiceProvider;
use DreamFactory\Managed\Services\BluemixService;

/**
 * BluemixService facade
 *
 * @method static array getDatabaseConfig($service = BluemixService::BM_DB_SERVICE_KEY, $index = BluemixService::BM_DB_INDEX, $subkey = BluemixService::BM_DB_CREDS_KEY)
 */
class Bluemix extends BaseFacade
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
