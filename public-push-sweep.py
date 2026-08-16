#!/usr/bin/env python3
"""B3 - the P9 public-push safety sweep, as a command instead of a memory exercise.

github.com/2slowDD/AI-Assets-Scanner is a PUBLIC repo: pushing PUBLISHES. P9 requires five
checks before every push there, and until now they lived only in prose and depended on a human
remembering all five at the moment they were most eager to ship.

Usage:
    python public-push-sweep.py [<git-range>]     # default: origin/main..HEAD

Exit codes:  0 = nothing found   1 = findings to review   2 = usage/git error

WHAT THIS DOES AND DOES NOT COVER - read this before trusting a green run.

Only ADDED lines in the range are scanned; deletions cannot leak anything. Of P9's five checks,
three are mechanically decidable and are automated here (local absolute paths, internal
hostnames / non-public hosts, real-looking key literals). Customer identifiers are covered only
insofar as they appear as hostnames - a customer named in prose is not detectable. Unfixed
vulnerability disclosure is NOT automatable at all and is printed as a required human
acknowledgement rather than silently dropped, because a sweep that reports "5/5 clean" while
actually checking three is worse than no sweep: it converts an unchecked item into a checked one
in the reader's mind.

This script is deliberately NOT installed as a git hook. Doing so changes how the operator's
pushes behave and can block them mid-flight; that is their call, not the script's. To opt in:

    git config core.hooksPath .githooks    # then add .githooks/pre-push calling this file
"""
import re
import subprocess
import sys

DEFAULT_RANGE = "origin/main..HEAD"

# Hosts that are already public in shipped code, or are RFC-reserved test domains. A host NOT on
# this list is reported - the allowlist is the thing under review, which is the same inverted
# shape tests/SecretFixtureAllowlistTest.php uses. Widening it should require a reason out loud.
PUBLIC_HOSTS = {
    "wpservice.pro", "updates.wpservice.pro", "www.wpservice.pro",
    "img.shields.io", "api.wordpress.org", "wordpress.org", "github.com", "api.github.com",
    "developer.wordpress.org", "schema.org", "www.w3.org", "example.com", "example.test",
    "example.org", "site.test", "localhost", "a.example", "b.example",
}

CHECKS = [
    (
        "local absolute paths",
        re.compile(r"(?:[A-Za-z]:\\\\|[A-Za-z]:\\)(?:Users|AI|home|Documents)\b|/(?:home|Users)/[A-Za-z0-9._-]+"),
        "a developer's filesystem layout, published",
    ),
    (
        "real-looking key literals",
        re.compile(r"['\"](?:sk-|cusk_)[A-Za-z0-9_-]{6,}['\"]"),
        "a credential-shaped literal; the test allowlists cover fixtures, this catches the rest",
    ),
    (
        "private-network addresses",
        re.compile(r"\b(?:10\.\d{1,3}|192\.168|172\.(?:1[6-9]|2\d|3[01]))\.\d{1,3}\.?\d{0,3}\b"),
        "internal infrastructure addressing",
    ),
]

HOST_RE = re.compile(r"https?://([A-Za-z0-9.-]+)")

# NOTE: reserved TLDs (.example/.test/.invalid) are deliberately NOT blanket-skipped. The
# GENERIC reserved names are on PUBLIC_HOSTS above, so ordinary fixtures stay silent - but a
# host like `customer-name.example` is still reported, and should be. The risk it carries is
# not that it resolves (it cannot); it is that the LABEL names a real customer in a public
# repo. Blanket-skipping the TLD deletes exactly the catch this sweep earned on its first run.

# Files whose whole job is to hold credential-shaped fixtures. Scanning them re-reports every
# allowlist entry as a finding - the same self-matching trap the cusk_ guard hit on its first
# run. Their contents are already governed by tests/SecretFixtureAllowlistTest.php.
SKIP_PATHS = ("tests/SecretFixtureAllowlistTest.php",)


def added_lines(rng):
    """(file, line_text) for every added line in the range, excluding the +++ header."""
    try:
        out = subprocess.run(
            ["git", "diff", "--no-color", "-U0", rng],
            capture_output=True, text=True, check=True,
        ).stdout
    except subprocess.CalledProcessError as exc:
        print("git diff failed: %s" % (exc.stderr or "").strip(), file=sys.stderr)
        sys.exit(2)

    current = "?"
    for line in out.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
        elif line.startswith("+") and not line.startswith("+++"):
            yield current, line[1:]


def main():
    rng = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_RANGE
    findings = []

    for path, text in added_lines(rng):
        if path in SKIP_PATHS:
            continue
        for name, pattern, why in CHECKS:
            for hit in pattern.findall(text):
                findings.append((name, path, hit if isinstance(hit, str) else hit[0], why))
        for host in HOST_RE.findall(text):
            if host.lower() not in PUBLIC_HOSTS:
                findings.append((
                    "non-public hostname", path, host,
                    "an internal or customer host, published",
                ))

    print("P9 public-push safety sweep - range: %s" % rng)
    print("=" * 72)

    if findings:
        seen = set()
        for name, path, hit, why in findings:
            key = (name, path, hit)
            if key in seen:
                continue
            seen.add(key)
            print("  [%s]\n    %s\n    in %s\n    %s" % (name, hit, path, why))
        print("-" * 72)
        print("%d finding(s). Each is a candidate, not a verdict - review before pushing." % len(seen))
    else:
        print("  No local paths, key-shaped literals, private addresses or non-public hosts")
        print("  in the added lines of this range.")

    print("-" * 72)
    print("NOT CHECKED BY THIS SCRIPT - confirm by hand before you push:")
    print("  * Unfixed vulnerability disclosure: do the added lines describe a weakness that is")
    print("    still live in shipped code? No tool can decide this. A commit message or comment")
    print("    explaining how to exploit something not yet fixed is the failure mode.")
    print("  * Customer identifiers in PROSE rather than as hostnames.")

    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main())
