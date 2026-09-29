# Add the Staff module (teachers, staff, shifts and the School Administration pages)

Status: done
Branch: feat/staff-module

## Problem
The school site needs a **স্কুল প্রশাসন** (School Administration) section, like the one on vhbub.edu.bd.shongket.com. It lists the Head Teacher, Assistant Head, Teachers, Staff, Ex-Heads, Ex-Teachers and Ex-Staff, and gives each person a public profile. Admins must be able to manage current and former teaching and non-teaching staff, record their education and training history, and assign each person to one or more school shifts.

Today there is only a placeholder `Teacher` model and `teachers` table. It has an India-style address, a required `user_id` and no writers. There is also a stub `TeacherController` (`[]` or 501) and an "not implemented" admin page.

Logins for staff will be linked later, so `user_id` becomes nullable now.

## Scope
**In:**

1. **`staff` table and model.**
   - It replaces `teachers`, which is dropped because nothing writes to it.
   - `subject_assignments.teacher_id` is repointed to `staff_id`.
   - Columns:
     - `user_id`: nullable, `nullOnDelete`
     - `employee_id`: nullable, unique
     - `name_en` and `name_bn`: at least one is required
     - `category`: teacher / staff
     - `position`: head / assistant_head / teacher / staff
     - `designation`
     - `subject`
     - `mpo_index`
     - `joining_date` and `leaving_date`
     - `status`: active / retired / transferred / resigned / deceased
     - `gender`
     - `religion`
     - `date_of_birth`
     - `blood_group`
     - `nationality`: default "Bangladeshi"
     - `nid`
     - `mobile` and `email`
     - `show_contact`: default false
     - `present_address` and `permanent_address`
     - `district`
     - `photo`: `public` disk, `staff/{uuid}.ext`
     - `bio`
     - `sort_order`
     - `is_published`: default true
     - timestamps and soft deletes
2. **Child tables** `staff_educations` (degree, institution, board_university, passing_year, result, sort_order) and `staff_trainings` (title, organizer, duration, year, sort_order).
   - They are synced from `educations[]` and `trainings[]` in the staff store/update payload, like `GalleryService::syncItems()`.
   - There are no separate endpoints.
3. **Shifts module.**
   - `shifts` table: name_en, name_bn, a unique ASCII `slug`, nullable `start_time`/`end_time` (Asia/Dhaka wall-clock `time`, not UTC), sort_order and is_active.
   - A `shift_staff` pivot.
   - Every staff member has at least one shift.
   - Managed at `/api/shifts` and `/admin/shifts`.
4. **Staff backend** on the Subjects pattern:
   - `StaffRepositoryInterface` and `StaffRepository`, with the binding
   - `StaffService`
   - `Index`, `Store` and `UpdateStaffRequest`
   - `StaffResource`
   - `Api\StaffController` at `/api/staff`, replacing the `TeacherController` stub and reusing the `*-teachers` permissions
5. **Service rules:**
   - A former status (anything except `active`) requires `leaving_date`.
   - `leaving_date` must be on or after `joining_date`.
   - Each shift can have at most one active `head` and one active `assistant_head`.
   - A shift that still has staff can't be deleted (409).
   - Photo replace and remove follow the `InstituteSettingsService::update()` file handling: delete the new file on failure, and delete the old one after commit.
   - Saving or deleting staff clears the sitemap cache.
6. **Public site** (`Web\StaffController`, views in `resources/views/public/staff/`):
   - `staff.head` at `/administration/head`
   - `staff.assistant_head` at `/administration/assistant-head`
   - `staff.teachers` at `/administration/teachers`
   - `staff.employees` at `/administration/staff`
   - `staff.ex_heads` at `/administration/ex-heads`
   - `staff.ex_teachers` at `/administration/ex-teachers`
   - `staff.ex_employees` at `/administration/ex-staff`
   - `staff.show` at `/administration/staff/{staff}` (numeric id)

   The pages behave as follows:
   - The list pages use a card grid showing photo, name, designation, shift badges, and tenure for former staff.
   - Profiles show a summary, Education, Training, and Contact only when `show_contact` is set.
   - The Head and Assistant Head pages show the full profile inline, one per shift if the people differ.
   - On list pages, `?shift={slug}` filter pills appear only when more than one active shift exists. An unknown slug returns 404.
   - All 7 index routes are added to `MenuItem::ROUTES`, the `MenuForm.vue` route options and the sitemap. Profiles go in the sitemap too.
   - `MenuSeeder` gets a **স্কুল প্রশাসন** heading with the 7 children.
