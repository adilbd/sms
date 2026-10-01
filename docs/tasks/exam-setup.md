# Set up exams: part marks, paper pairs and subject teachers

Status: done
Branch: feat/exam-setup

## Problem
Exams (Task 2) and GPA results (Task 3) need two things that don't exist yet.

**How each subject is marked.**
- Bangladeshi exams score a subject in parts (written, MCQ, practical), and each part has its own full mark and pass mark.
- At SSC/HSC, Bangla 1st + 2nd Paper and English 1st + 2nd Paper are each graded as one combined subject.
- The curriculum (`class_subjects`) records neither.

**Who teaches each subject in each section.**
- Marks may only be entered by the assigned subject teacher or an admin.
- The `subject_assignments` table exists but has no module, and its unique key allows several teachers for one subject in one section.

The outcome: every curriculum row carries its part marks and an optional paper pair, and admins assign one teacher per subject, section and year. Exams can then read both.

This is Task 1 of 3 in the plan at `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Teacher logins (linking staff to users) are out of scope.

## Scope
**In:**

1. **Marks scheme on `class_subjects`.**
   - Migration columns:
     - nullable `written_full`/`written_pass`, `mcq_full`/`mcq_pass` and `practical_full`/`practical_pass` (unsigned smallint)
     - nullable `paper_group` (ASCII slug, e.g. `bangla`)
   - Backfill existing rows: `written_full = subjects.total_marks`, `written_pass = subjects.pass_marks`.
   - `CurriculumService` rules, with errors keyed per row (e.g. `subjects.3.mcq_pass`):
     - at least one part is set
     - each part's full and pass marks are both set or both null
     - pass ≤ full, and full ≥ 1
     - a `paper_group` has at most 2 rows in a class, and both rows have the same `type` and `group`
   - `SyncCurriculumRequest`:
     - validates the new fields
     - `paper_group` is lowercase ASCII matching `^[a-z0-9-]+$`, max 50
   - `ClassSubjectResource` returns the fields.
   - The rows are created with the subject's defaults when the curriculum editor omits marks:
     - keep backward compatibility when the PUT payload sends no part fields for a row
     - for an existing row, keep the saved marks
     - for a new row, use the subject's `total_marks`/`pass_marks` as written marks
2. **Curriculum editor** (`CurriculumEditor.vue`):
   - per-row inputs for the three parts (full / pass)
   - a paper-group text field
   - a per-row total, and a badge that marks paired rows
3. **`CurriculumSeeder`:**
   - for Class 9–12, sets realistic SSC/HSC parts, e.g. Physics, Chemistry and Biology written 50/17, MCQ 25/8, practical 25/8, and Mathematics written 70/23, MCQ 30/10
   - pairs Bangla 1st/2nd (`bangla`) and English 1st/2nd (`english`) in every class
   - still only fills empty classes. On already-seeded databases the backfill applies instead.
4. **Subject-teacher assignment module** for the existing `subject_assignments` table. Follow the full Subjects pattern:
   - **Migration:** the unique key becomes `(section_id, subject_id, academic_year_id)`. Drop the old 5-column key, minding the FK-before-index order on MySQL.
   - **Code:**
     - `SubjectAssignmentRepositoryInterface`/`SubjectAssignmentRepository`, bound in the provider
     - `SubjectAssignmentService`, FormRequests and `SubjectAssignmentResource`
     - a thin `Api\SubjectAssignmentController` at `/api/subject-assignments` (numeric id constraint)
     - permissions `view-classes` / `create-`, `edit-` and `delete-classes` via `resourcePermissions('classes')`
   - **Index filters:** `academic_year_id` (defaults to active), `class_id`, `section_id`, `staff_id`, `subject_id`.
   - **Rules:**
     - the staff member is active, `category=teacher`, and belongs to the section's shift
     - the subject is in the section's class curriculum (any row for that class)
     - `class_id` is filled from the section
     - the year defaults to the active year (422 on `academic_year_id` if none is active)
     - one teacher per (section, subject, year): a concurrent duplicate becomes a 422 via `App\Support\UniqueViolation`
   - **Bulk endpoint:** `PUT /api/sections/{section}/subject-teachers` with `{academic_year_id, assignments: [{subject_id, staff_id|null}]}`. It replaces the section's assignments for that year in one transaction, with the section row locked. `null` removes an assignment, and errors are keyed per row. Register it before the sections `apiResource`, as the `class-teachers` routes are.
   - **`canEnterMarks(User $user, Section $section, Subject $subject, AcademicYear $year): bool`** on `SubjectAssignmentService`, for Task 2. It returns true for the admin role, and otherwise only if the user's linked staff row (`staff.user_id`) holds that assignment.
   - **Guards:**
     - `StaffService::delete` returns 409 while the staff member has subject assignments.
     - The existing class/section/year `hasSubjectAssignments` guards stay.
     - `CurriculumService::sync` returns 422 on removing a subject from a class while assignments for it exist in that class. The message tells the admin to unassign it first.
5. **SPA:** `views/sections/SubjectTeachers.vue` at `/admin/sections/:id/subject-teachers`, linked from `SectionList.vue`.
   - A year selector, defaulting to the active year.
   - One row per curriculum subject of the class, filtered by the section's group if it has one, otherwise all.
   - A teacher select per row (active teachers of the section's shift).
   - One Save, with per-row 422 errors.
6. **Docs:**
   - Update the Curriculum note in `CLAUDE.md` (the marks scheme and paper pairs).
   - Add a "Subject teachers" note.

**Out:**
- Exams, mark entry and results (Tasks 2–3).
- Teacher logins.
- Showing teachers their own assignments.
- Timetables.

## Guidelines that apply
- `docs/architecture-guidelines.md`: a new module on the Subjects pattern, plus extending the Curriculum and Staff services.
- `docs/api-response-guidelines.md`: `data`/`meta` shapes, 201/204, 422 keyed per row, 409.
- CLAUDE.md domain rules: Class 1–12, groups from Class 9, and "Class", not "Grade".

## Acceptance criteria
- [x] The migrations run on SQLite and MySQL (with rollback). Existing curriculum rows are backfilled with written marks.
- [x] Curriculum PUT/GET carry part marks and `paper_group`. Every marks rule is enforced per row.
- [x] Omitting part fields keeps saved marks, or uses the subject defaults for new rows.
- [x] The subject-assignment CRUD and bulk endpoint follow the guidelines. One teacher per subject, section and year.
- [x] The shift, category, active and curriculum rules are enforced.
- [x] `canEnterMarks` is correct for an admin, an assigned teacher, an unassigned teacher and a user with no staff link.
- [x] The staff-delete and curriculum-removal guards work.
- [x] The editor shows the part marks and pairs, and the Subject Teachers page saves.
- [x] Seeding sets parts and pairs on a fresh database, and re-running it adds nothing.
- [x] The full suite, Pint on changed files, `npm run build` and smoke all pass.
- [x] `CLAUDE.md` is updated.

## Test cases
- [x] **Happy path.**
  - A PUT with Physics parts (50/17, 25/8, 25/8) returns them on GET.
  - Pairing Bangla 1st/2nd with `paper_group=bangla` succeeds.
  - A bulk-assign of 3 subjects to teachers in 9-A returns 200 and a list.
  - `GET /api/subject-assignments?section_id=` is filtered.
  - Deleting an assignment returns 204.
- [x] **Validation (422):**
  - no part set
  - a pass mark without its full mark
  - pass > full
  - a third row in one `paper_group`
  - a pair that mixes compulsory and optional
  - a pair across different groups
  - a bad `paper_group` slug
  - a non-teacher, an inactive teacher, or a teacher outside the section's shift
  - a subject not in the class curriculum
  - a duplicate (section, subject, year)
  - no active year
  - a bad filter (`?section_id[]=x`)
- [x] **Backward compatibility.**
  - A curriculum PUT without part fields keeps an existing row's marks.
  - A new row with no part fields gets the subject's total/pass as written marks.
- [x] **Authorization.**
  - A guest gets 401.
  - A teacher (`view-classes` only) can GET assignments but gets 403 on POST/PUT/DELETE.
  - A student or parent gets 403.
- [x] **Guards.**
  - Deleting a staff member with assignments returns 409.
  - Removing a subject that has assignments from a curriculum returns 422.
- [x] **`canEnterMarks`:**
  - an admin → true
  - an assigned teacher's user → true
  - another teacher's user → false
  - a teacher user with no staff link → false
  - the wrong year → false
- [x] **Edge cases.**
  - `/api/subject-assignments/1abc` returns 404.
  - The backfill migration sets the written marks from the subject.
  - Re-seeding adds no duplicates.
- [x] **Unit tests.** `SubjectAssignmentServiceTest`, plus the `CurriculumServiceTest` additions for the marks rules.
