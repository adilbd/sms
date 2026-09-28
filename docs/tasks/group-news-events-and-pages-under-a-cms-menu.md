# Group News, Events and Pages under a CMS menu

Status: done
Branch: feat/group-news-events-and-pages-under-a-cms-menu

## Problem
The admin sidebar has a single flat "News & Events" item (`/posts`), where admins pick the type from a filter and a select. Admins want News and Events as separate menu items. They also want a new "Pages" section for standalone public content pages such as policies or facilities. All three should sit under one collapsible "CMS" group. No page model exists today, so this task adds a full Pages module built to the Subjects pattern: admin CRUD with the existing rich text editor, and published pages served on the public site with the full SEO head and a sitemap entry.

## Scope
- In:
  - **Sidebar** (`views/Layout.vue`): replace the "News & Events" item with a collapsible **CMS** group containing **News**, **Events** and **Pages**.
    - The group expands automatically when the current route is one of its children, and the user can toggle it.
    - The active child is highlighted.
    - The rest of the menu is unchanged.
  - **Admin routes** (`router/index.js`):
    - `/news`, `/news/create`, `/news/:id/edit` and `/events`, `/events/create`, `/events/:id/edit` reuse `PostList.vue` / `PostForm.vue`, with the post type given through route `meta`.
    - `/pages`, `/pages/create`, `/pages/:id/edit` use new views.
    - The old `/posts` paths redirect: `/posts` goes to `/news`, and `/posts/:id/edit` goes to the matching news edit route.
  - **PostList / PostForm**:
    - The list is fixed to the route's type: it always sends `?type=news|event` and drops the type filter.
    - "Add", "Edit", "Cancel" and the post-save redirect stay within that section, and the page title and headings say "News" or "Events".
    - The form pre-selects the route's type on create and doesn't show a type selector. Changing the type of an existing post is out of scope.
    - Existing post API endpoints and shapes are unchanged.
  - **Pages module**, following `docs/architecture-guidelines.md`, with Subjects and Posts as references:
    - Migration and `App\Models\Page` with `HasFactory` and a factory. Columns:
      - `title` (string 255)
      - `slug` (string 255, unique, auto-generated from the title, ASCII only as `Post` does, kept unique on collision)
      - `body` (longText, sanitized HTML)
      - `meta_title` (nullable, 255)
      - `meta_description` (nullable, 300)
      - `is_published` (boolean, default false)
      - `published_at` (nullable timestamp, filled in when `is_published` is set, as on `Post`)
      - timestamps
    - A `published()` scope that also requires `published_at <= now()`.
    - Saving or deleting a page clears the cached `sitemap.xml`.
    - `PageRepositoryInterface` + `Eloquent\PageRepository` (list filters `search`, `is_published`; `findPublishedBySlug`), bound in `RepositoryServiceProvider`.
    - `PageService`: `list`, `create`, `update`, `delete`, `findPublishedBySlug`, and a listing for the sitemap. Bodies are sanitized with `PostBody::sanitize()`, and a body that is empty after sanitizing is a `ValidationException` on `body`.
    - `Requests/Page/{Index,Store,Update}PageRequest`: full validation, and a slug `Rule::unique(...)->ignore(...)` on update.
    - `PageResource` with explicit fields: `id, title, slug, excerpt, meta_title, meta_description, is_published, published_at, created_at, updated_at, web_url`. `body` is included only when opted in with `withBody()`, as in `PostResource`.
    - Thin `Api\PageController` guarded by `role:admin`, as posts are:
      - `Route::apiResource('pages', ...)->where(['page' => '[0-9]+'])`
      - index `data`/`meta`, store 201, update 200, destroy 204.
      - Uploading images from the pages editor reuses `POST /api/posts/media`.
    - **Public site**: `GET /pages/{slug}` through `Web\PageController` → `PageService`. The view is `resources/views/public/pages/show.blade.php` and has:
      - the full `<x-seo>` head (title, description with a plain-text fallback from `PostBody::plainText()`, canonical URL, OG/Twitter tags, WebPage JSON-LD)
      - exactly one `<h1>` (the title)
      - the body rendered with `{!! !!}` in `.prose-public`.
      - Unknown and draft slugs return a noindex 404.
    - The sitemap includes every published page. `SeoController` gets pages from `PageService`, not by querying `Page` directly.
  - **Admin SPA**: `views/pages/PageList.vue` (search, published filter, pagination, edit, delete, view-on-site link) and `views/pages/PageForm.vue` (title, slug, `RichTextEditor` body, meta fields, published). Both read the Resource shape.
  - **Docs**:
    - CLAUDE.md: shared public content / Post notes, and a Pages note.
    - Guideline tables if relevant.
    - `ApiAuthorizationTest` covers the new routes.
- Out:
  - converting About and Admissions into Page records (they stay hardcoded Blade)
  - adding pages to the public site's nav or footer
  - page ordering, hierarchy or templates
  - a public API for pages (`/api/public/pages`)
  - changing a post's type after creation
  - role-based menu filtering.

## Guidelines that apply
- `docs/architecture-guidelines.md`: Pages is a new backend module and follows the full pattern. `SeoController` isn't a module being converted. Add page reads through `PageService` only, and don't refactor its existing post queries.
- `docs/api-response-guidelines.md`: the new `/api/pages` endpoints use the standard shapes, error handling and field conventions (ISO 8601 dates, real booleans, absolute `web_url`).
- SEO rules in CLAUDE.md: the public page has the full SEO head, exactly one `<h1>`, and a noindex 404 for unknown or draft pages. The editor and sanitizer already allow only H2 to H4.

