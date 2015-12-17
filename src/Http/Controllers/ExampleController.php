<?php namespace DreamFactory\Managed\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;

class ExampleController
{
    //******************************************************************************
    //* Traits
    //******************************************************************************

    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStatus()
    {
        return response()->json(['results']);
    }
}
