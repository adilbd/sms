<?php

namespace App\Providers;

use App\Services\InstituteSettingsService;
use App\View\Composers\HeaderMenuComposer;
use App\View\Composers\InstituteComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // profile()/all() are read many times per request (the view composer, SchemaOrg,
        // /api/public/school, ...). Scoping it to the request lets the service memoize
        // both, instead of hitting the cache store repeatedly.
        $this->app->scoped(InstituteSettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.public', HeaderMenuComposer::class);
        View::composer(['layouts.public', 'public.*', 'components.seo', 'admin'], InstituteComposer::class);
    }
}
