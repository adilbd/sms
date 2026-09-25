<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controllers\Middleware;

abstract class Controller
{
    /**
     * Permission middleware for a resource controller, using the RolePermissionSeeder
     * naming: view-/create-/edit-/delete-{resource}.
     *
     * $overrides maps an action to a different permission, adds extra actions
     * (e.g. ['publish' => 'publish-exams']), or maps an action to null to leave it
     * open to any authenticated user. Every action must end up in the result:
     * RouteAuthorizationTest fails for authenticated API routes without a check.
     *
     * @param  array<string, string|null>  $overrides
     * @return list<Middleware>
     */
    protected static function resourcePermissions(string $resource, array $overrides = []): array
    {
        $actions = array_merge([
            'index' => "view-{$resource}",
            'show' => "view-{$resource}",
            'store' => "create-{$resource}",
            'update' => "edit-{$resource}",
            'destroy' => "delete-{$resource}",
        ], $overrides);

        $byPermission = [];
        foreach (array_filter($actions) as $action => $permission) {
            $byPermission[$permission][] = $action;
        }

        return array_map(
            fn (string $permission, array $only) => new Middleware("permission:{$permission}", only: $only),
            array_keys($byPermission),
            $byPermission,
        );
    }
}
