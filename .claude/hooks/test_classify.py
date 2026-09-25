#!/usr/bin/env python3
"""Regression tests for classify.py.

Every case here corresponds to a bug that actually shipped and was found by
a code-reviewer subagent pass on this hook (three review rounds total) --
this is not a speculative test suite. Run with:

    python3 .claude/hooks/test_classify.py

Exits non-zero (and prints each failure) if any case regresses. Run this
before touching classify.py, and again before committing a change to it.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import classify  # noqa: E402

FAILURES = []


def check(name, tool_name, tool_input, expected, cwd=None):
    """expected: list of classification lines classify() should return.
    cwd, if given, is the hook event's top-level "cwd" field (the live shell
    working directory) -- NOT part of tool_input; classify.classify() reads
    it from data["cwd"], matching the real PreToolUse hook event shape."""
    data = {"tool_name": tool_name, "tool_input": tool_input}
    if cwd is not None:
        data["cwd"] = cwd
    got = classify.classify(data)
    if got != expected:
        FAILURES.append(f"{name}\n    expected: {expected}\n    got:      {got}")


def bash(cmd):
    return "Bash", {"command": cmd}


# --- Basic recognition ---
check("plain gh pr create", *bash("gh pr create --draft"), ["PR_CREATE"])
check("compound with &&", *bash("pnpm test && gh pr create --draft"), ["PR_CREATE"])
check("env var prefix", *bash("GH_TOKEN=x gh pr create"), ["PR_CREATE"])
check(
    "git commit with author, no coauthor",
    *bash('git commit --author="Adil Hasan <adil.hasan@cefalo.com>" -m test'),
    ["GIT_COMMIT\tTrue\tFalse"],
)
check(
    "git commit missing author",
    *bash("git commit -m test"),
    ["GIT_COMMIT\tFalse\tFalse"],
)
check("plain git status", *bash("git status"), [])
check("echo mentioning gh pr create in prose", *bash('echo "run gh pr create later"'), [])

# --- Round 1 bug: substring grep false-positive on quoted prose ---
check(
    "commit message mentioning 'gh pr create' in prose (must not classify as PR_CREATE)",
    *bash(
        'git commit --author="Adil Hasan <adil.hasan@cefalo.com>" '
        '-m "mentions gh pr create in the message body"'
    ),
    ["GIT_COMMIT\tTrue\tFalse"],
)

# --- Round 2 bug: shell injection (structural — verified separately by
# piping crafted values through the real argv-based scripts; classify.py
# itself never interpolates, nothing to unit-test here beyond "it doesn't
# crash on weird input", covered by the unbalanced-quote case below). ---

# --- Round 2 bug: newline was dead as a separator (shlex swallows it as
# whitespace unless explicitly added to punctuation_chars) ---
check(
    "git commit on the SECOND physical line of a multi-line command",
    *bash('echo step1\ngit commit -m test\necho done'),
    ["GIT_COMMIT\tFalse\tFalse"],
)
check(
    "newline embedded INSIDE a quoted -m message must stay part of that token,"
    " not split into a new group",
    *bash(
        'git commit --author="Adil Hasan <adil.hasan@cefalo.com>" '
        '-m "line1\nline2\n\nCo-Authored-By: Claude <x@y.com>"'
    ),
    ["GIT_COMMIT\tTrue\tTrue"],
)

# --- Round 2 Should-fix: Co-Authored-By substring-anywhere false positive ---
check(
    "commit message merely discussing Co-Authored-By (not a real trailer)",
    *bash(
        'git commit --author="Adil Hasan <adil.hasan@cefalo.com>" '
        '-m "docs: explain that a Co-Authored-By trailer is forbidden here"'
    ),
    ["GIT_COMMIT\tTrue\tFalse"],
)
check(
    "real Co-Authored-By trailer line",
    *bash(
        'git commit --author="Adil Hasan <adil.hasan@cefalo.com>" '
        '-m "msg\n\nCo-Authored-By: Claude <noreply@anthropic.com>"'
    ),
    ["GIT_COMMIT\tTrue\tTrue"],
)

# --- Round 2 Should-fix: --author= presence-only, not exact-match ---
check(
    "--author with wrong value must NOT satisfy the check",
    *bash('git commit --author="Someone Else <x@y.com>" -m test'),
    ["GIT_COMMIT\tFalse\tFalse"],
)

# --- Round 2 Should-fix: command-shape variants ---
check("git -C <dir> commit", *bash("git -C . commit -m test"), ["GIT_COMMIT\tFalse\tFalse"])
check("git --no-pager commit", *bash("git --no-pager commit -m test"), ["GIT_COMMIT\tFalse\tFalse"])
check("command git commit", *bash("command git commit -m test"), ["GIT_COMMIT\tFalse\tFalse"])
check(
    "absolute path git commit",
    *bash("/usr/bin/git commit -m test"),
    ["GIT_COMMIT\tFalse\tFalse"],
)
check(
    "gh --repo owner/repo pr create",
    *bash("gh --repo nhst/repo pr create --draft"),
    ["PR_CREATE"],
)
check("gh -R owner/repo pr create", *bash("gh -R nhst/repo pr create"), ["PR_CREATE"])

# --- Round 3 Blocking: OVERRIDE self-write, direct forms ---
check(
    "mkdir + touch OVERRIDE (the original PoC)",
    *bash("mkdir -p .claude/.review-gate && touch .claude/.review-gate/OVERRIDE"),
    ["OVERRIDE_WRITE"],
)
check(
    "echo redirected into OVERRIDE",
    *bash("echo x > .claude/.review-gate/OVERRIDE"),
    ["OVERRIDE_WRITE"],
)
check(
    "unrelated touch must NOT be flagged",
    *bash("touch some/other/file.txt"),
    [],
)

# --- Round 3 Blocking: grouping syntax bypass (found independently by two
# review passes) ---
check(
    "subshell-wrapped touch OVERRIDE: (touch ...)",
    *bash("(touch .claude/.review-gate/OVERRIDE)"),
    ["OVERRIDE_WRITE"],
)
check(
    "brace-grouped touch OVERRIDE: { touch ...; }",
    *bash("{ touch .claude/.review-gate/OVERRIDE; }"),
    ["OVERRIDE_WRITE"],
)
check(
    "subshell-wrapped git commit must still be classified",
    *bash('(git commit -m "msg")'),
    ["GIT_COMMIT\tFalse\tFalse"],
)
check(
    "brace-grouped gh pr create must still be classified",
    *bash("{ gh pr create --title x --body y; }"),
    ["PR_CREATE"],
)

# --- Round 3 Blocking: dd's of=<path> argument style ---
check(
    "dd of=<path> targeting OVERRIDE",
    *bash("dd if=/dev/null of=.claude/.review-gate/OVERRIDE"),
    ["OVERRIDE_WRITE"],
)
check(
    "dd of=<other path> must NOT be flagged",
    *bash("dd if=/dev/null of=some/other/file.txt"),
    [],
)

# --- Write/Edit tool calls ---
check(
    "Write targeting OVERRIDE path",
    "Write",
    {"file_path": ".claude/.review-gate/OVERRIDE", "content": ""},
    ["OVERRIDE_WRITE"],
)
check(
    "Write targeting an absolute path ending in the OVERRIDE suffix",
    "Write",
    {"file_path": "/private/var/www/html/dn/x/.claude/.review-gate/OVERRIDE", "content": ""},
    ["OVERRIDE_WRITE"],
)
check(
    "Write to an unrelated path must NOT be flagged",
    "Write",
    {"file_path": "app/components/Foo.vue", "content": "<template></template>"},
    [],
)
check(
    "Edit to an unrelated path must NOT be flagged",
    "Edit",
    {"file_path": "README.md", "old_string": "a", "new_string": "b"},
    [],
)

# --- Round 4 Blocking: cd-then-relative-write ---
def check_cwd(name, cmd, expected, cwd="/repo"):
    """Like check(), but for a Bash command with an explicit hook-event cwd
    (top-level "cwd", the live shell working directory -- NOT tool_input)."""
    check(name, "Bash", {"command": cmd}, expected, cwd=cwd)


check_cwd(
    "cd into review-gate dir then bare touch OVERRIDE",
    "cd .claude/.review-gate && touch OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "cd with ; instead of &&",
    "cd .claude/.review-gate; touch OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "cd elsewhere then touch OVERRIDE must NOT be flagged",
    "cd app/components && touch OVERRIDE",
    [],
)
import os as _os  # noqa: E402

_os.environ["CLAUDE_PROJECT_DIR"] = "/repo"
check(
    "cd via CLAUDE_PROJECT_DIR fallback when no cwd field present",
    "Bash",
    {"command": "cd .claude/.review-gate && touch OVERRIDE"},
    ["OVERRIDE_WRITE"],
)
del _os.environ["CLAUDE_PROJECT_DIR"]

# --- Round 4 Blocking: cp/mv/install directory-target semantics ---
check_cwd(
    "cp SRC into review-gate dir (trailing slash), portable POSIX form",
    "cp OVERRIDE .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "mv SRC into review-gate dir",
    "mv OVERRIDE .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "cp -t DIR SRC (GNU form)",
    "cp -t .claude/.review-gate/ OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "cp --target-directory=DIR SRC (GNU form)",
    "cp --target-directory=.claude/.review-gate/ OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "install -D SRC -t DIR (GNU form)",
    "install -D OVERRIDE -t .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "cp into an unrelated directory must NOT be flagged",
    "cp OVERRIDE some/other/dir/",
    [],
)
check_cwd(
    "cp with a source that isn't named OVERRIDE, into review-gate dir,"
    " must NOT be flagged",
    "cp readme.txt .claude/.review-gate/",
    [],
)

# --- Round 4 Blocking: path normalization (.. and //) ---
check_cwd(
    "touch with .. traversal resolving to the override path",
    "touch .claude/.review-gate/../.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "touch with a doubled slash",
    "touch .claude//.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check(
    "Write tool_input.file_path with .. traversal",
    "Write",
    {"file_path": ".claude/.review-gate/../.review-gate/OVERRIDE", "content": ""},
    ["OVERRIDE_WRITE"],
)

# --- Round 4 Should-fix: case-insensitive command/path matching ---
check_cwd(
    "uppercase TOUCH (case-insensitive filesystem lookup on macOS)",
    "TOUCH .claude/.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "lowercase override filename, still the same file on a"
    " case-insensitive filesystem",
    "touch .claude/.review-gate/override",
    ["OVERRIDE_WRITE"],
)
check(
    "uppercase GIT still recognized",
    *bash("GIT commit -m test"),
    ["GIT_COMMIT\tFalse\tFalse"],
)

# --- Round 4 Should-fix: cat/echo/printf without a redirect are reads, not
# writes -- must not be false-flagged as OVERRIDE_WRITE ---
check(
    "cat OVERRIDE with no redirect is a read, not a write",
    *bash("cat .claude/.review-gate/OVERRIDE"),
    [],
)
check(
    "echo mentioning the path with no redirect is not a write",
    *bash('echo "checking .claude/.review-gate/OVERRIDE"'),
    [],
)
check(
    "echo WITH a redirect into OVERRIDE is still a write",
    *bash("echo x > .claude/.review-gate/OVERRIDE"),
    ["OVERRIDE_WRITE"],
)

# --- Round 5 Blocking: compound redirect operators (shlex max-munches
# adjacent punctuation, so >|, &>, &>> never equal the bare "&gt;"/"&gt;&gt;"
# token an earlier version checked for) ---
check_cwd(
    "force-clobber redirect >| into OVERRIDE",
    "echo x >|.claude/.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "append-both-streams redirect &>> into OVERRIDE",
    "cat file &>>.claude/.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "redirect-both-streams &> into OVERRIDE",
    "ls &> .claude/.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "plain > into OVERRIDE still works (no regression)",
    "printf x > .claude/.review-gate/OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "2>&1-style fd-duplication redirect must NOT false-deny"
    " (the '2'/'1' tokens aren't path-shaped, so nothing matches"
    " OVERRIDE even though _has_write_redirect broadly triggers on"
    " the '>&' operator token — over-scanning here is harmless,"
    " not a false positive, since a deny still requires an actual"
    " override-path match)",
    "some-cmd 2>&1",
    [],
)

# --- Round 5 Blocking: ln's directory-target form was added to
# ALWAYS_WRITE_CMDS but never wired into the directory-target resolver ---
check_cwd(
    "ln (hardlink) into review-gate dir",
    "ln OVERRIDE .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "ln -s (symlink) into review-gate dir",
    "ln -s OVERRIDE .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)

# --- Round 5 Blocking: multi-source cp/install only checked the
# second-to-last positional, missing earlier sources in a 3+-arg form ---
check_cwd(
    "cp with 3 positional args (2 sources + dest dir):"
    " first source is OVERRIDE",
    "cp OVERRIDE otherfile .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "install with 3 positional args: first source is OVERRIDE",
    "install OVERRIDE otherfile .claude/.review-gate/",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "cp with 3 positional args, none named OVERRIDE, must NOT be flagged",
    "cp a b .claude/.review-gate/",
    [],
)

# --- Round 6 Blocking: subshell-scoped `cd` must not leak out and clobber
# the real tracked cwd once the subshell closes ---
check_cwd(
    "real cd, decoy subshell cd elsewhere, then bare write -- the"
    " subshell's cd must be discarded once its ')' closes, not overwrite"
    " the real tracked cwd (the exact bypass found by review)",
    "cd .claude/.review-gate && (cd /tmp) && touch OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "same, with ; instead of &&",
    "cd .claude/.review-gate; (cd /tmp); touch OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "a cd genuinely INSIDE the subshell should still gate a write that's"
    " also inside the same subshell",
    "(cd .claude/.review-gate && touch OVERRIDE)",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "a cd inside a subshell must NOT persist to a write AFTER the"
    " subshell closes, when that write is legitimately elsewhere",
    "(cd .claude/.review-gate) && touch some/other/file",
    [],
)
check_cwd(
    "nested subshells: cd two levels deep, decoy cd at the inner level"
    " only, real cd at the outer level must survive",
    "cd .claude/.review-gate && ( (cd /tmp) ) && touch OVERRIDE",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "brace grouping (not a subshell) DOES persist cd past its own"
    " closing brace, matching real bash",
    "{ cd .claude/.review-gate; } && touch OVERRIDE",
    ["OVERRIDE_WRITE"],
)

# --- Round 7 Blocking: subshell-wrapped cp/mv/install/ln's directory-target
# form was defeated by an unstripped trailing ")" being misread as the
# destination (candidate_write_targets' "last positional = dst" heuristic) ---
check_cwd(
    "subshell-wrapped cp into review-gate dir",
    "(cp OVERRIDE .claude/.review-gate/)",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "subshell-wrapped mv into review-gate dir",
    "(mv OVERRIDE .claude/.review-gate/)",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "subshell-wrapped install, 3 positional args",
    "(install OVERRIDE otherfile .claude/.review-gate/)",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "subshell-wrapped ln -s into review-gate dir",
    "(ln -s OVERRIDE .claude/.review-gate/)",
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "bare (cd) -- trailing ')' must not become a bogus cd argument",
    "(cd) && touch some/other/file",
    [],
)

# --- Round 7 Blocking: an unmatched ")" (most commonly a `case` pattern-arm
# terminator) must not be treated as closing a real subshell scope, and a
# command sitting right after it must still be examined ---
check_cwd(
    "case arm containing a cd, real write after the case statement ends",
    'case "$X" in foo) cd .claude/.review-gate ;; esac; touch OVERRIDE',
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "case arm containing the write itself, with a full path",
    'case "$X" in foo) touch .claude/.review-gate/OVERRIDE ;; esac',
    ["OVERRIDE_WRITE"],
)
check_cwd(
    "case statement with no override involvement must NOT be flagged",
    'case "$X" in foo) touch some/other/file ;; esac',
    [],
)
check_cwd(
    "a case arm's ')' must not corrupt cwd-tracking for an UNRELATED,"
    " separate subshell later in the same command",
    'case "$X" in foo) true ;; esac && (cd .claude/.review-gate && touch OVERRIDE)',
    ["OVERRIDE_WRITE"],
)

# --- Round 7: documented, accepted gap -- a `case` NESTED inside a real
# (...) subshell can desynchronize the paren-depth counter, since a
# case-arm's ')' and a real subshell-closing ')' are the identical token
# and telling them apart needs actual case/esac grammar recognition. See
# classify.py's Design boundary section. This asserts the CURRENT behavior
# so a future change is a deliberate decision, not an accidental regression
# in either direction -- same pattern as the $(...)/variable-expansion gaps
# below. ---
check_cwd(
    "documented gap: a case statement nested INSIDE the same enclosing"
    " subshell as the write it should gate -- the case arm's ')' is"
    " miscounted as closing the OUTER subshell early, so the write"
    " (still legitimately inside it, in real bash) gets checked against"
    " the wrong, already-restored cwd. A case NOT nested inside a"
    " subshell (tested above) works correctly; only this combination"
    " doesn't -- see classify.py's Design boundary section",
    '(cd .claude/.review-gate; case "$X" in foo) true ;; esac; touch OVERRIDE)',
    [],
)

# --- Round 4: explicitly-out-of-scope cases (documented design boundary,
# not a bug -- see classify.py's module docstring). These assert the
# CURRENT, ACCEPTED behavior so a future change to this list is a deliberate
# decision, not an accidental regression in either direction. ---
check(
    "$(...) command substitution is NOT traced (documented gap)",
    *bash("$(touch .claude/.review-gate/OVERRIDE)"),
    [],
)
check(
    "path built from a shell variable is NOT traced (documented gap)",
    *bash("X=OVERRIDE; touch .claude/.review-gate/$X"),
    [],
)

# --- Robustness ---
check(
    "unbalanced quotes must not crash, and must not be gated",
    *bash("git commit -m 'unclosed"),
    [],
)
check("empty command", "Bash", {"command": ""}, [])
check("no tool_input at all", "Bash", {}, [])

if FAILURES:
    print(f"FAILED: {len(FAILURES)} check(s) below")
    for f in FAILURES:
        print("---")
        print(f)
    sys.exit(1)
else:
    print("test_classify.py: all checks passed")
