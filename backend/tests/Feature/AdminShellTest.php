<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Any /admin/... path serves the SPA shell; the router's catch-all route
     * (`:pathMatch(.*)*` in resources/js/admin/router/index.js) then redirects an unknown
     * one to the dashboard. The redirect itself is client-side: open /admin/foo/bar signed
     * in and expect to land on /admin/.
     */
    public function test_an_unknown_admin_path_still_serves_the_spa_shell(): void
    {
        $this->get('/admin/foo')->assertOk()->assertSee('id="app"', false);
        $this->get('/admin/foo/bar/baz')->assertOk();
    }

    public function test_the_router_has_a_catch_all_that_redirects_to_the_dashboard(): void
    {
        $router = file_get_contents(resource_path('js/admin/router/index.js'));

        $this->assertMatchesRegularExpression("/path: ':pathMatch\\(\\.\\*\\)\\*',\\s+name: 'NotFound',\\s+redirect: '\\/'/", $router);
    }
}
