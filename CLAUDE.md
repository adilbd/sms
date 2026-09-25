# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository layout

The whole application is a **single Laravel 11 app in `backend/`**. It serves three surfaces:

- `/`: public school website, server-rendered Blade and built for SEO (`routes/web.php`, `app/Http/Controllers/Web`, `resources/views/public`)
- `/admin/*`: admin SPA in Vue 3 + Pinia + Vue Router, mounted by `resources/views/admin.blade.php` and bundled by Vite from `resources/js/admin`
- `/api/*`: JSON API (`routes/api.php`, `app/Http/Controllers/Api`)

There used to be a separate `frontend/` Vue app, but it has been removed and the admin SPA now lives entirely in `backend/resources/js/admin`.

## Commands

Run all of these from `backend/`:

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # .env.example defaults to sqlite
php artisan migrate:fresh --seed                    # roles/permissions, admin user, sample public content
php artisan storage:link                            # public disk (post uploads) served from /storage

composer dev          # serve + queue:listen + pail logs + vite, all at once
npm run dev           # Vite only (HMR on :5173, strictPort)
npm run build         # production assets

php artisan test                                   # full suite (sqlite :memory:, see phpunit.xml)
php artisan test --filter=PublicSeoTest            # one class
php artisan test --filter=test_public_page_is_server_rendered_with_seo_tags
./vendor/bin/pint                                  # code style (Laravel Pint)
```

Docker alternative (run from the repo root): `docker compose up` starts MySQL (host port 3307, db `scms`), the app on :8000 and Vite on :5173.

Seeded admin login: `admin@sms.com` / `password`.

## Architecture notes

- **Auth**: the admin SPA uses Sanctum **bearer tokens** stored in `localStorage` (`resources/js/admin/services/api.js`), not cookie/session auth. A 401 response clears the token and redirects to `/admin/login`. The Vue router uses `createWebHistory('/admin/')`, and the catch-all `Route::view('/admin/{any?}', 'admin')` in `web.php` serves every admin path.
- **Roles and permissions**: Spatie Permission. The `role` and `permission` middleware aliases are registered in `bootstrap/app.php`. Roles (admin/teacher/student/parent) and kebab-case permissions like `view-students` come from `RolePermissionSeeder`. Every authenticated API controller declares per-action permissions through `HasMiddleware` and `Controller::resourcePermissions()`, except posts, which uses `role:admin`. `tests/Feature/ApiAuthorizationTest.php` fails if a route lacks a check, so new routes need a mapping or an explicit entry in its allowlist. Sections reuse the `*-classes` permissions, exam schedules reuse `*-exams`, and academic years are readable by any user but need `edit-settings` to change. Permissions are role-wide: nothing yet limits parents or teachers to their own students.
- **Shared public content**: news and events are a single `Post` model split by `type` (`Post::TYPE_NEWS` / `TYPE_EVENT`). `App\Services\PostService` holds the read queries. Both the Blade site (`Web\PostController`) and the unauthenticated mobile-app API (`Api\PublicContentController`, `/api/public/*`, output through `PostResource`) use it, so the two always return the same data. Put new public-content queries in the service, not in a controller. Contact form submissions work the same way: `ContactService` (its validation rules are `ContactService::RULES`) is shared by the web form and `/api/public/contact`.
- **Post model behavior**: slugs are generated automatically and kept unique. Setting `is_published` fills in `published_at`. The `published()` scope also requires `published_at <= now()`. Saving or deleting a post clears the cached `sitemap.xml` (cached for 1 hour in `SeoController`). Post bodies are sanitized HTML, written by the Tiptap editor in the admin SPA and stored as-is: `App\Services\PostService::create()`/`update()` sanitize with `App\Support\PostBody::sanitize()` (the `post_body` profile in `config/purifier.php`, via `mews/purifier`) and reject a body that is empty after sanitizing. `PostBody::plainText()` produces the excerpt and meta-description fallbacks. The public page renders the stored HTML directly (`{!! $post->body !!}` in `resources/views/public/posts/show.blade.php`); there is a single render path, so nothing re-sanitizes on read. Uploaded images go through `POST /api/posts/media` (admin only) to the `public` disk under `posts/{Y}/{m}/{uuid}.{ext}`, served from `/storage/...` (`php artisan storage:link`). A one-off data migration converted bodies written before this feature (Markdown, rendered with `Str::markdown` at read time) to sanitized HTML.
- **SEO**: every public page yields a `<x-seo>` component (`resources/views/components/seo.blade.php`) into the `seo` section of `layouts/public.blade.php`. The component handles the title, description, canonical URL (via `App\Support\Seo::canonical()`, which keeps only `?page=N` when N > 1), OG/Twitter tags and JSON-LD (built with `App\Support\SchemaOrg`). Site and organization values come from `config/seo.php` through `SEO_*` / `SCHOOL_*` env vars. `tests/Feature/PublicSeoTest.php` requires each public page to have the full SEO head and **exactly one `<h1>`**, and requires unknown or draft posts to return a noindex 404. `Web\PostController` also returns 404 for out-of-range pagination pages. Keep new public pages consistent with these rules.
- **Vite entries**: there are two, `resources/css/public.css` (the public site, Tailwind) and `resources/js/admin/main.js` (the admin). The `@` alias points to `resources/js/admin`.
- **Model naming quirks**: the class model is `App\Models\Classes` (table `classes`), because `Class` is a reserved word. The parent model is `ParentModel`. The class–section pivot is `ClassSection`.
- **Stub controllers**: these API controllers are empty 12-line stubs even though `api.php` registers routes for them: Teacher, Parent, Exam, ExamSchedule, ExamResult, FeeType, FeeStructure, FeePayment and Dashboard. For now, `index` returns `{"data": []}` and the other actions return 501. The extra routes (`dashboard/stats`, `dashboard/recent-activities`, `exams/{exam}/publish`, `exam-results/student/...`, `fee-payments/student/...`, `fee-payments/receipt/...`) point at methods that don't exist yet, so they return 500. The admin views that use them are mostly placeholders too.

## Development workflow

This repo ships a per-task agent harness under `.claude/`: slash commands, two subagents,
a smoke script and a `PreToolUse` gate hook. A task flows **spec → task file → branch →
code → test → smoke → commit → review → PR**.

**Who does what.** The main session (usually Opus) plans, asks the user questions,
delegates and reports. It does **not** write product code itself. Code comes from Sonnet:
the **`implementer`** subagent writes features, fixes and review follow-ups, and
**`test-runner`** fixes failing tests. Review comes from **`code-reviewer`** on Opus, so
the code is always reviewed by a different model from the one that wrote it.

- **`/new-task <spec>`** grooms the spec (problem, scope, acceptance criteria and
  **explicit test cases**), saves it to `docs/tasks/<slug>.md` (template in
  `docs/tasks/README.md`), and cuts `feat/<slug>` or `fix/<slug>` from `main`.
- **`/implement <task file | description>`** hands the task to `implementer` (Sonnet),
  checks the diff stays in scope, and reports. Follow-up fixes go back to `implementer`.
- **`/new-module <Model>`** plans a backend module and has `implementer` build it to the
  architecture guideline, using Subjects as the reference: repository interface and implementation, binding,
  service, FormRequests, resource, controller with permissions, routes and tests.
- **`/wrap-up [base]`** runs the end-of-task gate in this order:
  1. The **`test-runner`** subagent runs `php artisan test`, Pint on files added on this
     branch, and the hook classifier tests. It fixes failures until green.
  2. **Smoke:** `bash .claude/scripts/smoke.sh` seeds a throwaway SQLite database, boots
     the app on :8123, checks the public pages, `/admin`, the public API, an
     unauthenticated 401 and an admin login, then tears everything down. It never
     touches your real database.
  3. It updates the task file, then **asks** before committing.
  4. The **`code-reviewer`** subagent (read-only, runs on Opus, a different model from
     the Sonnet writer) reviews `base...HEAD` against `CLAUDE.md` and both guidelines, then
     records its verdict.
  5. If everything is green, it **asks** whether to push and open the PR.
- **`/pr-review [base]`** runs only the review step, for example after a fixup commit.
  It refuses on a dirty tree.
- Both review commands stop after **2 consecutive `changes-requested`** verdicts and hand
  the decision back to the user.

**Automated gates** (`.claude/hooks/gate.sh`, with commands classified by `classify.py`):
- `git commit` is denied on `main` or `master`. Use a task branch.
- `gh pr create` is denied unless `code-reviewer` recorded `pass` or `pass-with-nits` for
  the **exact current HEAD** on a clean tree. Receipts live in `.claude/.review-gate/`
  (gitignored, per machine).
- **`.claude/.review-gate/OVERRIDE`** disables both gates. Only the user creates it, by
  hand, outside Claude Code. Any tool call that tries to create or edit it is denied, and
  an agent must never suggest working around the gate by creating it.
- The hook is a cooperative guard, not a security boundary. Before changing
  `classify.py` or `gate.sh`, read their headers, then run
  `python3 .claude/hooks/test_classify.py`. Every case in it is a real bypass that was
  found and fixed.

## Architecture layers

Backend features follow Controller → Service → Repository interface → Eloquent repository. Controllers only handle HTTP, services hold the business rules and transactions, and repositories are the only place that builds queries. Every interface is bound in `RepositoryServiceProvider`. Subjects is the reference module. Legacy modules are converted only when they're otherwise being changed.

@docs/architecture-guidelines.md

## API responses

Every `/api` endpoint must follow the response format in the guideline below: resources wrapped in `data`, pagination under `meta`, standard error shapes and status codes, and field conventions. It also lists the legacy endpoints that don't follow it yet and how to migrate one together with the admin SPA views that use it.

@docs/api-response-guidelines.md
