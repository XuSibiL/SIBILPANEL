#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  publish_release.sh — نسخهٔ تازه را همان لحظه روی گیت‌هاب قابل‌دانلود می‌کند.
#
#  Usage:
#     GITHUB_TOKEN=ghp_xxx bash build/publish_release.sh
#     bash build/publish_release.sh --token ghp_xxx [--repo owner/name] [--dry-run]
#
#  کارها به ترتیب:
#     1) نسخه از فایل VERSION خوانده می‌شود (همان نسخه‌ای که تست شده)
#     2) تگ `v<VERSION>` روی همان کامیت ساخته/به‌روز می‌شود
#     3) ریلیز گیت‌هاب ساخته می‌شود و بسته‌ها به آن پیوست می‌شوند:
#        tar.gz · zip · sibil-installer.sh · sha256
#     4) لینک دانلود مستقیم چاپ می‌شود
#
#  اگر ریلیز از قبل باشد، دارایی‌ها جایگزین می‌شوند (idempotent).
#  توکن فقط در همان اجرا استفاده می‌شود و هیچ‌جا نوشته نمی‌شود.
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail

RED=$'\033[31m'; GRN=$'\033[32m'; YEL=$'\033[33m'; B=$'\033[1m'; R=$'\033[0m'
say()  { printf '%s\n' "$*"; }
step() { printf '\n%s▸ %s%s\n' "$B" "$1" "$R"; }
ok()   { printf '%s✓%s %s\n' "$GRN" "$R" "$*"; }
warn() { printf '%s!%s %s\n' "$YEL" "$R" "$*"; }
die()  { printf '%s✗ %s%s\n' "$RED" "$*" "$R" >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO="${PANEL_UPDATE_REPO:-XuSibiL/SIBILPANEL}"
TOKEN="${GITHUB_TOKEN:-}"
DRY=0
BRANCH="${PANEL_UPDATE_BRANCH:-main}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --token) TOKEN="${2:-}"; shift 2 ;;
        --token=*) TOKEN="${1#*=}"; shift ;;
        --repo) REPO="${2:-}"; shift 2 ;;
        --repo=*) REPO="${1#*=}"; shift ;;
        --branch) BRANCH="${2:-}"; shift 2 ;;
        --dry-run|-n) DRY=1; shift ;;
        -h|--help) sed -n '2,25p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

cd "$ROOT"
[[ -f VERSION ]] || die "VERSION file is missing in $ROOT"
VERSION="$(tr -d ' \r\n' <VERSION)"
[[ -n "$VERSION" ]] || die "VERSION file is empty"
TAG="v$VERSION"

say ""
say "  ${B}release publisher${R}"
say "  repo    : $REPO"
say "  version : $VERSION  (tag: $TAG)"
say "  branch  : $BRANCH"
say ""

# ── ۱) بسته‌ها باید موجود و تازه باشند ────────────────────────────────────────
step "1/4 checking the local packages"
TARBALL="release/panel-service-$VERSION-src.tar.gz"
ZIPFILE="release/panel-service-$VERSION-src.zip"
INSTALLER="dist/sibil-installer.sh"
SHAFILE="release/panel-service-$VERSION-src.sha256"

if [[ ! -f "$TARBALL" || ! -f "$ZIPFILE" ]]; then
    warn "packages are missing — building them now"
    bash build/package.sh >/dev/null || die "build/package.sh failed"
fi
[[ -f "$TARBALL" ]] || die "missing $TARBALL"
[[ -f "$ZIPFILE" ]] || die "missing $ZIPFILE"
ok "tarball : $TARBALL ($(wc -c <"$TARBALL") bytes)"
ok "zip     : $ZIPFILE ($(wc -c <"$ZIPFILE") bytes)"
if [[ -f "$INSTALLER" ]]; then
    ok "installer: $INSTALLER ($(wc -c <"$INSTALLER") bytes)"
else
    warn "installer not found (dist/sibil-installer.sh) — it will be skipped"
