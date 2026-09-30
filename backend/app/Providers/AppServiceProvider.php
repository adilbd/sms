<?php

namespace App\Providers;

use App\Services\InstituteSettingsService;
use App\Support\Mobile;
use App\View\Composers\HeaderMenuComposer;
use App\View\Composers\InstituteComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // 5 sign-in attempts a minute per login identifier and IP. The identifier is
        // normalized (case, +88 prefix) so variants of one login share a bucket.
        RateLimiter::for('login', function (Request $request) {
            $login = $request->input('login', $request->input('email'));
            $login = is_string($login) ? Str::lower((string) Mobile::normalize($login)) : '';

            return Limit::perMinute(5)->by($login.'|'.$request->ip());
        });

        View::composer('layouts.public', HeaderMenuComposer::class);
        View::composer(['layouts.public', 'public.*', 'components.seo', 'admin'], InstituteComposer::class);
    }
}
