#!/usr/bin/env python3
"""Command classifier for .claude/hooks/gate.sh.

Reads a PreToolUse hook-event JSON object from stdin and prints zero or more
classification lines to stdout (one per gated command found), or "NONE" if
nothing in the input needs gating. Lines are one of:

    PR_CREATE
    GIT_COMMIT\t<has_author:True|False>\t<has_coauthor:True|False>
    OVERRIDE_WRITE

This lives in its own file (rather than embedded inline in gate.sh via a
bash heredoc) for two reasons: heredoc-embedded Python is fragile to edit
correctly (a bash escaping mistake here previously broke the hook for every
command in the repo until fixed), and a standalone file is directly
importable by test_classify.py, which is how the bugs this file now guards
against were actually found — see that file before "fixing" a false
negative here; every case it tests was a real bypass in an earlier version,
across four review rounds:

  1. substring-grep false positives on prose inside quoted commit messages
  2. a dead newline separator that let a multi-line command skip
     classification entirely (git commit/gh pr create not on line 1)
  3. shell/Python injection via unescaped interpolation of a crafted branch
     name into a "python3 -c ..." source string (fixed in gate.sh /
     record-review.sh, not here — noted for context)
  4. "(...)"/"{ ...; }" shell grouping syntax shifting the real command off
     the token position head-matching checked
  5. dd's of=<path> argument style not being recognized as a path at all
  6. `cd`-then-relative-write, and cp/mv/install's directory-target
     semantics ("cp OVERRIDE .claude/.review-gate/"), both silently
     resolving to the guarded path without any token literally containing
     it — closed by actually resolving what path a write command targets
     (tracking `cd` across the command, and considering both the
     file-target and directory-target reading of a two-argument cp/mv/
     install), rather than pattern-matching tokens in isolation.

Design boundary — read this before adding another layer of pattern-matching.
The line drawn here is: catch what an honest, cooperative agent could hit by
ordinary accident; do not chase what only a deliberately evasive one would
type. Item 6 above (cd-tracking, directory-target cp/mv/install, path
normalization) is squarely on the "accident" side — none of that requires
any intent to evade, they're everyday shell idioms. Explicitly NOT handled,
because producing them requires deliberately routing around this exact
check, not habit: shell variable expansion in a path (`X=OVERRIDE; touch
.claude/.review-gate/$X`), `$(...)`/backtick command substitution used to
perform the write as a side effect of evaluating an argument, globbing,
symlink tricks, or a write reached through a nested interpreter
(`bash -c '...'`, `python3 -c "open(...)..."`, `perl -e '...'`). A
determined, adversarial agent can defeat this hook; it was never meant to
survive one — see gate.sh's header for that framing. Command-name
matching (git/gh/touch/cp/...) IS case-insensitive (`.lower()`), since a
case-insensitive filesystem (the default on macOS) makes `TOUCH ...` behave
identically to `touch ...` with zero evasive intent required.

One more, narrower boundary, found the hard way: a bare `)` with no `(` to
its left is recognized (via an `open_parens` counter, not just "did we see
a `)` token") as NOT a real subshell close — the common case is a `case`
pattern-arm terminator (`pattern) cmd ;; esac`) — so it doesn't corrupt an
enclosing subshell's tracked cwd, and a command sitting right after it
(`foo) touch OVERRIDE ;; esac`) is still examined as its own group rather
than staying glued to the unmatched "case ... in foo" blob ahead of it
(`;;` is treated as a separator for exactly this reason). What this does
NOT do: distinguish a case-arm's `)` from a REAL subshell-closing `)` when
a `case` is nested INSIDE a `(...)` — both are the same token, and telling
them apart needs actual `case`/`in`/`esac` grammar recognition (knowing
we're "inside a case body" is a parser concept, not something a flat
paren-depth counter carries), which this file does not attempt. Concretely:
`(cd .claude/.review-gate; case $X in foo) true ;; esac; touch OVERRIDE)` —
the case arm's `)` is miscounted as closing the outer subshell early, so
the final touch (still legitimately inside it, in real bash) gets checked
against the wrong, already-restored cwd. This is a real, currently-open
gap, joining the list above — a `case` statement nested inside a subshell
is a big enough jump in "how much of a real shell program is this" that
it's on the same side of the line as `if`/`for`/`while`/function
definitions (never attempted at all, for the same reason): correctly
handling any of them needs recursive-descent parsing of shell grammar, not
token-stream classification.
"""
import json
import os
import posixpath
import re
import shlex
import sys

