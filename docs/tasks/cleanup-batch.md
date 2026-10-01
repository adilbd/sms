# Clear the follow-up list from earlier reviews

Status: done
Branch: fix/cleanup-batch

## Problem
Earlier tasks left a list of small follow-ups, recorded in their task files and reviews. The worst of them is a random academic-year factory value. It has made a different test fail roughly once every few runs, which keeps hiding real failures.

The intended outcome:
- The suite is reliable: 3 consecutive full green runs.
- Every follow-up listed below is fixed, each with a test.

This is Task 3 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`.

## Scope
**In:**

1. **Flaky factory.**
   - `AcademicYearFactory` produces a unique, consistent `year`, `code` (the year), `name` and Jan 1–Dec 31 dates, using a sequence that can't collide with years tests hardcode. For example, start at 2100+ or skip any year that already exists.
   - Remove the per-test workarounds that are redundant once the factory is fixed. Only remove a workaround if the test stays clear on its own.
   - Run the full suite 3 times in a row and report all three summary lines.
2. **Promotion: `skip` action** (`PromotionService`, `ApplyPromotionRequest`, `Promotion.vue`):
   - `skip` leaves the student completely untouched: the source enrolment stays active, nothing is created, and the student isn't counted in capacity.
   - A student who is already enrolled in the target year can be skipped instead of blocking the section. The preview suggests `skip` for those students.
   - The summary counts skipped students.
3. **Promotion: result screen.** The result screen lists target sections with their class, e.g. "Class 7 – Section A (Morning)".
4. **`TrustedProxies::apply()`:**
   - Filter out empty entries, so a trailing or doubled comma does nothing.
   - When the value is empty or null, call `TrustProxies::flushState()`.
5. **Trusted login IPs aren't invalidated.**
   - Add a per-user trust version that is part of the trust key, e.g. `login-trusted:{id}:{version}:{sha1(ip)}`, with the version stored at `login-trust-version:{id}`.
   - Bump the version, which invalidates every trusted IP for that user, when:
     - the user changes their password
     - an admin resets a student, guardian or staff password
     - the login is deactivated
   - Put this in one place, for example a `UserRepository` or `AuthService` method, and call it from `AuthService::changePassword`, `StudentService`, `StaffService` and wherever tokens are revoked.
6. **Admin router catch-all.** An unknown `/admin/...` path renders blank today. Add a `/:pathMatch(.*)*` route that redirects to the dashboard, or to a small not-found view inside the layout.
7. **Attendance: enrolment start.** A student who enrols mid-year is counted against school days before they joined.
   - Add `student_enrolments.enrolled_on` (date, nullable). Backfill it from the Asia/Dhaka date of `created_at`, or the academic year start if that is later.
   - `EnrolmentService` sets it to today in Dhaka on a new enrolment. Promotion sets it to the target year's `start_date`.
   - The attendance report and the per-student school-day range start at `max(range start, enrolled_on)`.
   - The sheet for a past date lists the enrolments that were active on that date: `enrolled_on <= date`, and not left or graduated before it. Use the student's `leaving_date` where that is set.
   - Tests: a mid-year joiner's percentage only counts days since joining, and the sheet for a date before joining doesn't list them.
8. **Attendance: check order.** `yearForDate()` runs after the access check, so a user without access gets 403 even when the date is outside the year.
9. **Small nits:**
   - `RolePermissionSeeder`: add a comment that `edit-attendance` and `delete-attendance` are kept but unused by routes. Admins keep them.
   - `IndexHolidayRequest`: `per_page` max 100.
   - `SubjectSeeder`: only set `total_marks` and `pass_marks` when a subject is created, so a re-run doesn't overwrite an admin's edits. Keep the name sync.
   - `Gpa.php`: re-wrap the long docblock lines.
   - `ExamResultFactory`: make the defaults consistent: `passed_count` > 0 with `is_pass` true and grade `A`.
   - `ExamSetupMigrationsTest`: fix the "seven newest migrations" comment, or remove the count.
   - `StaffService.php`: remove the stray blank line at the start of the `try` block.
   - The `login_enabled` migration docblock: note that it only backfills logins that are currently active.
10. **Docs.** Update the CLAUDE.md notes for promotion (`skip`), attendance (`enrolled_on`) and trusted logins (trust version).

**Out:**
- Any new feature beyond these items.

## Acceptance criteria
- [x] The full suite passes 3 times in a row with no flaky failure.
- [x] Every item above is done, with a test where it's behaviour.
- [x] The `enrolled_on` migration works on SQLite and on Docker MySQL 8, including rollback and re-run.
- [x] Pint, `npm run build` and smoke pass. CLAUDE.md is updated.

## Test cases
- [x] **Factory.** Creating 50 academic years with the factory, next to a hardcoded 2024–2027 set, never collides.
- [x] **Promotion `skip`.**
  - A skipped student's source enrolment stays active, and no target enrolment is created.
  - An already-enrolled student can be skipped while the rest of the section is promoted.
  - The preview suggests `skip` for already-enrolled students.
  - The summary counts skips.
- [x] **`TrustedProxies`.** `"10.0.0.1,,"` trusts only 10.0.0.1. An empty value clears any earlier trust.
- [x] **Trust version.**
  - After a password change, a previously trusted IP is subject to the per-account lock again.
  - The same happens after an admin resets a password, and after a login is deactivated.
- [x] **Router.** An unknown `/admin/foo` lands on the dashboard or the not-found view. Check with a unit or route test where possible, otherwise document the manual check.
- [x] **`enrolled_on`.**
  - A mid-year joiner's report only counts school days from `enrolled_on`.
  - A past-date sheet excludes students enrolled later and includes students who left later.
  - Promotion sets `enrolled_on` to the target year's start.
  - The backfill is correct.
- [x] **Attendance check order.** An out-of-year date from a non-class-teacher returns 403.
- [x] **`SubjectSeeder`.** A re-run keeps an admin-edited pass mark.

## Docker and browser check
- [x] **Bug found and fixed on this branch.** The first `enrolled_on` backfill used `created_at`, which set every 2026 enrolment to 2026-10-01 (the seed date) and hid past attendance. It now uses `App\Support\EnrolmentStart`: the admission date, clamped to the year. After the fix, 2026 enrolments are 2026-01-05, promoted 2027 enrolments are 2027-01-01, and the 10-A sheet for 2026-09-30 lists all 5 students again.
- [x] An unknown `/admin/this-does-not-exist` redirects to the Dashboard.

## Carried to Task 4 (from review)
- `AttendanceService::studentView` returns 404 for a student with no enrolment before it checks access, which reveals whether a student is enrolled. Check access first, and share the 403 helper with `authorizeSection`.
