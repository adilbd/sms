---
description: Review-only gate — run the code-reviewer against base...HEAD and record a verdict that unlocks `gh pr create`
argument-hint: "[base-branch]"
allowed-tools: Agent, Bash(git:*), Bash(.claude/hooks/record-review.sh:*), Bash(bash .claude/hooks/record-review.sh:*)
---

# /pr-review — review-only gate

The base branch is `$1` if given, otherwise `main`. This is the review-only path, with no
tests or smoke. Use **`/wrap-up`** for the full gate (tests → smoke → review). Use this
command on its own when tests and smoke have already passed and you only need to satisfy
the review gate again, for example after a small fixup commit.

## Steps

1. **Refuse on `main`.** If the current branch is `main` (or `master`), stop. Never
   review, commit or open a PR from the default branch.

2. **Refuse on a dirty tree.** Run `git status --porcelain`. If it's non-empty, stop and
   list the files. The recorded review is keyed to the current HEAD commit, so
   uncommitted changes must be committed first (ask before committing).

3. **Review.** Spawn the **`code-reviewer`** subagent (read-only, runs on Opus, a
   different model from the Sonnet implementer) to review `git diff <base>...HEAD`. It reports a verdict (`pass`, `pass-with-nits` or
   `changes-requested`) with findings grouped by severity. As its final step it records
   the verdict via `.claude/hooks/record-review.sh <verdict> <base>`.

4. **Relay findings verbatim**, grouped by severity (Blocking → Should-fix → Nit). Do
   **not** auto-apply fixes; surface them so the user decides.

5. **Report gate status.** Say plainly whether `gh pr create` is now unblocked, or
   exactly what still blocks it: `changes-requested` findings that need fixing and a
   re-run, or a verdict that failed to record.

## Review-round cap

Track how many consecutive reviews for this task have come back `changes-requested`.
**After 2 in a row, stop.** Don't fix the findings yourself and quietly re-run
`/pr-review` a third time. Report the findings from both rounds and hand the decision to
the user. They can fix and re-run themselves, tell the agent to continue, or accept the
risk via the `.claude/.review-gate/OVERRIDE` escape hatch. The override is the user's
call, never the agent's (see `CLAUDE.md`). The count resets once a round records `pass`
or `pass-with-nits`, or when the user explicitly says to keep going past the cap.
