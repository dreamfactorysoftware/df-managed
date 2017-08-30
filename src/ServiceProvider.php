<?php
namespace DreamFactory\Managed;



use DreamFactory\Managed\Http\Middleware\ClusterAuditor;
use Route;
use Event;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    use ServiceDocBuilder;

    /**
     * @inheritdoc
     */
    public function boot()
    {
        $this->addMiddleware();
    }




    /**
     * Register any middleware aliases.
     *
     * @return void
     */
    protected function addMiddleware()
    {
        // the method name was changed in Laravel 5.4
        if (method_exists(\Illuminate\Routing\Router::class, 'aliasMiddleware')) {
            Route::aliasMiddleware('df.evaluate_limits', EvaluateLimits::class);
        } else {
            /** @noinspection PhpUndefinedMethodInspection */
            Route::middleware('df.evaluate_limits', EvaluateLimits::class);

        }
        Route::pushMiddlewareToGroup('df.api', 'df.evaluate_limits');
    }



}
