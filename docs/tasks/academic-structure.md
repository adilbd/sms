# Build the academic structure (Classes, Sections, Academic Years, Class teachers)

Status: done
Branch: feat/academic-structure

## Problem
Students, Exams/GPA, Fees and Attendance all depend on the school's academic structure, and that structure is still legacy code.

- `ClassController`, `SectionController` and `AcademicYearController` validate inline and query Eloquent in the controller. They return legacy shapes (flat paginator, bare models, 200 on delete), and `ClassController::destroy` returns `$e->getMessage()` with a 500. `?search` has an ungrouped `orWhere`, and `per_page` isn't clamped.
- None of the domain rules in `CLAUDE.md` are enforced. Nothing limits classes to Class 1–12, there are no levels, and there are no Science/Business Studies/Humanities groups.
- Sections have no shift, although the school runs Morning and Day shifts.
- `class_sections` (section per year, with a class teacher) points at `users` and isn't used anywhere.
- The admin Classes page is a placeholder, and there is no Academic Years screen.

The outcome is:
- The whole module follows Controller → Service → Repository and returns guideline-compliant responses.
- Classes are fixed to Class 1–12 and have a derived level.
- Every section belongs to a shift and may carry a group from Class 9.
- Academic years run January to December, and exactly one is active.
- Class teachers (staff) are assigned per section per year.
- Admin screens cover all of the above.

## Scope
**In:**

1. **Classes.**
   - `number` (1–12, unique) and `name_bn` (nullable) are added.
   - The level is derived from `number` and not stored: `primary` 1–5, `junior_secondary` 6–8, `secondary` 9–10, `higher_secondary` 11–12.
   - `has_groups` is true when `number >= 9`.
   - `code` stays unique and is uppercased on input, as in Subjects.
2. **Groups.** `App\Support\AcademicGroup` holds `science`, `business_studies` and `humanities`, with English and Bangla labels. It is not a table.
3. **Sections.**
   - `shift_id` is added. It is required in validation, and the FK is `restrictOnDelete`.
   - `group` is added. It is nullable and is only allowed when the class number is 9 or higher. The service checks this against the model with the input applied.
   - The unique key changes from `(class_id, code)` to `(class_id, shift_id, code)`.
   - `class_id` can't be changed on update.
   - The shift must be active.
   - `capacity` must be between 1 and 200.
4. **Academic years.**
   - `year` (2000–2100, unique) is added.
   - `start_date` and `end_date` default to Jan 1 and Dec 31 of `year`. When they are sent, both must fall inside that calendar year and end must be after start. The service checks this on partial updates too.
   - `code` defaults to the year.
   - `activate` runs in a transaction and leaves exactly one year active.
5. **Class teachers.**
   - `class_sections.class_teacher_id` (users) is replaced by `staff_id` (staff, restrict).
   - Unique indexes: `(section_id, academic_year_id)` and `(academic_year_id, staff_id)`.
   - `GET /api/sections/{section}/class-teachers` lists one row per year.
   - `PUT /api/sections/{section}/class-teacher` takes `{academic_year_id, staff_id|null}`, and `staff_id: null` unassigns.
   - The staff member must be active, have category `teacher` and belong to the section's shift.
   - A teacher leads at most one section per year.
6. **Delete guards** (409, plain-sentence messages, because these models use soft deletes):
   - **Class:** has sections, students, attendances, exam schedules, fee structures or subject assignments.
   - **Section:** has students, attendances, exam schedules or subject assignments. Its class-teacher rows are deleted in the same transaction.
   - **Academic year:** is active, or is referenced by students, exams, fee structures, class-teacher rows or subject assignments.
   - **Shift:** is used by sections (`ShiftService::delete`).
   - **Staff:** is a class teacher (`StaffService::delete`).
7. **Backend pieces.**
   - Repositories, interfaces and bindings.
   - Services: `ClassService`, `SectionService`, `AcademicYearService`, `ClassTeacherService`.
   - FormRequests: Index/Store/Update for each model, plus `AssignClassTeacherRequest`.
   - Resources: `ClassResource`, `SectionResource`, `AcademicYearResource`, `ClassTeacherResource`.
   - Thin controllers.
   - Numeric id constraints on `classes`, `sections`, `academic-years` and the activate route.
   - Factories.
8. **Seeders** (idempotent, matched on natural keys):
   - `ClassSeeder`: Class 1–12, with Bangla names প্রথম … দ্বাদশ শ্রেণি and codes `C01`…`C12`.
   - `AcademicYearSeeder`: 2026, active.
   - `SectionSeeder`: Section A for every class in each seeded shift.