# The author / Co-Authored-By flags are still reported on GIT_COMMIT lines, but
# this project's gate.sh does not enforce them (it only blocks commits on main).
# Kept so the classifier and its tests stay identical to the upstream hook.
REQUIRED_AUTHOR = "--author=Adil Hasan <adil.hasan@cefalo.com>"
OVERRIDE_SUFFIX = ".claude/.review-gate/OVERRIDE"

GROUPING_OPEN = {"(", "{"}
SEPARATORS = {"&&", "||", ";", "|", "&", "\n", ";;"}
# ";;" (bash's case-arm terminator, e.g. `pattern) cmd ;; esac`) isn't
# semantically "two separators" — it's its own token meaning "end this
# case arm" — but treating it as one for OUR purposes (find any real
# simple command hidden inside a case body) is a safe, useful
# approximation: it makes `cd`/`touch`/etc. sitting inside a case arm show
# up as their own classifiable group instead of getting glued into one
# opaque blob headed by the literal word "case", which nothing matches.
# This is NOT full case/esac parsing (a `case`/`in`/pattern/`esac` token
# never carries any meaning here beyond "not git/gh/cd/a write command, so
# ignore it") — just enough to stop it from hiding a plain command inside.
ALWAYS_WRITE_CMDS = {"touch", "cp", "mv", "tee", "dd", "install", "ln"}
REDIRECT_ONLY_CMDS = {"cat", "echo", "printf"}  # only a write when redirected


def _expand_punct_token(tok):
    """shlex (with punctuation_chars set) glues ADJACENT punctuation
    characters into a single token even when they're semantically distinct
    bash tokens written with no space between them — e.g. `);` (a closing
    paren immediately followed by a separator, from something like
    `(cmd);`) comes out as one two-character token, which then matches
    neither the "(" / ")" scope-tracking checks nor the SEPARATORS set
    exactly, so it was silently dropped as an inert ordinary token —
    breaking group-splitting AND subshell-close tracking together. "(" and
    ")" never meaningfully combine with other operators in real bash, so
    peeling any leading/trailing run of them off a punctuation-only token
    and keeping the (still possibly-compound, e.g. "&&"/">>"/">|") middle
    intact recovers the individually-meaningful tokens. Non-punctuation-only
    tokens (anything with a letter, digit, path character, etc.) pass
    through unchanged."""
    if not tok or not all(c in "(){}<>|&;" for c in tok):
        return [tok]
    i, j = 0, len(tok)
    lead = []
    while i < j and tok[i] in "()":
        lead.append(tok[i])
        i += 1
    trail = []
    while j > i and tok[j - 1] in "()":
        trail.append(tok[j - 1])
        j -= 1
    middle = tok[i:j]
    return lead + ([middle] if middle else []) + list(reversed(trail))


def tokenize(command):
    # A real (unquoted) newline is treated as its own separator token, same
    # as && / ; — without this, only the first physical line of a
    # multi-line Bash tool command is ever classified. shlex still keeps a
    # newline *inside* a quoted argument (e.g. a multi-line -m message) as
    # literal content, not a separator, because punctuation splitting only
    # applies outside quotes.
    lex = shlex.shlex(command, posix=True, punctuation_chars="();<>|&\n")
    lex.whitespace = " \t\r\x0c"
    lex.whitespace_split = True
    try:
        raw = list(lex)
    except ValueError:
        return None
    return [t for tok in raw for t in _expand_punct_token(tok)]


def strip_env_assignments(group):
    i = 0
    while i < len(group) and re.match(r"^[A-Za-z_][A-Za-z0-9_]*=", group[i]):
        i += 1
    return group[i:]


