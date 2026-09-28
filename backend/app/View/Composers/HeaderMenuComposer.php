<?php

namespace App\View\Composers;

use App\Services\MenuService;
use Illuminate\View\View;

/**
 * Shares the cached header menu tree with layouts.public. See MenuService::headerTree()
 * and resources/views/components/header-menu.blade.php.
 */
class HeaderMenuComposer
{
    public function __construct(private MenuService $menu) {}

    public function compose(View $view): void
    {
        $view->with('headerMenu', $this->menu->headerTree());
    }
}
