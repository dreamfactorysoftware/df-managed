<?php namespace DreamFactory\Managed\Contracts;

use Illuminate\Http\Request;

interface ProvidesDataCollection
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Logs API requests to logging system
     *
     * @param Request $request     The request
     * @param array   $sessionData Any session data to log
     *
     * @return bool
     */
    public function logRequest(Request $request, $sessionData = []);
}
