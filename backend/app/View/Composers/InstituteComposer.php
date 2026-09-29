<?php

namespace App\View\Composers;

use App\Services\InstituteSettingsService;
use Illuminate\View\View;

/**
 * Shares the institute's public profile (name, logo, contact, address) with the
 * public layout, every public.* page, the shared <x-seo> component and the admin
 * shell. See InstituteSettingsService::profile().
 */
class InstituteComposer
{
    public function __construct(private InstituteSettingsService $institute) {}

    public function compose(View $view): void
    {
        $view->with('institute', $this->institute->profile());
    }
}
