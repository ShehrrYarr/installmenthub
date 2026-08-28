<?php

namespace App\Providers;

use App\Models\Shop;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit binding (not just Shop::getRouteKeyName()) because Volt::route()
        // destinations have no controller/closure signature for Laravel's implicit
        // model binding to inspect — without this, {shop} reaches route middleware
        // (ResolveTenant) as a raw slug string instead of a resolved Shop model.
        Route::bind('shop', fn (string $value) => Shop::where('slug', $value)->firstOrFail());
    }
}
