<?php

namespace App\Providers;

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
        //
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
