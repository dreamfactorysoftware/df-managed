<?php namespace Dreamfactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Managed\Facades\Audit;

class ClusterAuditor
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
            try {
                $_session = Session::getPublicInfo();
            } catch (\Exception $_ex) {
                $_session = Session::all();
            }

            Audit::auditRequest($request, $_session);
        } catch (\Exception $_ex) {
            //  Completely ignored...
        }

        return $next($request);
    }
}
