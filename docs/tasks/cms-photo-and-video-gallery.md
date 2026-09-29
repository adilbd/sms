# Add a photo and video gallery to the CMS

Status: done
Branch: feat/cms-photo-and-video-gallery

## Problem
The school has no way to show photos and videos of its events, campus and activities on
the public site. Admins need to create several galleries from the CMS, each holding a mix
of photos and YouTube videos. Images should live in a reusable media library, so an image
uploaded once can be picked again for another gallery instead of being uploaded twice.
Visitors get a server-rendered, SEO-friendly `/gallery` page that lists the galleries, and
a page for each gallery. A seeder fills in some sample galleries so a fresh install
already has something to show.

## Scope
- In:
  - **Media library** (`App\Models\Media`, table `media`): one row per uploaded image
    (`disk`, `path`, `original_name`, `mime_type`, `size`, `width`, `height`, nullable
    `alt`, timestamps). Full module on the Controller → Service → Repository pattern:
    `GET /api/media` (paginated, newest first, `search` on `original_name`/`alt`),
    `POST /api/media` (upload one image: jpeg/png/webp/gif, max 5 MB, stored on the
    `public` disk as `media/{Y}/{m}/{uuid}.{ext}`), `PUT /api/media/{media}` (edit `alt`),
    `DELETE /api/media/{media}` (409 if any gallery item uses it; otherwise delete the
    row and, after commit, the file). `MediaResource` exposes an absolute `url`.
  - **Galleries** (`App\Models\Gallery`): `title` (Bangla or English), unique
    auto-generated `slug` (ASCII, mirrors `Post`/`Page`), nullable `description`, nullable
    `cover_media_id`, `is_published`/`published_at` with a `published()` scope like
    `Page`, `sort_order`. Full module: `/api/galleries` apiResource, numeric id
    constraint, `role:admin` like posts, pages and menu.
  - **Gallery items** (`App\Models\GalleryItem`): `gallery_id` (cascade on delete),
    `type` of `image` or `video` (`GalleryItem::TYPES`), `media_id` (required for
    `image`, restrict on delete), `youtube_url` + stored `youtube_id` (required for
    `video`), nullable `caption`, `sort_order`. Managed through the gallery: the gallery
    store/update payload carries an `items` array that the service syncs in one
    transaction (create, update, delete missing, reorder by array position).
  - **YouTube only**: accept `youtube.com/watch?v=`, `youtu.be/`, `youtube.com/embed/`,
    `youtube.com/shorts/` and `m.youtube.com` URLs; extract the 11-character video id in
    one place (e.g. `App\Support\YouTube`). Anything else → 422. The public page embeds
    `https://www.youtube-nocookie.com/embed/{id}` and uses
    `https://i.ytimg.com/vi/{id}/hqdefault.jpg` as the thumbnail.
  - **Cover**: `cover_media_id` if set, otherwise the first image item, otherwise the
    first video's YouTube thumbnail, otherwise a neutral placeholder.
  - **Admin SPA**: a **Gallery** entry in the sidebar's **CMS** group. Gallery list
    (title, item count, published state, edit/delete) and gallery form (title,
    description, published, cover, items with add image / add video / caption / reorder /
    remove). "Add image" opens a reusable **media picker** modal with two tabs: *Library*
    (paginated grid with search, multi-select) and *Upload* (upload one or more new
    images, which land in the library and are then selected). Also a **Media library**
    page under CMS to browse, upload, edit alt text and delete images.
  - **Public site**: `GET /gallery` (`gallery.index`) lists published galleries (cover,
    title, item count), paginated like news, 404 for out-of-range pages;
    `GET /gallery/{slug}` (`gallery.show`) shows the images in a grid with a lightweight
    lightbox and the videos as responsive embeds. Both through `GalleryService`, full
    `<x-seo>` head, exactly one `<h1>`, draft/unknown slugs → noindex 404. Add both to
    `sitemap.xml` (and clear the sitemap cache on gallery save/delete). Add `gallery.index`
    to `MenuItem::ROUTES` so admins can link it from the header menu.
  - **Public API**: `GET /api/public/galleries` and `GET /api/public/galleries/{slug}`
    through `GalleryService`, so the mobile app and the Blade site return the same data.
  - **Seeder**: `GallerySeeder` (called from `DatabaseSeeder`) creates about 3–4
    published galleries with Bangladeshi-school content (e.g. Annual Sports Day, Victory
    Day celebration, Science Fair, Campus), a few images each registered in `media`, and
    at least one YouTube video item. Images are placeholder JPEGs generated with GD into
    the `public` disk (no network access during seeding). Re-running the seeder must not
    duplicate galleries.
