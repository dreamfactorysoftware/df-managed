<?php namespace DreamFactory\Managed\Http\Controllers;

use DreamFactory\Managed\Exceptions\ManagedInstanceException;
use DreamFactory\Managed\Facades\Cluster;
use DreamFactory\Managed\Providers\ClusterServiceProvider;
use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class InstanceController extends BaseController
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /*** Constructor */
    public function __construct()
    {
        $this->middleware('access_check', ['except' => ['getFastTrack', 'getTableCount']]);
    }

    /**
     * Tell an instance to contact the console and get a fresh copy of it's configuration.
     * Endpoint handler for PUT /instance/refresh
     *
     * @return array
     */
    public function putRefresh()
    {
        $_result = true;

        // Get an instance of the Cluster Service Provider
        /** @type ClusterService $_cluster */
        $_cluster = ClusterServiceProvider::service();

        try {
            // Force the instance to pull the config from the console
            logger('[df-managed.instance-controller] instance configuration refresh initiated by console');

            $_cluster->setup();
        } catch (ManagedInstanceException $_ex) {
            $_result = false;
        }

        /** @noinspection PhpUndefinedMethodInspection */
        return Response::json(['success' => $_result]);
    }

    /**
     * Validates and processes a FastTrack login request. An HTTP redirect is performed at the end of the method.
     * Endpoint handler for GET /instance/fast-track
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function getFastTrack(Request $request)
    {
        //  Pass off to the cluster service to handle
        return Cluster::handleLoginRequest($request);
    }

    /**
     * Deletes all cached managed data for instance
     *
     * @return Response
     */
    public function deleteManagedDataCache()
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return Response::json(['success' => Cluster::deleteManagedDataCache()]);
    }

    /**
     * @return Response
     */
    public function getTableCount()
    {
        $_count = false;

        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $_tables = DB::connection()->getDoctrineSchemaManager()->listTables();

            $_count = count($_tables);
        } catch (\Exception $_ex) {
            /** @noinspection PhpUndefinedMethodInspection */
            Log::error('[dfe.instance-api-client.get-instance-table-count] error contacting instance database: ' .
                $_ex->getMessage());
        }

        /** @noinspection PhpUndefinedMethodInspection */
        return Response::json(['success' => true, 'count' => $_count]);
    }
}
