# Promote a section to the next academic year

Status: done
Branch: feat/student-promotion

## Problem
Every student has one enrolment per academic year, covering section, roll, group and 4th subject. Moving a class up at the end of the year (in December) means editing each student by hand, and nothing marks last year's enrolment as promoted, retained or left.

Intended outcome:
- An admin picks a source section and year, a target year, and a default target section.
- The admin reviews one row per student, with suggestions taken from the published annual exam result. Failed students are suggested to be kept back.
- The admin adjusts any exceptions and confirms.
- The whole batch is applied in one transaction:
  - new enrolments are created in the target year;
  - last year's enrolments get their outcome;
  - Class 12 students graduate;
  - leavers are marked left.

This is Task B of the Students plan, which the user approved earlier ("promote whole class to next year, except some students"). It now also uses exam results.

## Scope
**In:**

1. **`PromotionService` and `PromotionRepository`** (a narrow contract). The service reuses `EnrolmentService` and `StudentEnrolmentRepository` for the enrolment rules and section locking. Don't duplicate those queries.

2. **Preview:** `GET /api/promotions/preview?from_academic_year_id=&section_id=&to_academic_year_id=` (`edit-students`).
   - It returns every **active** enrolment in that section and year, ordered by roll.
   - **Each row:**
     - Student: id, `student_id`, names, roll, group, 4th subject.
     - `exam_result`: `{exam_id, exam_name, gpa, grade, is_pass}` or null. It comes from the latest **published** exam of type `annual` in the source year for that class.
     - `suggested_action`:
       - `graduate` for Class 12;
       - `retain` when there is a published annual result with `is_pass=false`;
       - `promote` otherwise.
     - `already_enrolled_in_target`: true when the student already has an enrolment in the target year. Only included when `to_academic_year_id` is given.
   - **Top level:** the source class, `next_class` (null for Class 12), `suggested_target_section_id`, and `needs_group_choice`.
     - `suggested_target_section_id` is the section of the next class with the same `code` and shift, if one exists.
     - `needs_group_choice` is true for 8→9 and 10→11.

3. **Apply:** `POST /api/promotions` (`edit-students`):

   ```
   {from_academic_year_id, to_academic_year_id, section_id, default_target_section_id,
    exceptions: [{student_id, action: promote|retain|leave, target_section_id?, group?, optional_subject_id?}]}
   ```

   - Students who aren't listed in `exceptions` are promoted into `default_target_section_id`.
   - Class 12 students always graduate. A promote action for them means graduate, and `default_target_section_id` isn't required when the source class is 12.
   - **What each action does:**

     | Action | New enrolment | Source enrolment status | Student |
     |---|---|---|---|
     | `promote` | Class N+1 | `promoted` | unchanged |
     | `retain` | Same class (target section defaults to the same section) | `retained` | unchanged |
     | `leave` | None | `left` | `status=left`, `leaving_date` = today in Asia/Dhaka, logins synced |
     | graduate (Class 12) | None | `graduated` | `status=graduated`, `leaving_date` = today in Asia/Dhaka, logins synced |

     For `leave` and graduate, use the existing `StudentService`/`EnrolmentService` status paths so the guardian and student logins follow, as they do on a normal status change.
   - **Rules**, all checked before anything is written. Errors are keyed per row as `exceptions.N.field`, or top-level for the shared fields.
     - **Years:** the target year's `year` must be later than the source year's (422 on `to_academic_year_id`).
     - **Source:** the section must belong to the source class.
     - **Duplicates:** an exception for a student who isn't in the source list is a 422, and so is the same student twice.
     - **Already enrolled:** a student who already has an enrolment in the target year returns 422 on their row. For students not listed in `exceptions`, list them under `already_enrolled`.
     - **Target section:**
       - It must belong to the right class: N+1 for promote, N for retain.
       - Its shift must be active.
       - Capacity is checked for the whole batch per target section, counting existing active enrolments plus the new ones.
     - **Group and 4th subject:**
       - **9→10 and 11→12:** the group and 4th subject carry over. They are re-checked against the target section's group and the target class's curriculum, using the same rule as `EnrolmentService`. An invalid 4th subject is a 422 unless the exception gives a valid one or explicitly passes `optional_subject_id: null`.
       - **8→9 and 10→11:** if the target section has a group, the student gets that group. Otherwise the group must come from the exception: a 422 on `exceptions.N.group`, or, for students not listed, a top-level `missing_groups` error listing their names. The 4th subject is reset, unless the exception gives a valid one.
       - **Below Class 9:** no group and no 4th subject.
     - **Roll numbers:** left null in the new year, and assigned by admins afterwards.
   - **Locking:** the whole batch runs in one `DB::transaction`. Lock the source section first, then each target section in ascending id order, so two concurrent promotions can't deadlock.
   - **Response:** `{data: {summary: {promoted, retained, left, graduated}, target_sections: [{id, name, enrolled_after}]}, message}`.

