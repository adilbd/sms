# Rank merit by GPA, then subjects passed, then total marks

Status: done
Branch: fix/merit-position-order

## Problem
Merit positions are currently ordered by pass status, then GPA, then total marks (`Gpa::positions()`). The user wants the school's rule: **GPA, then the number of subjects passed, then total marks.**

Example: a student with 700 marks and 1 failed subject must rank below a student with 690 marks and every subject passed. This already happens, because any compulsory F gives GPA 0.00. What's missing is the middle key. Among students with the same GPA, mostly the failed students who all have 0.00, the order currently jumps straight to total marks. So a student who failed 3 subjects with 700 marks outranks one who failed only 1 with 690. Counting passed subjects before total marks fixes that.

## Scope
**In:**
1. **Change the ranking.** `Gpa::positions()` ranks by:
   1. GPA, descending
   2. number of passed subjects, descending
   3. total obtained, descending

   Rows that tie on all three share a position (standard competition ranking 1, 2, 2, 4). Drop the separate "passed first" key: GPA already separates passed students (1.00 and above) from failed ones (0.00), so they still come first.
2. **Define "subjects passed".** It is the number of graded units with a grade other than F. That counts every compulsory unit plus the 4th subject, and a combined paper pair counts as one unit.
   - Add `Gpa::grade...()` output `passed_count`, next to `failed_count`.
   - Add a migration for a new `exam_results.passed_count` column (unsigned smallint, default 0). Backfill it from the `subjects` JSON for rows that already exist. If backfilling isn't practical, document that existing exams need reprocessing. Prefer the backfill.
   - Expose `passed_count` in `ExamResultResource`.
   - Show "Subjects passed" on the report card (`ReportCards.vue`) and in the results tabulation (`ExamResults.vue`).
3. **Docs.** Update the "Exam results and GPA" note in CLAUDE.md and the merit rule in the plan wording to the new order.

**Out:**
- Using roll number to break ties. It stays display order only, and tied students still share a position.

## Guidelines that apply
- `docs/architecture-guidelines.md`: `Gpa` stays a stateless helper.
- `docs/api-response-guidelines.md`: `passed_count` is an integer.

## Acceptance criteria
- [x] Positions follow GPA → passed subjects → total, with ties sharing a position.
- [x] `passed_count` is stored, backfilled for existing results, returned by the API, and shown on report cards and the tabulation.
- [ ] The migration works on SQLite and MySQL, including rollback. Verified on SQLite. MySQL is checked on the next `php artisan migrate` against Docker.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **The user's example.** 700 marks with 1 failed subject vs 690 marks with all passed: the 690 student is 1st.
- [x] **Failed students.** Both have GPA 0.00. One failed 1 subject with 690, the other failed 3 with 700. The 1-fail student ranks above.
- [x] **Same GPA and same passed count.** The higher total ranks above.
- [x] **Full tie** on GPA, passed count and total: both get the same position, and the next position is skipped (1, 2, 2, 4).
- [x] **4th subject.** A failed 4th subject reduces `passed_count` but doesn't fail the student or change their GPA. Of two otherwise-equal students, the one who passed the 4th subject ranks above.
- [x] **Pairs.** A combined pair counts as one passed or failed unit.
- [x] **Migration.** The backfill sets `passed_count` from the existing `subjects` JSON.
- [x] **Processing.** Processing an exam stores `passed_count`, and positions in both the class and the section use the new order.
