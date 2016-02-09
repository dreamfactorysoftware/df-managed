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
        //  Don't log console requests
        if (!env('DF_IS_VALID_CONSOLE_REQUEST', false)) {
            try {
                try {
                    $_session = Session::getPublicInfo();
                } catch (\Exception $_ex) {
                    $_session = Session::all();
                }

                //  Register the auditing service
                app()->register(AuditServiceProvider::class);

                //  We use provider's service() method because Facades aren't loaded yet
                AuditServiceProvider::service()->logRequest($request, $_session);
            } catch (\Exception $_ex) {
                //  Completely ignored...
                /** @noinspection PhpUndefinedMethodInspection */
                Log::error('Exception during auditing: ' . $_ex->getMessage());
            }
        }

        return $next($request);
    }
}