## Acceptance criteria
- [x] The sidebar shows a collapsible "CMS" group containing News, Events and Pages. It opens automatically on any of their routes, and "News & Events" is gone.
- [x] News lists only news and Events lists only events. "Add" from each section creates a post of that type, and saving returns to that section.
- [x] Old `/admin/posts` links redirect to the News section.
- [x] Admins can create, edit, list, search and delete pages with the rich text editor, including image upload, and content round-trips.
- [x] `/api/pages` follows the response guideline and is admin-only. Non-numeric ids return 404.
- [x] Bodies are sanitized on write, and an empty body returns 422.
- [x] A published page renders at `/pages/{slug}` with the full SEO head and exactly one `<h1>`. A draft, future-dated or unknown slug returns a noindex 404.
- [x] `sitemap.xml` lists published pages, and saving or deleting a page clears the sitemap cache.
- [x] CLAUDE.md is updated. `ApiAuthorizationTest`, the full suite and Pint all pass.

## Test cases
- [x] Happy path (`PageApiTest`):
  - admin index returns `data` and `meta.total`
  - show returns `data.body`
  - store returns 201 with an auto-generated slug and `published_at` set when published
  - update returns 200
  - destroy returns 204, and the row is gone.
- [x] Slug: two pages with the same title get `about-us` and `about-us-2`. A non-ASCII title produces an ASCII slug. A duplicate explicit slug returns 422 on `slug`, and update may keep its own slug.
- [x] Sanitizing: `<script>`, `onerror=`, `<h1>` and off-allowlist iframes are stripped from the body. `<img>` and the youtube-nocookie iframe are kept.
- [x] Validation (422):
  - store without `title` or `body`
  - a title over 255 characters
  - a meta description over 300 characters
  - a non-boolean `is_published`
  - a body of only `<p></p>`
  - `?search[]=x` on index.
- [x] Filters: `search` matches the title, and `is_published=0` or `1` filters by status.
- [x] Authorization: a teacher, student or parent on every `/api/pages` action → 403, and a guest → 401. `ApiAuthorizationTest` passes with the new routes.
- [x] Edge cases: `/api/pages/1abc` → 404, and `/api/pages/999999` → 404 `{"message": "Record not found."}`.
- [x] Public (`PublicSeoTest`):
  - `/pages/{slug}` for a published page has the full SEO head, exactly one `<h1>`, a canonical URL and the body HTML (an `<img>` is present)
  - a body containing `<h1>` still gives exactly one `<h1>`
  - the meta description falls back to plain text from the body
  - draft, future `published_at` and unknown slugs → 404 with noindex.
- [x] Sitemap: a published page's URL appears in `sitemap.xml` and a draft's doesn't. Creating a page after the sitemap is cached makes it appear, because the cache is cleared.
- [x] Unit (`PageServiceTest`, repository mocked): create and update sanitize the body, and an empty body throws a `ValidationException` without calling the repository's `create`.
- [x] Posts regression: the existing `PostApiTest`/`PublicApiTest`/`PublicSeoTest` tests still pass, and `GET /api/posts?type=event` returns only events.
- [x] SPA (manual, smoke): `npm run build` succeeds. In a browser against a throwaway seeded DB: `/admin/posts` redirects to `/admin/news`; the CMS group is expanded on News, Events and Pages; News lists only news and Events only events, with "Add News"/"Add Event" staying in-section; a page created through `/admin/pages/create` appears in the list, renders at `/pages/{slug}` with one `<h1>`, canonical and a plain-text meta description, and is in `sitemap.xml`; no console errors.

## Notes
- Named the public page route `page.show` (singular), not `pages.show`: `Route::apiResource('pages', ...)` already registers an API route named `pages.show`, and Laravel's api routes are registered before web routes (see `ApplicationBuilder::buildRoutingCallback`), so a same-named web route would silently resolve `route('pages.show', ...)` to whichever one is registered last rather than raising an error. Posts avoid this collision because their web routes are named `news.show`/`events.show`, not `posts.show`. `Page::url()` and `SchemaOrg::page()` use the new name.
- `PageResource`'s `excerpt` is always a computed `PostBody::plainText($body)` fallback — unlike `Post`, `Page` has no `excerpt`/`custom_excerpt` column, so there's nothing to round-trip through the admin form for that field.
- Pages are admin-only via `role:admin` (like posts), so no new `RolePermissionSeeder` permission was needed and no `ApiAuthorizationTest::OPEN_TO_ANY_USER` entry was required either.
- `PostList.vue`/`PostForm.vue` now read the post type from `route.meta.type` and no longer expose a type selector or filter; the underlying `/api/posts` endpoints and payload shape are unchanged, so this is UI-only.
- Did not touch `routes/api.php`'s pre-existing Pint violations (`no_unused_imports`, `single_blank_line_at_eof`) — confirmed via `git stash` that they predate this change; only lines I added to that file are clean.
- Did not add pages to the public nav/footer, an `/api/public/pages` endpoint, or page ordering/hierarchy, and did not convert About/Admissions to `Page` records — all explicitly out of scope.
- Review follow-ups (nits) addressed: `PostForm.vue` now redirects to the matching section's edit route when a loaded post's type doesn't match `route.meta.type`, and `/admin/posts/create` redirects to `/admin/news/create`; `RichTextEditor` gained a `placeholder` prop (`PageForm` passes "Write the page content…"); `SchemaOrg::page()`'s `isPartOf` (pointing at a non-`CreativeWork` node) was replaced with `publisher`, matching `post()`; `PublicSeoTest` gained a test that deleting a page clears the cached sitemap. The auto-slug race (shared with posts) remains out of scope.
