#!/usr/bin/env python3
"""Build the AAS release ZIP for a given version.
Guarantees: lowercase `ai-assets-scanner/` root, forward-slash entries, runtime files only.
Usage: python build-release.py <version>
"""
import sys, os, hashlib, zipfile, re

VERSION = sys.argv[1]
ROOT = os.path.dirname(os.path.abspath(__file__))
PKG = "ai-assets-scanner"
OUT_DIR = os.path.join(ROOT, "releases", VERSION)
OUT_ZIP = os.path.join(OUT_DIR, "ai-assets-scanner.zip")

TOP_FILES = ["ai-assets-scanner.php", "uninstall.php", "README.md", "CHANGELOG.md", "INSTALL.md"]
TOP_DIRS = ["admin", "includes"]
EXCLUDE_PARTS = {".git", "node_modules", "tests", "vendor", "releases", "tasks", "__pycache__"}
EXCLUDE_EXT = {".pyc", ".map", ".bak"}

os.makedirs(OUT_DIR, exist_ok=True)


def _md_inline(text):
    """Convert the small Markdown inline subset used in CHANGELOG.md to HTML.
    Code spans are converted first so their contents are not re-processed."""
    text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", text)
    text = text.replace(" -- ", " &mdash; ").replace("—", "&mdash;")
    text = text.replace(" -> ", " &rarr; ").replace("→", "&rarr;")
    return text


def _changelog_block_lines(version):
    """Lines of the `## <version>` section of CHANGELOG.md (header line excluded)."""
    path = os.path.join(ROOT, "CHANGELOG.md")
    if not os.path.isfile(path):
        return []
    out, capturing = [], False
    for ln in open(path, encoding="utf-8").read().splitlines():
        if ln.startswith("## "):
            if capturing:
                break
            capturing = ln[3:].strip().startswith(version)
            continue
        if capturing:
            out.append(ln)
    return out


def _block_to_html(version):
    """Render the CHANGELOG block for <version> as the changelog.html snippet."""
    html = ["<p>Version %s (beta).</p>" % version]
    in_ul = False
    for raw in _changelog_block_lines(version):
        s = raw.strip()
        if not s or s == "---":
            if in_ul:
                html.append("</ul>")
                in_ul = False
            continue
        if s.startswith("### "):
            if in_ul:
                html.append("</ul>")
                in_ul = False
            html.append("<p><strong>" + _md_inline(s[4:].strip()) + "</strong></p>")
        elif s.startswith(("- ", "* ")):
            if not in_ul:
                html.append("<ul>")
                in_ul = True
            html.append("<li>" + _md_inline(s[2:].strip()) + "</li>")
        elif len(s) > 1 and s.startswith("_") and s.endswith("_"):
            if in_ul:
                html.append("</ul>")
                in_ul = False
            html.append("<p><em>" + _md_inline(s[1:-1].strip()) + "</em></p>")
        else:
            if in_ul:
                html.append("</ul>")
                in_ul = False
            html.append("<p>" + _md_inline(s) + "</p>")
    if in_ul:
        html.append("</ul>")
    return "\n".join(html) + "\n"


def ensure_changelog_html(version):
    """changelog.html is REQUIRED (stable.json.changelog_url target). Keep a
    hand-written one if present; otherwise generate it from CHANGELOG.md so a
    release can never ship without one."""
    path = os.path.join(OUT_DIR, "changelog.html")
    if os.path.isfile(path) and os.path.getsize(path) > 0:
        return "present"
    open(path, "w", encoding="utf-8", newline="\n").write(_block_to_html(version))
    return "generated"


def add_file(zf, abs_path, arc_rel):
    # arc_rel is POSIX-style relative to PKG root
    arcname = f"{PKG}/{arc_rel}"
    zi = zipfile.ZipInfo(arcname)
    zi.compress_type = zipfile.ZIP_DEFLATED
    zi.external_attr = 0o644 << 16
    with open(abs_path, "rb") as f:
        zf.writestr(zi, f.read())

entries = []
with zipfile.ZipFile(OUT_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for fn in TOP_FILES:
        p = os.path.join(ROOT, fn)
        if os.path.isfile(p):
            add_file(zf, p, fn)
            entries.append(f"{PKG}/{fn}")
    for d in TOP_DIRS:
        base = os.path.join(ROOT, d)
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [x for x in dirnames if x not in EXCLUDE_PARTS]
            for fname in sorted(filenames):
                if os.path.splitext(fname)[1] in EXCLUDE_EXT:
                    continue
                abs_path = os.path.join(dirpath, fname)
                rel = os.path.relpath(abs_path, ROOT).replace(os.sep, "/")
                add_file(zf, abs_path, rel)
                entries.append(f"{PKG}/{rel}")

sha = hashlib.sha256(open(OUT_ZIP, "rb").read()).hexdigest()
print("ENTRIES", len(entries))
print("SHA256", sha)
print("BACKSLASH_COUNT", sum("\\" in e for e in entries))
print("HAS_MAIN", any(e == f"{PKG}/ai-assets-scanner.php" for e in entries))
print("UPPERCASE_ROOT", any(e.startswith("AI-Assets-Scanner/") for e in entries))
print("ZIP_BYTES", os.path.getsize(OUT_ZIP))
# write checksum.txt (no trailing newline issues — match prior format: bare hash + newline)
open(os.path.join(OUT_DIR, "checksum.txt"), "w", newline="\n").write(sha + "\n")
print("WROTE", os.path.join(OUT_DIR, "checksum.txt"))
# changelog.html is a required release artifact (stable.json.changelog_url).
# Auto-ensure it so a release can never ship without one.
print("CHANGELOG_HTML", ensure_changelog_html(VERSION))
