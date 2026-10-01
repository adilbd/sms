# Don't count the 4th subject against a student in merit

Status: done
Branch: fix/fourth-subject-not-counted

## Problem
Failing the 4th (optional) subject already doesn't fail a student. `is_pass` stays true and the GPA just gets no bonus.

The merit-order change, however, counted the 4th subject in `passed_count`. A failed 4th subject therefore lowers a student's "subjects passed", and they can rank below an otherwise equal student. The user's rule is that a failed 4th subject must not count against the student. Only the compulsory subjects decide pass/fail, the subject counts and the merit tiebreak.

Intended outcome:
- `passed_count` counts **compulsory units only**, which matches `failed_count`.
- The 4th subject only ever helps: it adds its bonus when it scores above 2.00.
- The 4th subject keeps its own grade on the report card. It is never counted as a failure.

## Scope
**In:**
1. **`Gpa::result()`.**
   - `passed_count` = the number of compulsory units with a grade other than F. A combined pair counts once.
   - The 4th subject is excluded from the count.
   - `is_pass`, `failed_count` and the GPA formula don't change.
2. **Migration fixing existing rows.** A new migration recomputes `passed_count` for existing `exam_results` from the `subjects` JSON, counting units that are not `is_optional` and not F. Do it in PHP over chunks, so it works on both MySQL and SQLite. Existing positions only change once an exam is reprocessed; say so in the migration docblock.
3. **Report card and tabulation.**
   - A failed 4th subject's row still shows its F grade, but it isn't highlighted the way failed compulsory subjects are. Show it muted, with a short note such as "4th subject — not counted as a fail".
   - "Subjects passed" shows compulsory subjects only, e.g. "11 of 11".
4. **Docs.** Update the "Exam results and GPA" note in CLAUDE.md: the 4th subject never counts as a fail and never counts in `passed_count`.

**Out:**
- Any change to the GPA bonus rule.

## Acceptance criteria
- [x] `passed_count` excludes the 4th subject, both when results are processed and in the backfill of existing rows.
- [x] A failed 4th subject changes neither pass/fail nor `passed_count`, and so not merit.
- [x] The report card shows a failed 4th subject as not counted.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated. The migration works on SQLite and MySQL.

## Test cases
- [x] Two students are equal in everything except the 4th subject: one passed it, one failed it. Both have the same `passed_count`. The one who passed it with more than 2.00 points gets the bonus, so a higher GPA and a better rank. With equal GPA, the higher total ranks first.
- [x] A student fails only the 4th subject: `is_pass` is true, `failed_count` is 0, and `passed_count` equals the number of compulsory units.
- [x] A combined pair still counts once.
- [x] The migration recomputes `passed_count` and excludes optional units.
- [x] Update the earlier merit test that expected a failed 4th subject to rank lower: it now ranks the same, or ahead on total marks.
