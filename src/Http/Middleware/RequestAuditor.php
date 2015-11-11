<?php namespace Dreamfactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Managed\Facades\Audit;

class RequestAuditor
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Send a copy of each incoming request out to the cluster logging system
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            Audit::auditRequest(app(), $request);
        } catch (\Exception $_ex) {
            //  Completely ignored...
        }

        return $next($request);
    }
}