def unwrap_command_prefix(group):
    # Strips wrapper syntax that's transparent to "what command is actually
    # being invoked" for this gate's purposes: command/exec/env prefixes, a
    # leading subshell/group-command opener, and — symmetrically — a
    # trailing subshell closer. `(cmd)` and `{ cmd; }` are ordinary,
    # non-adversarial bash (e.g. `(cd dir && git commit ...)` to scope a
    # directory change) — without the leading strip, core[0] is "(" or "{"
    # and every check below that matches on core[0] silently misses the
    # real command. The trailing strip matters for a different reason: bash
    # doesn't require anything (no ";") before a subshell's closing ")", so
    # when a write-shaped command is the LAST statement before one, its
    # group ends up as e.g. ["cp","OVERRIDE",".claude/.review-gate/",")"]
    # — that trailing ")" isn't a real argument, but candidate_write_targets
    # and match_cd's positional-argument logic don't know that, and "last
    # positional = the destination" is exactly the heuristic
    # candidate_write_targets uses for cp/mv/install/ln's directory-target
    # form. Left unstripped, the real destination gets misread as a
    # "source" and the bogus ")" as the "destination", so nothing matches
    # and the write goes unflagged — confirmed as a live bypass for
    # subshell-wrapped cp/mv/install/ln (though not touch, which doesn't
    # rely on positional counting) before this fix. (`{`/`}` brace grouping
    # doesn't have this problem: unlike "(...)", "{ ...; }" syntactically
    # *requires* a separator before the closing "}", so it always ends up
    # in its own group already, never glued onto a real command's tokens.)
    changed = True
    while changed and group:
        changed = False
        if group[0] in ("command", "exec"):
            group = group[1:]
            changed = True
        elif group[0] == "env":
            group = group[1:]
            group = strip_env_assignments(group)
            changed = True
        elif group[0] in GROUPING_OPEN:
            group = group[1:]
            changed = True
    while group and group[-1] == ")":
        group = group[:-1]
    return group


def basename(tok):
    return tok.rsplit("/", 1)[-1]


def cmd_name(tok):
    """Lowercased basename, for comparing against a known command name. A
    case-insensitive filesystem (the default on macOS) resolves `TOUCH` to
    the same binary as `touch` with zero evasive intent required — this is
    an accident an honest agent can hit, not something only chased for
    adversarial reasons (contrast the Design boundary note above)."""
    return basename(tok).lower()


def match_git_commit(core):
    core = unwrap_command_prefix(strip_env_assignments(core))
    if not core or cmd_name(core[0]) != "git":
        return None
    i = 1
    while i < len(core):
        tok = core[i]
        if tok == "commit":
            return core[i:]
        if tok in ("-C", "--git-dir", "--work-tree"):
            i += 2
            continue
        if tok == "-c":
            i += 2
            continue
        if tok.startswith("--git-dir=") or tok.startswith("--work-tree="):
            i += 1
            continue
        if tok in ("--no-pager", "--bare", "--no-replace-objects"):
            i += 1
            continue
        if tok.startswith("-"):
            i += 1  # unrecognized global flag — skip and keep scanning
            continue
        return None  # first non-flag token isn't "commit"
    return None


def match_gh_pr_create(core):
    core = unwrap_command_prefix(strip_env_assignments(core))
    if not core or cmd_name(core[0]) != "gh":
        return None
    i = 1
    saw_pr = False
    while i < len(core):
        tok = core[i]
        if not saw_pr:
            if tok == "pr":
                saw_pr = True
                i += 1
                continue
            if tok in ("-R", "--repo"):
                i += 2
                continue
            if tok.startswith("--repo="):
                i += 1
                continue
            if tok.startswith("-"):
                i += 1
                continue
            return None
        else:
            if tok == "create":
                return core[i:]
            if tok.startswith("-"):
                i += 1
                continue
            return None
    return None


def match_cd(core):
    """If core is a `cd [path]` invocation, return the raw path argument
    (or "" for a bare `cd`/`cd -`/`cd ~...`, meaning "cwd tracking lost —
    go conservative"), else return None (not a cd at all)."""
    core = unwrap_command_prefix(strip_env_assignments(core))
    if not core or cmd_name(core[0]) != "cd":
        return None
    args = [t for t in core[1:] if not t.startswith("-")]
    if not args or args[0] in ("-",) or args[0].startswith("~"):
        return ""
    return args[0]


