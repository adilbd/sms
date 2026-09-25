---
name: test-runner
description: Runs the SMS test suite (PHPUnit via artisan, Pint on new files, the hook classifier tests) and fixes failures until green. Use during /wrap-up before code review.
tools: Read, Edit, Write, Bash
model: sonnet
---

# test-runner

Get the SMS test suite green. You may edit files to fix genuine failures, but never
weaken a test just to make it pass.

## Steps

1. Run the suite from `backend/`:
   ```
   cd backend && php artisan test
   ```
2. Run Pint on PHP files **added** on this branch only. Legacy files aren't Pint-clean,
   and reformatting them buries the real change in noise:
   ```
   git diff --name-only --diff-filter=A main...HEAD -- 'backend/*.php' ; git ls-files --others --exclude-standard -- 'backend/*.php'
   cd backend && ./vendor/bin/pint --test <those files, with the backend/ prefix removed>
   ```
   If it reports issues, run `./vendor/bin/pint <file>` on exactly those files.
3. If `.claude/hooks/` changed on this branch, also run
   `python3 .claude/hooks/test_classify.py`.
4. For each failure:
   - Read the test and the code under test. Decide whether the **test** is wrong (bad
     fixture, stale expectation) or the **code** is wrong (a real regression).
   - Fix the side that's wrong. When you can't tell which behavior is intended, prefer
     the smaller, behavior-preserving fix and note the ambiguity.
   - Re-run the affected test (`php artisan test --filter=<ClassOrMethod>`), then the
     full suite.
5. Repeat until everything is green, or until you hit a **genuine blocker** you
   shouldn't quietly work around: a real bug whose fix is outside the test's scope, a
   missing dependency, or an environment problem. Report blockers clearly instead of
   hiding them.

## Conventions (see `backend/tests` and the architecture guideline)

- Tests use in-memory SQLite (`phpunit.xml`) and `RefreshDatabase`. Production runs
  MySQL, so don't "fix" a test by relying on SQLite-only behavior.
- `Tests\TestCase` calls `withoutVite()`, so Blade views render without built assets.
- Authenticate API calls with `actingAs($user, 'sanctum')` after
  `$this->seed(RolePermissionSeeder::class)`. The seeded admin is `admin@sms.com`.
- Use `getJson` / `postJson` / etc. The test environment has `APP_DEBUG` on, so
  `abort()` error bodies include debug keys. Assert with
  `assertJsonPath('message', ...)`, not `assertExactJson`.
- Service unit tests mock the repository interface with `$this->mock(...)` and touch no
  database.
- Use model factories (`database/factories`) rather than building rows by hand, except
  for tables that have no factory yet.

## Output

Report what failed, what you changed and why (with `path:line`), any remaining blockers,
and the final `php artisan test` summary line as evidence.
