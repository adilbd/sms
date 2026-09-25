#!/usr/bin/env bash
# PreToolUse hook: gates "gh ... pr create" behind a recorded code review,
# blocks "git commit" on main/master, and blocks any tool call from writing the
# .claude/.review-gate/OVERRIDE escape hatch -- that file exists for a human to
# create by hand outside any Claude Code tool call, never for a session to grant
# itself.
#
# Adapted from the DN advertising-frontend harness. Unlike that one, this project
# does NOT enforce a commit --author or ban Co-Authored-By trailers; classify.py
# still reports those flags, and they are ignored here on purpose.
#
# Command classification lives in classify.py so it's directly testable -- see
# test_classify.py. Every case there was a real bypass of an earlier version
# (prose inside quoted commit messages, multi-line commands, "(...)"/"{ ...; }"
# grouping, cd-then-relative writes). Read both before changing either, and run
# `python3 .claude/hooks/test_classify.py` afterwards.
#
# This is a cooperative-agent gate, not a security boundary against an
# adversarial one -- see classify.py's docstring for what it does not trace
# (nested shells, variable expansion, scripting one-liners).
#
# Every value that varies (branch, sha, path, verdict) reaches Python via argv,
# NEVER interpolated into a Python source string: branch names can contain
# characters that make that an injection vector.
set -u

INPUT="$(cat)"
[ -z "$INPUT" ] && exit 0

HOOK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CLASSIFY="$(printf '%s' "$INPUT" | python3 "$HOOK_DIR/classify.py" 2>/dev/null)"

[ -z "$CLASSIFY" ] && exit 0
[ "$CLASSIFY" = "NONE" ] && exit 0

deny() {
  python3 -c '
import json, sys
print(json.dumps({"hookSpecificOutput": {"hookEventName": "PreToolUse", "permissionDecision": "deny", "permissionDecisionReason": sys.argv[1]}}))
' "$1"
  exit 0
}

# --- Resolve repo root ---
REPO_ROOT="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$REPO_ROOT" ] || [ ! -d "$REPO_ROOT/.git" ]; then
  REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
fi
[ -z "$REPO_ROOT" ] && exit 0
cd "$REPO_ROOT" 2>/dev/null || exit 0

# The OVERRIDE write-guard applies unconditionally -- including when OVERRIDE is
# already set -- since the only legitimate way to create that file is a human
# acting outside any Claude Code tool call.
if printf '%s\n' "$CLASSIFY" | grep -q '^OVERRIDE_WRITE$'; then
  deny "Blocked: .claude/.review-gate/OVERRIDE may not be created, edited, or touched by any tool call. It is the maintainer's manual override -- create it yourself outside Claude Code if you intend to use it."
fi

if [ -f "$REPO_ROOT/.claude/.review-gate/OVERRIDE" ]; then
  echo "WARNING: PR review / commit gate overridden via .claude/.review-gate/OVERRIDE" >&2
  exit 0
fi

while IFS=$'\t' read -r KIND _A _B; do
  [ -z "$KIND" ] && continue

  if [ "$KIND" = "PR_CREATE" ]; then
    BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
    HEAD="$(git rev-parse HEAD 2>/dev/null)"
    SAFE_BRANCH="$(printf '%s' "$BRANCH" | tr '/' '_')"
    RECEIPT="$REPO_ROOT/.claude/.review-gate/${SAFE_BRANCH}.json"

    if [ ! -f "$RECEIPT" ]; then
      deny "PR blocked: no review recorded for branch '${BRANCH}'. Run /pr-review (or /wrap-up) first."
    fi

    if [ -n "$(git status --porcelain)" ]; then
      deny "PR blocked: working tree has uncommitted changes. Commit them, then re-run /pr-review -- a recorded review is only valid for the exact clean HEAD it was run against."
    fi

    READ_OUT="$(python3 -c '
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    print("", "")
else:
    print(d.get("head",""), d.get("verdict",""))
' "$RECEIPT" 2>/dev/null)"
    R_HEAD="${READ_OUT%% *}"
    R_VERDICT="${READ_OUT#* }"

    if [ "$R_HEAD" != "$HEAD" ]; then
      deny "PR blocked: review is stale (recorded for ${R_HEAD:0:7}, HEAD is ${HEAD:0:7}). Re-run /pr-review."
    fi

    case "$R_VERDICT" in
      pass|pass-with-nits) ;;
      changes-requested)
        deny "PR blocked: last review verdict was changes-requested. Address the findings and re-run /pr-review." ;;
      *)
        deny "PR blocked: unrecognized or missing review verdict ('${R_VERDICT}'). Re-run /pr-review." ;;
    esac

  elif [ "$KIND" = "GIT_COMMIT" ]; then
    BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
    if [ "$BRANCH" = "main" ] || [ "$BRANCH" = "master" ]; then
      deny "Commit blocked: never commit directly on '${BRANCH}'. Create a task branch first (feat/<slug> or fix/<slug>; /new-task does this)."
    fi
  fi
done <<< "$CLASSIFY"

exit 0
