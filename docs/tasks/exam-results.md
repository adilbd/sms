# Process exam results with GPA, publish them, and print report cards

Status: done
Branch: feat/exam-results

## Problem
Exams, their subject snapshots and part-wise marks are in place (Tasks 1–2), but nothing turns marks into results. Subjects have no grades, students have no GPA or merit position, nothing can be published, students and guardians can't see their results, and there are no report cards.

Intended outcome:
- An admin processes an exam. Each enrolled student gets per-subject grades, combined paper pairs, the 4th-subject bonus, a GPA on the Bangladesh scale (CLAUDE.md) and a class and section merit position.
- The admin then publishes the results.
- Students and guardians see only their own published results.
- Admins print report cards from a print-friendly page.

This is Task 3 of 3 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Follow its **Domain rules** section exactly.

## Scope
**In:**

1. **`App\Support\Gpa`** is a stateless helper, like `Seo`, with no database access.

   Subject grades:
   - `gradeFor(float $percentage): {grade, point}` uses the CLAUDE.md scale: A+ 80–100 = 5.00, A 70–79 = 4.00, A- 60–69 = 3.50, B 50–59 = 3.00, C 40–49 = 2.00, D 33–39 = 1.00, F 0–32 = 0.00. Percentages are compared half-up on 2 decimals.
   - A **unit** is one subject, or one paper pair. It is failed (F, 0.00) if the student was absent from any of its papers, or if any part's obtained marks are below that part's pass mark. For a pair, the part's marks and pass marks are both summed across the two papers.
   - Otherwise the unit's grade comes from (obtained ÷ full) × 100.
   - A `paper_group` with only one row is graded as a single subject.

   GPA:
   - The **compulsory units** are every compulsory row the student takes: common rows plus the student's group rows. The **4th subject** is the enrolment's `optional_subject_id`, if any.
   - GPA = (sum of compulsory points + max(0, 4th points − 2)) ÷ number of compulsory units. It is capped at 5.00, rounded half-up to 2 decimals, and returned as a string.
   - Any compulsory F makes the GPA 0.00 and the grade F, and the student has failed. Failing the 4th subject never fails the student.

   Overall grade from the GPA:

   | GPA | Grade |
   |---|---|
   | 5.00 | A+ |
   | 4.00 or more | A |
   | 3.50 or more | A- |
   | 3.00 or more | B |
   | 2.00 or more | C |
   | 1.00 or more | D |
   | otherwise | F |

   Merit position:
   - Calculated separately for the class and for the section.
   - Order: passed students first, then GPA descending, then total obtained descending.
   - Use standard competition ranking (1, 2, 2, 4). Roll number orders the display only.
   - Missing marks count as absent.

2. **`exam_results` table** (migration), with one row per exam and enrolment:
   - Keys: `exam_id`, `student_id`, `enrolment_id`, `class_id`, `section_id`, all FKs.
   - Totals: `total_obtained` and `total_full` as decimal(7,2), `gpa` as decimal(3,2), `grade`.
   - Outcome: `is_pass`, `failed_count`, `class_position`, `section_position`.
   - `subjects`: JSON, one entry per unit. Each entry holds the subject ids, names (en and bn), whether it's optional or combined, the part marks obtained and full marks, `percentage`, `grade`, `point` and `is_absent`.
   - Timestamps.
   - Unique on `(exam_id, enrolment_id)`.
   - Model with `decimal:2` and `array` casts, plus a factory.

3. **Processing and publishing** (`ResultService` and `ExamResultRepository`, using `Gpa`):
   - **Process:** `POST /api/exams/{exam}/process` (`publish-exams`).
     - Allowed from `marks_entry` or `processed`. A published exam returns 409.
     - In one transaction, with the exam row locked, it recomputes every active enrolment of every class in the exam and replaces that exam's results.
     - Sets the status to `processed`.
     - Returns `{data: {exam, summary: {per class and section: students, passed, failed, missing_marks}}}`.
   - **Marks entry while processed:** `ExamMarkService` now accepts `processed` too. Saving marks while processed sets the exam back to `marks_entry`, so stale results can't be published.
   - **Publish:** `POST /api/exams/{exam}/publish` (`publish-exams`) requires `processed` (otherwise 409) and sets `published_at`. `POST /api/exams/{exam}/unpublish` returns the exam to `processed`. Mark entry stays locked while published.

4. **Results APIs** (`view-results`, numeric constraints):
   - `GET /api/exams/{exam}/results?class_id=&section_id=` is a tabulation, ordered by position, paginated with `meta`. It carries an `ExamResultResource` row with the student's id, names, roll and group, plus the summary fields.
   - `GET /api/exams/{exam}/results/{student}` is the full breakdown with `subjects`. It returns 404 if there's no result.
   - **Own records**, published exams only, through `MyRecordsController`:
     - `GET /api/my/results` (`role:student`) lists the student's results, with the full breakdown.
     - `GET /api/my/children/{student}/results` (`role:parent`) returns 403 unless the student is one of the guardian's own children.
     - `GET /api/my/exams` (student or parent) returns the published-or-marks-entry exam schedules for the caller's class, or the children's classes: subject, date and time only.

