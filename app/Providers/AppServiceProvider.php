<?php

namespace App\Providers;

use App\Models\Shop;
use App\Support\SubPathHandleRequests;
use App\Support\SubPathUrlGenerator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // See App\Support\SubPathUrlGenerator and App\Support\SubPathHandleRequests
        // for why these are needed when the app is served from a sub-path
        // (e.g. APP_URL=http://host/sih). The UrlGenerator override fixes
        // every relative route(..., absolute: false) call app-wide (Breeze's
        // post-login redirects included); the HandleRequests override is
        // Livewire-specific and mostly redundant with it now, but harmless
        // to keep since it no-ops once the prefix is already present.
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            return new SubPathUrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url']
            );
        });

        $this->app->singleton(HandleRequests::class, SubPathHandleRequests::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Makes Laravel's *absolute* URL generation (url('/'), route(...))
        // consistent with APP_URL when served from a sub-path. Doesn't
        // affect Livewire's relative-URL update endpoint — see the
        // HandleRequests binding in register() for that.
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }

        // Explicit binding (not just Shop::getRouteKeyName()) because Volt::route()
        // destinations have no controller/closure signature for Laravel's implicit
        // model binding to inspect — without this, {shop} reaches route middleware
        // (ResolveTenant) as a raw slug string instead of a resolved Shop model.
        Route::bind('shop', fn (string $value) => Shop::where('slug', $value)->firstOrFail());
    }
}
