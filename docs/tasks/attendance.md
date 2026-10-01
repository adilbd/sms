# Build daily attendance by section

Status: done
Branch: feat/attendance

## Problem
Attendance is still the legacy `AttendanceController`. It validates inline, queries Eloquent directly, and uses old response shapes. Its admin views are 8-line placeholders. Its table records `class_id`/`section_id` per row rather than linking to a student's yearly enrolment. The student and parent roles also hold `view-attendance` role-wide, so once real data exists they could read every student's attendance.

The intended outcome:
- Each section's class teacher, or an admin, marks attendance once a day for every enrolled student: present, absent, late or leave.
- The date defaults to today in Asia/Dhaka.
- Fridays (configurable) and listed holidays are skipped.
- Monthly reports are available per section and per student.
- Students and guardians see only their own records.

This is Task 2 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Staff logins and `TeacherScope` from Task 1 are on `main`.

## Scope
**In:**
1. **Data.** The legacy `attendances` table has no real data, so a migration drops and recreates it. `down()` restores the old structure without data.
   - **`attendances`:**
     - `student_id` (FK, cascade)
     - `enrolment_id` (FK student_enrolments, cascade)
     - `section_id` and `academic_year_id` (FK, restrict)
     - `date` (date)
     - `status`: one of present, absent, late, leave
     - `remarks` (nullable, max 255)
     - `marked_by` (FK users, nullOnDelete)
     - timestamps
     - unique on `(enrolment_id, date)`, plus an index on `(section_id, date)`
   - **`holidays`:**
     - `date` (unique)
     - `name_en`, `name_bn`: at least one is required
     - `academic_year_id` (FK, restrict)
     - timestamps
   - **New institute setting `weekly_holidays`:**
     - a list of weekday names, defaulting to `['friday']`
     - registered in `App\Support\InstituteSettings` with validation
     - read through `InstituteSettingsService`
2. **Module** (Controller → Service → Repository; `AttendanceService`, `AttendanceRepository`, `HolidayService`, `HolidayRepository`), with numeric id constraints:
   - `GET /api/attendance/sheet?section_id=&date=` (`view-attendance`):
     - lists the section's active enrolments for the date's academic year, ordered by roll
     - includes any saved status and remarks, plus `is_holiday` and the holiday name
     - `date` defaults to today in Asia/Dhaka
   - `PUT /api/attendance/sheet` (`mark-attendance`): `{section_id, date, entries: [{student_id, status, remarks?}]}`
     - saves the whole sheet in one transaction, locking the section first via `StudentEnrolmentRepository::lockSection`
     - per-row errors use the key `entries.N.field`
   - `GET /api/attendance/report?section_id=&month=YYYY-MM` (`view-attendance`) returns `{data: {month, school_days: [dates], students: [{student, days: {date: status}, totals: {present, absent, late, leave}, percentage}]}}`
     - percentage = (present + late) ÷ school days so far in that month, as a `decimal:2` string
   - `GET /api/attendance/students/{student}?month=` (`view-attendance`) returns one student's month plus their year-to-date totals and percentage.
   - Holidays CRUD at `/api/holidays`:
     - `view-attendance` for index
     - `edit-settings` for writes
     - index filters by `academic_year_id`
3. **Rules for saving a sheet** (`AttendanceService`):
   - **Who may mark:** only the section's class teacher for that year (via `ClassTeacherService`/`TeacherScope`) or an admin. Anyone else gets 403. The office role doesn't get `mark-attendance`.
   - **Which date:**
     - `date` must not be after today in Asia/Dhaka, and must fall within the section's academic year (422)
     - not a holiday, and not a weekly holiday (422 on `date`, naming the reason)
   - **The edit window:** admins may edit any past date. Teachers may only save dates within the last 7 days, counting today (422 otherwise).
   - **Which students:** every entry's student must be on that section's sheet for that year (422 per row). Students who aren't sent are left unchanged.
   - **Who changed it:** `marked_by` is set to the current user on every saved row.
4. **Teacher scope.** For a teacher, the sheet and report work only for sections where they are class teacher (403 otherwise). The section list used by the SPA's attendance pickers is limited to those sections; reuse Task 1's `TeacherScope`. A subject teacher who isn't the class teacher can't mark attendance.
5. **Own records:**
   - `GET /api/my/attendance?month=` (`role:student`)
   - `GET /api/my/children/{student}/attendance?month=` (`role:parent`; returns 403 for anyone who isn't the guardian's own child, through `StudentService::findChildOf`)
   - Both go through `AttendanceService`, the same one the reports use.
   - `RolePermissionSeeder` removes `view-attendance` from the student and parent roles.
6. **Legacy cleanup:**
   - Remove `AttendanceController` and the `attendances`, `attendances/bulk` and `attendances/report/{student}` routes.
   - Remove the old `Attendance` model parts that no longer apply.
   - Repoint the `hasAttendances` guards (class, section, student) to the new table. The class and section guards go through `section_id`.
   - Update CLAUDE.md and both guidelines' legacy lists so they no longer list Attendance.