9. **Admin SPA.**
   - `constants/academic.js`.
   - `ClassList.vue` and `ClassForm.vue`: classes grouped by level, with their sections.
   - `SectionList.vue` and `SectionForm.vue`:
     - filters for class, shift and group
     - the group select only shows for Class 9 and above
     - a class-teacher column for a chosen academic year, which defaults to the active one
   - `AcademicYearList.vue` and `AcademicYearForm.vue`: create, edit, activate and delete.
   - A collapsible **Academic** sidebar group in `Layout.vue`.
   - Check that the `/classes` fetch in `StudentList.vue` still works.
10. **Docs.**
    - Add an "Academic structure" note to `CLAUDE.md`.
    - Remove these modules from the legacy tables in both guidelines.

**Out:**
- The class–subject curriculum by group, including the optional 4th subject. This is the next task.
- Converting the Students module, and assigning a group to each student.
- Converting Attendance.
- Repointing `subject_assignments` to sections per year.

## Guidelines that apply
- `docs/architecture-guidelines.md`: legacy modules are converted in full, in this same change.
- `docs/api-response-guidelines.md`: every endpoint moves to `data`/`meta`, returns 201 on store and 204 on delete, and leaves errors to the handler. SPA consumers are updated in the same change.
- The domain rules in `CLAUDE.md`: Class 1–12, levels, groups from Class 9, Jan–Dec academic year, and "Class" in copy, never "Grade".

## Acceptance criteria
- [x] The three legacy controllers are thin and call one service method per action. They contain no Eloquent, no `DB::`, no inline validation and no try/catch.
- [x] Every endpoint returns the guideline shapes: `data`, plus `links`/`meta` on lists, 201 on store and 204 on delete. `per_page` is clamped.
- [x] Classes are restricted to numbers 1–12. `level` and `has_groups` come out of the resource.
- [x] Every section has an active shift. A group is only accepted from Class 9, and codes are unique per class and shift.
- [x] Academic years stay inside their calendar year, and exactly one is active after `activate`.
- [x] A class teacher can be assigned and unassigned per section per year, with the staff rules above.
- [x] Every delete guard returns 409.
- [x] Non-numeric ids return 404.
- [x] `migrate:fresh --seed` produces 12 classes, 2026 active, and Section A in each shift. Re-running the seeders creates no duplicates.
- [x] The admin screens work against the new shapes. They show 422 errors per field and show the 409 message.
- [x] `ApiAuthorizationTest`, `PublicSeoTest` and the full suite pass, and the smoke script passes.
- [x] `CLAUDE.md` and both guidelines are updated.

## Test cases
- [x] **Happy path.**
  - The class list is ordered by number and has `data[].level` and `meta.total`.
  - Creating a section in Class 9 with `group=science` returns 201.
  - Creating year 2027 returns 201, and its dates default to 2027-01-01 and 2027-12-31.
  - Activating 2027 deactivates 2026.
  - Assigning an active teacher from the section's shift returns 200.
  - Deleting an unused class, section or year returns 204.
- [x] **Validation (422).**
  - Class: `number` of 0 or 13, a duplicate number, or a duplicate code (compared case-insensitively after uppercasing).
  - Section: missing `shift_id`; an inactive shift; `group` on a Class 8 section; a partial update that adds a group to a Class 5 section; a duplicate code in the same class and shift. The same code in another shift is OK.
  - Section update: sending `class_id` is prohibited.
  - Year: `start_date` or `end_date` outside `year`; end before start; a partial update of `end_date` that falls before the saved `start_date`.
  - Class teacher: a non-teacher, an inactive teacher, or a teacher not in the section's shift; the same teacher on a second section in the same year.
  - List filters: `?search[]=x` and an invalid `level` or `group`.
- [x] **Authorization.**
  - Guest → 401.
  - A role without `create-classes`, `edit-classes` or `delete-classes` gets 403 on class and section writes and on the class-teacher PUT.
  - A role without `edit-settings` gets 403 on year writes and activate, while any signed-in user can read years.
- [x] **Conflicts (409).**
  - Deleting a class that has sections.
  - Deleting a section that has students. Deleting a section that only has class-teacher rows succeeds and removes those rows.
  - Deleting the active year, or a year referenced by students.
  - Deleting a shift that sections use.
  - Deleting a staff member who is a class teacher.
- [x] **Edge cases.**
  - `/api/classes/1abc` and `/api/sections/1abc/class-teachers` return 404.
  - `staff_id: null` unassigns, and unassigning when nothing is assigned is a no-op that returns 200.
  - A concurrent duplicate code is reported as a 422, not a 500.
  - Errors are JSON even without an `Accept` header.
- [x] **Unit.** The services' rules are tested with mocked repository interfaces: the group-from-9 rule, calendar-year bounds, the single active year, the teacher-per-year rule, and each delete guard.
