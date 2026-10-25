#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  deploy.sh — put this package on GitHub from a Linux server, in one command.
#
#  Usage:
#     bash deploy.sh <owner>/<repo>
#     bash deploy.sh <owner>/<repo> --token ghp_xxx
#     GITHUB_TOKEN=ghp_xxx bash deploy.sh <owner>/<repo>
#     bash deploy.sh <owner>/<repo> ~/SIBILPANEL        # custom target directory
#
#  Steps (each one prints its number so you can see where it stops):
#     1) check git + clean copy of the package
#     2) strip machine-only files (tests, temp, local DB, secrets, build junk)
#     3) git init -b main + one commit
#     4) ask for the token (only if the push needs one)
#     5) git push
#
#  It never uploads .env, *.sqlite3, logs/, backups/, release/ or .git.
#  The token is used for one push only — never written to .git/config or a file.
#  No step can wait forever: git runs with prompts disabled and a hard timeout.
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BOLD=$'\033[1m'; OFF=$'\033[0m'
say()  { printf '%s\n' "$*"; }
step() { printf '\n%s[%s]%s %s\n' "$BOLD" "$1" "$OFF" "$2"; }
ok()   { printf '%s✔%s %s\n' "$GREEN" "$OFF" "$*"; }
warn() { printf '%s!%s %s\n' "$YELLOW" "$OFF" "$*"; }
die()  { printf '%s✗ %s%s\n' "$RED" "$*" "$OFF" >&2; exit 1; }

# git must never sit on a "Username for https://github.com:" prompt
export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=/bin/true

REPO=""; TARGET=""
TOKEN="${GITHUB_TOKEN:-}"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --token|-t) TOKEN="${2:-}"; shift 2 ;;
        -*) die "unknown option: $1" ;;
        *)  if [[ -z "$REPO" ]]; then REPO="$1"; elif [[ -z "$TARGET" ]]; then TARGET="$1"; fi; shift ;;
    esac
done
TARGET="${TARGET:-SIBILPANEL}"

LINKED='[deploy.sh](http://deploy.sh)'
[[ "$REPO" == "$LINKED" || "$REPO" == "http://deploy.sh" ]] && \
    die "you pasted a chat link, not a repo — type:  bash deploy.sh <owner>/<repo>"
