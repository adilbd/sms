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
- **Roles and permissions**: Spatie Permission. The `role` and `permission` middleware aliases are registered in `bootstrap/app.php`. Roles (admin/teacher/student/parent) and kebab-case permissions like `view-students` come from `RolePermissionSeeder`. Right now only `posts` uses role middleware (`role:admin`). Every other authenticated API resource just requires `auth:sanctum`.
- **Shared public content**: news and events are a single `Post` model split by `type` (`Post::TYPE_NEWS` / `TYPE_EVENT`). `App\Services\PostService` holds the read queries. Both the Blade site (`Web\PostController`) and the unauthenticated mobile-app API (`Api\PublicContentController`, `/api/public/*`, output through `PostResource`) use it, so the two always return the same data. Put new public-content queries in the service, not in a controller. Contact form submissions work the same way: `ContactService` (its validation rules are `ContactService::RULES`) is shared by the web form and `/api/public/contact`.
- **Post model behavior**: slugs are generated automatically and kept unique. Setting `is_published` fills in `published_at`. The `published()` scope also requires `published_at <= now()`. Saving or deleting a post clears the cached `sitemap.xml` (cached for 1 hour in `SeoController`). Post bodies are Markdown and are rendered with HTML stripped.
- **SEO**: every public page yields a `<x-seo>` component (`resources/views/components/seo.blade.php`) into the `seo` section of `layouts/public.blade.php`. The component handles the title, description, canonical URL (via `App\Support\Seo::canonical()`, which keeps only `?page=N` when N > 1), OG/Twitter tags and JSON-LD (built with `App\Support\SchemaOrg`). Site and organization values come from `config/seo.php` through `SEO_*` / `SCHOOL_*` env vars. `tests/Feature/PublicSeoTest.php` requires each public page to have the full SEO head and **exactly one `<h1>`**, and requires unknown or draft posts to return a noindex 404. `Web\PostController` also returns 404 for out-of-range pagination pages. Keep new public pages consistent with these rules.
- **Vite entries**: there are two, `resources/css/public.css` (the public site, Tailwind) and `resources/js/admin/main.js` (the admin). The `@` alias points to `resources/js/admin`.
- **Model naming quirks**: the class model is `App\Models\Classes` (table `classes`), because `Class` is a reserved word. The parent model is `ParentModel`. The class–section pivot is `ClassSection`.
- **Stub controllers**: these API controllers are empty 12-line stubs even though `api.php` registers routes for them: Teacher, Parent, Exam, ExamSchedule, ExamResult, FeeType, FeeStructure, FeePayment and Dashboard. For now, `index` returns `{"data": []}` and the other actions return 501. The extra routes (`dashboard/stats`, `dashboard/recent-activities`, `exams/{exam}/publish`, `exam-results/student/...`, `fee-payments/student/...`, `fee-payments/receipt/...`) point at methods that don't exist yet, so they return 500. The admin views that use them are mostly placeholders too.

## Architecture layers

Backend features follow Controller → Service → Repository interface → Eloquent repository. Controllers only handle HTTP, services hold the business rules and transactions, and repositories are the only place that builds queries. Every interface is bound in `RepositoryServiceProvider`. Subjects is the reference module. Legacy modules are converted only when they're otherwise being changed.

@docs/architecture-guidelines.md

## API responses

Every `/api` endpoint must follow the response format in the guideline below: resources wrapped in `data`, pagination under `meta`, standard error shapes and status codes, and field conventions. It also lists the legacy endpoints that don't follow it yet and how to migrate one together with the admin SPA views that use it.

@docs/api-response-guidelines.md