- Out:
  - Uploading video files (YouTube URLs only).
  - Moving the post/page editor's `POST /api/posts/media` uploads into the media library
    (the endpoint stays as it is).
  - Image resizing/thumbnail generation, albums inside albums, per-role gallery access.

## Guidelines that apply
- docs/architecture-guidelines.md (two new backend modules: Media and Gallery; Subjects is the reference)
- docs/api-response-guidelines.md (all new `/api` endpoints, `data`/`meta` shapes, 201/204/409/422)
- SEO rules in CLAUDE.md (`/gallery` and `/gallery/{slug}` are public pages; `PublicSeoTest`)
- `ApiAuthorizationTest`: every new authenticated route needs a `role:` check
- Update CLAUDE.md's architecture notes with a short Gallery/Media section when done

## Acceptance criteria
- [x] Admin can upload images to the media library, edit alt text, and delete an unused image; deleting a used image returns 409.
- [x] Admin can create, edit and delete galleries; each gallery holds ordered image and YouTube video items with captions.
- [x] Adding an image to a gallery lets the admin pick existing library images or upload new ones in the same modal.
- [x] Non-YouTube video URLs are rejected with 422; valid YouTube URL forms are all accepted and normalized to a video id.
- [x] Deleting a gallery deletes its items but never the library images.
- [x] `/gallery` lists published galleries with covers; `/gallery/{slug}` shows images (lightbox) and embedded videos; drafts are not visible.
- [x] `/api/public/galleries` and `/gallery` return the same galleries via `GalleryService`.
- [x] Gallery appears in the admin sidebar's CMS group, in `sitemap.xml`, and as a menu route option.
- [x] `php artisan migrate:fresh --seed` produces visible sample galleries with images and a video; re-running `GallerySeeder` doesn't duplicate them.
- [x] Bangla titles work and still get an ASCII slug.

## Test cases
- [x] Happy path (media): admin uploads a PNG → 201, row in `media`, file on the fake `public` disk, `url` absolute.
- [x] Happy path (gallery): admin creates a gallery with two image items and one video item → 201, items returned in order with `media`/`youtube_id`; update reorders and removes one item → items synced.
- [x] Validation: upload a PDF or a 6 MB image → 422; gallery without `title` → 422; image item without `media_id` or with a non-existent one → 422; video item with `https://vimeo.com/123` or `https://youtube.com.evil.com/watch?v=...` → 422; unknown `type` → 422.
- [x] YouTube parsing (unit): `watch?v=`, `youtu.be/`, `embed/`, `shorts/`, `m.youtube.com`, extra query params → correct 11-char id; non-YouTube hosts and malformed ids → null.
- [x] Authorization: guest → 401 on `/api/galleries` and `/api/media`; teacher/student/parent → 403 on list, store, update and destroy of both.
- [x] Conflict: deleting a media row used by a gallery item → 409 and the file is kept; deleting an unused one → 204 and the file is removed.
- [x] Cascade: deleting a gallery → 204, its items are gone, its media rows and files remain.
- [x] Not found: `/api/galleries/999` → 404; `/api/galleries/1abc` → 404.
- [x] Public: `/gallery` shows published galleries only, has the full SEO head and exactly one `<h1>`; `/gallery?page=99` → 404; `/gallery/{draft-slug}` and `/gallery/unknown` → noindex 404; a gallery page embeds `youtube-nocookie.com/embed/{id}`.
- [x] Public API: `/api/public/galleries` returns only published galleries with `data`/`meta`; `/api/public/galleries/{slug}` includes items; draft slug → 404.
- [x] Sitemap: includes `/gallery` and each published gallery URL, not drafts.
- [x] Edge case: gallery with no items renders with a placeholder cover and an empty-state message; Bangla title gets a non-empty unique ASCII slug.
- [x] Seeder: running `GallerySeeder` twice leaves the same number of galleries.