[[ -n "$REPO" ]] || die "usage: bash deploy.sh <owner>/<repo> [--token ghp_xxx]"
[[ "$REPO" == */* && "$REPO" != /* ]] || die "repo must look like  owner/repo"

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$SRC/sibil.php" && -f "$SRC/VERSION" ]] || \
    die "run this inside the package directory (sibil.php and VERSION were not found here)"

step 1/5 "checking git and preparing $(pwd)/$TARGET"
command -v git >/dev/null 2>&1 || die "git is not installed — run: apt-get install -y git"
[[ "$(git --version 2>/dev/null | awk '{print $3}')" > /dev/null ]] || die "git is broken"
if [[ -e "$TARGET" && -n "$(ls -A "$TARGET" 2>/dev/null)" ]]; then
    die "$TARGET already exists and is not empty — run: rm -rf $TARGET"
fi
mkdir -p "$TARGET"
tar -C "$SRC" \
    --exclude='.git' --exclude='tests' --exclude='release' --exclude='build/__pycache__' \
    --exclude='.env' --exclude='*.env.bak' --exclude='*.sqlite3*' --exclude='*.db' \
    --exclude='logs' --exclude='backups' --exclude='uploads' --exclude='*.log' \
    --exclude='*.pid' --exclude='.idea' --exclude='.vscode' --exclude='*.tmp' \
    -cf - . 2>/dev/null | tar -C "$TARGET" -xf - || die "copy failed"
find "$TARGET" -maxdepth 2 \( -name '.env' -o -name '*.sqlite3*' -o -name '*.log' \) \
    -delete 2>/dev/null || true
ok "$(find "$TARGET" -type f | wc -l) files copied — no .env, no database, no logs"

step 2/5 "initialising the repository on branch main"
cd "$TARGET"
if [[ -d .git ]]; then
    git checkout -B main >/dev/null 2>&1 || true
else
    git init -q -b main 2>/dev/null || { git init -q; git checkout -b main >/dev/null 2>&1; }
fi
git config user.name  >/dev/null 2>&1 || git config user.name  "panel-admin"
git config user.email >/dev/null 2>&1 || git config user.email "panel-admin@localhost"
git add -A
if git diff --cached --quiet; then
    warn "nothing new to commit"
else
    git commit -q -m "release: $(cat VERSION 2>/dev/null || echo 1.0.0)"
    ok "commit created: $(cat VERSION 2>/dev/null)"
fi
REMOTE="https://github.com/${REPO}.git"
git remote remove origin >/dev/null 2>&1 || true
git remote add origin "$REMOTE"
ok "remote: $REMOTE"

step 3/5 "checking the repository on github"
if command -v curl >/dev/null 2>&1; then
    code="$(curl -s -o /dev/null -w '%{http_code}' --connect-timeout 8 --max-time 15 \
        "https://api.github.com/repos/$REPO" 2>/dev/null || echo 000)"
    case "$code" in
        200) ok "repository found (public)" ;;
        404) warn "repository not found (or private) — create it empty at github.com/new (no README)" ;;
        403) warn "GitHub rate limit — continuing" ;;
        000) warn "could not reach api.github.com — continuing" ;;
        *)   warn "github answered HTTP $code — continuing" ;;
    esac
fi

step 4/5 "github token"
if [[ -z "$TOKEN" ]]; then
    if [[ -t 0 ]]; then
        say "  GitHub no longer accepts a password over HTTPS; a token is required."
        say "  make one: github.com → Settings → Developer settings → Personal access tokens"
        say "            → Tokens (classic) → Generate new token → tick only  repo"
        printf '  paste token (nothing is shown while you paste, then press Enter): '
        read -r -s -t 180 TOKEN || TOKEN=''
        printf '\n'
    fi
fi
if [[ -z "$TOKEN" ]]; then
    warn "no token given — trying the push anyway (works only if the repo already trusts this machine)"
    PUSH_URL="$REMOTE"
else
    ok "token received (${#TOKEN} characters) — it is used once and never stored"
    PUSH_URL="https://${TOKEN}@github.com/${REPO}.git"
fi

step 5/5 "pushing main to $REMOTE"
set +e
if command -v timeout >/dev/null 2>&1; then
    out="$(timeout 180 git push -u "$PUSH_URL" main 2>&1)"; rc=$?
else
    out="$(git push -u "$PUSH_URL" main 2>&1)"; rc=$?
fi
set -e
printf '%s\n' "$out" | sed 's#//[^@]*@github.com#//***@github.com#g'
git remote set-url origin "$REMOTE" >/dev/null 2>&1 || true

# اگر مخزن از قبل کامیت دارد، تاریخچه‌اش حفظ می‌شود: کامیت ما روی HEAD ریموت می‌نشیند
if [[ $rc -ne 0 ]] && [[ "$out" == *"rejected"* || "$out" == *"non-fast-forward"* || "$out" == *"fetch first"* ]]; then
    warn "the repository already has commits — rebasing this release on top of them"
    if git fetch --depth=1 "$PUSH_URL" main >/dev/null 2>&1; then
        git reset --soft FETCH_HEAD
        git commit -q -m "release: $(cat VERSION 2>/dev/null || echo 1.0.0)"
        set +e
        out="$(timeout 180 git push -u "$PUSH_URL" main 2>&1)"; rc=$?
        set -e
        printf '%s\n' "$out" | sed 's#//[^@]*@github.com#//***@github.com#g'
        git remote set-url origin "$REMOTE" >/dev/null 2>&1 || true
    fi
fi

if [[ $rc -ne 0 ]]; then
    printf '\n'
    case "$out" in
        *"Repository not found"*|*"404"*)
            die "the repository does not exist, or the token cannot see it — create it empty at github.com/new" ;;
        *"Authentication failed"*|*"could not read Username"*|*"Invalid username"*|*"403"*)
            die "the token was rejected — make a new token with the  repo  scope ticked" ;;
        *"timed out"*|*"timeout"*|*124)
            die "the push timed out — the server cannot reach github.com:22/443 (check the firewall)" ;;
        *"rejected"*|*"non-fast-forward"*)
            die "the remote already has commits — run: cd $TARGET && git pull --rebase origin main && git push -u origin main" ;;
        *)
            die "push failed (exit $rc) — the message above is the reason" ;;
    esac
fi

ok "pushed successfully"
say ""
say "  repo:    $REMOTE"
say "  branch:  main"
say "  local:   $(pwd)"
say ""
say "  install: sudo bash install.sh --token <BOT_TOKEN> --admins <YOUR_ID>"
say "  update later from the bot:  ⚙️ تنظیمات ربات → ⬆️ بروزرسانی ربات"
