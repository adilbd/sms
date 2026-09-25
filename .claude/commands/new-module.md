---
description: Scaffold a backend module (Controller → Service → Repository interface) following the Subjects reference
argument-hint: "<Model> [notes about fields/rules]"
---

# /new-module — scaffold a backend module

You (the main session) plan; the **`implementer`** subagent (Sonnet) writes the code. Do the
"Before writing code" steps yourself, ask the user about anything unclear, then spawn
`implementer` with the module name, the answers, and the checklist below. Relay its report
and the test summary.

The module to build is **$ARGUMENTS**, following
`docs/architecture-guidelines.md` (layers, authorization, validation) and
`docs/api-response-guidelines.md` (response shapes). The Subjects module is the canonical
example; open each Subjects file as you create its counterpart rather than working from
memory.

## Before writing code

1. **Read the existing pieces.** Look at the model, migration and factory, any stub
   controller in `backend/app/Http/Controllers/Api/`, its routes in
   `backend/routes/api.php`, the permissions for this resource in
   `RolePermissionSeeder`, and any admin SPA view under `backend/resources/js/admin/views`
   that already calls the endpoint.
2. **List the tables that reference this model.** Delete needs a 409 guard for each one
   when the model soft-deletes.
3. If the fields, validation rules or business rules aren't clear from the migration and
   the notes, ask before guessing. Validation rules become the API contract.

## Create, in order (checklist from the architecture guideline)

1. Model: `$fillable`, `$casts`, `$attributes` defaults that mirror the migration,
   constants for enum-like values, and `HasFactory`. Add a factory if one is missing.
2. `Repositories/Contracts/{Model}RepositoryInterface` extending `RepositoryInterface`,
   with only the extra methods the service needs.
3. `Repositories/Eloquent/{Model}Repository` extending `EloquentRepository`. Its
   `query()` starts from `parent::query()` and ends with `orderBy('id')`, and
   `applyFilters()` checks each filter with `filled()`.
4. A binding in `RepositoryServiceProvider::$bindings`.
5. `Services/{Model}Service`: business rules, `DB::transaction()` for multi-table writes,
   and 409 guards for references before deleting.
6. `Requests/{Model}/Index|Store|Update{Model}Request`. Use `sometimes` for `NOT NULL`
   columns, plus `max`, numeric bounds and `Rule::in`. Validate list filters.
7. `Resources/{Model}Resource` with explicit fields and ISO 8601 dates.
8. A thin controller implementing `HasMiddleware` with
   `static::resourcePermissions('<resource>', [...extra actions...])`. Replace the stub
   if one exists.
9. Routes: `->where(['<param>' => '[0-9]+'])` on the resource. Map every extra route
   action in the permissions.
10. Any missing permissions, added to `RolePermissionSeeder` for the right roles.
11. Tests:
    - `tests/Unit/{Model}ServiceTest` (mocked repository interface).
    - `tests/Feature/{Model}ApiTest`, covering the list shape, filters, 201/422/404/204/409
      and a 403 for a role without the permission.
12. SPA: if an admin view already reads this endpoint in the old shape, update it to read
    `data` and `meta` in the same change.

## Verify

```
cd backend
php artisan test && ./vendor/bin/pint --test <new files>
php artisan route:list --path=<resource> -v   # shows the permission middleware per route
```

`ApiAuthorizationTest` must stay green. If it fails, an action has no permission mapped.
Then update the legacy tables in both guideline docs if this converted a legacy module.
Finish with `/wrap-up`.
