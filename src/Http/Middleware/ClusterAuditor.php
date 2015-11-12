<?php namespace DreamFactory\Managed\Http\Middleware;

use Closure;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Managed\Providers\AuditServiceProvider;
use Illuminate\Support\Facades\Log;

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
        logger('[middleware] ClusterAuditor');

        try {
            try {
                $_session = Session::getPublicInfo();
            } catch (\Exception $_ex) {
                $_session = Session::all();
            }

            app()->register(AuditServiceProvider::class);
            AuditServiceProvider::service()->logRequest($request, $_session);
        } catch (\Exception $_ex) {
            //  Completely ignored...
            /** @noinspection PhpUndefinedMethodInspection */
            Log::error('Exception during auditing: ' . $_ex->getMessage());
        }

        return $next($request);
    }
}
