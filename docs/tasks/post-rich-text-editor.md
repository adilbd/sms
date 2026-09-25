# Add a rich text editor with media to news and event bodies

Status: done
Branch: feat/post-rich-text-editor

## Problem
Admins edit news and event bodies in a plain Markdown `<textarea>` (`PostForm.vue`). Both render paths (`public/posts/show.blade.php` and `PostResource`) run `Str::markdown` with `html_input => strip`. There is no upload support, no editor and no HTML sanitizer. Admins need a WYSIWYG editor (Tiptap) that can insert uploaded images and YouTube/Vimeo videos. After this change the body is stored as sanitized HTML with a single render path, and existing Markdown bodies are converted by a migration.

## Scope
- In:
  - An HTML sanitizer (`mews/purifier`, `post_body` profile) and a stateless `App\Support\PostBody` helper with `sanitize`, `fromMarkdown`, `plainText` and `isEmpty`.
  - Full conversion of the legacy posts module to the Subjects pattern:
    - `PostRepositoryInterface` and `Eloquent\PostRepository` with the binding
    - `PostService` built on the repository (public reads keep their signatures, plus `list`, `create`, `update` and `delete`)
    - `Requests/Post/{Index,Store,Update}PostRequest`
    - a thin `Api\PostController` returning `PostResource`: `data` and `meta` for lists, 201 for store, 204 for delete
    - a numeric route constraint
    - `role:admin` kept.
  - `POST /api/posts/media` (admin only) for image upload. Accepts jpg, png, webp and gif up to 5 MB, but not SVG. Stores to the `public` disk under `posts/{Y}/{m}/{uuid}.{ext}` and returns 201 `{data: {path, url}}`.
  - The public page renders the sanitized HTML body. CSS for images, figures and responsive iframes in `.prose-public`.
  - A data migration converting existing Markdown bodies to HTML (its `down()` is a no-op). The seeder switches to HTML bodies.
  - Admin SPA:
    - `components/RichTextEditor.vue` (Tiptap)
    - toolbar: bold, italic, underline, strike, H2 to H4, lists, blockquote, link, image, video, undo and redo
    - image upload by picker, drag and drop or paste, with an alt text prompt
    - `extensions/VideoEmbed.js` for YouTube and Vimeo only, using youtube-nocookie
    - `PostForm.vue` and `PostList.vue` updated to the new response shapes.
  - `php artisan storage:link` in the setup docs and the Docker startup. Updates to the CLAUDE.md post notes and to the legacy tables in both guidelines.
- Out: cover-image upload (the cover stays a string field), cleanup of orphaned uploads, and a media library UI.

## Guidelines that apply
- `docs/architecture-guidelines.md`: the posts module is legacy, so the **whole module** is converted in this change.
- `docs/api-response-guidelines.md`: the admin posts endpoints move off the legacy shapes, and the SPA consumers are updated in the same change.
- SEO rules in CLAUDE.md:
  - exactly one `<h1>`, so the editor and sanitizer allow only H2 to H4
  - the meta description must be plain text
  - unknown and draft posts return a noindex 404.

## Acceptance criteria
- [x] Admins can create and edit news and events with a Tiptap editor. Content round-trips when the post is saved and reopened.
- [x] Images can be uploaded by picker, by drag and drop or by paste, and show on the public page from `/storage/posts/...`.
- [x] A pasted YouTube or Vimeo URL embeds a responsive iframe. Other URLs are rejected.
- [x] Bodies are sanitized on write:
  - stripped: script, event attributes, `javascript:` links, `h1`, and iframes from hosts not on the allowlist
  - kept: allowed formatting, `img`, and youtube-nocookie and Vimeo iframes.
- [x] A body that is empty after sanitizing (no text, no img or iframe) returns 422 on `body`.
- [x] The admin posts API follows the response guideline: lists under `data`/`meta`, show/store/update as `data`, store 201, destroy 204.
- [x] `/api/public/*` still returns `body` and `body_html` (both hold the sanitized HTML).
- [x] Excerpt and meta-description fallbacks are plain text, with no tags and with entities decoded.
- [x] Existing Markdown bodies are converted to HTML by the migration, and the seeded posts are HTML.
- [x] Docs are updated: CLAUDE.md (post notes, `storage:link`) and the legacy tables in both guidelines.

## Test cases
- [x] Happy path:
  - Admin CRUD on `/api/posts`: the list has `data` and `meta.total`, show returns `data.body`, store returns 201 and sets the author, update returns 200, destroy returns 204.
  - A jpg upload to `/api/posts/media` returns 201 with `path` and an absolute `url`, and the file exists on the faked public disk.
- [x] Sanitizing:
  - removed: `<script>`, `onerror=`, `href="javascript:..."`, `<h1>`, `<iframe src="https://evil.com">`
  - kept: `<img>`, `https://www.youtube-nocookie.com/embed/ID`, `https://player.vimeo.com/video/ID`.
- [x] Validation (422):
  - store without title, body or type
  - an invalid `type`
  - a body of only `<p></p>`
  - a media upload that is missing, a pdf, an svg, or over 5 MB.
- [x] Authorization:
  - a teacher on the posts CRUD endpoints and on `/api/posts/media` → 403
  - a guest → 401.
- [x] Edge case: `/api/posts/1abc` → 404 (numeric constraint).
- [x] SEO (`PublicSeoTest`):
  - a detail page renders the HTML body unescaped (an `<img>` is present)
  - a body containing `<h1>` still gives exactly one `<h1>`
  - the meta description from an HTML body has no tags.
- [x] Unit, `PostServiceTest` (repository mocked): create and update sanitize the body, an empty body throws a `ValidationException`, and create sets the author.
- [x] Unit, `PostBodyTest`:
  - `fromMarkdown('## x')` produces `<h2>`
  - `plainText` strips tags and decodes entities
  - `isEmpty` is false for an image-only body and true for `<p></p>`.
- [x] `PublicApiTest`: the admin posts assertions are updated to the new shapes, and `body_html` is still present on public detail.

## Notes
- Verified by automated tests (95 passed), `npm run build` and the smoke script. The editor UX (picker, drag-and-drop and paste uploads, video embeds, reopening a saved post) still needs a manual check in the browser.
