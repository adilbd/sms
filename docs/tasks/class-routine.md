# Add class routines: periods per shift and a weekly grid per section

Status: done
Branch: feat/class-routine

## Problem
There is no class routine (timetable). Teachers, students and guardians don't know who teaches what, when or where, and nothing stops a teacher or room being double-booked.

Intended outcome:
- Admins define the periods for each shift (number, times, breaks).
- Each section gets a weekly grid of school days × periods, and each cell holds a subject, a teacher and a room.
- Teacher and room double-bookings are refused.
- Teachers, students and guardians each see their own routine, in the admin panel and the portal, and can print it.

This is Task 2 of 4 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. The "Task 2" section there is authoritative.

## Scope
See the plan's Task 2.
- **Data:**
  - `periods`: `shift_id`, `number`, `name_en`/`name_bn`, `start_time`/`end_time` (Asia/Dhaka wall clock), `is_break`.
    - Unique on (shift, number).
    - Periods in the same shift must not overlap.
  - `routine_slots`: `academic_year_id`, `section_id`, `day` (saturday..thursday, excluding the institute's `weekly_holidays`), `period_id`, `subject_id`, `staff_id` (nullable), `room` (nullable).
    - Unique on (year, section, day, period).
- **`RoutineService` rules,** all inside one transaction with the section locked:
  - The period belongs to the section's shift and is not a break.
  - The subject is in the section's curriculum (group-aware).
  - The teacher is assigned to that subject in this section and year, through Task 1's multiple subject teachers.
  - **Teacher clash:** the same teacher can't be in two places on the same day at overlapping *times*, across sections and shifts.
  - **Room clash:** the same room can't be used twice on the same day at overlapping times. Rooms are compared trimmed and case-insensitive.
  - Errors are keyed per cell (`slots.N.field`) and name the section that clashes.
  - Saving replaces the whole grid.
- **API:**
  - `/api/periods` CRUD (`edit-settings` for writes; reads open to signed-in staff, like shifts).
  - `GET`/`PUT /api/routines/sections/{section}?academic_year_id=` (`view-classes` to read, `edit-classes` to save).
  - `GET /api/routines/teachers/{staff}`: a teacher may only read their own (403 for anyone else's); an admin may read any.
  - `GET /api/my/routine` returns the student's own section routine, the selected child's routine for a guardian, or the teacher's own week.
- **SPA:**
  - `Periods.vue`, per shift and reached from Shifts.
  - `RoutineEditor.vue`: a day × period grid with a subject select, a teacher select filtered to that subject's assigned teachers, a room field, and clash errors shown inline.
  - `TeacherRoutine.vue`.
  - Print views for section and teacher routines: A4 landscape, Bangla or English (`banglaNumber.js`, `banglaDate.js`).
  - Sidebar entries by permission. Teachers see "My routine".
- **Portal:** `/portal/routine`, in the portal's look, with a print option. Guardians switch between children with the existing switcher. It goes through the same service as `/api/my/routine`.
- **Docs:** add a CLAUDE.md "Class routine" note.

**Out:**
- Auto-generating routines.
- Substitute teachers.
- Per-period attendance.

## Acceptance criteria
- [x] Periods work per shift, with overlap checks.
- [x] The routine grid saves, with every rule and clash check enforced.
- [x] Teacher, student, guardian and portal views each show only the caller's own routine.
- [x] Print views work in Bangla and English. The portal routine page (guardian of 10-A) shows the subject, room and teacher, prints A4 landscape, has one `<h1>`, and is no-store and noindex.
- [x] Migrations work on SQLite and on Docker MySQL 8, including rollback and a re-run, also after `room_key` was widened to 100.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Periods:**
  - Overlapping periods in one shift → 422.
  - A duplicate number → 422.
  - Deleting a period used by routine slots → 409.
- [x] **Grid validation (422):**
  - a break period given a subject
  - a period from another shift
  - a day that is a weekly holiday
  - a subject not in the curriculum, or not for the section's group
  - a teacher not assigned to that subject
- [x] **Clashes (422):**
  - The same teacher in 10-A and 9-A at the same day and time.
  - The same teacher across Morning and Day shifts at overlapping times.
  - The same room, e.g. "room 101" and "Room 101 ", at the same time.
  - The same teacher at non-overlapping times → OK.
- [x] **Replace:** saving a grid replaces the old one, and cells left out are removed.
- [x] **Access:**
  - A teacher reading another teacher's routine → 403.
  - `/api/my/routine` returns only the caller's own routine for a student, a guardian (each child) and a teacher.
  - A guardian asking for another family's child → 403.
  - A student or parent calling `/api/routines/*` → 403.
- [x] **Portal:** the portal routine page matches the API, has one `<h1>`, and is noindex and no-store.

## Docker check
- [x] An overlapping period, a teacher clash (VHBUB-3 in 9-A at Sunday 08:00-08:40), a room clash (" room 101 ") and Friday were each refused with a message naming the clash.
- [x] A guardian sees their child's 10-A routine. VHBUB-9 reading VHBUB-3's routine gets 403, and VHBUB-9 sees their own week.

## Follow-ups (from review)
- Small race in `PeriodService::update`: it reads the years using a period before locking them, so a routine save in another year can slip in between. Lock the period row first, or document the gap. Also reword the "only lock both paths share" comment.
- Routine saves run several queries per cell. Preloading would help with large grids.
- The `Section`/`ClassSection` factories can pick a colliding random class number, which makes tests flaky. Fix the factories as was done for academic years.
