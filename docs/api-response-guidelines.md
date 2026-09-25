# API Response Guidelines

These rules apply to every endpoint under `/api` (controllers in `backend/app/Http/Controllers/Api`). All new endpoints must follow them. Existing endpoints that don't are listed under [Legacy endpoints](#legacy-endpoints). Migrate them only as described there.

The format follows Laravel's API Resource conventions, which `/api/public/*` already uses (see `PublicContentController` and `PostResource`).

## Success responses

### Single resource: `show`, `store`, `update` and actions that return a resource

```json
{
  "data": { "id": 1, "name": "Class 1", "created_at": "2026-09-25T10:00:00+00:00" },
  "message": "Class created successfully"
}
```

- Return a `JsonResource`, never a bare model or array: `return new ClassResource($class);`
- `message` is optional. Include it for mutations the admin UI shows a toast for, using `->additional(['message' => '...'])`.
- `store` returns **201**: `(new ClassResource($class))->response()->setStatusCode(201)`
- Load relations in the repository (its `query()` for lists, or a dedicated method) and expose them in the resource with `whenLoaded()`.

### Paginated list: `index`

```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "per_page": 15, "to": 15, "total": 42, "path": "..." }
}
```

- Pass the service's paginator to the resource: `return SubjectResource::collection($this->subjects->list($filters, $perPage));`. Controllers never build queries (see [architecture-guidelines.md](architecture-guidelines.md)).
- Clamp `per_page`: `min(max((int) $request->query('per_page', 15), 1), 100)`. Never pass the raw request value to `paginate()`.
- Filters are query parameters with `snake_case` names matching the columns (`class_id`, `academic_year_id`, `search`, `is_active`). Validate them in an index FormRequest (see `IndexSubjectRequest`) and pass `$request->safe()->only([...])` to the service. The repository's `applyFilters()` checks each key with `filled()`, not `isset()` or `has()`, so empty values are ignored rather than treated as `false`.

### Non-paginated list: small lookups such as dropdown options

```json
{ "data": [ { "id": 1, "name": "A" } ] }
```

`return SectionResource::collection($sections);` with a `Collection`, not a paginator.

### Computed data: reports, stats, dashboards

Wrap everything in `data`, and use named keys inside it:

```json
{ "data": { "stats": { "total": 20, "present": 18, "percentage": 90.0 }, "attendances": [ ] } }
```

### Action with nothing to return: logout, change password, contact form

```json
{ "message": "Password changed successfully" }
```

Return 200, or 201 if something was created and the client doesn't need it back (for example, a contact submission).

### Delete

Return **204 No Content**: `return response()->noContent();`

## Error responses

Let Laravel's exception handler render errors. **Don't catch exceptions just to return JSON.** Every error has the same shape: a top-level `message`, plus `errors` for validation failures.

```json
{ "message": "The code has already been taken.", "errors": { "code": ["The code has already been taken."] } }
```

| Situation | How to produce it | Status |
|-----------|-------------------|--------|
| Invalid input | `$request->validate([...])` or a FormRequest | 422 |
| A problem the user can fix that isn't a simple rule (for example, wrong current password or bad credentials) | `throw ValidationException::withMessages(['field' => ['...']])` | 422 |
| Not logged in or bad token | `auth:sanctum` middleware | 401 |
| Wrong role or permission | Per-action `permission:` middleware on the controller (see [architecture-guidelines.md](architecture-guidelines.md)), or `abort(403)` | 403 |
| Record not found | Route model binding, or `findOrFail()`. `bootstrap/app.php` renders these as `{"message": "Record not found."}`, so model class names never reach the client | 404 |
| Conflicts with the current state (for example, deleting a class that still has students) | `abort(409, 'Class still has students.')` | 409 |
| Too many requests | `throttle:` middleware | 429 |
| Endpoint not implemented yet | `abort(501, 'Not implemented yet.')` | 501 |
| Unexpected failure | Let it throw | 500 |

Rules:
- **Never put `$e->getMessage()`, stack traces, SQL or class names in a response.** For a 500, let the handler render it. With `APP_DEBUG=false` it returns only `{"message": "Server Error"}`, and the details go to the log.
- For multi-step writes, use `DB::transaction(function () { ... })` in the service. It rolls back and rethrows on failure. Don't use `beginTransaction()` / `try` / `catch` / `return 500`.
- Error `message` strings are shown to end users. Write them as plain sentences.
- `bootstrap/app.php` renders every `/api/*` error as JSON, whether or not the client sends `Accept: application/json`, and guests get a 401 instead of a redirect. Tests should still use `getJson` / `postJson` / etc., and `SubjectApiTest::test_errors_are_json_even_without_accept_header` covers requests without the header.

