# Issue certificates and print student ID cards

Status: in progress
Branch: feat/certificates

## Problem
The office writes testimonials (প্রশংসাপত্র), transfer certificates, study and character certificates by hand, and student ID cards are made outside the system. There is no register of what was issued, so serial numbers are kept on paper and a TC can be issued to a student who still owes fees.

The intended outcome:
- An admin or office user issues a certificate from a student's page. It gets a serial number from a register, and its printed fields are snapshotted, so a reprint always matches what was issued.
- A TC is blocked while fees are outstanding unless overridden with a note, and issuing it marks the student as left.
- ID cards print in a batch per section, or for one student.

This is Task 4 of 4 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Multiple class teachers (main + co-teachers), the routine and homework are on `main`.

## Scope
**In:**
1. **Data.** A `certificates` table (no soft deletes; cancelling replaces deleting):
   - `type`: `testimonial`, `transfer`, `study` or `character` (`Certificate::TYPES`)
   - `serial_no`, unique, formatted `{PREFIX}-{Asia/Dhaka issue year}-{0001}`, with prefixes TES, TC, STU, CHR. It comes from a locked per-(type, year) counter row in a `certificate_counters` table: select it `for update` and insert only when it's missing, retried with `DB::transaction(..., 3)`, the same as `fee_receipt_counters`. A refused issue rolls the number back. A cancelled certificate's number is never reused.
   - `student_id` (restrict), `enrolment_id` (nullable, restrict), `academic_year_id` (restrict)
   - `issued_on` (Asia/Dhaka date, defaults to today), `issued_by` (users, nullOnDelete)
   - `data` (JSON snapshot of every printed field), `cancelled_at`, `cancelled_by`, `cancel_reason`
   - index on `(type, issued_on)` and `student_id`
2. **Snapshot.** `CertificateService::issue()` builds `data` at issue time and the print view only ever reads `data`:
   - the student's names in both languages, the father's and mother's names, date of birth, student ID
   - class, section, shift, group and roll, from the enrolment used
   - the academic year
   - the school header from `InstituteSettingsService::profile()` (names, address, EIIN if set, logo URL)
   - the **main** class teacher of that section and year (name only, or null), and the head teacher: the active `position=head` staff member of the section's shift (name and designation, or null)
   - plus the per-type fields below
3. **Types and rules** (`CertificateService`, Controller → Service → Repository):
   - **Testimonial**: uses the latest enrolment. Optional admin-entered fields: `exam` (`ssc`/`hsc`), `exam_roll`, `registration_no`, `board`, `passing_year`, `gpa` (`decimal:2`, 0–5), `session`; plus `conduct` (default "ভালো" / good) and `remarks`.
   - **Transfer certificate**:
     - Snapshot fields: admission date, last class and section, last attendance date (the latest `attendances.date` for the student, or null), `reason` (required, max 500), conduct, and the dues status.
     - When the student has outstanding fees (any open due, through the Fees repositories), the issue is a 409 that names the amount, unless `allow_outstanding: true` is sent together with an `outstanding_note` (required with it, max 500). Both go in the snapshot.
     - A TC can't be issued to a student who isn't `active` (422 on `student_id`), and at most one non-cancelled TC per student (409).
     - Issuing it, in the same transaction, sets the student to `left` with `leaving_date` = the issue date through `StudentService::changeStatus()`, which already syncs the logins, guardian access and enrolment. A leaving-date problem from `leavingDateErrors()` is a 422.
     - Cancelling a TC does **not** reactivate the student; say so in the UI and the docs.
   - **Study certificate**: requires an `active` student with an active enrolment in the active academic year (422 on `student_id` otherwise). Text: "currently studying in Class X, Section Y, roll Z, in the academic year".
   - **Character certificate**: any student. `conduct` and optional `remarks`.
   - Text fields are plain text (max 500), never HTML.