# --- Path resolution -------------------------------------------------------
#
# We resolve *where a command actually writes*, not just whether some token
# looks like the guarded path in isolation — that's what let `cd
# .claude/.review-gate && touch OVERRIDE` and `cp OVERRIDE
# .claude/.review-gate/` through in an earlier version. Resolution needs a
# starting directory; we use whichever of these is available, in order:
# the hook event's own "cwd" field (the actual live shell cwd — most
# authoritative when present), then $CLAUDE_PROJECT_DIR (set by gate.sh's
# caller; a reasonable approximation of the repo root otherwise). If neither
# is available we skip cwd-aware resolution and fall back to literal/suffix
# token matching only — which is still run in ALL cases, in addition to (not
# instead of) resolved-path matching, since cwd tracking through `cd`/`(...)`
# is necessarily an approximation (see below) and the safe failure direction
# for this guard is to over-block, not under-block.
#
# `cd` tracking is a flat, sequential pass across the whole command's split
# groups, ignoring subshell "(...)" scoping (a `cd` inside a subshell really
# only affects that subshell, not commands after its closing paren) — this
# over-approximates in the conservative direction (more things read as
# "still inside the cd'd directory" than strictly correct), which again is
# the safe direction of error here.


def resolve(cwd, token):
    if cwd is None:
        return None
    if token.startswith("/"):
        return posixpath.normpath(token)
    return posixpath.normpath(posixpath.join(cwd, token))


def _paths_equal(a, b):
    # Case-insensitive: on the default macOS filesystem (APFS,
    # case-insensitive-preserving), "OVERRIDE" and "override" name the same
    # real file — an honest agent typing either needs zero evasive intent,
    # same reasoning as cmd_name() above.
    return a.lower() == b.lower()


def path_matches_override(raw_token, cwd, override_target):
    if not raw_token:
        return False
    norm = posixpath.normpath(raw_token.replace("\\", "/"))
    if _paths_equal(norm, OVERRIDE_SUFFIX) or norm.lower().endswith("/" + OVERRIDE_SUFFIX.lower()):
        return True
    if override_target is not None:
        resolved = resolve(cwd, raw_token)
        if resolved is not None and _paths_equal(resolved, override_target):
            return True
    return False


# Tools whose two-or-more-positional-argument form is "sources..., then a
# destination directory" — cp/mv/install/ln all share this (ln's directory
# form: `ln [-s] TARGET... DIRECTORY`, same shape as cp/mv/install).
DIR_TARGET_CMDS = {"cp", "mv", "install", "ln"}


def candidate_write_targets(core):
    """Best-effort list of path-shaped arguments a write-shaped command
    might target: dd's of=<path>; cp/install/ln's --target-directory=/-t
    (combined with each remaining source's basename); the classic
    `cp/mv/install/ln SRC... DST` positional form — when there are exactly
    two positional args, DST is considered both as a literal file target AND
    as a directory SRC's basename lands inside (we can't stat the real
    filesystem here, so both readings are checked); with three or more,
    real coreutils semantics require the last to be a directory and every
    earlier one a source landing inside it (`cp a b c DIR/` copies a, b, AND
    c into DIR) — checking only the second-to-last source missed this;
    otherwise every plain positional argument. Not a full option parser for
    any of these tools; covers the common, non-adversarial invocation
    shapes."""
    core = unwrap_command_prefix(strip_env_assignments(core))
    if not core:
        return []
    head = cmd_name(core[0])
    args = core[1:]

    targets = [a[len("of="):] for a in args if a.startswith("of=")]

    tdir = None
    positional = []
    i = 0
    while i < len(args):
        a = args[i]
        if a.startswith("--target-directory="):
            tdir = a[len("--target-directory="):]
            i += 1
            continue
        if a in ("-t", "--target-directory") and i + 1 < len(args):
            tdir = args[i + 1]
            i += 2
            continue
        if a.startswith("of="):
            i += 1
            continue
        if a.startswith("-"):
            i += 1
            continue
        positional.append(a)
        i += 1

    if tdir is not None:
        for src in positional:
            targets.append(posixpath.join(tdir, posixpath.basename(src)))
    elif head in DIR_TARGET_CMDS and len(positional) >= 2:
        dst = positional[-1]
        srcs = positional[:-1]
        if len(srcs) == 1:
            targets.append(dst)  # exactly 2 args: dst might be a literal file
        for src in srcs:
            targets.append(posixpath.join(dst, posixpath.basename(src)))
    else:
        targets.extend(positional)

    return targets


