# Add the class–subject curriculum (per class, per group from Class 9, compulsory vs optional 4th subject)

Status: done
Branch: feat/class-subject-curriculum

## Problem
The academic structure is in place: Class 1–12 with derived levels, `App\Support\AcademicGroup`, and sections with shifts. What's missing is which subjects each class studies.

In Class 9–12 the subject list depends on the student's group, and each group offers optional 4th-subject choices. The Students module (choosing a group and a 4th subject) and Exams/GPA (which subjects are scheduled and graded, with the 4th subject scored differently) both depend on this.

The admin Subjects page (`views/subjects/SubjectList.vue`) is still an 8-line placeholder, even though its API is built.

The intended outcome:
- Admins can manage subjects, including a Bangla name.
- Each class has one current curriculum. Class 1–8 have one list of compulsory subjects. Class 9–12 have common subjects, subjects specific to a group, and the optional 4th subjects each group offers.
- A sample NCTB curriculum is seeded.

## Scope
**In:**

1. **The `class_subjects` table**, with a `ClassSubject` model and factory. There are no soft deletes.

   | Column | Definition |
   |---|---|
   | `class_id` | FK, `cascadeOnDelete` |
   | `subject_id` | FK, `restrictOnDelete` |
   | `group` | nullable; `null` means the whole class, or all groups from Class 9 |
   | `type` | `compulsory` or `optional`, default `compulsory` |
   | `sort_order` | |
   | timestamps | |

   Add an index on `(class_id, group)`. Add the relations `Classes::curriculum()` and `Subject::classSubjects()`.

2. **Add `name_bn`** (nullable) to `subjects`, with the matching changes to the FormRequest, `SubjectResource` and the tests.

3. **Rules**, enforced in a new `CurriculumService` with errors keyed per row (for example `subjects.3.group`):
   - Below Class 9, `group` must be null and `type` must be `compulsory`.
   - From Class 9, an `optional` row is a 4th-subject choice. It's for one group, or for every group when `group` is null.
   - There is at most one row per (class, subject, group).
   - A subject that is common to the class (group `null`) can't also be listed for a specific group.
   - Only active subjects can be added. Rows that already exist stay in place when a subject is deactivated later.

4. **API**, on `Api\ClassController`, using the `view-classes` and `edit-classes` permissions, with a numeric constraint on `{class}`:
   - `GET /api/classes/{class}/subjects?group=` returns `{data: [...]}`. With a group, it returns that group's effective list: the common rows plus the group's rows.
   - `PUT /api/classes/{class}/subjects` takes `{subjects: [{subject_id, group, type}]}`. It replaces the whole curriculum in one transaction, and array order becomes `sort_order`.

5. **Guards:**
   - `SubjectService::delete` returns 409 while a curriculum uses the subject.
   - `ClassService::delete` removes the class's curriculum rows in the same transaction.
   - `ClassService::update` rejects lowering `number` below 9 while the class has group or optional rows (422 on `number`).

6. **Backend pieces:**
   - `ClassSubjectRepositoryInterface` and its repository. It's a narrow contract like `ClassTeacherRepository`, and it's bound in `RepositoryServiceProvider`.
   - `IndexCurriculumRequest` and `SyncCurriculumRequest`. Exists rules ignore soft-deleted subjects.
   - `ClassSubjectResource`, which nests `SubjectResource` including `is_active`.

7. **Seeders**, run from `DatabaseSeeder` after `ClassSeeder`:
   - `SubjectSeeder` seeds NCTB subjects with Bangla names. It's idempotent and matches on `code`.
   - `CurriculumSeeder` seeds a sample curriculum for Class 1–5, 6–8, 9–10 (SSC groups with 4th-subject options) and 11–12 (HSC). It only seeds a class whose curriculum is empty, so it never overwrites admin edits.

