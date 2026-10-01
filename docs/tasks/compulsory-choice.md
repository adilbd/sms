# Let Science students choose Biology or Higher Math as compulsory

Status: done
Branch: feat/compulsory-choice

## Problem
Under NCTB rules, a Class 9–12 Science student takes **either** Biology **or** Higher Mathematics as a compulsory subject. The other one becomes their 4th (optional) subject.

The curriculum can't express that today:
- Biology is always compulsory, Higher Math is only an optional choice, and every Science student is treated the same.
- So a student who takes Higher Math as compulsory and Biology as their 4th subject is graded wrong, and appears on the wrong mark sheets.

Intended outcome:
- The curriculum can mark an **either-or pair** per class and group.
- Each student's 4th-subject choice decides which subject of the pair is compulsory for them.
- Mark sheets, GPA and report cards follow that choice.

This is Task 4 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`.

## Scope
**In:**

1. **Curriculum** (`class_subjects`): add a nullable `choice_group` ASCII slug (e.g. `science-4th`).
   - Rows in the same class and group that share a `choice_group` form an either-or pair.
   - `CurriculumService` validates, keyed per row:
     - exactly 2 rows;
     - one `compulsory` and one `optional`;
     - the same `group`, which is not null;
     - Class 9 and above only;
     - not also in a `paper_group`;
     - a slug format like `paper_group`.
   - The PUT and GET payloads, `ClassSubjectResource` and the curriculum editor all carry the field.
2. **Seed data**: `CurriculumSeeder` puts Biology and Higher Math in a `science-4th` choice pair for Class 9–12 Science. Agriculture stays a plain optional.
   - The seeder only fills classes that are empty.
   - Add a small idempotent data migration, or a seeder step, that sets `choice_group` on existing curricula when that exact Biology (compulsory) + Higher Math (optional) pair exists. Don't touch other rows.
3. **Who takes what.** These rules live in one place, `StudentEnrolment::subjectRequirements()` and `takes()`, which mark sheets, results and the portal already share.
   - If the enrolment's `optional_subject_id` is a member of a choice pair (either row), that subject is the student's 4th subject, and the **other** member of the pair is compulsory for them.
   - If the 4th subject is not in the pair, or there is none, the pair's compulsory row applies as now.
   - The student takes both members of the pair either way. Only which one counts as compulsory changes.
4. **Enrolment validation** (`EnrolmentService::placementErrors`): the 4th subject may be either member of the pair, as well as any plain optional row for the class and group. Promotion carry-over (9→10, 11→12) keeps the choice and re-validates it against the target curriculum.
5. **Exams.**
   - `exam_subjects` snapshots `choice_group`. Generating and regenerating copies it.
   - The mark sheet lists every student who takes the subject (unchanged in effect).
   - `ResultService::gradeEnrolment` decides compulsory or 4th per student: the pair member the student chose as 4th is graded as optional, and the other member as compulsory.
   - `Gpa` itself is unchanged; it already takes a compulsory/optional split.
   - Results that already exist are not recomputed. An exam has to be reprocessed. Say so in CLAUDE.md.
6. **SPA.**
   - `CurriculumEditor` gets a choice-group field.
   - `StudentForm`'s 4th-subject select lists both pair members, with a hint: "Choosing Biology makes Higher Math compulsory".
   - Report cards and the public marksheet mark the student's actual 4th subject.
7. **Carried from the Task 3 review.** `AttendanceService::studentView` runs the access check before returning the "no enrolment" 404, so access is no longer revealed. Share the 403 check with `authorizeSection`, and assign `$year` from `yearForMonth`.
8. **Docs.** Update the Curriculum and Exam results notes in CLAUDE.md.

**Out:**
- Choice pairs below Class 9.
- More than 2 members in a pair.
- Changing the GPA formula.

## Acceptance criteria
- [x] Choice pairs validate and are saved in the curriculum. Seeding and the backfill set Biology and Higher Math for Science.
- [x] A student with Biology as 4th has Higher Math compulsory. Their GPA treats Biology as the optional subject, with only the bonus above 2 counting, and a failed Biology doesn't fail them.
- [x] A student with Higher Math as 4th, or with no choice, keeps the current behaviour.
- [x] Mark sheets, results, report cards and promotion all follow the choice.
- [x] `studentView` checks access before the 404.
- [x] The migration works on SQLite and on Docker MySQL 8, including rollback and a re-run.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Curriculum validation (422):**
  - 1 or 3 members;
  - two compulsory rows;
  - a pair across different groups;
  - a pair below Class 9;
  - a row in both a paper pair and a choice pair;
  - a bad slug.
- [x] **4th subject = Biology:**
  - `takes()` covers both subjects.
  - In GPA, Higher Math is compulsory and Biology optional.
  - Failing Biology leaves the student passed, while failing Higher Math fails them.
  - The report card marks Biology as the 4th subject.
- [x] **4th subject = Higher Math:** behaviour is the same as before. Biology is compulsory.
- [x] **No 4th subject:** the pair's compulsory row (Biology) applies.
- [x] **Enrolment:** both pair members are accepted as the 4th subject. A subject outside the curriculum is still refused.
- [x] **Promotion:** 9→10 carries Biology-as-4th over to the Class 10 pair. If the target class has no pair, the subject is re-validated or the promotion is refused.
- [x] **Exams:** the snapshot copies `choice_group`, and reprocessing an exam applies the choice.
- [x] **Backfill:** it sets `choice_group` only on the exact Biology + Higher Math Science rows, and a second run changes nothing.
- [x] **`studentView`:** a staff member without access gets 403 both for a student with no enrolment and for one with an enrolment.

## Docker and browser check
- [x] The backfill paired exactly Class 9 and 10 Science Biology (compulsory) and Higher Math (optional) as `science-4th`, 4 rows in total. The Docker Class 11 and 12 curriculum didn't have that exact pair, so it was left alone.
- [x] On Nayeem Khan's student form (Class 9 Science), the 4th-subject select lists Biology, Higher Mathematics and Agriculture Studies. Choosing Biology shows "Choosing Biology makes Higher Mathematics compulsory". Saving stored BIO.
- The Docker Half-Yearly exam was generated before this change, so it has no `choice_group`. Mark-sheet placement is covered by `CompulsoryChoiceTest`.

## Follow-ups (from review)
- In HSC (Class 11–12), Biology and Higher Math each have 1st and 2nd papers, but a pair member can't be in a paper group, so this can't be expressed yet.
- MarkEntry and ExamSchedule still label Higher Math "4th". Show "Either/or" when `choice_group` is set.
- `ExamSubject::choiceSubjectIds()` queries from the model. Optionally move it to the repository.
