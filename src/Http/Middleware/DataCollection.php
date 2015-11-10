<?php namespace Dreamfactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Managed\Facades\Managed;

class DataCollection
{
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
        try {
            Managed::auditRequest($request);
        } catch (\Exception $_ex) {
            //  Completely ignored...
        }

        return $next($request);
    }
}
