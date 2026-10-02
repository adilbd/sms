# Add homework assigned by subject teachers

Status: done
Branch: feat/homework

## Problem
Teachers have no way to assign homework, and students and guardians can't see what's due. The goal:
- A subject teacher (or an admin) assigns homework for their section and subject: title, details, due date and an optional attachment.
- Students and guardians see it in the portal and through `/api/my/homework`.
- There's no online submission or grading (the user's decision).

This is Task 3 of 4 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. The "Task 3" section there is authoritative.

## Scope
See the plan's Task 3.
- **Data:** a `homework` table with soft deletes:
  - `academic_year_id`, `section_id`, `subject_id`
  - `staff_id`, the author
  - `title`, plus `details` sanitized with `PostBody::sanitize`
  - `assigned_on` and `due_on`, Asia/Dhaka dates, with `due_on` no earlier than `assigned_on`
  - `attachment_path`, optional: a PDF or image up to 5 MB, kept on the private disk
- **Rules,** enforced in `HomeworkService`:
  - Only an assigned subject teacher for that section, subject and year (from Task 1's multiple teachers), or an admin, may create homework.
  - The author can edit or delete it until the due date. An admin can do so at any time.
  - The subject must be in the section's curriculum, taking the section's group into account.
- **API:**
  - CRUD at `/api/homework`.
  - Filters: section, subject, date range and `due`, which is `upcoming` or `past`.
  - A teacher's list only shows their own sections, through `TeacherScope`.
  - `GET /api/my/homework`: a student sees only the subjects they take, using `StudentEnrolment::takes()`, which respects the 4th subject and choice pairs. A guardian sees each child's, through the child switcher. Another family's child returns 403.
  - The attachment is available to staff through an authenticated endpoint, and to the portal through a signed URL that expires.
- **SPA:**
  - `HomeworkList.vue` and `HomeworkForm.vue`. A teacher picks from their assigned sections and subjects only.
  - A sidebar entry for teachers and admins.
- **Portal:**
  - `/portal/homework` lists homework grouped by due date, with overdue items marked and the attachment link signed and short-lived.
  - The portal dashboard gets a "Due this week" tile.
  - It goes through the same service as `/api/my/homework`.
- **Docs:** add a "Homework" note to CLAUDE.md.

**Out:**
- Online submission or grading.
- Notifications.

## Acceptance criteria
- [x] The homework table migrates on SQLite and MySQL, including rollback.
- [x] Teachers can only create homework for subjects assigned to them. Admins can create any. The edit window and the curriculum check are enforced.
- [x] Students and guardians see only their own homework, scoped by `takes()`.
- [x] Attachments are private. A signed URL works, and an expired or tampered one returns 403.
- [x] The portal page and its dashboard tile match the API.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Assigning:**
  - An assigned teacher creates homework → 201.
  - An unassigned teacher → 403.
  - An admin → 201 for any section.
- [x] **Validation (422):**
  - A due date before the assigned date.
  - A subject that isn't in the curriculum, or isn't for the section's group.
  - An attachment that's the wrong type or too large.
- [x] **Editing:**
  - The author edits before the due date → 200, and after it → 403.
  - Another assigned teacher edits someone else's homework → 403.
  - An admin edits at any time.
- [x] **Listing:**
  - A teacher's list only shows their own sections.
  - A student sees homework for their compulsory subjects and their chosen 4th subject only, including a choice pair: Biology as 4th still shows Higher Math homework.
  - A guardian sees each child's homework, and another family's child returns 403.
- [x] **Attachments:**
  - An authenticated staff download → 200.
  - A teacher outside the section → 403.
  - A portal signed URL → 200, and an expired or tampered one → 403.
- [x] **Portal:**
  - Grouped by due date, with overdue items marked.
  - The "Due this week" tile counts match the API.
  - One `<h1>`, no-store and noindex.

## Docker check (MySQL)
- [x] Migrate, rollback and migrate again succeeded; `RolePermissionSeeder` re-ran.
- [x] `VHBUB-3` created homework for 10-A, Bangla 1st Paper, with a PDF attachment (201). The same teacher posting for English 1st Paper, which they don't teach, got 403.
- [x] Guardian `01999000047` sees the homework through `/api/my/homework` with its due date and a signed attachment link. The signed link returns 200 `application/pdf`; a tampered link returns 403. Asking for another family's child returns 403.

## Review
- pass-with-nits on `d264ddf`. Fixed: the attachment name is trimmed to 255 characters (a longer one would fail on MySQL), and `ClassSubject::choiceSubjectIds()` is memoized.
