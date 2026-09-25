# Architecture Guidelines: Service/Repository Pattern

Backend features use four layers:

```
Controller  →  Service  →  Repository interface  →  Eloquent repository  →  Model
(HTTP)         (business     (contract the           (the only place
               rules)        service depends on)     that builds queries)
```

All new modules must follow this pattern, and existing modules are migrated to it over time (see [Legacy code](#legacy-code)). Responses from these layers must also follow [api-response-guidelines.md](api-response-guidelines.md).

**The Subjects module is the reference implementation.** Copy it when you build a new module:

| Layer | File |
|-------|------|
| Controller | `backend/app/Http/Controllers/Api/SubjectController.php` |
| FormRequests | `backend/app/Http/Requests/Subject/IndexSubjectRequest.php`, `StoreSubjectRequest.php`, `UpdateSubjectRequest.php`, `Concerns/NormalizesSubjectCode.php` |
| Routes | `Route::apiResource('subjects', ...)` in `backend/routes/api.php` |
| Service | `backend/app/Services/SubjectService.php` |
| Repository interface | `backend/app/Repositories/Contracts/SubjectRepositoryInterface.php` |
| Eloquent repository | `backend/app/Repositories/Eloquent/SubjectRepository.php` |
| Binding | `backend/app/Providers/RepositoryServiceProvider.php` |
| Resource | `backend/app/Http/Resources/SubjectResource.php` |
| Tests | `backend/tests/Feature/SubjectApiTest.php`, `backend/tests/Unit/SubjectServiceTest.php` |

## Layers and responsibilities

### Controller: `app/Http/Controllers/{Api,Web}/{Model}Controller.php`

Handles HTTP only. Each action:
1. Receives a FormRequest (for writes) or `Request` (for reads with query filters).
2. Calls **one** service method.
3. Returns a Resource (API) or a view (web).

**Allowed:** route model binding (`Subject $subject`), reading query parameters, clamping `per_page`, and choosing the status code.

**Not allowed:** Eloquent queries (`Model::where`, `->save()`, relationship queries), `DB::`, business rules, `try/catch` that turns exceptions into responses, and injecting repositories directly. Controllers talk to services only.

The controller gets the service through constructor injection:

```php
public function __construct(private SubjectService $subjects) {}
```

**Authorization is required on every action.** `auth:sanctum` only proves who the user is. Implement `HasMiddleware` and build the middleware with `Controller::resourcePermissions()`. It maps `index`/`show` → `view-{resource}`, `store` → `create-`, `update` → `edit-` and `destroy` → `delete-`, using the `RolePermissionSeeder` names:

```php
class SubjectController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return static::resourcePermissions('subjects');
    }
}
```

Pass overrides for non-standard names and **every extra action**:

```php
return static::resourcePermissions('attendance', [
    'store' => 'mark-attendance',
    'bulkStore' => 'mark-attendance',
    'studentReport' => 'view-attendance',
]);
```

Mapping an action to `null` leaves it open to any signed-in user. Do that only on purpose, and add the route to `ApiAuthorizationTest::OPEN_TO_ANY_USER`. That test fails for any authenticated `/api` route without a `permission:` or `role:` check. If a module needs a permission that doesn't exist yet, add it to `RolePermissionSeeder`.

Permissions are role-wide. `view-students` lets a parent list **every** student, not just their own children. Rules scoped to particular records belong in the service or a policy, and none exist yet.

**Routes:** constrain resource ids to digits, for example `Route::apiResource('subjects', ...)->where(['subject' => '[0-9]+'])`. MySQL casts `'1abc'` to `1` when comparing it with an integer column, so without the constraint `/api/subjects/1abc` would act on subject 1.

### FormRequest: `app/Http/Requests/{Model}/{Action}{Model}Request.php`

All input validation lives here, not in controllers or services. `authorize()` may return `true` **only** because the controller's permission middleware already protects the action (say so in a comment). Use it for per-record checks that middleware can't express, such as "teachers may edit only their own classes".

Validate every field fully: lengths (`max:255` for string columns), numeric bounds (`min` / `max`), and enum-like values (`Rule::in(Model::TYPES)`). Normalize values whose comparison differs between databases in `prepareForValidation()`. Subjects uppercases `code` because MySQL compares strings case-insensitively and SQLite doesn't (see `Concerns/NormalizesSubjectCode`).

For unique rules on update, ignore the bound model: `Rule::unique('subjects', 'code')->ignore($this->route('subject'))`.

Controllers pass `$request->validated()` (or `$request->safe()->only([...])` for list filters), never `$request->all()`, to the service. Validate list filters too: an unvalidated `?search[]=x` reaches the query as an array and causes a 500.

For columns that are `NOT NULL` with a database default, use `sometimes`, not `nullable`. `nullable` lets an explicit `null` through to the insert, which then fails with a 500.

### Service: `app/Services/{Model}Service.php`

The business layer. Services:
- Enforce business rules, for example "a subject used in exam schedules can't be deleted".
- Coordinate work across several repositories.
- Own transactions: `DB::transaction(fn () => ...)`. Any write that touches more than one table goes in one.
- Fire events and invalidate caches.
- Hold logic shared between the web and API sides. For example, the Blade site and `/api/public` both read posts through `PostService`, so they always agree.

Rules:
- Depend only on repository **interfaces**, injected through the constructor. Never on concrete repositories or Eloquent models' static query methods.
- Accept plain arrays (validated data) and models, and return models, collections or paginators. **Never** accept a `Request` or return a `Response` or Resource.
- Signal failures with exceptions that the handler renders according to the API guideline:
  - `abort(409, '...')` for a state conflict
  - `abort(403)` when an authorization rule lives in the service
  - `throw ValidationException::withMessages([...])` for a problem the user can fix
- Enforce rules that depend on saved state in the service, not in the FormRequest. For example, `pass_marks <= total_marks` is checked against the model with the input applied (`(clone $subject)->fill($data)`), so a partial update that sends only one of the two fields is still checked.
- Before deleting a soft-deletable model, check every table that references it and `abort(409)` if any rows exist. Foreign-key restrictions don't fire on soft deletes.
- Never catch exceptions to return error arrays. The one exception is translating a database error into the error the user would otherwise have seen. For example, catch `UniqueConstraintViolationException` from a concurrent duplicate and rethrow it as a `ValidationException` for that field (see `SubjectService::withUniqueCode()`).

### Repository interface: `app/Repositories/Contracts/{Model}RepositoryInterface.php`

The contract a service depends on. It extends the base `RepositoryInterface` and declares one method for each extra query the service needs:

```php
interface SubjectRepositoryInterface extends RepositoryInterface
{
    public function isUsedInExamSchedules(Subject $subject): bool;
}
```

- Name methods for intent (`isUsedInExamSchedules`, `activeForClass`, `findBySlug`), not for SQL (`whereClassIdAndActive`).
- Type-hint the concrete model in parameters and return types for model-specific methods.
- Don't add methods speculatively. Add them only when a service needs them.

### Eloquent repository: `app/Repositories/Eloquent/{Model}Repository.php`

The **only** layer that builds queries. It extends `EloquentRepository`, implements its interface and sets `$model`.

- Override `query()` for default ordering or eager loads. Start from `parent::query()` so the class still works if `$model` changes, and end the ordering with a unique column (`->orderBy('id')`) so pagination is stable.
- Override `applyFilters()` for list filters. Check each key with `filled()`. Group `OR` conditions in a nested `where(fn ...)` so they can't escape the other filters.
- Route model binding resolves models without going through the repository, so `query()` eager loads only apply to lists. When a single-record response needs relations, load them in the service (`$subject->load('sections')`).
- Return models, collections or paginators. Never arrays or HTTP responses.
- No business rules, no `abort()`, no validation, no transactions. The service decides what a query result *means*.

## Base contract

`App\Repositories\Contracts\RepositoryInterface`, implemented by the abstract `App\Repositories\Eloquent\EloquentRepository`:

| Method | Behavior |
|--------|----------|
| `find(int $id): ?Model` | `null` if missing |
| `findOrFail(int $id): Model` | Throws `ModelNotFoundException`, which renders as a 404 |
| `paginate(array $filters = [], int $perPage = 15)` | Runs `query()`, then `applyFilters()`, then `paginate()` |
| `create(array $attributes): Model` | Mass assignment, so `$fillable` must be set on the model. Refreshes the model so database column defaults appear in the response |
| `update(Model $model, array $attributes): Model` | Returns the same, updated instance |
| `delete(Model $model): void` | Respects `SoftDeletes` |

Extend the base contract only for operations nearly every model needs. Everything else goes on the model-specific interface.

## Binding

Every interface is bound to its implementation in `App\Providers\RepositoryServiceProvider::$bindings`, which is registered in `bootstrap/providers.php`:

```php
public array $bindings = [
    SubjectRepositoryInterface::class => SubjectRepository::class,
];
```

A missing binding fails at runtime with `Target [App\Repositories\Contracts\XRepositoryInterface] is not instantiable`.

## Testing

- **Service unit tests** (`tests/Unit/{Model}ServiceTest.php`): mock the repository **interface** and test the business rules without a database. Extend `Tests\TestCase`, which boots the container so `$this->mock()` works:
  ```php
  $this->mock(SubjectRepositoryInterface::class, function (MockInterface $mock) use ($subject) {
      $mock->shouldReceive('isUsedInExamSchedules')->once()->with($subject)->andReturn(true);
      $mock->shouldNotReceive('delete');
  });
  ```
- **Endpoint feature tests** (`tests/Feature/{Model}ApiTest.php`): send real HTTP requests through `getJson` / `postJson` against the in-memory SQLite database. Cover auth, the response shape, validation (422), not found (404) and each status code the endpoint can return.
- **Repository tests:** only for non-trivial queries (complex filters, aggregates), as feature tests against the database.
- Give each model a factory in `database/factories` so tests don't hand-build rows.

## Checklist for a new module

1. Model with `$fillable`, `$casts` and `HasFactory`, plus a factory
2. `Contracts/{Model}RepositoryInterface` extending `RepositoryInterface`
3. `Eloquent/{Model}Repository` extending `EloquentRepository`
4. A binding in `RepositoryServiceProvider::$bindings`
5. `{Model}Service` with constructor-injected interface(s)
6. `Index{Model}Request` (list filters), `Store{Model}Request` / `Update{Model}Request`
7. `{Model}Resource` (see the API guideline)
8. A thin controller with per-action permission middleware, and routes in `routes/api.php` with a numeric id constraint
9. A service unit test and an endpoint feature test, including a test that a role without the permission gets 403
10. The admin SPA view that uses it (`resources/js/admin`) reading the Resource shape

## Legacy code

These modules predate the pattern and query Eloquent directly:

| Code | Current state |
|------|---------------|
| `StudentController`, `ClassController`, `SectionController`, `AcademicYearController`, `AttendanceController`, `Api\PostController`, `AuthController` | Validate inline and query models in the controller. Permission checks are in place |
| `PostService`, `ContactService` | Services exist but query Eloquent directly, with no repository |
| `Web\ContactController` | Validates inline with `$request->validate(ContactService::RULES)` instead of a FormRequest |
| Stub controllers (teachers, parents, exams, fees, dashboard) | Not implemented. **Build them with this pattern from the start.** |

**How to migrate:** don't refactor a module just to apply the pattern. When a task changes a legacy module, convert **that whole module** in the same change: repository and interface, binding, service, FormRequests, resource, and a thin controller. At the same time, update its response shape and SPA consumers as described in the API guideline. Update the tables above and in the API guideline afterwards.

Helpers that are stateless and don't touch the database (for example `App\Support\Seo` and `App\Support\SchemaOrg`) stay as they are and don't need repositories.