def _has_write_redirect(tokens):
    # shlex (with punctuation_chars set) max-munches adjacent punctuation
    # into one token, so compound redirect operators — >|, &>, &>>, >& —
    # never equal the bare "&gt;"/"&gt;&gt;" this used to check for; they're
    # each their own distinct token. Matching any token that's built
    # entirely from redirect/pipe punctuation and contains ">" catches all
    # of these (>, >>, >|, &>, &>>, >&, 1>, 2>&1's trailing "&1" is NOT
    # matched since "1" isn't in the punctuation set, which is fine — that
    # form redirects to an already-open fd, not a path).
    return any(t and all(c in "<>|&" for c in t) and ">" in t for t in tokens)


def group_writes_override(core, cwd, override_target):
    stripped = unwrap_command_prefix(strip_env_assignments(core))
    if not stripped:
        return False
    head = cmd_name(stripped[0])
    has_redirect = _has_write_redirect(stripped)

    if head in ALWAYS_WRITE_CMDS:
        pass
    elif head in REDIRECT_ONLY_CMDS and has_redirect:
        pass
    elif has_redirect:
        pass  # some other command with a shell redirection
    else:
        return False

    for tok in candidate_write_targets(stripped):
        if path_matches_override(tok, cwd, override_target):
            return True
    return False


def is_override_path(p):
    """Used for Write/Edit/NotebookEdit tool_input paths, which are already
    concrete file paths (not shell commands needing cwd/redirect analysis)."""
    if not p:
        return False
    p = posixpath.normpath(p.replace("\\", "/"))
    return _paths_equal(p, OVERRIDE_SUFFIX) or p.lower().endswith("/" + OVERRIDE_SUFFIX.lower())


def classify_group(group, cwd, override_target):
    """Classify one simple-command group (as produced by _walk_groups
    below), given the cwd in effect for it. Returns a classification line,
    or None if this group is a `cd` (handled by the caller, which mutates
    cwd_stack) or isn't gated at all."""
    cd_arg = match_cd(group)
    if cd_arg is not None:
        return ("CD", cd_arg)

    if group_writes_override(group, cwd, override_target):
        return "OVERRIDE_WRITE"
    m = match_gh_pr_create(group)
    if m is not None:
        return "PR_CREATE"
    m = match_git_commit(group)
    if m is not None:
        args = m[1:]  # drop the literal "commit" token
        has_author = any(tok == REQUIRED_AUTHOR for tok in args)
        has_coauthor = any(re.search(r"(?im)^\s*co-authored-by\s*:", tok) for tok in args)
        return "GIT_COMMIT\t%s\t%s" % (has_author, has_coauthor)
    return None