8. **Admin SPA:**
   - Build `SubjectList.vue` and `SubjectForm.vue` to replace the placeholder, following the `ShiftList`/`ShiftForm` pattern.
   - Build `views/classes/CurriculumEditor.vue` at `/admin/classes/:id/subjects`, linked from `ClassList.vue`:
     - Class 1–8 show one ordered compulsory list.
     - Class 9–12 show a Common section plus one tab per group, each split into Compulsory and Optional (4th subject), with an effective-list preview per group.
     - Adding subjects uses a subject picker that shows active subjects only. Subjects can be reordered.
     - A single Save sends the whole list, and 422 errors are shown on the row they belong to.
   - Put Subjects in the sidebar's Academic group.

9. **Docs:** add a "Curriculum" note to `CLAUDE.md`.

**Out:**
- Each student's group and 4th-subject choice (the Students conversion).
- Exam schedules generated from the curriculum, and GPA with the 4th-subject bonus.
- Versioning the curriculum by academic year.
- "Choose one of" religion subjects. One Religion & Moral Education subject is seeded for now.
- Assigning a teacher to each subject.

## Guidelines that apply
- `docs/architecture-guidelines.md`: Controller → Service → Repository, with Subjects as the reference module.
- `docs/api-response-guidelines.md`: responses wrapped in `data`, standard errors, 422 and 409 as described.
- The domain rules in `CLAUDE.md`: Class 1–12, groups from Class 9, "Class" in copy (never "Grade"), and Bangla names allowed.

## Acceptance criteria
- [x] `class_subjects` and `subjects.name_bn` are migrated, and the migrations work on SQLite and MySQL, including rollback. Verified on Docker MySQL 8.0 with migrate → rollback --step=2 → migrate, then seeded: 23 subjects and 136 curriculum rows, and a re-seed added nothing.
- [x] `GET` and `PUT /api/classes/{class}/subjects` follow the response guideline and are covered by `ApiAuthorizationTest`.
- [x] Every rule listed under Scope item 3 is enforced in `CurriculumService`, with 422 errors keyed per row.
- [x] `?group=` returns that group's effective list.
- [x] The subject delete guard returns 409, class delete cascades to curriculum rows, and lowering a class's number below 9 returns 422 when the class has group or optional rows.
- [x] `name_bn` is stored and returned on subjects.
- [x] `migrate:fresh --seed` seeds subjects and a curriculum for every class, and a second seed run adds nothing.
- [x] The Subjects screen and the Curriculum editor work against the API and show 422 and 409 messages.
- [x] The full suite, Pint on new files, `npm run build` and the smoke script all pass.
- [x] `CLAUDE.md` is updated.

## Test cases
- [x] **Happy path.**
  - `GET` returns `data[].subject.name` in order.
  - `?group=science` returns the common rows plus the science rows, and no humanities rows.
  - `PUT` replaces the list: it deletes rows that aren't sent, and array order becomes `sort_order`.
  - `subjects: []` clears the curriculum.
  - An optional row with a null group is accepted on Class 9.
- [x] **Validation (422):**
  - a group on Class 8
  - an optional row on Class 7
  - the same subject twice for the same group
  - a subject that is both common and group-specific
  - an inactive subject
  - a soft-deleted subject
  - an unknown group in the body
  - `?group=x` on the list
  - a missing `subjects` key
  - a type that isn't `compulsory` or `optional`
- [x] **Authorization:**
  - A guest gets 401.
  - A role without `edit-classes` gets 403 on `PUT`.
  - A teacher (`view-classes`) can `GET`.
- [x] **Conflicts and guards:**
  - Deleting a subject used in a curriculum returns 409.
  - Deleting a class removes its curriculum rows.
  - Lowering Class 9's number to 8 while it has group or optional rows returns 422. It's allowed when the class has only common compulsory rows.
- [x] **Edge cases:**
  - `/api/classes/1abc/subjects` returns 404.
  - An inactive subject that is already in a curriculum can still be read back, with `subject.is_active=false`.
  - `name_bn` round-trips on subject store, update and show.
  - Running the seeders twice creates no duplicates.
- [x] **Unit:** `CurriculumServiceTest` covers each rule with a mocked repository interface. The `ClassServiceTest` and `SubjectServiceTest` guard additions are covered.