7. **SPA:**
   - `views/attendance/MarkAttendance.vue`:
     - section picker (class teachers only see their own sections) and date picker
     - everyone defaults to present when nothing has been saved yet
     - tap or click cycles a student's status; a "mark all present" button; per-row remarks
     - Save shows per-row 422 errors and the 403/422 messages
     - shows a banner on holidays and weekly holidays
   - `views/attendance/AttendanceList.vue`: the monthly grid (rows are students, columns are days) with totals and percentage, printable with `@media print`.
   - `views/attendance/Holidays.vue`, under the Academic menu.
   - Sidebar: the Attendance item appears for admins and for teachers who are class teacher of at least one section (based on `/api/my/assignments` `class_teacher_of`).
8. **Seeder.** `HolidaySeeder` adds a few 2026 Bangladesh public holidays: 21 February (Shaheed Dibosh), 26 March (Independence Day), 1 May (May Day), 16 December (Victory Day), and others with fixed dates. It matches on `date`, so it can be re-run. `AttendanceSeeder` adds attendance for the last 5 school days for the seeded Section A sections, with some absences and late marks. It matches on `(enrolment_id, date)`.
9. **Docs.** Add an "Attendance" note to CLAUDE.md.

**Out:**
- Per-period (subject) attendance.
- SMS alerts.
- A timetable or class routine.
- Moveable lunar holidays computed automatically. Admins add those by hand.

## Guidelines that apply
- `docs/architecture-guidelines.md`: Attendance is a legacy module, so it is converted in full.
- `docs/api-response-guidelines.md`: computed data under `data`, 403/422 as described, and the `decimal:2` percentage sent as a string.
- CLAUDE.md: Asia/Dhaka for dates, "Class" not "Grade", and the Staff logins and teacher scope note.

## Acceptance criteria
- [x] The legacy controller and routes are gone. The new tables migrate on SQLite and on Docker MySQL 8 (migrate, rollback and migrate again all succeed).
- [x] A class teacher or an admin can mark and edit a section's day. Every rule (403, future date, holiday, weekly holiday, 7-day teacher window, roster) is enforced, with per-row errors.
- [x] Monthly reports and per-student months are correct, and percentages are sent as `decimal:2` strings.
- [x] Holidays CRUD works, and the `weekly_holidays` setting works.
- [x] `/api/my/*` attendance is scoped to the caller. The student and parent roles no longer have `view-attendance`.
- [x] The SPA mark sheet, the report, holidays, and the role-aware sidebar all work.
- [x] `ApiAuthorizationTest`, the full suite, Pint, `npm run build` and smoke pass. CLAUDE.md and the guidelines are updated.

## Test cases
- [x] **Happy path.**
  - The class teacher of 10-A loads today's sheet, which lists the active enrolments by roll.
  - Saving present, absent, late and leave returns 200, and reloading shows the statuses with `marked_by` set.
  - An admin edits a date 20 days ago and gets 200.
  - The monthly report counts totals, and the percentage is (present + late) ÷ school days.
  - The student endpoint returns the year-to-date figure.
- [x] **403:**
  - a teacher who isn't the class teacher, saving or reading the sheet
  - the office role marking attendance
  - a student or parent calling `/api/attendance/*`
  - a guardian requesting another family's child
- [x] **422:**
  - a future date (Asia/Dhaka)
  - a date outside the academic year
  - a holiday
  - a Friday, or another configured weekly holiday
  - a teacher saving a date older than 7 days
  - a student who isn't on the section's sheet
  - a bad status
  - a malformed month
  - `?section_id[]=x`
- [x] **Edge cases:**
  - Students not sent are left unchanged.
  - Re-saving the same day updates rows rather than duplicating them.
  - A student who left mid-month shows only their days.
  - A holiday added after attendance was marked hides that day from school days.
  - `weekly_holidays` set to `['friday','saturday']` skips both days.
- [x] **Own records:**
  - A student sees only their own month.
  - A guardian sees each child.
- [x] **Legacy:**
  - The old `/api/attendances` routes return 404.
  - The class, section and student delete guards use the new table.
- [x] **Seeders:** `HolidaySeeder` and `AttendanceSeeder`, each run twice, create no duplicates.
- [x] **Unit:** `AttendanceServiceTest` covers each rule with mocked repositories.

## Browser check (Docker MySQL)
- [x] Seeders: 6 holidays and 275 attendance rows (2026-09-27 to 2026-10-01).
- [x] Signed in as `VHBUB-3`, class teacher of 10-A. The sidebar shows Attendance, the section picker offers only 10-A, and the date defaults to 01/10/2026 (today in Dhaka).
- [x] Changing Karim to Late and saving stored the rows with `marked_by=vhbub-3`. The monthly report shows totals and `100.00`.
- [x] For this teacher, the API rejects a future date, a Friday (weekly holiday) and a date 11 days ago (the 7-day window). Another section returns 403.

## Carried to Task 3 (from review)
- A student enrolled mid-year is counted against school days before they enrolled.
- `yearForDate()` runs before the access check, so an out-of-year date returns 422 instead of 403.
- The sheet lists enrolments active today, even for a past date.
- `edit-attendance` and `delete-attendance` are no longer used.
- The holidays `per_page` has no `max`.
