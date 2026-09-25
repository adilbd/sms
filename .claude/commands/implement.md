---
description: Hand a task to the Sonnet implementer subagent, then report what it built
argument-hint: "<docs/tasks/<slug>.md | description of the change>"
allowed-tools: Agent, Read, Bash(git status:*), Bash(git diff:*), Bash(git branch:*)
---

# /implement — delegate the coding to Sonnet

The main session plans and checks the work; the **`implementer`** subagent (Sonnet)
writes the code. The task is:

> $ARGUMENTS

## Steps

1. **Check the branch.** If you're on `main`, stop and suggest `/new-task` first. Code is
   written on a task branch.
2. **Load the task.** If the argument is a `docs/tasks/*.md` file, read it. If it's a free
   description with no task file, confirm the acceptance criteria and test cases with the
   user in one short message before delegating. Answer any open questions about the API
   contract, data model or authorization *now*, because the implementer is told to stop
   rather than guess.
3. **Delegate.** Spawn the **`implementer`** subagent. Pass it the task file path (or the
   agreed spec), the user's answers, and any constraints from the conversation. Don't
   paste whole guideline docs; it reads them itself.
4. **Check the result.** Read `git status` and `git diff --stat`, and skim the diff for
   anything outside the task's scope. Confirm the implementer's `php artisan test`
   summary is green. If it stopped with a question, ask the user, then re-delegate with
   the answer.
5. **Report** a short summary: what was built, the test result, any decisions or open
   items. Suggest `/wrap-up` to test, smoke, commit and review.

Don't fix the implementer's code yourself in the main session. Send follow-ups back to
`implementer` so all code keeps coming from Sonnet, and review stays on a different model.