fi
mkdir -p release
[[ -f "$SHAFILE" ]] || { (cd release && sha256sum "$(basename "$TARBALL")" >"$(basename "$SHAFILE")") || true; }

if [[ $DRY -eq 1 ]]; then
    step "dry-run"
    say "would push tag $TAG and publish a release with:"
    say "  · $(basename "$TARBALL")"
    say "  · $(basename "$ZIPFILE")"
    [[ -f "$INSTALLER" ]] && say "  · $(basename "$INSTALLER")"
    [[ -f "$SHAFILE" ]] && say "  · $(basename "$SHAFILE")"
    exit 0
fi

[[ -n "$TOKEN" ]] || die "a GitHub token is required (--token ghp_xxx or GITHUB_TOKEN=…)"

api() {   # api METHOD PATH [curl args…]
    local method="$1" path="$2"; shift 2
    curl -sS -X "$method" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/vnd.github+json" \
        -H "X-GitHub-Api-Version: 2022-11-28" \
        -H "User-Agent: sibil-publisher" \
        "https://api.github.com$path" "$@"
}

json_get() { python3 -c "import json,sys;d=json.load(sys.stdin);print(d.get('$1','') or '')" 2>/dev/null; }

# ── ۲) سلامت مخزن و کامیت فعلی ────────────────────────────────────────────────
step "2/4 confirming the repository and the commit"
repoInfo="$(api GET "/repos/$REPO" 2>/dev/null || true)"
fullName="$(printf '%s' "$repoInfo" | json_get full_name)"
[[ -n "$fullName" ]] || die "cannot reach $REPO with this token"
ok "repository: $fullName"
sha="$(api GET "/repos/$REPO/commits/$BRANCH" | json_get sha)"
[[ -n "$sha" ]] || die "cannot read the head of branch $BRANCH"
ok "commit $BRANCH: ${sha:0:8}"

# نسخهٔ همان کامیت باید برابر VERSION محلی باشد، وگرنه ریلیز گمراه‌کننده است.
# منبع: Contents API (همان لحظهٔ پوش)، چون raw.githubusercontent تا ۵ دقیقه
# نسخهٔ کهنه را از کش برمی‌گرداند.
remoteVersion="$(api GET "/repos/$REPO/contents/VERSION?ref=$BRANCH" 2>/dev/null \
    | sed -n 's/.*"content" *: *"\([^"]*\)".*/\1/p' | head -1 | sed 's/\\n//g' \
    | base64 -d 2>/dev/null | tr -d ' \r\n' || true)"
if [[ -z "$remoteVersion" ]]; then
    remoteVersion="$(curl -fsS --max-time 15 -H 'Cache-Control: no-cache' \
        "https://raw.githubusercontent.com/$REPO/$BRANCH/VERSION?t=$(date +%s)" 2>/dev/null \
        | tr -d ' \r\n' || true)"
fi
if [[ -n "$remoteVersion" && "$remoteVersion" != "$VERSION" ]]; then
    die "VERSION on GitHub is $remoteVersion but locally $VERSION — push the commit first"
fi
ok "VERSION on GitHub matches: $VERSION"

# ── ۳) تگ + ریلیز ─────────────────────────────────────────────────────────────
step "3/4 creating the tag and the release"
existingTag="$(api GET "/repos/$REPO/git/ref/tags/$TAG" | json_get ref)"
if [[ -n "$existingTag" ]]; then
    ok "tag $TAG already exists — reusing it"
else
    payload="$(python3 - "$TAG" "$sha" <<'PY'
import json, sys
print(json.dumps({"ref": "refs/tags/" + sys.argv[1], "sha": sys.argv[2]}))
PY
)"
    created="$(api POST "/repos/$REPO/git/refs" -d "$payload" | json_get ref)"
    [[ -n "$created" ]] || die "could not create tag $TAG"
    ok "tag created: $TAG"
fi