7. **Public API**, through the same `StaffService`:
   - `GET /api/public/staff?position=&former=&shift=`
   - `GET /api/public/staff/{id}`
   - Neither ever includes nid, date_of_birth, addresses or mpo_index, and mobile/email appear only when `show_contact` is set.
8. **Admin SPA:**
   - `views/staff/StaffList.vue` and `StaffForm.vue` at `/staff`, `/staff/create` and `/staff/:id/edit`. `/teachers` redirects to `/staff`, and the sidebar says "Staff".
   - The list has Current/Former tabs, filters for category, position and shift, search, photo thumbnails, and an Archive modal that asks for status and leaving date.
   - The form has sections for Basic, Employment, Shifts, Personal, Contact (with `show_contact`), Address ("same as present"), Photo upload/remove, and Education/Training repeaters with add, remove and reorder.
   - Shift admin lives in `views/shifts/ShiftList.vue` and `ShiftForm.vue`.
   - Update and create send multipart, using `_method=PUT` on update.
9. **Seed data:**
   - `ShiftSeeder` creates Morning (প্রভাতি, 07:00–12:00) and Day (দিবা, 12:30–17:30).
   - `StaffSeeder` reads a committed fixture, `database/seeders/data/staff.json`, plus photos in `database/seeders/data/staff-photos/`. Both are made by a one-off scrape of vhbub.edu.bd.shongket.com (`/staffs/head`, `/staffs/assistant_head`, `/staffs/assistant`, `/staffs/staffs`, `/ex-teacher`, and each `/singlestaffs/{id}`). The user has confirmed they are authorised by the school to reuse this data.
   - The scrape and fixture take **only** these fields: name_en, name_bn, designation, subject, joining_date, status, photo, education rows and training rows.
   - The seeder never touches the network.
   - Which source list a person came from sets their position and category. Ex-teachers get status `retired`.
   - `employee_id` is `VHBUB-{source_id}`, so reruns update rows instead of duplicating them.
   - Everyone is assigned the Morning shift.
   - Unparseable fields are left null.
10. **Docs:** add a Staff and Shifts module note to CLAUDE.md, remove Teacher from the stub list, and update the legacy tables in both guidelines.