5. **Report cards in the SPA** (`views/exams/ReportCards.vue` at `/admin/exams/:id/report-cards?section_id=`, plus a single-student variant):
   - The page uses print CSS: `@media print`, one card per page with `break-after: page`, and the admin chrome hidden.
   - A Print button calls `window.print()`.
   - Each card shows the school logo and names (en/bn) from `/api/public/school`, and the exam name, year, class, section and shift.
   - Student block: name (bn/en), student ID, roll and group.
   - Subject table: part marks, total, grade and point, with pairs shown combined and the 4th subject marked.
   - Footer: GPA, grade, class and section positions, failed count, and signature lines for class teacher, head teacher and guardian.
   - Load Noto Sans Bengali from Google Fonts for Bangla.
   - Other screens:
     - a Results tabulation view (`ExamResults.vue`) with Process, Publish and Unpublish buttons and the processing summary
     - links from `ExamList.vue`

6. **Task 2 review nits:**
   - Validate a schedule subject's `exam_date` against the exam's `start_date`/`end_date` in `ExamScheduleService`, checked on the subject with the input applied.
   - `ExamService` update: compute class additions from the second, locked read, so a concurrent class removal isn't silently dropped.
   - In regenerate, check that the class belongs to the exam (404) before the curriculum check.
   - In `ExamMarkService`, re-check `canEnterMarks` inside the transaction, after the locks.

7. **Docs.** Add an "Exam results and GPA" note to CLAUDE.md. Record the known curriculum limit there: the curriculum can't express "Biology or Higher Math, one compulsory and the other the 4th subject", because the 4th subject is fixed per group.

**Out:**
- Weighted or combined results across exams.
- Server-side PDFs.
- Choosing which Science subject is compulsory per student (the Biology/Higher Math swap).
- SMS notifications.
- Using results in promotion. That belongs to the Students Task B.

## Guidelines that apply
- `docs/architecture-guidelines.md`: services and repositories, plus a stateless `Gpa` helper.
- `docs/api-response-guidelines.md`: computed data under `data` with named keys. `gpa` and marks are `decimal:2` strings. 409, 403 and 404 as listed.
- CLAUDE.md domain rules: the GPA scale, groups from Class 9, the 4th subject, Bangla names.

## Acceptance criteria
- [x] `Gpa` implements every domain rule and has exhaustive unit tests.
- [x] Processing writes correct results for every enrolment, replaces them on re-run, and returns the summary.
- [x] Status transitions: process from marks_entry/processed, publish only from processed, unpublish back to processed, and saving marks while processed sets the exam back to marks_entry. Mark entry is locked while published.
- [x] The tabulation and breakdown APIs follow the guideline. `/api/my/*` shows only the caller's own published results.
- [ ] Report cards print one per page, with correct data and Bangla text rendering. The page builds and its data comes from the tested API, but it hasn't been checked in a browser yet; check it after `php artisan migrate` on Docker.
- [x] The four Task 2 nits are fixed.
- [x] The migration works on SQLite and MySQL, including rollback.
- [x] The full suite, Pint, `npm run build` and smoke all pass.
- [x] CLAUDE.md is updated.
- [x] Processing the seeded HY-2026 exam gives results, and one student's GPA has been checked by hand.

## Test cases
- [x] **`Gpa` unit tests:**
  - every grade boundary (32/33, 39/40, 49/50, 59/60, 69/70, 79/80, 100)
  - a part below its pass mark gives F even when the total is high
  - absent gives F
  - a pair passes on combined marks while one paper's part is below its own pass mark
  - a pair fails when the combined part is below the combined pass mark
  - a single-row paper group is graded alone
  - 4th-subject bonus: A+ adds (5 − 2) ÷ n, and a D (1.00) adds nothing
  - a failed 4th subject doesn't fail the student
  - one compulsory F gives GPA 0.00 and grade F
  - the GPA is capped at 5.00
  - half-up rounding (e.g. 4.125 → 4.13)
  - Class 1–8 with no 4th subject
  - merit ties give 1, 2, 2, 4, and a failed student ranks after every passed student
- [x] **Happy path:**
  - Process the seeded exam: 200, status processed, the summary has missing-mark counts, and results exist for every enrolment.
  - Publish, then a student sees their result in `/api/my/results`.
  - A guardian sees each child's result.
  - The tabulation is ordered by position.
  - The breakdown has combined pairs and the 4th subject.
  - Unpublish works.
- [x] **409:** processing a published exam; publishing from marks_entry or draft; saving marks while published.
- [x] **Edge cases:**
  - Saving marks while processed sets the exam back to marks_entry, and publish then returns 409 until it's reprocessed.
  - Reprocessing replaces results without duplicates.
  - A student with no marks at all gets an F with failed_count = number of compulsory units.
  - An unpublished exam is hidden from `/api/my/*`.
  - `/api/exams/1abc/results` returns 404.
  - A breakdown for a student with no result returns 404.
- [x] **Authorization:**
  - A guest gets 401.
  - A teacher (`view-results`) can read the tabulation but gets 403 on process and publish.
  - A student or parent gets 403 on `/api/exams/*/results`.
  - A guardian gets 403 on another family's child.
  - A student gets 403 on `/api/my/children/...`.
- [x] **Task 2 nits:**
  - An exam_date outside the exam's dates returns 422.
  - Regenerate for a class not in the exam returns 404.
  - A teacher whose assignment is removed mid-save gets 403. A unit test covers this: the re-check after the lock.
- [x] **Unit:** `ResultServiceTest` covers the transitions and orchestration with mocked repositories.