relId="$(api GET "/repos/$REPO/releases/tags/$TAG" | json_get id)"
if [[ -n "$relId" ]]; then
    ok "release $TAG already exists (id $relId) — replacing the assets"
else
    notes="$(python3 - "$ROOT/docs/CHANGELOG.md" "$VERSION" <<'PY'
import re, sys
path, version = sys.argv[1], sys.argv[2]
text = open(path, encoding='utf-8').read()
m = re.search(r'^## ' + re.escape(version) + r'(?:[ \t][^\r\n]*)?\r?\n(.*?)(?=^## |\Z)', text, re.S | re.M)
print((m.group(1).strip() if m else '')[:5000])
PY
)"
    [[ -n "$notes" ]] || notes="SIBILPANEL $VERSION"
    payload="$(python3 - "$TAG" "$VERSION" "$notes" "$BRANCH" <<'PY'
import json, sys
tag, version, notes, branch = sys.argv[1:5]
print(json.dumps({
    "tag_name": tag,
    "target_commitish": branch,
    "name": "SIBILPANEL " + version,
    "body": notes,
    "draft": False,
    "prerelease": False,
}))
PY
)"
    relId="$(api POST "/repos/$REPO/releases" -d "$payload" | json_get id)"
    [[ -n "$relId" ]] || die "could not create the release"
    ok "release created: $TAG (id $relId)"
fi

# دارایی‌های قبلی همان نام حذف می‌شوند تا نام تکراری خطا ندهد
assets="$(api GET "/repos/$REPO/releases/$relId/assets" || true)"
python3 - "$relId" <<'PY' >/tmp/asset_ids.$$ 2>/dev/null || true
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    data = []
for row in data:
    print(row.get("id"), row.get("name"))
PY
while read -r aid aname; do
    [[ -z "${aid:-}" ]] && continue
    api DELETE "/repos/$REPO/releases/assets/$aid" >/dev/null 2>&1 || true
    ok "old asset replaced: $aname"
done < <(printf '%s' "$assets" | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    data = []
for row in data:
    print(row.get("id"), row.get("name"))
' 2>/dev/null || true)
rm -f /tmp/asset_ids.$$

upload() {   # upload FILE LABEL
    local file="$1" name; name="$(basename "$1")"
    local type="application/octet-stream"
    case "$name" in
        *.tar.gz) type="application/gzip" ;;
        *.zip)    type="application/zip" ;;
        *.sh)     type="text/x-shellscript" ;;
        *.sha256) type="text/plain" ;;
    esac
    local url="https://uploads.github.com/repos/$REPO/releases/$relId/assets?name=$name"
    local out
    out="$(curl -sS -X POST -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/vnd.github+json" \
        -H "Content-Type: $type" \
        -H "User-Agent: sibil-publisher" \
        --data-binary @"$file" "$url")"
    if printf '%s' "$out" | grep -q '"browser_download_url"'; then
        ok "uploaded: $name"
    else
        warn "upload failed: $name — ${out:0:120}"
        return 1
    fi
}

upload "$TARBALL" || die "the source tarball could not be uploaded"
upload "$ZIPFILE" || warn "zip upload failed (tarball is available)"
[[ -f "$INSTALLER" ]] && { upload "$INSTALLER" || warn "installer upload failed"; }
[[ -f "$SHAFILE" ]] && { upload "$SHAFILE" || warn "checksum upload failed"; }

# ── ۴) لینک نهایی ─────────────────────────────────────────────────────────────
step "4/4 done"
say ""
say "  ${B}release page${R} : https://github.com/$REPO/releases/tag/$TAG"
say "  ${B}latest${R}       : https://github.com/$REPO/releases/latest"
say "  ${B}download${R}     : https://github.com/$REPO/releases/download/$TAG/$(basename "$TARBALL")"
say "  ${B}install${R}      : bash <(curl -Ls https://raw.githubusercontent.com/$REPO/$BRANCH/install.sh)"
say ""
ok "SIBILPANEL $VERSION is published and downloadable on GitHub"
