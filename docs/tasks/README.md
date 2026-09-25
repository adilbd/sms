# Task files

`/new-task` writes one groomed spec per task here as `<slug>.md`, and `/wrap-up` ticks it
off when the work is done. The file is the task's source of truth: the reviewer and future
sessions read it to learn what "done" meant. Keep it short.

## Template

```markdown
# <Imperative title>

Status: in progress | done
Branch: feat/<slug>

## Problem
One paragraph: why this is needed and the intended outcome.

## Scope
- In: ...
- Out: ...

## Guidelines that apply
- docs/architecture-guidelines.md (backend module) / docs/api-response-guidelines.md (API) / SEO rules in CLAUDE.md (public pages)

## Acceptance criteria
- [ ] ...

## Test cases
- [ ] Happy path: ...
- [ ] Validation: ... → 422
- [ ] Authorization: <role without permission> → 403
- [ ] Edge case: ...
```
