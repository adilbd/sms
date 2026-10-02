# Allow several class teachers per section and several teachers per subject

Status: done
Branch: feat/multi-teachers

## Problem
Today a section has a single class teacher per year, and a teacher can lead only one section a year. The unique keys on `class_sections` enforce both: `(section_id, academic_year_id)` and `(academic_year_id, staff_id)`. A subject in a section can also have only one teacher per year, enforced by the unique key `(section_id, subject_id, academic_year_id)` on `subject_assignments`.

Real schools share both roles:
- A section has a main class teacher plus co-teachers.
- A large section's subject is often split between two teachers.

Intended outcome:
- Each section/year has one **main** class teacher and any number of **co-teachers**, and a teacher may lead several sections.
- Each (section, subject, year) can have any number of teachers.
- All class teachers can take attendance, and any assigned subject teacher can enter marks.
- Report cards, certificates and the dashboard show the **main** class teacher.

This is Task 1 of 4 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Its "Task 1" section is authoritative.

## Scope
See the plan's Task 1 section:
- **Schema:**
  - `class_sections`: drop both unique keys, add `is_main`, add a unique key on `(section_id, academic_year_id, staff_id)`, and backfill `is_main = true` on existing rows.
  - `subject_assignments`: the unique key becomes `(section_id, subject_id, academic_year_id, staff_id)`.
- **Class teacher API:** `GET` and `PUT /api/sections/{section}/class-teachers` (full replace per year).
  - With a non-empty list, exactly one teacher is main.
  - Each teacher must be active, of category teacher, and in the section's shift.
  - Errors come back per row.
  - The old single-teacher `PUT .../class-teacher` stays or goes: decide, document it, and update the SPA to match.
- **Subject teacher API:** `PUT /api/sections/{section}/subject-teachers` takes `{subject_id, staff_ids: []}` per subject.
- **Scope and permissions:**
  - `TeacherScope` returns the union of all of a teacher's class-teacher sections.
  - `canEnterMarks` allows any assigned teacher.
  - Attendance is allowed for any class teacher.
  - `/api/my/assignments` includes `is_main`.
- **Display:** report cards and the dashboard show the main class teacher.
- **SPA:**
  - `SectionList.vue` gets a class-teacher multi-select with a "main" radio.
  - `SubjectTeachers.vue` gets a multi-select of teachers for each subject.
- **Docs:** update the CLAUDE.md notes for class teachers, subject teachers, attendance and staff logins.

**Out of scope:** substitute teachers.

## Acceptance criteria
- [ ] The migrations backfill `is_main` and work on SQLite and MySQL, including rollback. SQLite is verified. MySQL is checked on Docker before the merge.
- [x] Several class teachers per section, with exactly one main. A teacher can lead several sections.
- [x] Several teachers per subject, and every assigned one can enter marks.
- [x] Every class teacher can mark attendance. A teacher who isn't a class teacher gets 403.
- [x] Report cards and the dashboard show the main teacher.
- [ ] The SPA pickers work. The build passes; check them in Chrome on Docker before the merge.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Class teachers:**
  - 1 main + 2 co-teachers saves.
  - Two mains, or a non-empty list with no main, gives 422.
  - A teacher outside the section's shift, or inactive, gives 422 on their row.
  - One teacher can lead 2 sections in a year.
- [x] **Attendance:**
  - A co-teacher can mark it.
  - A teacher who isn't a class teacher gets 403.
  - A retired co-teacher gets 403.
- [x] **Subject teachers:**
  - Two teachers on one subject both save marks.
  - An unassigned teacher gets 403.
  - The bulk PUT replaces the whole set.
- [x] **`TeacherScope` and `/api/my/assignments`:**
  - They return every led and taught section, with `is_main`.
  - A teacher's student list includes all those sections.
- [x] **Display:** the report card and the dashboard name the main class teacher.
- [x] **Migration:**
  - The backfill sets `is_main` on existing rows.
  - Rollback restores the old unique keys. It fails clearly if duplicates exist; document this.
