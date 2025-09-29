<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        if (app()->environment('production')) {
        // Paksa skema & root URL sesuai APP_URL
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }
        URL::forceScheme('https');
    }
    }
}