def walk_and_classify(toks, initial_cwd, override_target):
    """Single pass over the FULL token stream (not pre-split), tracking `cd`
    with real (...)-subshell scoping: a `cd` inside a `(...)` only affects
    commands up to that subshell's matching `)`, exactly like real bash — a
    `cd` there must NOT leak out and override the outer, still-correct
    tracked cwd once the subshell closes. `{ ...; }` grouping, by contrast,
    does NOT get its own scope (a `cd` inside persists past the closing `}`
    in real bash too), so `{`/`}` tokens are not treated specially here —
    only literal `(`/`)`.

    An earlier version processed each split-on-separator group independently
    against a single mutable `cwd` variable with no notion of nesting; a
    trailing `cd` inside a "(...)" aside (e.g. `cd real/target && (cd
    /somewhere/else) && touch OVERRIDE`) would silently clobber the real
    tracked cwd on its way out of the subshell, causing the FINAL bare write
    to be resolved against the wrong directory and missed — an under-block,
    not the "safe over-approximation" the flat model was assumed to be.
    This walks tokens directly instead, so a `cd`'s effect can be scoped and
    discarded when its subshell closes.

    override_target is fixed (computed once by the caller from the initial
    cwd) and never recomputed here — only the per-group `cwd` used to
    resolve relative *write-target* tokens changes."""
    out = []
    cwd_stack = [initial_cwd]
    cur = []
    pending_closes = 0
    # Real, currently-open "(" count — NOT just "how many ')' tokens have
    # we seen". A bare ")" can appear with no matching "(" at all: a `case`
    # pattern-arm terminator ("foo)") is the common one, produced by
    # ordinary, non-adversarial shell control flow. Treating every ")" as
    # closing a scope (as an earlier version did) meant a case arm's
    # terminator could pop a REAL, unrelated subshell's scope early,
    # corrupting cwd-tracking for the rest of that subshell's body. Only a
    # ")" that actually has an open "(" to close is allowed to affect
    # cwd_stack; an unmatched one is just an ordinary token (also handled
    # by unwrap_command_prefix's trailing-")" strip when it ends up glued
    # onto a real command's group).
    open_parens = 0

    def flush():
        nonlocal cur, pending_closes
        if cur:
            result = classify_group(cur, cwd_stack[-1], override_target)
            if isinstance(result, tuple) and result[0] == "CD":
                cd_arg = result[1]
                if cd_arg == "":
                    cwd_stack[-1] = None  # lost track (bare cd / cd -/~)
                elif cwd_stack[-1] is not None:
                    cwd_stack[-1] = resolve(cwd_stack[-1], cd_arg)
            elif result is not None:
                out.append(result)
        for _ in range(pending_closes):
            if len(cwd_stack) > 1:
                cwd_stack.pop()
        pending_closes = 0
        cur = []

    for t in toks:
        if t in SEPARATORS:
            flush()
            continue
        if t == "(":
            cur.append(t)
            open_parens += 1
            cwd_stack.append(cwd_stack[-1])  # enter a new, independent scope
            continue
        if t == ")":
            if open_parens > 0:
                cur.append(t)
                open_parens -= 1
                pending_closes += 1  # real scope exit, once this group flushes
            else:
                # Unmatched — most commonly a `case` pattern-arm terminator
                # ("pattern)"). It isn't part of the pseudo-command before
                # it (a case pattern never matches anything checked below
                # anyway) — but real bash DOES start a fresh command list
                # right after it, so treat it as a group boundary: flush
                # what's accumulated so far (harmless), then start clean.
                # Without this, `case $X in foo) touch OVERRIDE ;; esac`
                # stays one opaque blob headed by the literal word "case",
                # so "touch OVERRIDE" sitting right after the ")" is never
                # separately examined at all.
                flush()
            continue
        cur.append(t)

    flush()
    return out


def classify(data):
    tool_name = data.get("tool_name") or ""
    ti = data.get("tool_input") or {}

    if tool_name in ("Write", "Edit", "NotebookEdit"):
        for key in ("file_path", "path", "notebook_path"):
            if is_override_path(ti.get(key)):
                return ["OVERRIDE_WRITE"]
        return []

    cmd = ti.get("command") or ti.get("text") or ti.get("script") or ""
    if not cmd:
        return []

    toks = tokenize(cmd)
    if toks is None:
        # Unbalanced quotes or similar — cannot safely classify; do not gate.
        return []

    initial_cwd = data.get("cwd")
    if not isinstance(initial_cwd, str) or not initial_cwd:
        initial_cwd = os.environ.get("CLAUDE_PROJECT_DIR") or None
    # Fixed for the whole call — see walk_and_classify's docstring for why
    # this must never be recomputed relative to a moving cwd.
    override_target = resolve(initial_cwd, OVERRIDE_SUFFIX) if initial_cwd else None

    return walk_and_classify(toks, initial_cwd, override_target)


def main():
    try:
        data = json.load(sys.stdin)
    except Exception:
        print("NONE")
        return
    lines = classify(data)
    print("\n".join(lines) if lines else "NONE")


if __name__ == "__main__":
    main()
