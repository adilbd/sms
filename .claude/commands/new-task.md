---
description: Groom a spec into a task file under docs/tasks/ (with test cases) and cut the work branch
argument-hint: "<task description / rough spec>"
allowed-tools: Read, Write, Bash(git checkout:*), Bash(git branch:*), Bash(git status:*), Bash(git switch:*)
---

# /new-task — start a task

Turn the spec below into a groomed task file, then cut the work branch. Do the steps in
order.

Spec provided by the user:

> $ARGUMENTS

## Steps

1. **Check the tree.** Run `git status --porcelain`. If there are uncommitted changes,
   stop and ask whether to commit, stash or carry them over. Don't start a new task on
   top of someone else's unfinished work.

2. **Groom the spec.** Rewrite it into:
   - a clear, imperative **title**,
   - a one-paragraph **problem statement** (why this is needed, and the intended outcome),
   - **scope** (what's in, and what's explicitly out),
   - **acceptance criteria**, and
   - **explicit test cases**: concrete scenarios to verify (happy path, validation,
     authorization by role, and the obvious edge cases). This is required; a task without
     test cases is not ready.

   Say which guidelines apply: `docs/architecture-guidelines.md` for backend modules,
   `docs/api-response-guidelines.md` for API changes, and the SEO rules in `CLAUDE.md`
   for public pages. If the task changes a legacy module, note that the whole module
   gets converted to the pattern in the same change.

   If the spec is too thin to groom, ask the user the *minimum* clarifying questions first.

3. **Write the task file.** Derive a `slug` from the title: lowercase, with every run of
   non-alphanumeric characters collapsed to `-`, trimmed to about 50 characters (e.g.
   "Teacher CRUD API" → `teacher-crud-api`). Save the groomed spec to
   `docs/tasks/<slug>.md` using the template in `docs/tasks/README.md`, with
   `Status: in progress`.

4. **Cut the branch.** Use `feat/<slug>` for features and `fix/<slug>` for bug fixes.
   Branch from `main` unless the user names another base:
   ```
   git switch main && git switch -c feat/<slug>
   ```
   Never work directly on `main` (the commit hook blocks it anyway).

5. **Report** the task file path and the new branch name. For a new backend resource,
   suggest `/new-module <Model>`. Otherwise, suggest `/implement docs/tasks/<slug>.md`, which
   hands the task to the Sonnet `implementer` subagent.

When the code is done, finish with **`/wrap-up`**.
