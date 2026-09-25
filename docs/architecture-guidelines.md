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
| FormRequests | `backend/app/Http/Requests/Subject/StoreSubjectRequest.php`, `UpdateSubjectRequest.php` |
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

### FormRequest: `app/Http/Requests/{Model}/{Action}{Model}Request.php`

All input validation lives here, not in controllers or services. Use `authorize()` for per-request checks that go beyond the route's `role:` / `permission:` middleware. Otherwise return `true`.

For unique rules on update, ignore the bound model: `Rule::unique('subjects', 'code')->ignore($this->route('subject'))`.

Controllers pass `$request->validated()`, never `$request->all()`, to the service.

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
- Never catch exceptions to return error arrays.

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

- Override `query()` for default ordering or eager loads, and `applyFilters()` for list filters. Group `OR` conditions in a nested `where(fn ...)` so they can't escape the other filters.
- Return models, collections or paginators. Never arrays or HTTP responses.
- No business rules, no `abort()`, no validation, no transactions. The service decides what a query result *means*.

## Base contract

`App\Repositories\Contracts\RepositoryInterface`, implemented by the abstract `App\Repositories\Eloquent\EloquentRepository`:

| Method | Behavior |
|--------|----------|
| `find(int $id): ?Model` | `null` if missing |
| `findOrFail(int $id): Model` | Throws `ModelNotFoundException`, which renders as a 404 |
| `paginate(array $filters = [], int $perPage = 15)` | Runs `query()`, then `applyFilters()`, then `paginate()` |
| `create(array $attributes): Model` | Mass assignment, so `$fillable` must be set on the model |
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
6. `Store{Model}Request` / `Update{Model}Request`
7. `{Model}Resource` (see the API guideline)
8. A thin controller and routes in `routes/api.php`
9. A service unit test and an endpoint feature test
10. The admin SPA view that uses it (`resources/js/admin`) reading the Resource shape

## Legacy code

These modules predate the pattern and query Eloquent directly:

| Code | Current state |
|------|---------------|
| `StudentController`, `ClassController`, `SectionController`, `AcademicYearController`, `AttendanceController`, `Api\PostController`, `AuthController` | Validate inline and query models in the controller |
| `PostService`, `ContactService` | Services exist but query Eloquent directly, with no repository |
| Stub controllers (teachers, parents, exams, fees, dashboard) | Not implemented. **Build them with this pattern from the start.** |

**How to migrate:** don't refactor a module just to apply the pattern. When a task changes a legacy module, convert **that whole module** in the same change: repository and interface, binding, service, FormRequests, resource, and a thin controller. At the same time, update its response shape and SPA consumers as described in the API guideline. Update the tables above and in the API guideline afterwards.

Helpers that are stateless and don't touch the database (for example `App\Support\Seo` and `App\Support\SchemaOrg`) stay as they are and don't need repositories.