**Out:**
- Creating logins for staff or linking them to users (only the nullable `user_id` column is added).
- Linking shifts to sections or students.
- A per-role tenure history (a person's final position is what counts).
- `ClassSection.class_teacher_id`, which stays pointed at users.
- Teacher dashboards and permissions scoped to a teacher's own records.
- Management committee (পরিচালনা পরিষদ).

**Never imported from the source site:** mobile, email, date of birth, home addresses, NID, MPO index.

## Guidelines that apply
- docs/architecture-guidelines.md: Staff and Shifts are new modules on the Subjects pattern.
- docs/api-response-guidelines.md: `/api/staff`, `/api/shifts` and `/api/public/staff`.
- SEO rules in CLAUDE.md: the 8 public pages each need the full `<x-seo>` head, exactly one `<h1>` and breadcrumbs JSON-LD, and a `Person` schema on the profile. Unknown or unpublished profiles return a noindex 404.
- The `teachers` stub is replaced wholesale, and `SubjectRepository::hasTeacherAssignments` moves to `staff_id`.

## Acceptance criteria
- [x] `php artisan migrate:fresh --seed` works on SQLite and MySQL. The `teachers` table is gone, and `staff`, `staff_educations`, `staff_trainings`, `shifts` and `shift_staff` exist.
- [x] An admin can create, edit, archive, restore and delete a staff member, including a photo and education/training rows, from `/admin/staff`.
- [x] An admin can manage shifts at `/admin/shifts`.
- [x] All 7 স্কুল প্রশাসন pages and the profile page render with seeded data, and appear in the seeded header menu under স্কুল প্রশাসন.
- [x] Private fields never appear in public HTML or `/api/public/staff*`. Mobile and email appear only when `show_contact` is set.
- [x] The shift filter and badges work on public list pages.
- [x] The seeded staff match the source site's lists (≈1 head, assistant head(s), 39 teachers, 6 staff, 9 ex-teachers), with photos, and include no personal contact, DOB or address data.
- [x] `ApiAuthorizationTest` passes, and `/api/shifts` reads are in `OPEN_TO_ANY_USER`.
- [x] The docs are updated.

## Test cases
- [x] Happy path: an admin POSTs `/api/staff` as multipart with a photo, `shift_ids` and 2 educations → 201 with a `data` shape including `photo_url`, `shifts`, `educations` and `trainings`. The file exists on the faked `public` disk.
- [x] Happy path: update with a new photo → the old file is deleted after commit. `remove_photo=1` → the photo is null and the file is deleted.
- [x] Happy path: update with a reordered and trimmed `educations[]` → rows are created, updated and deleted, and `sort_order` matches the array order.
- [x] Happy path: `GET /api/staff?is_active=0&position=teacher&shift=<id>&search=...` returns only the matching staff, paginated with `meta`.
- [x] Happy path: `DELETE /api/staff/{id}` → 204, and the staff member is soft-deleted.
- [x] Validation: missing both names, missing `shift_ids`, an inactive shift id, a bad `position`, `status` or `blood_group`, or a non-image photo → 422.
- [x] Validation: status `retired` without `leaving_date`, or a `leaving_date` before `joining_date` → 422. This applies on partial updates too (checked against the model with the input applied).
- [x] Validation: a second active head in the same shift → 422. An active head in a different shift → OK. Archiving the old head, then promoting a new one → OK.
- [x] Authorization: the teacher role (no `*-teachers` permissions) → 403 on every `/api/staff` action.
- [x] Authorization: a non-admin user without `edit-settings` → 403 on shift store, update and destroy, and 200 on shift index. A guest gets 401.
- [x] Not found: `/api/staff/1abc` → 404, and an unknown id → `{"message": "Record not found."}`.
- [x] Shifts: CRUD shapes. Deleting a shift that has staff → 409. A duplicate slug → 422.
- [x] Public: each of the 7 pages and a profile return 200, with the full SEO head and exactly one `<h1>`.
- [x] Public: an unpublished, unknown or soft-deleted profile returns a noindex 404, and `/administration/head?shift=nope` returns 404.
- [x] Public: former staff appear only on the ex pages, and active staff only on the current pages.
- [x] Public: the profile HTML and `/api/public/staff/{id}` never contain nid, DOB, addresses or mpo_index. Mobile appears only when `show_contact=true`.
- [x] Public: with one active shift, no filter pills are shown. With two, they are shown and filter the results.
- [x] Edge case: the head page with no head shows an empty state (200, one h1).
- [x] Edge case: a staff member with only `name_bn` (Bangla, non-ASCII) renders correctly everywhere.
- [x] Seeder: running `StaffSeeder` twice against a small test fixture creates no duplicates, and the seeded rows have no mobile, email, DOB or address.
- [x] Unit (`StaffServiceTest`, with the repository mocked): the head-per-shift rule, leaving date required when former, and photo cleanup when the transaction fails.

## Notes
- Archiving and restoring are both a plain `PUT /api/staff/{id}`, which changes `status` and `leaving_date`. There is no separate restore endpoint.
- The public staff lists aren't paginated. They return full collections, which is fine at about 40 people.
- The source site lists no ex-heads or ex-staff, so those two pages show their empty state on a fresh seed.
- Staff and shift models set `$table` explicitly, because Eloquent's inflector guesses the wrong names for "staff" and "education".
- The one-off scrape script was not committed. Only `database/seeders/data/staff.json` and `staff-photos/` are committed, and they hold names, designation, subject, dates, status, photo, education and training only.
