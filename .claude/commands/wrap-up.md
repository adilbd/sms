---
description: Finish a task — tests, smoke test, commit, review, then ask whether to open a PR
argument-hint: "[base-branch]"
allowed-tools: Agent, Bash(git:*), Bash(bash .claude/scripts/smoke.sh:*), Bash(.claude/hooks/record-review.sh:*), Bash(bash .claude/hooks/record-review.sh:*)
---

# /wrap-up — test → smoke → commit → review → PR

Run the end-of-task gate. The base branch is `$1` if given, otherwise `main`. Run the
steps in order, and stop early only if a step hard-fails.

Review runs **last**, after tests and smoke, because `test-runner` (step 1) may edit
product code to fix genuine failures. Reviewing before that would miss those edits. The
review verdict is also keyed to a specific commit sha, so it has to run against a clean,
final tree.

## Steps

0. **Refuse on `main`.** If the current branch is `main` (or `master`), stop. Tasks live
   on `feat/*` or `fix/*` branches (see `/new-task`).

1. **Tests.** Spawn the **`test-runner`** subagent. It runs `php artisan test`, runs Pint
   on files added on this branch, and runs the hook classifier tests if `.claude/hooks/`
   changed. It fixes failures and re-runs until green, or reports a genuine blocker. Relay
   its final summary. If it reports a blocker it couldn't fix, stop and surface it.

2. **Smoke test.** Run:
   ```
   bash .claude/scripts/smoke.sh
   ```
   It seeds a throwaway SQLite database, boots the app on :8123, and checks the public
   pages, `/admin`, the public API, an unauthenticated 401, and an admin login
   round-trip. Then it tears everything down. If it prints `SMOKE FAIL` (non-zero exit),
   stop and report. If the chrome-devtools MCP is available and the task touched the UI,
   also load the affected page and confirm there are no console errors.

3. **Task file.** If a `docs/tasks/<slug>.md` exists for this branch, tick off the
   acceptance criteria and test cases that are now met, and set `Status: done` (or list
   what's left).

4. **Commit.** Check `git status --porcelain`. If the tree is dirty (expected after steps
   1 and 3), **ask** whether to commit; never commit silently. If the user agrees, commit
   with a descriptive message. If the tree is already clean, skip this step.

5. **Code review.** Spawn the **`code-reviewer`** subagent (read-only, runs on Opus, a
   different model from the Sonnet implementer) to review `git diff <base>...HEAD`. Because review runs after steps 1 and 3, it covers
   their edits too. Relay its findings verbatim, grouped by severity. Do **not**
   auto-apply fixes; surface them so the user decides. When asked to fix them, hand the
   findings to the **`implementer`** subagent rather than editing in the main session. The subagent records its own verdict via `.claude/hooks/record-review.sh`,
   which is what unlocks `gh pr create`.

6. **Summarize and ask.** Give a compact status line for tests, smoke and review, and say
   plainly whether the PR gate is open (a `pass` or `pass-with-nits` verdict recorded for
   the current HEAD) or what's blocking it. If it's open, **ask whether to push and open
   the PR**. Don't push or create the PR without the user's explicit go-ahead. Note that
   `gh pr create` is hook-enforced: the gate denies it without a fresh recorded review.

## Review-round cap

If step 5 comes back `changes-requested`, the default move is to have `implementer` fix the findings, commit,
and re-run step 5. Track how many consecutive times that has happened for this task.
**After 2 consecutive `changes-requested` verdicts, stop.** Don't fix and re-review a
third time on your own. Report the findings from both rounds and hand the decision to the
user. They can fix and re-run themselves, tell the agent to continue, or accept the risk
via the `.claude/.review-gate/OVERRIDE` escape hatch. The override is the user's call,
never the agent's (see `CLAUDE.md`). The count resets once a round records `pass` or
`pass-with-nits`, or when the user explicitly says to keep going past the cap.
