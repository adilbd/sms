# Add admin-managed institute settings to the CMS

Status: done
Branch: feat/institute-settings

## Problem
The school's identity (name, logo, contact details, address) is hardcoded in `config/seo.php` through env vars, and the defaults are US placeholders ("123 Learning Lane", "Springfield"). Admins can't change any of it without a deploy. This task adds an **Institute** screen as the first item in the admin sidebar's CMS group, before News. Each field is stored as its own key-value row in a `settings` table, and the public site, favicon, SEO JSON-LD and `/api/public/school` all read from it. `config/seo.php` stays as the fallback for any empty key.

## Scope
- In:
  - The `settings` table, the `Setting` model and its factory.
  - The `App\Support\InstituteSettings` key registry, which holds keys, groups, labels and validation rules.
  - The repository, its interface and binding, the service, FormRequest, resource and controller.
  - `GET` and `PUT /api/settings/institute`.
  - Logo and favicon uploads.
  - A seeder.
  - Public wiring: the layout (header, favicon, footer), the contact page, the `site_name` usages, `SchemaOrg`, `/api/public/school` and the admin page title and favicon.
  - The admin SPA page and sidebar entry.
  - Tests and a CLAUDE.md note.
- Out:
  - Other settings groups (the table is generic but only the institute group is built).
  - Bangla UI translation of the admin form.
  - An embedded map.
  - A district dropdown (district stays free text).
  - Moving `seo.default_description` or `twitter_handle` into settings.

### Keys
| Group | Keys |
|---|---|
| Identity | `name_en` (required when sent), `name_bn`, `short_name`, `motto`, `established_year`, `eiin` (6 digits), `institute_code`, `mpo_code`, `education_board` (dhaka, rajshahi, cumilla, jashore, chattogram, barishal, sylhet, dinajpur, mymensingh, madrasah, technical) |
| Branding | `logo`, `favicon` (stored path on the `public` disk, exposed only as `logo_url` / `favicon_url`) |
| Contact | `phone`, `telephone`, `email`, `office_hours` |
| Address | `village`, `ward`, `union`, `post_office`, `post_code`, `upazila`, `district`, `division` (barishal, chattogram, dhaka, khulna, mymensingh, rajshahi, rangpur, sylhet) |
| Location | `latitude` (-90..90), `longitude` (-180..180) |
| Social | `facebook_url`, `youtube_url` |

### Design
- **Migration** `create_settings_table`: `id`, `key` (string, unique), `value` (text, nullable), timestamps.
- **`SettingRepositoryInterface extends RepositoryInterface`** adds `valuesFor(array $keys): array` (key ⇒ value) and `upsertMany(array $pairs): void`. `Eloquent\SettingRepository` implements it with `upsert()`. Both are bound in `RepositoryServiceProvider`.
- **`InstituteSettingsService`**:
  - `all()` returns every registry key (null when unset), cached forever under `settings.institute`.
  - `update(array $data, ?UploadedFile $logo, ?UploadedFile $favicon, bool $removeLogo, bool $removeFavicon)` upserts only the keys that were sent, inside `DB::transaction`.
    - Files are stored as `settings/{uuid}.{ext}` on the `public` disk.
    - The old or removed file is deleted **after commit**.
    - The cache is forgotten.
  - `profile()` returns the effective public values, falling back to `config('seo.*')`, plus absolute `logo_url` / `favicon_url`. Every public reader uses it.
- **`UpdateInstituteSettingsRequest`**:
  - Rules come from the registry: `sometimes|nullable` for every key, `sometimes|required` for `name_en`, and `max:255`, `email`, `url`, `Rule::in`, `numeric|between` or `integer|between:1800,<this year>` as each key needs.
  - `logo`: `image|mimes:jpg,jpeg,png,webp|max:2048`. SVG is rejected because it can carry XSS.
  - `favicon`: `mimes:png,ico|max:512`.
  - `remove_logo` / `remove_favicon`: boolean.
  - `authorize()` returns true, with a comment that the permission middleware guards the action.
- **`InstituteSettingsController`** (`HasMiddleware`): `show` → `view-settings`, `update` → `edit-settings`. `update` returns `InstituteSettingsResource` with `message: "Institute settings saved"`. The SPA sends multipart `POST` with `_method=PUT`.
- **`InstituteComposer`** shares `$institute` (`profile()`) with `layouts.public`, `public.*`, `components.seo` and `admin`. `SchemaOrg` reads `profile()`:
  - `alternateName` = `name_bn`, `logo` = `logo_url`, and `geo` when both coordinates are set.
  - Address: `streetAddress` from village/ward/union, `addressLocality` = upazila, `addressRegion` = district, `postalCode` = post_code, `addressCountry` = `BD`.