4. **SPA:** `views/students/Promotion.vue` at `/admin/students/promotion`, linked from `StudentList.vue`.
   - **Step 1:** choose the source year (defaults to the active year), the section (class plus section, with shift labels), and the target year. The target year defaults to the year after the source.
   - **Step 2:** a table with one row per student: roll, name, exam result badge (GPA and grade, or "No annual result"), action select (prefilled from `suggested_action`), target section select (filtered by action), a group select when needed, and a 4th-subject select when needed.
     - Bulk "promote all" and "retain failed".
     - Rows already enrolled are shown disabled.
   - **Step 3:** a summary of counts per action and per target section, then confirm, apply, and a result screen.
   - Shows per-row 422 errors and `missing_groups`.

5. **Docs:** add a "Promotion" paragraph to the Students note in CLAUDE.md.

**Out:**
- Promoting a whole class (all sections) in one request.
- Assigning roll numbers automatically by merit.
- Undoing a promotion.
- Promotion for the Biology/Higher Math swap (a known curriculum limit).

## Guidelines that apply
- `docs/architecture-guidelines.md`: a new service on the existing repositories.
- `docs/api-response-guidelines.md`: computed data under `data`, 201 vs 200 (apply returns 200 with a summary because it is an action, not a single created resource), and per-row 422s.
- CLAUDE.md domain rules: Class 1–12, groups from Class 9, the 4th subject, and Asia/Dhaka for dates.

## Acceptance criteria
- [x] The preview returns the right rows, suggestions (graduate, retain on a failed published annual result, promote otherwise), the suggested target section and the flags.
- [x] Apply runs every action and rule in one locked transaction, writes nothing on any error, and returns the summary.
- [x] Group and 4th-subject carry-over or reset is correct for every class transition.
- [x] Leavers and graduates get status, leaving date and logins synced through the existing paths.
- [ ] The SPA wizard works against the API. It builds and its API is fully tested, but it hasn't been clicked through in a browser yet. That needs a 2027 academic year on the Docker database.
- [x] The full suite, Pint, `npm run build` and smoke all pass, and CLAUDE.md is updated.

## Test cases
- [x] **Happy path.**
  - Promote all of 2026 Class 6-A into 2027 Class 7-A: 201 or 200, new enrolments in 2027, source enrolments `promoted`, rolls null.
  - Exceptions:
    - one retain → a new enrolment in Class 6 and the source becomes `retained`;
    - one leave → no new enrolment, the student is `left` with today's Asia/Dhaka date, and the guardian login is deactivated when it was the last active child;
    - one promote into 7-B.
  - Class 12 → everyone graduates.
  - 9→10 Science → group and 4th subject carry over.
  - 8→9 into a Science-grouped section → the group is taken from the section.
- [x] **Preview.**
  - A failed published annual result suggests `retain`.
  - An unpublished or non-annual exam is ignored.
  - Class 12 suggests `graduate`.
  - `suggested_target_section_id` matches on code and shift.
  - `already_enrolled_in_target` is flagged.
- [x] **Validation (422):**
  - the target year isn't later than the source year;
  - the section isn't in the source class;
  - a student in the exceptions isn't in the source section;
  - a duplicate exception;
  - a student already enrolled in the target year;
  - the wrong target class for promote or retain;
  - an inactive target shift;
  - target capacity exceeded across the batch;
  - 8→9 into an ungrouped section without groups (`missing_groups` and per-row errors);
  - 9→10 with a 4th subject that isn't valid in the target;
  - a group below Class 9.
  - In every case nothing is written: assert the counts are unchanged.
- [x] **Authorization.**
  - A guest gets 401.
  - A teacher gets 403 on preview and apply.
  - A student or parent gets 403.
- [x] **Edge cases.**
  - An empty section → an empty preview, and apply returns zero counts.
  - Ids that aren't numbers → 422.
  - Two concurrent promotions into the same target section → the capacity check holds under the locks. Unit-test the lock order.
- [x] **Unit.** `PromotionServiceTest` covers each rule, using mocked repositories.