## Status codes

| Code | Use for |
|------|---------|
| 200 | Successful read, update or action |
| 201 | Resource created (`store`, `bulkStore`) |
| 204 | Successful delete |
| 401, 403, 404, 409, 422, 429, 501 | See the error table above |

Don't return 200 with an error body, and don't return 500 for errors the user caused.

## Field conventions

- **Keys** are `snake_case`, matching the column names.
- **IDs** are integers. Foreign keys use the `_id` suffix (`class_id`).
- **Dates and times** use ISO 8601: `$this->published_at?->toIso8601String()`. Date-only columns use `Y-m-d` (`$this->date?->toDateString()`).
- **Booleans** are real `true` / `false`. Add a `boolean` cast to the model, and don't send `0` / `1`.
- **Money and marks**: decimal columns use the `decimal:2` cast and are sent as strings (`"1500.00"`), so clients never see floating-point errors.
- **Nullable fields** are always included, with the value `null`. Don't leave keys out depending on the value. The exceptions are relations (`whenLoaded`) and heavy fields that are only sent when requested (`PostResource::withBody()`).
- **Enums** are lowercase `snake_case` strings (`half_day`, `event`). Validate them with `Rule::in([...])`.
- **URLs** are absolute (`asset()`, `route()`, `url()`). Name them with a `_url` suffix (`cover_image_url`, `web_url`).
- **Never expose** passwords, remember tokens, API tokens (except in the login response), IP addresses or other internal-only columns. List fields explicitly in the resource instead of using `parent::toArray()`.

## Resources

The Subjects module (`SubjectResource`, `SubjectController`) is the reference example. It follows this guideline and [architecture-guidelines.md](architecture-guidelines.md).

- Create one resource per model in `backend/app/Http/Resources`, named `{Model}Resource`. Map the fields explicitly in `toArray()`.
- Nest related models through their own resources: `'class' => new ClassResource($this->whenLoaded('class'))`.
- Eager-load in the repository (see [architecture-guidelines.md](architecture-guidelines.md)). Never lazy-load inside a resource, because it causes N+1 queries on lists.
- If a list and a detail view need different fields, use one resource with an opt-in flag, as `PostResource::withBody()` does. Don't create a second resource.

## Tests

Every new or changed endpoint needs a feature test in `backend/tests/Feature` that checks the response shape:

```php
$this->getJson('/api/classes')
    ->assertOk()
    ->assertJsonStructure(['data' => [['id', 'name', 'code']], 'links', 'meta' => ['total']]);

$this->postJson('/api/classes', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'code']);

$this->deleteJson("/api/classes/{$class->id}")->assertNoContent();
```

## Consuming the API in the admin SPA

- Resource: `const { data } = await api.get(...); item.value = data.data`
- List: `items.value = data.data; pagination.value = data.meta`
- Validation errors: `error.response?.status === 422`, then `errors.value = error.response.data.errors`
- Other errors: show `error.response?.data?.message`

## Legacy endpoints

These endpoints were written before this guideline and don't follow it yet:

| Endpoint | What's different |
|----------|------------------|
| `index` on students, classes, sections, academic-years, attendances, posts | Return Laravel's flat paginator (`current_page`, `total`, ... at the top level, not under `meta`) |
| `show` on students, classes, sections, academic-years; all post endpoints | Return a bare model instead of `{ "data": ... }` |
| `store` / `update` on students, classes, sections, academic-years, attendances | `{message, data}` with a raw model instead of a resource |
| `destroy` on all resources except subjects | 200 with `{message}` instead of 204 |
| `students` store/update/destroy, `classes` destroy | Catch exceptions and return `error: $e->getMessage()` with a 500 |
| `attendances/report/{student}` | `{stats, attendances}` without the `data` wrapper |
| `login` / `me` | `{user, token}` and a bare user |
| Stub controllers (teachers, parents, exams, fees, dashboard) | `index` returns `{data: []}`. Other actions return a custom 501 body, and some routes have no method at all. |

**How to migrate:** when you change a legacy endpoint, convert it completely **in the same change**:
1. Update the controller.
2. Add or update its resource.
3. Update every admin SPA view that reads it. Search for its path in `backend/resources/js/admin`, for example `students.value = response.data.data` and `pagination = { current_page: response.data.current_page ... }` in `StudentList.vue`.
4. Update the tests (for example, `PublicApiTest` asserts on the admin posts endpoints).

Don't convert endpoints you aren't otherwise changing, and never leave the backend and the SPA out of sync.
