# Build exams, exam schedules and mark entry

Status: done
Branch: feat/exams-and-marks

## Problem
The curriculum now holds each subject's part marks and paper pairs, and each section's subjects have assigned teachers. Exams are still stubs:
- `ExamController`, `ExamScheduleController` and `ExamResultController` return `{data: []}` or 501.
- `exams/{exam}/publish` and `exam-results/student/...` point at methods that don't exist, so they return 500.
- The `exams`, `exam_schedules` and `exam_results` tables were never used, and their columns don't fit part-wise marks.

The outcome:
- An admin creates an exam for an academic year and selects its classes.
- The exam's subject schedule is generated from each class's curriculum as a snapshot, so later curriculum edits don't change past exams.
- Marks are entered by section and subject, part by part. Only the assigned subject teacher or an admin can enter them.

GPA processing, publishing and report cards are Task 3 (`feat/exam-results`). This is Task 2 of 3 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`.

## Scope
**In:**

1. **Tables.** Drop the never-used `exam_results`, `exam_schedules` and `exams` tables, along with their models (`ExamSchedule`, `ExamResult`) and stub controllers (`ExamScheduleController`, `ExamResultController`) and those routes. Task 3 recreates `exam_results` with its own shape. `down()` recreates the old structure without data. Then create:
   - **`exams`** (soft deletes):
     - `academic_year_id` (FK, restrict)
     - `name_en` and `name_bn`, at least one required
     - `code`, unique per year
     - `type`: `class_test`, `half_yearly`, `test`, `model_test`, `pre_test` or `annual`
     - `start_date` and `end_date`, which must fall within the academic year, with end on or after start
     - `status`: `draft`, `marks_entry`, `processed` or `published`; default `draft`. Only `draft`/`marks_entry` are used here; Task 3 adds the rest.
     - `published_at` (nullable)
   - **`exam_subjects`**:
     - `exam_id` (FK, cascade), `class_id` (FK, restrict) and `subject_id` (FK, restrict)
     - a snapshot of the curriculum row: `group`, `type`, `paper_group`, and the six part full/pass marks
     - `exam_date`, `start_time` and `end_time`, all nullable and in Asia/Dhaka wall-clock time like shifts
     - `sort_order`
     - unique on `(exam_id, class_id, subject_id)`
   - **`exam_marks`**:
     - `exam_subject_id` (FK, cascade), `student_id` (FK, cascade) and `enrolment_id` (FK, restrict)
     - `written`, `mcq` and `practical`: nullable `decimal(5,2)`, with the `decimal:2` cast
     - `is_absent`
     - `entered_by` (FK users, nullOnDelete) and timestamps
     - unique on `(exam_subject_id, student_id)`

2. **Exams API** (`Api\ExamController`, `ExamService`, `ExamRepository`):
   - CRUD at `/api/exams`, using the `*-exams` permissions and a numeric id constraint.
   - Index filters: `academic_year_id` (defaults to the active year), `type`, `status` and `search`.
   - The store and update payloads take `class_ids[]`. Store generates `exam_subjects` from each class's curriculum inside one transaction, copying the curriculum's part marks and pairing.
   - Adding a class on update generates its subjects. Removing a class deletes its subjects; once marks exist for that class it returns 409.
   - `POST /api/exams/{exam}/classes/{class}/regenerate` (`edit-exams`) re-syncs one class from the curriculum. It returns 409 once any marks exist for that class.
   - Delete returns 409 once any marks exist.
   - `POST /api/exams/{exam}/open-marks-entry` (`edit-exams`) moves `draft` to `marks_entry`, and returns 409 from any other status.
   - Remove the old `exams/{exam}/publish` route; Task 3 adds it back properly.

3. **Schedule API**:
   - `GET /api/exams/{exam}/subjects?class_id=` (`view-exams`).
   - `PUT /api/exams/{exam}/subjects/{examSubject}` (`edit-exams`) edits the date, times and part marks. It checks that the subject belongs to the exam, otherwise 404.
   - Part marks follow the same rules as the curriculum: at least one part, full and pass set together, pass no greater than full. Changing marks after any marks have been entered for that subject returns 409.

4. **Mark entry** (`ExamMarkService`, `ExamMarkRepository`):
   - **Read the sheet:** `GET /api/exams/{exam}/marks?section_id=&exam_subject_id=` (`enter-results`). It returns the students whose active enrolment for the exam's year is in that section and who take the subject, with any marks already saved. A student takes the subject when it is common to the class (`group` null and compulsory), belongs to the student's group, or is their chosen 4th subject.
   - **Save the sheet:** `PUT /api/exams/{exam}/marks` (`enter-results`) with `{section_id, exam_subject_id, marks: [{student_id, written, mcq, practical, is_absent}]}`. The section row is locked, the whole sheet is upserted in one transaction, and errors are keyed per row (`marks.3.mcq`).
   - **Rules:**
     - The exam must be in `marks_entry` (or `processed` once Task 3 exists). Any other status returns 409.
     - `SubjectAssignmentService::canEnterMarks` must pass, otherwise 403.
     - Every student in the request must be on the sheet.
     - The section must be in the exam subject's class.
     - Each part is null or between 0 and that part's full mark, and a part the subject doesn't have must be null.
     - An absent student must have every part null.

5. **Exam-setup review nits**, folded in here because this task uses that code:
   - `SubjectAssignmentRepository::userHoldsAssignment` also requires the staff member to be `status = active`, so a retired or transferred teacher can't enter marks.
   - Assignment writes (`create`, `update` and `syncForSection`) lock the class row before the section row, always in that order, so they can't race a curriculum edit.
   - Subject assignments must match the section's group. A section with a group only accepts curriculum rows whose group is null or matches. The curriculum-removal guard follows the same rule, and only counts the active year and later years, so history doesn't block curriculum edits.
   - A paper group with only one row is allowed on purpose and is graded as a single subject. Record this in the Curriculum note.

6. **Guards:**
   - `hasExamSchedules` on classes and sections and `isUsedInExamSchedules` on subjects now check `exam_subjects`. The section guard isn't needed, because sections aren't on `exam_subjects` and enrolments already block a section delete, so remove it.
   - `AcademicYear::hasExams` checks the new `exams`.
   - Student delete's `hasExamResults` becomes "has exam marks".
   - `StaffService` is unchanged.

7. **Permissions:** in `RolePermissionSeeder`, the student and parent roles lose `view-exams` and `view-results`. Task 3 gives them access through `/api/my/*`. Update `ApiAuthorizationTest`.

8. **SPA:**
   - `views/exams/ExamList.vue` and `ExamForm.vue` (names, type, dates, classes) replace the placeholder.
   - `ExamSchedule.vue` has a class picker and a table to edit each subject's date, times and part marks, plus a per-class Regenerate button.
   - `MarkEntry.vue`:
     - section and subject pickers
     - a grid with only the subject's part columns, an absent checkbox and a running total
     - Enter and arrow-key navigation, and per-row 422 errors
     - a clear message on 403 ("Only the assigned subject teacher or an admin can enter marks") and on 409
   - Add routes and an Exams sidebar entry.

9. **Seeder:** `ExamSeeder` creates one 2026 Half-Yearly exam for Classes 9 and 10 in `marks_entry`. It enters marks for Section A, Morning shift: realistic, deterministic, varied and including a couple of absences. It matches on the `(academic_year_id, code)` exam code, so re-running it adds nothing. Task 3 uses this data for its GPA checks.

10. **Docs:** add an "Exams and marks" note to `CLAUDE.md`, and update the stub-controller lists in CLAUDE.md and both guidelines. The exam stubs are gone; Fee and Dashboard remain.

**Out:**
- GPA, grades, merit and processing.
- Publishing.
- Results APIs, `/api/my/*` results and report cards (all Task 3).
- Teacher logins.
- Importing marks from CSV.

## Guidelines that apply
- `docs/architecture-guidelines.md`: these are new modules built on the stubs, so they follow the pattern from the start. The Curriculum and SubjectAssignment services are extended.
- `docs/api-response-guidelines.md`: `data`/`meta` shapes, 201/204, 409/422/403, and `decimal:2` marks sent as strings.
- CLAUDE.md domain rules: groups from Class 9, the 4th subject, Asia/Dhaka for times, and "Class" (never "Grade").

## Acceptance criteria
- [x] Old exam tables, models and stubs are gone. The new migrations run on SQLite and MySQL, including rollback.
- [x] Exam CRUD generates the schedule from the curriculum as a snapshot. Adding, removing and regenerating classes is guarded by existing marks.
- [x] Schedule edits follow the part-mark rules, and are blocked once marks exist.
- [x] Mark sheets list the right students, respecting group and 4th subject. Saving enforces every rule, with per-row errors, inside a locked transaction.
- [x] `canEnterMarks` gates saving. A retired or transferred teacher is refused.
- [x] The exam-setup nits are done: assignment lock order, group-aware assignments and removal guard, and single-row paper groups documented.
- [x] Delete guards point at the new tables. The student and parent roles lose `view-exams` and `view-results`.
- [x] The SPA exam list, form, schedule and mark entry work against the API.
- [x] `ExamSeeder` seeds an exam with marks, and re-running it adds nothing.
- [x] Full suite, Pint on changed files, `npm run build` and smoke all pass. CLAUDE.md and the guidelines are updated.

## Test cases
- [x] **Happy path.**
  - Creating an exam with `class_ids` [9, 10] returns 201, and `exam_subjects` copies the curriculum's part marks and paper groups.
  - Opening mark entry succeeds.
  - An admin loads the 9-A Physics sheet, which lists only students who take Physics.
  - Saving marks returns 200, and reloading the sheet shows them.
  - An assigned teacher's user can save; that teacher's staff record is linked to a user in the test.
  - Editing the schedule date succeeds.
  - Deleting an exam with no marks returns 204.
- [x] **Validation (422):**
  - neither name given
  - dates outside the academic year
  - end before start
  - duplicate code in the same year
  - unknown `class_ids`
  - a part above its full mark
  - a part the subject doesn't have
  - absent with marks
  - a student who isn't on the sheet
  - a section from another class
  - schedule part-mark rules
  - `?search[]=x`
- [x] **403:**
  - an unassigned teacher saving marks
  - an assigned teacher whose staff record is retired
  - a teacher with no staff link
  - the student and parent roles on `/api/exams`
- [x] **409:**
  - saving marks while the exam is in `draft`
  - opening mark entry twice
  - regenerating, removing a class, editing a subject's marks, or deleting the exam once marks exist
- [x] **The sheet's student list:**
  - a Science student sees Physics but not Accounting
  - only students who chose Higher Math as their 4th subject see it
  - students below Class 9 get only common subjects
- [x] **Exam-setup nits:**
  - assigning a Humanities-only subject to a Science section returns 422
  - removing a curriculum row that a past year's assignment uses is allowed
  - removing one used by the active year returns 422
- [x] **Edge cases:**
  - `/api/exams/1abc` returns 404
  - an exam subject from another exam returns 404
  - re-running `ExamSeeder` adds nothing
  - a concurrent duplicate exam code returns 422
- [x] **Unit:** `ExamServiceTest` and `ExamMarkServiceTest` cover each rule with mocked repositories.