4. **API** (numeric id constraints, Resource shapes per the API guideline):
   - `POST /api/certificates` (`edit-students`, so admin and office) → 201.
   - `GET /api/certificates` (`view-students`): the register, paginated, with filters `type`, `student_id`, `academic_year_id`, `from`/`to` (on `issued_on`), `status` (`issued`/`cancelled`) and `search` (serial number or student names/ID). A teacher only sees students in their `TeacherScope` sections (internal `scope_section_ids`, never from input).
   - `GET /api/certificates/{certificate}` (`view-students`, the same teacher scope, 403 outside it).
   - `POST /api/certificates/{certificate}/cancel` (`delete-students`, so admin only), with a required `reason`. Cancelling twice is a 409.
   - `GET /api/id-cards?section_id=` or `?student_id=` (exactly one, `view-students`; a teacher gets 403 for a section or student outside their scope). Returns the active enrolments of the active year in the section by roll (or the one student) with what a card needs: names, student ID, class, section, shift, roll, group, blood group, guardian mobile, `photo_url` and `valid_until` (the year's end date), plus a `school` block. No register entry.
   - `CertificateResource` with a narrow student shape. The student's sensitive fields appear only through the snapshot, which teachers can read only for their own students.
   - Delete guards: `StudentService::delete()` returns 409 while the student has certificates, and so does `AcademicYearService::delete()` for a year that has them.
5. **Printing (SPA, no server PDF):**
   - `views/certificates/CertificatePrint.vue` (`/admin/certificates/:id/print`): A4 portrait, Bangla or English toggle (Bangla digits and dates through `banglaNumber.js`/`banglaDate.js`), the school header and logo, the serial number and issue date, the body text per type, signature lines for the main class teacher and the head teacher, and a CANCELLED watermark on a cancelled certificate. Reads only `data`. Uses the report-card print pattern: `@page` written into a `<style>` and a body class that hides the admin chrome.
   - `views/certificates/IdCards.vue` (`/admin/id-cards?section_id=|student_id=`): CR80 cards (85.6 × 54 mm) in a grid of 10 per A4 portrait page (2 × 5) with crop marks, **front only** (school logo and name, photo, the fields above, valid until). The photo is a public `photo_url`; an initials placeholder is shown when it's missing.
6. **SPA screens:**
   - `views/certificates/Certificates.vue` (`/admin/certificates`): the register, with filters, a Print link and an admin-only Cancel with reason, plus an Issue form: student search, type, and the per-type fields. A TC 409 shows the outstanding amount and offers the override checkbox with its note.
   - `StudentDetails.vue` gets "Issue certificate" (preselects the student) and "Print ID card" buttons.
   - The sidebar, under Students: Certificates and ID cards, gated by route `meta` (`view-students`; issuing needs `edit-students`).
7. **Docs:** add a "Certificates and ID cards" note to CLAUDE.md.

**Out:**
- Board result import for testimonials.
- Server-generated PDFs and QR verification.
- Card backs.
- Certificates for staff.
- Reactivating a student when a TC is cancelled.

## Guidelines that apply
- `docs/architecture-guidelines.md`: a new module (Controller → Service → Repository, bindings, FormRequests, Resource).
- `docs/api-response-guidelines.md`: 201 on issue, 409/422 shapes, `decimal:2` for the GPA and money.
- CLAUDE.md: Asia/Dhaka dates, "Class" not "Grade", the locked-counter pattern from Fees, the main class teacher from Academic structure, `StudentService::changeStatus()` from Students, and teacher scope.

## Acceptance criteria
- [x] The four certificate types can be issued, listed, shown, printed in Bangla and English, and cancelled, and a reprint shows the snapshot.
- [x] Serial numbers are sequential per type and year and never reused.
- [x] A TC is blocked by outstanding dues unless overridden with a note, and it sets the student to left.
- [x] ID cards print per section or per student, 10 per A4 page.
- [x] Permissions and teacher scope are enforced, and `ApiAuthorizationTest` passes.
- [ ] The migration works on SQLite and MySQL, including rollback. SQLite is verified; MySQL is checked on Docker before the merge.
- [x] The full suite, Pint, `npm run build` and smoke pass. CLAUDE.md is updated.

## Test cases
- [x] **Happy path:**
  - Issuing a testimonial, a study and a character certificate returns 201 with serials `TES-2026-0001`, `STU-2026-0001` and `CHR-2026-0001`.
  - A second testimonial is `TES-2026-0002`.
  - The snapshot holds the main class teacher (not a co-teacher) and the head teacher of the student's shift.
- [x] **Counter:**
  - With the counter row missing, it is created on the first issue.
  - A refused issue (a 409 TC) doesn't use up a number.
  - A cancelled certificate's number isn't reused.
  - A new Dhaka year starts again at 0001.
- [x] **Transfer certificate:**
  - With an outstanding due → 409 naming the amount.
  - `allow_outstanding: true` without a note → 422; with a note → 201, and the note is in the snapshot.
  - After a TC the student is `left` with `leaving_date` = the issue date, the student login is inactive, the guardian login too when it was their only active child, and the enrolment is `left`.
  - A second TC → 409. A TC for a student who has already left → 422.
  - Cancelling the TC leaves the student `left`.
- [x] **Study certificate:** a student who has left, or has no active enrolment in the active year → 422.
- [x] **Snapshot:** after renaming the student and changing the main class teacher, `GET {id}` still returns the original values.
- [x] **Validation (422):**
  - a bad `type`
  - a TC without `reason`
  - a `gpa` above 5
  - text over 500 characters
  - `?type[]=x` on the register
  - `id-cards` with neither or both of `section_id` and `student_id`
- [x] **Authorization:**
  - A teacher issuing → 403.
  - Office cancelling → 403; admin cancelling → 200; cancelling twice → 409.
  - A teacher reading a certificate or ID cards outside their sections → 403; inside them → 200.
  - Student and parent → 403 on every endpoint.
- [x] **Guards:** deleting a student, or an academic year, that has certificates → 409.
- [x] **ID cards:** a section returns its active enrolments by roll with `valid_until` = the year end and `photo_url` absolute or null. A single student works.
- [x] **Migration:** rollback and migrate again work.
- [x] **Unit:** `CertificateServiceTest` covers the rules with mocked repositories.

## Decisions made during implementation
- **`snapshot` instead of `data`:** the API sends the stored `data` column as `snapshot`. A resource key named `data` would break Laravel's wrapping. It's only included on a single certificate, like `PostResource::withBody()`.
- **Issue date:** `issued_on` is always today in Asia/Dhaka, so the serial year matches the real issue date.
- **Order of TC checks:** the duplicate-TC 409 is checked before the "student not active" 422.
- **Enrolment status after a TC:** `EnrolmentService::syncStatus()` runs after `changeStatus()`, as promotion does.
- **Teacher scope:** a certificate with no enrolment is outside every teacher's scope.