- **`/api/public/school`** keeps every existing key and adds `name_bn`, `logo_url`, `favicon_url`, `eiin`, `institute_code`, `telephone`, the new address parts and `geo`.
- **Admin SPA**:
  - Route `institute` → `views/settings/InstituteSettings.vue`.
  - `Layout.vue` gets `{ name: 'Institute', path: '/institute', icon: '🏛️' }` as the first entry in `cmsItems`.
  - The form is split by group, uses selects for division and board, shows image previews with a Remove button, and has a "view on map" link.
  - It shows 422 errors per field and a success toast.

## Guidelines that apply
- docs/architecture-guidelines.md (new backend module on the Subjects pattern)
- docs/api-response-guidelines.md (new `/api/settings/institute` endpoints; `/api/public/school` stays additive)
- SEO rules in CLAUDE.md (layout, contact page and JSON-LD change; every public page still has the full SEO head and exactly one `<h1>`)

## Acceptance criteria
- [x] The **Institute** entry appears first under the sidebar's CMS group, before News, and opens `/admin/institute`.
- [x] Each key is stored as its own row in `settings` (`key` unique, `value` text).
- [x] `GET /api/settings/institute` returns `{data: {...every key..., logo_url, favicon_url}}` with nulls included and never raw storage paths.
- [x] `PUT /api/settings/institute` saves only the keys that were sent, ignores unknown keys and returns the updated resource with `message`.
- [x] Logo and favicon upload, replace (the old file is deleted) and remove all work.
- [x] The public header shows the logo and `name_en`. The favicon uses the uploaded file. The footer and contact page show the Bangladeshi address and contact details from settings.
- [x] Organization JSON-LD reflects the settings, and `/api/public/school` returns the new fields plus all old keys.
- [x] With an empty `settings` table, everything falls back to `config/seo.php` exactly as it does today.
- [x] The seeder fills sample Bangla/English values without overwriting existing rows.
- [x] CLAUDE.md documents the module.

## Test cases
- [x] Happy path: an admin sends `GET /api/settings/institute` → 200 with every registry key (nulls for unset keys) plus `logo_url` / `favicon_url`.
- [x] Happy path: an admin sends `PUT` with `name_en`, `name_bn` (Bangla), `eiin=123456` and `division=dhaka` → 200 with `message`. The rows are stored, and a follow-up `GET` returns the new values.
- [x] Partial update: sending only `phone` leaves `name_en` and every other key unchanged.
- [x] Unknown key: `foo=bar` is ignored, and no `settings` row is created for it.
- [x] Validation → 422:
  - `email=not-an-email`
  - `latitude=91`
  - `longitude=-181`
  - `eiin=12345`
  - `eiin=abcdef`
  - `division=london`
  - `education_board=oxford`
  - `name_en=""`
  - `established_year=1700`
  - `facebook_url=not-a-url`
  - a string longer than 255 characters
- [x] Upload: with `Storage::fake('public')`, a PNG logo is stored under `settings/` and `logo_url` is an absolute URL.
- [x] Upload: an SVG logo → 422, and a favicon over 512 KB → 422.
- [x] Replace: uploading a second logo deletes the first file.
- [x] Remove: `remove_logo=1` deletes the file and nulls `logo` / `logo_url`.
- [x] Authorization: a guest → 401 on both routes.
- [x] Authorization: a role without `edit-settings` (for example teacher or student) → 403 on `PUT`.
- [x] Authorization: a role without `view-settings` → 403 on `GET`.
- [x] Errors are JSON even without an `Accept` header.
- [x] Cache: after a `PUT`, the public home page immediately shows the new `name_en` (the `settings.institute` cache is forgotten).
- [x] Unit (mocked `SettingRepositoryInterface`):
  - `update()` calls `upsertMany` with only the provided keys.
  - It forgets `settings.institute`.
  - `profile()` falls back to `config('seo.site_name')` / `seo.organization.*` when values are null.
- [x] Public: once settings are saved, the home page contains `name_en` and the logo `<img>`, and the favicon link points to the uploaded favicon.
- [x] Public: the contact page shows the upazila, district and a maps link when lat/lng are set.
- [x] Public: JSON-LD contains `alternateName` (`name_bn`) and `addressCountry: "BD"`.
- [x] Public: `/api/public/school` includes the new fields and every old key.
- [x] Fallback: with an empty table, the home page and `/api/public/school` show the config values.
- [x] Regression: `PublicSeoTest` and `ApiAuthorizationTest` pass unchanged.
