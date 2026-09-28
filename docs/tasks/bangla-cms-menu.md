# Seed Bangla CMS content and add an admin-managed header menu

Status: done
Branch: feat/bangla-cms-menu

## Problem
The public header is a hardcoded English list in `layouts/public.blade.php`, with no dropdowns and no mobile nav, and the seeded content is English sample data with no Pages. The school site needs Bangla content and a menu the admin can edit. The CMS structure is modelled on two real Bangladeshi school sites: vhbub.edu.bd.shongket.com supplies the content and menu, and sagc.edu.bd is a structural reference. This task adds a backend-managed, nested header menu with an admin UI, renders it on the public Blade site, and seeds Bangla news, events, pages and the menu. Real vhbub text is copied where it exists, and realistic Bangla fill is written where vhbub only has placeholders.

Full plan: `~/.claude/plans/https-vhbub-edu-bd-shongket-com-https-sa-eager-karp.md`.
Scraped source text (verbatim Bangla JSON): `~/.claude/plans/https-vhbub-edu-bd-shongket-com-https-sa-eager-karp-agent-a4de407735965bd62.md`.

## Scope
**In**
- A new Menu module (`MenuItem`), built on the Subjects pattern. It has:
  - a `menu_items` table: `location` (default `header`), self `parent_id` (cascade), `label`, `type` (one of page, route, url, heading), `page_id` (nullOnDelete), `route_name` (whitelisted), `url`, `sort_order`, `is_active`, `open_in_new_tab`
  - the repository and its interface, a binding, `MenuService`, FormRequests and `MenuItemResource`
  - `Api\MenuItemController` behind `role:admin`
  - `apiResource('menu-items')` with a numeric id constraint, plus `PUT menu-items/reorder`
- Public header rendering:
  - A view composer calls `MenuService::headerTree()`, which is cached in `menu.header`.
  - `MenuItem` save/delete and `Page` save/delete clear that cache.
  - The header renders through a `header-menu` Blade partial. On desktop it has CSS-only dropdowns up to 3 levels. On mobile it uses a `<details>` hamburger.
  - If the menu is empty, the header falls back to the current six links.
- Admin SPA:
  - A `Menu` item in the CMS sidebar group.
  - `views/menu/MenuList.vue`: an indented tree with up/down reorder, an active toggle, and edit/delete.
  - `views/menu/MenuForm.vue`: the target field depends on the type (page select, route select or URL).
- Seeders, all idempotent, with explicit ASCII slugs:
  - `PublicContentSeeder` is rewritten in Bangla: about 8 news items and about 5 events. It keeps the draft `draft-upcoming-announcement`.
  - A new `PageSeeder` with 14 pages. See the plan's table for slugs; facilities uses `school-facilities`.
  - A new `MenuSeeder` that seeds the Bangla tree only when the header location is empty.
  - `DatabaseSeeder` order: RolePermission → PublicContent → Page → Menu.
- A short Menu paragraph in the `CLAUDE.md` architecture notes.

**Out**
- The hardcoded Home, About and Admissions Blade copy, the footer links, `<html lang>` and the app locale.
- Menu locations other than `header`.
- Drag-and-drop reordering.
- Image or PDF attachments copied from vhbub.
- Staff, results, gallery and login menu entries, because those features don't exist.
- Any personal data, such as the head teacher's NID, phone number or date of birth.

## Guidelines that apply
- `docs/architecture-guidelines.md`: new backend module (Controller → Service → Repository interface → Eloquent repository)
- `docs/api-response-guidelines.md`: `/api/menu-items` endpoints
- The SEO rules in `CLAUDE.md` for public pages. The header must not add an `<h1>`, so every page keeps exactly one `<h1>`.

## Acceptance criteria
- [x] `php artisan migrate:fresh --seed` produces:
  - Bangla news, events and pages, with real vhbub text on history, introduction, head, founder and assistant-head messages, and courses
  - the Bangla header menu from the plan
- [x] Running the seed a second time creates no duplicates.
- [x] `/` renders the Bangla header with nested dropdowns, and a `<details>` menu at phone width.
- [x] Menu items linking to an unpublished or missing page are hidden, and so are headings with no visible children.
- [x] Inactive items are hidden.
- [x] Admin CRUD and reorder under `/admin/menu` update the public header immediately, through cache invalidation.
- [x] The service enforces these rules and returns a 422 when one is broken:
  - maximum depth of 3
  - no cycles
  - the parent is in the same location
  - the target required by the item's type is present
- [x] The API follows the response guideline: `data`/`meta` wrapping, 201 on store, 204 on delete, and 404 for a missing id.
- [x] Every existing test passes: PublicSeoTest, PublicApiTest, ApiAuthorizationTest, PageApiTest and PostApiTest.

## Test cases
- [x] Happy path:
  - Admin creates page, route, url and heading items → 201 with the `MenuItemResource` shape, including `href`.
  - Index paginates under `meta`.
  - Update → 200.
  - Delete → 204, and the children are removed too.
- [x] Happy path: reorder persists the new `sort_order` and `parent_id` values.
- [x] Happy path: after a menu change, the public `/` shows the seeded Bangla labels and a nested child link.
- [x] Validation → 422 for each of:
  - a bad `type`
  - an unknown `route_name`
  - a `javascript:` URL
  - a missing `page_id` for type `page`
  - a nonexistent `page_id`
  - a `label` longer than 255 characters
  - a negative `sort_order`
- [x] Validation → 422 when:
  - the parent would put the item at depth 4
  - the parent is the item itself or one of its descendants
- [x] Authorization:
  - a guest → 401
  - teacher, student and parent → 403 on every action, including reorder
- [x] Not found: `/api/menu-items/999` → 404, and `/api/menu-items/1abc` → 404
- [x] Edge case: hidden from the header:
  - an item whose page is unpublished or future-dated
  - an inactive item
  - a heading with no visible children
- [x] Edge case: an empty menu renders the fallback default links.
- [x] Edge case: unpublishing a page clears the menu cache, so the link disappears on the next request.
- [x] Edge case: every public page still has exactly one `<h1>`, with the menu seeded.
- [x] Unit (`MenuServiceTest`, with a mocked interface) covers:
  - the depth rule
  - the cycle rule
  - the target required for each type
  - `headerTree` filtering
