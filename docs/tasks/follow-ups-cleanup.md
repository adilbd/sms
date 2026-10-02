# Clear the accumulated review follow-ups

Status: done
Branch: fix/follow-ups-cleanup

## Problem
Reviews of the last tasks left small follow-ups in their task files: races, query counts, flaky factories, accessibility and wording. None of them blocks anything alone, but together they add up to flaky tests, wrong totals and confusing labels. This task clears every follow-up that is still open, in one change.

## Scope
**In.** For each item, first confirm it still applies on `main`. If it was fixed already, skip it and say so in the report.
1. **Routine** (`class-routine.md`):
   - `PeriodService::update()` reads the years that use a period before locking them. Lock the period row first, or re-read the years under the lock, so a routine save in another year can't slip in between. Reword the "only lock both paths share" comment.
   - `RoutineService` runs several queries per cell. Preload active staff, the year's assignments and the other sections' slots once, then check in memory. The error messages and keys must not change. Add a query-count test that doesn't grow with the number of cells.
2. **Factories:** `SectionFactory`/`ClassSectionFactory` can pick a colliding random class number. Fix them the way `AcademicYearFactory` was fixed (a sequence or unique values) and make sure the suite passes several times in a row.
3. **Admissions** (`admission-print.md`, `online-admission.md`):
   - Raise the signed copy-photo URL lifetime to 60 minutes. Update the CLAUDE.md note and the tests.
   - Bundle Noto Sans Bengali for the admin print views (`ApplicationPrint.vue`, `ApplicationListPrint.vue`, `ReportCards.vue`, `FeeReceipt.vue`, `CertificatePrint.vue`, `IdCards.vue`, `RoutinePrint.vue`; grep for `fonts.googleapis`) via `@fontsource/noto-sans-bengali`, imported once, instead of the runtime Google Fonts link. The public Blade pages are out of scope.
   - `ApplicationPrint.vue`: change the second school name from `<h2>` to `<p>`.
   - CLAUDE.md Admissions note: move "PDFs as attachments, images inline" to the admin file endpoint sentence.
4. **Exams** (`compulsory-choice.md`): `MarkEntry.vue` and `ExamSchedule.vue` label a choice-pair member "4th". When `choice_group` is set, show "Either/or" (the `ExamSubject` resource must carry `choice_group`; check it does).
5. **Dashboard** (`dashboard.md`):
   - `BarList.vue`: mark the bar `aria-hidden="true"` and drop the `progressbar` role and attributes.
   - `DashboardService`: choice-pair subjects must not run one query per exam subject. Derive the pair from the loaded subjects and extend the query-count test.
   - `DashboardRepository` fee totals include dues of enrolments that have since left. Make the month figures consistent with `FeeReportService`; decide by comparing with it. If the fees reports also count them, keep both counting and document it rather than diverge. Add a test.
6. **Small fixes:**
   - `SyncSectionSubjectTeachersRequest.php` docblock (~line 30): it still says comparing across subjects "is fine". Reword it to match the per-row check.
   - Portal (`portal.md`): `PortalPageRequest` narrows `page` to `a4` on every portal page. Move that rule into the print requests only.
   - Portal: the password is written twice (`changePassword`, then `logoutOtherDevices`). Make it one write plus revoking the other sessions, in a way that can't leave other sessions signed in after a partial failure.
   - Admin SPA: on `/admin/login?from=portal`, show a short note: "This login is for staff. Students and guardians sign in at the portal." with a link to `/portal/login`.
   - `ClassSubject.php`: put the choice cache property above the "Mirrors the column defaults" docblock, as `ExamSubject` does.
   - `HomeworkService::safeName()`: cut with `mb_substr` instead of `Str::limit`, so length is measured the same way throughout.

**Out:**
- HSC Biology/Higher Math with 1st and 2nd papers in a choice pair (a curriculum feature that needs a decision).
- Counter row pre-creation (the retry covers it).
- The duplicate index in the certificates migration (it has already run).

## Guidelines that apply
- `docs/architecture-guidelines.md` (queries only in repositories) and `docs/api-response-guidelines.md` (no response shape changes).
- CLAUDE.md: update every note whose behaviour changes (routine, admissions, dashboard, portal).

## Acceptance criteria
- [x] Every item is fixed or reported as already fixed.
- [x] No API response shape or error message changes, except where an item says so.
- [x] The full suite passes three runs in a row. Pint, `npm run build` and smoke pass.

## Test cases
- [x] The routine save query count doesn't grow with the number of cells, and every existing clash test still passes.
- [x] The period move locks the period first: a unit test checks the lock order.
- [x] Factories: creating 30 sections and 30 class-section rows in one test never collides.
- [x] The copy-photo URL expires after 60 minutes, not 10.
- [x] Dashboard: the query count is unchanged with choice-pair subjects. The fee totals match `FeeReportService` with a student who has left.
- [x] Portal: `?page=2` on a non-print portal page isn't rejected or narrowed. The password change revokes the other sessions and writes the hash once.
- [x] `/admin/login?from=portal` shows the note.

## Decisions made during implementation
- **Period moves lock every academic year**, not only those that have slots, in ascending id order. This rules out a save in a year the period doesn't appear in yet. The lock is taken only when the period's times change.
- **Routine request validation:** the per-cell `exists` rules became one `whereIn` per table in `after()`, so the request's query count doesn't grow with the number of cells either.
- **Dashboard fee totals:** `FeeReportService` also counts dues of enrolments that have left. Both keep counting them, which is now documented and tested.
- **Portal password:** the hash is written once. The `AuthenticateSession` middleware ends the other sessions.
- **`/admin/login?from=portal` note:** it has no automated test, because the SPA has no JS test setup.
