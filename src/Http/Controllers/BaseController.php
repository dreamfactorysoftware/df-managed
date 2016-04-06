<?php namespace DreamFactory\Managed\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;

/** The base df-managed controller */
abstract class BaseController extends Controller
{
    //******************************************************************************
    //* Traits
    //******************************************************************************

    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
