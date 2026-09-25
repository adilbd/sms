#!/usr/bin/env bash
# Record a code-review verdict for the current branch, keyed to the current
# HEAD sha, so the PR gate hook (.claude/hooks/gate.sh) can unlock
# `gh pr create`. The reviewer subagent calls this as its own final step.
#
# Usage: record-review.sh <pass|pass-with-nits|changes-requested> [base-branch]
set -euo pipefail

VERDICT="${1:-}"
BASE="${2:-main}"

case "$VERDICT" in
  pass|pass-with-nits|changes-requested) ;;
  *)
    echo "Usage: record-review.sh <pass|pass-with-nits|changes-requested> [base-branch]" >&2
    exit 1
    ;;
esac

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || {
  echo "record-review.sh: not inside a git repo" >&2
  exit 1
}
cd "$REPO_ROOT"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" = "HEAD" ]; then
  echo "record-review.sh: refusing to record a review on a detached HEAD" >&2
  exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
  echo "record-review.sh: refusing to record a review with uncommitted changes — commit first, then re-run /pr-review" >&2
  exit 1
fi

HEAD_SHA="$(git rev-parse HEAD)"
SAFE_BRANCH="$(printf '%s' "$BRANCH" | tr '/' '_')"
GATE_DIR="$REPO_ROOT/.claude/.review-gate"
mkdir -p "$GATE_DIR"
RECEIPT="$GATE_DIR/${SAFE_BRANCH}.json"

# Values are passed as argv, never interpolated into the Python source
# string — a git branch name can contain characters (', $, (, ), ;) that
# would make source-string interpolation a real injection vector, not just a
# style nit (this bit us: an earlier version embedded $BRANCH/$BASE etc.
# directly in the "python3 -c ..." string).
python3 -c '
import json, sys, datetime
branch, head, base, verdict, receipt_path = sys.argv[1:6]
receipt = {
    "branch": branch,
    "head": head,
    "base": base,
    "verdict": verdict,
    "ts": datetime.datetime.now(datetime.timezone.utc).isoformat(),
}
with open(receipt_path, "w") as f:
    json.dump(receipt, f, indent=2)
    f.write("\n")
' "$BRANCH" "$HEAD_SHA" "$BASE" "$VERDICT" "$RECEIPT"

echo "Recorded verdict '${VERDICT}' for branch '${BRANCH}' at ${HEAD_SHA:0:7} -> ${RECEIPT}"
