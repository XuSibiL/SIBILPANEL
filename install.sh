#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  Panel Service · installer (PHP edition)   updater
#
#  One-line install (works even when piped — the repo is downloaded automatically):
#     bash <(curl -Ls https://raw.githubusercontent.com/XuSibiL/SIBILPANEL/main/install.sh)
#     curl -Ls https://raw.githubusercontent.com/XuSibiL/SIBILPANEL/main/install.sh | sudo bash
#
#  Install with parameters (no questions):
#     bash install.sh --token 123:ABC --admins 111,222 --domain panel.example.com \
#                     --name "نام ربات شما"
#
#  --name / --brand   display name of the bot (default: read from settings)
#  --repo             owner/repo used by the self-update (default: the official repo)
#  --branch           git branch to fetch (default: main)
#  --source           source directory to copy the code from
#  --support          support handle, without @ (optional)
#  --channel          official Telegram channel url (optional)
#
#  Modes:
#     --update       replace the code and restart (database and .env are kept)
#     --uninstall    remove the service (data deleted only on confirmation)
#     --check        only run the health check of the installed bot
#     --no-ssl       no domain / no TLS (internal port only)
#     --dry-run      show every step, install nothing
#     --yes          do not ask for confirmation
# ═══════════════════════════════════════════════════════════════════════════════
set -uo pipefail

# ── هیچ پکیج/سرویسی نباید منتظر زدن Enter بماند ────────────────────────────
# در اوبونتو ۲۲/۲۴ ابزار needrestart بعد از نصب پکیج‌ها سؤال می‌پرسد و نصب
# بی‌صدا معلق می‌شود. این چهار متغیر + تنظیم فایلش، آن را کامل خاموش می‌کند.
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1
export APT_LISTCHANGES_FRONTEND=none
export UCF_FORCE_CONFFOLD=1

# ── خاموش‌کردن کامل needrestart (پرسش «Restart services?» در اوبونتو) ───────
silence_needrestart() {
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1
    if [[ $IS_ROOT -eq 1 && -f /etc/needrestart/needrestart.conf ]]; then
        # هر شکلی از کلید (کامنت‌شده یا فعال) به «خودکار» تغییر می‌کند
        sed -i "s/^#\?\s*\$nrconf{restart} = .*/\$nrconf{restart} = 'a';/g" \
            /etc/needrestart/needrestart.conf 2>/dev/null || true
        sed -i "s/^#\?\s*\$nrconf{kernelhints} = .*/\$nrconf{kernelhints} = 0;/g" \
            /etc/needrestart/needrestart.conf 2>/dev/null || true
        grep -q "\$nrconf{restart}" /etc/needrestart/needrestart.conf 2>/dev/null \
            || printf "\$nrconf{restart} = 'a';\n" >>/etc/needrestart/needrestart.conf 2>/dev/null || true
    fi
    # debconf هم بی‌صدا شود تا هیچ پکیجی سؤال نکند
    if [[ $IS_ROOT -eq 1 ]] && command -v debconf-set-selections >/dev/null 2>&1; then
        printf 'debconf debconf/frontend select Noninteractive\n' \
            | debconf-set-selections 2>/dev/null || true
    fi
    # در همان جلسه هم نادیده گرفته شود (حتی اگر فایلش قابل نوشتن نباشد)
    export NEEDRESTART_SUSPEND=1
}

BOT_USERNAME_IN="${PANEL_BOT_USERNAME:-}"
APP_NAME="${PANEL_BOT_NAME:-Panel Service}"
CHANNEL_DEFAULT="https://t.me/sibilpanel"
VERSION="3.18.0"
# سازگاری با کلیدهای قدیمی
export PANEL_HOME="${PANEL_HOME:-${SIBIL_HOME:-}}"
export PANEL_BOT_TOKEN="${PANEL_BOT_TOKEN:-${SIBIL_BOT_TOKEN:-}}"
export PANEL_ADMINS="${PANEL_ADMINS:-${SIBIL_ADMINS:-}}"
export PANEL_PRIMARY_OWNER_ID="${PANEL_PRIMARY_OWNER_ID:-}"
export PANEL_DB="${PANEL_DB:-${SIBIL_DB:-}}"
export PANEL_WEB_URL="${PANEL_WEB_URL:-${SIBIL_WEB_URL:-}}"
export PANEL_WEB_PORT="${PANEL_WEB_PORT:-${SIBIL_WEB_PORT:-}}"
export PANEL_DOMAIN="${PANEL_DOMAIN:-${SIBIL_DOMAIN:-}}"
export PANEL_PHP_BIN="${PANEL_PHP_BIN:-${SIBIL_PHP_BIN:-}}"

HOME_WAS_SET="${PANEL_HOME:+1}"
if [[ -n "${PANEL_HOME:-}" ]]; then
    :
elif [[ ${EUID:-$(id -u)} -eq 0 ]]; then
    PANEL_HOME="/opt/sibil"
elif [[ -n "${PREFIX:-}" && -d "${PREFIX:-}" ]]; then
    PANEL_HOME="$PREFIX/var/sibil"      # Termux
else
    PANEL_HOME="$HOME/sibil"
fi
export PANEL_HOME
SERVICE="sibil"
WEB_SERVICE="sibil-web"
CLI_PATH="/usr/local/bin/sibil"
PHP_BIN="php"
WEB_PORT_DEFAULT="8080"

DOMAIN_IN=""; TOKEN_IN=""; ADMINS_IN=""; PRIMARY_OWNER_IN="${PANEL_PRIMARY_OWNER_ID:-}"; BRAND_IN=""; UPDATE_URL_IN=""; PHP_IN=""
REPO_IN=""; BRANCH_IN="main"; SOURCE_IN=""; SUPPORT_IN=""; CHANNEL_IN=""
MODE="install"; NO_SSL=0; DRY=0; YES=0
SELF="${BASH_SOURCE[0]:-$0}"
SELF_DIR="$(cd "$(dirname "$SELF")" 2>/dev/null && pwd || echo /tmp)"
IS_ROOT=0; [[ ${EUID:-$(id -u)} -eq 0 ]] && IS_ROOT=1
HAS_SYSTEMD=0; command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]] && HAS_SYSTEMD=1

ORIG_ARGS=("$@")     # برای اجرای دوبارهٔ همین نصب‌کننده بعد از بوت‌استرپ
[[ ${#ORIG_ARGS[@]} -eq 1 && -z "${ORIG_ARGS[0]:-}" ]] && ORIG_ARGS=()

# آرگومان‌هایی که نسخهٔ داخل مخزن می‌شناسد؛ بقیه حذف می‌شوند تا نسخهٔ قدیمی‌تر
# روی مخزن باعث خطای «Unknown parameter» نشود
compatible_args() {
    local target="$1"; shift
    local -a out=() args=("$@")
    local i=0 arg
    while [[ $i -lt ${#args[@]} ]]; do
        arg="${args[$i]}"
        [[ -z "$arg" ]] && { i=$((i + 1)); continue; }
        if [[ "$arg" == --* ]]; then
            if ! grep -q -- "        $arg" "$target" 2>/dev/null \
                && ! grep -q -- "|$arg)" "$target" 2>/dev/null \
                && ! grep -q -- "$arg)" "$target" 2>/dev/null; then
                printf '  %s!%s the installer in the repository does not know %s — dropped\n' \
                    "$YEL" "$R" "$arg" >&2
                if [[ -n "${args[$((i + 1))]:-}" && "${args[$((i + 1))]}" != --* ]]; then
                    i=$((i + 2))
                else
                    i=$((i + 1))
                fi
                continue
            fi
        fi
        out+=("$arg")
        i=$((i + 1))
    done
    [[ ${#out[@]} -eq 0 ]] && return 0
    printf '%s\0' "${out[@]}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN_IN="${2:-}"; shift 2 ;;
        --token) TOKEN_IN="${2:-}"; shift 2 ;;
        --admins) ADMINS_IN="${2:-}"; shift 2 ;;
        --brand|--name) BRAND_IN="${2:-}"; shift 2 ;;
        --php) PHP_IN="${2:-}"; shift 2 ;;
        --update-url) UPDATE_URL_IN="${2:-}"; shift 2 ;;
        --repo) REPO_IN="${2:-}"; shift 2 ;;
        --branch) BRANCH_IN="${2:-main}"; shift 2 ;;
        --source) SOURCE_IN="${2:-}"; shift 2 ;;
        --support) SUPPORT_IN="${2:-}"; shift 2 ;;
        --channel) CHANNEL_IN="${2:-}"; shift 2 ;;
        --update) MODE="update"; shift ;;
        --uninstall) MODE="uninstall"; shift ;;
        --check) MODE="check"; shift ;;
        --no-ssl) NO_SSL=1; shift ;;
        --dry-run) DRY=1; shift ;;
        --yes|-y) YES=1; shift ;;
        --help|-h) sed -n '2,20p' "$0"; exit 0 ;;
        "") shift ;;                       # آرگومان خالی (نتیجهٔ پایپ) نادیده گرفته می‌شود
        *) echo "Unknown parameter: $1"; echo "  run: bash install.sh --help"; exit 1 ;;
    esac
done
[[ -n "$BRAND_IN" ]] && APP_NAME="$BRAND_IN"

# ── output helpers (English only) ────────────────────────────────────────────
if [[ -t 1 && "${TERM:-dumb}" != "dumb" ]]; then
    B=$'\033[1m'; DIM=$'\033[2m'; R=$'\033[0m'; RED=$'\033[31m'
    GRN=$'\033[32m'; YEL=$'\033[33m'; MAG=$'\033[35m'; CYN=$'\033[36m'
else
    B=""; DIM=""; R=""; RED=""; GRN=""; YEL=""; MAG=""; CYN=""
fi
say()  { printf '%s\n' "$*"; }
step() { printf '\n%s▸ %s%s\n' "$CYN$B" "$*" "$R"; }
ok()   { printf '  %s✓%s %s\n' "$GRN" "$R" "$*"; }
warn() { printf '  %s!%s %s\n' "$YEL" "$R" "$*"; }
die()  { printf '  %s✗%s %s\n' "$RED" "$R" "$*"; exit 1; }
run()  { if [[ $DRY -eq 1 ]]; then printf '  %s[dry]%s %s\n' "$DIM" "$R" "$*"; else eval "$@"; fi; }

# stop the bot processes of this installation — never the shell that runs the installer
stop_bot() {
    [[ -f "$PANEL_HOME/sibil.pid" ]] && {
        kill "$(cat "$PANEL_HOME/sibil.pid")" 2>/dev/null || true
        rm -f "$PANEL_HOME/sibil.pid"
    }
    local pid
    for pid in $(pgrep -f "$PANEL_HOME/sibil\.php" 2>/dev/null || true); do
        [[ "$pid" == "$$" || "$pid" == "${PPID:-0}" ]] && continue
        kill "$pid" 2>/dev/null || true
    done
    return 0
}

banner() {
    local line="  $APP_NAME  ·  version $VERSION  ·  installer  "
    local width=${#line}
    local bar
    bar="$(printf '─%.0s' $(seq 1 "$width"))"
    printf '%s%s%s\n' "$MAG$B" "$bar" "$R"
    printf '%s%s%s\n' "$MAG$B" "$line" "$R"
    printf '%s%s%s\n\n' "$MAG$B" "$bar" "$R"
}

# ── payload (single-file bundle) ────────────────────────────────────────────
# built from two halves so this line never matches the real marker
PAYLOAD_BEGIN="# @@PAY""LOAD_BEGIN@@"
PAYLOAD_END="# @@PAY""LOAD_END@@"
EXTRACT_DIR="$(mktemp -d)"

# ── بوت‌استرپ: نصب با یک خط از گیت‌هاب، حتی وقتی فایل پایپ می‌شود ─────────────
#   bash <(curl -Ls .../install.sh)  یا  curl -Ls .../install.sh | sudo bash
#   در این حالت نه پی‌لود هست و نه سورس کنار اسکریپت؛ خودش مخزن را می‌آورد.
bootstrap_sources() {
    [[ -n "${PANEL_BOOTSTRAPPED:-}" ]] && return 1
    local slug="${REPO_IN:-XuSibiL/SIBILPANEL}"
    local branch="${BRANCH_IN:-main}"
    local dest="${SOURCE_IN:-}"
    if [[ -z "$dest" ]]; then
        if [[ $IS_ROOT -eq 1 ]]; then dest="/root/${slug##*/}"; else dest="$HOME/${slug##*/}"; fi
    fi

    local dl=""
    if command -v curl >/dev/null 2>&1; then dl="curl"
    elif command -v wget >/dev/null 2>&1; then dl="wget"
    else die "neither curl nor wget is available — install one of them first"; fi

    step "Fetching the sources from github.com/$slug ($branch)"
    local tmp; tmp="$(mktemp -d)"
    local tgz="$tmp/code.tar.gz"
    local ok_dl=0 url
    local urls=()
    # آینه یا فایل محلی (برای شبکه‌های محدود): PANEL_BOOTSTRAP_URL=/path/or/url.tar.gz
    [[ -n "${PANEL_BOOTSTRAP_URL:-}" ]] && urls+=("$PANEL_BOOTSTRAP_URL")
    urls+=(
        "https://codeload.github.com/$slug/tar.gz/refs/heads/$branch"
        "https://github.com/$slug/archive/refs/heads/$branch.tar.gz"
    )
    for url in "${urls[@]}"; do
        say "  ${DIM}GET $url${R}"
        if [[ "$dl" == curl ]]; then
            curl -fsSL --connect-timeout 12 --max-time 180 -o "$tgz" "$url" && ok_dl=1 && break
        else
            wget -q -T 15 -O "$tgz" "$url" && ok_dl=1 && break
        fi
    done
    if [[ $ok_dl -eq 0 ]]; then
        # آخرین راه: بستهٔ تک‌فایلی از همان مخزن
        local bundle="https://raw.githubusercontent.com/$slug/$branch/dist/sibil-installer.sh"
        say "  ${DIM}GET $bundle${R}"
        if [[ "$dl" == curl ]]; then
            curl -fsSL --connect-timeout 12 --max-time 180 -o "$tmp/bundle.sh" "$bundle" && ok_dl=2
        else
            wget -q -T 15 -O "$tmp/bundle.sh" "$bundle" && ok_dl=2
        fi
        [[ $ok_dl -eq 2 ]] || die "could not download the sources from github.com/$slug — check the network or pass --repo owner/repo"
        chmod +x "$tmp/bundle.sh"
        ok "single-file bundle downloaded"
        mapfile -d '' -t _args < <(compatible_args "$tmp/bundle.sh" "${ORIG_ARGS[@]}")
        exec env PANEL_BOOTSTRAPPED=1 PANEL_SKIP_PREREQS=1 bash "$tmp/bundle.sh" ${_args[@]+"${_args[@]}"}
    fi

    mkdir -p "$tmp/x"
    tar -xzf "$tgz" -C "$tmp/x" 2>/dev/null \
        || die "the downloaded archive could not be extracted (corrupt download)"
    local root; root="$(find "$tmp/x" -maxdepth 2 -name 'sibil.php' -print -quit 2>/dev/null | xargs -r dirname)"
    [[ -n "$root" && -f "$root/install.sh" ]] \
        || die "the archive does not look like this project (install.sh / sibil.php missing)"

    # کپی روی مقصد، بدون دست‌زدن به .env و دیتابیس و لاگ و بکاپ
    mkdir -p "$dest"
    local item
    for item in "$root"/* "$root"/.env.example "$root"/.gitignore "$root"/.github; do
        [[ -e "$item" ]] || continue
        case "$(basename "$item")" in
            .env|logs|backups|uploads|release) continue ;;
        esac
        cp -a "$item" "$dest/" 2>/dev/null || true
    done
    chmod 755 "$dest/install.sh" 2>/dev/null || true
    chmod 755 "$dest"/bin/* 2>/dev/null || true
    rm -rf "$tmp"
    local repo_ver; repo_ver="$(tr -d ' \r\n' <"$dest/VERSION" 2>/dev/null || echo '?')"
    ok "sources ready: $dest (v$repo_ver)"
    if [[ "$repo_ver" != "$VERSION" ]]; then
        warn "the repository is on v$repo_ver while this installer is v$VERSION"
        say "  ${DIM}push the package to the repo so both match: bash deploy.sh ${slug}${R}"
    fi
    say "  ${DIM}continuing with the installer from that directory${R}"
    say ""
    mapfile -d '' -t _args < <(compatible_args "$dest/install.sh" ${ORIG_ARGS[@]+"${ORIG_ARGS[@]}"})
    exec env PANEL_BOOTSTRAPPED=1 PANEL_SKIP_PREREQS=1 bash "$dest/install.sh" ${_args[@]+"${_args[@]}"}
}

extract_payload() {
    grep -q "$PAYLOAD_BEGIN" "$SELF" 2>/dev/null || return 1
    local inside=0 count=0 name blob line
    while IFS= read -r line; do
        [[ "$line" == "$PAYLOAD_BEGIN" ]] && { inside=1; continue; }
        [[ "$line" == "$PAYLOAD_END" ]] && break
        [[ $inside -eq 0 ]] && continue
        [[ "$line" == \#PAYLOAD\|* ]] || continue
        line="${line#\#PAYLOAD|}"
        name="${line%%|*}"
        blob="${line#*|}"
        [[ -z "$name" ]] && continue
        printf '%s' "$blob" | base64 -d >"$EXTRACT_DIR/$name" 2>/dev/null || return 1
        case "$name" in sibil|*.sh) chmod 755 "$EXTRACT_DIR/$name" ;; esac
        count=$((count + 1))
    done <"$SELF"
    [[ $count -gt 0 ]] || return 1
    PAYLOAD_COUNT=$count
    return 0
}

resolve_sources() {
    if extract_payload; then
        SRC_DIR="$EXTRACT_DIR"
        ok "payload extracted from the bundle ($PAYLOAD_COUNT files)"
        return 0
    fi
    if [[ -f "$SELF_DIR/sibil.php" ]]; then
        SRC_DIR="$SELF_DIR"
        ok "files read from the installer directory: $SELF_DIR"
        return 0
    fi
    warn "no local sources found (piped install) — downloading the repository"
    bootstrap_sources || die "bootstrap failed"
    # اگر بوت‌استرپ اجرا نشود، اجرا هم ادامه ندارد
    die "the sources could not be prepared"
}

# php که استفاده می‌کنیم: --php دستی، بعد .env قبلی، بعد یک php سالم از سیستم
pick_php() {
    if [[ -n "$PHP_IN" ]]; then
        [[ -x "$PHP_IN" ]] || die "--php $PHP_IN does not exist or is not executable"
        PHP_PATH="$PHP_IN"
        return 0
    fi
    if [[ -f "$PANEL_HOME/.env" ]]; then
        local remembered
        remembered="$(grep -E '^PANEL_PHP_BIN=' "$PANEL_HOME/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"')"
        if [[ -n "$remembered" && -x "$remembered" ]] && php_bin_has_sqlite "$remembered"; then
            PHP_PATH="$remembered"
            return 0
        fi
    fi
    PHP_PATH="$(command -v php 2>/dev/null || true)"
}

# بررسی درست: خودِ php می‌گوید افزونه لود شده یا نه (خروجی -m قابل‌اعتماد نیست)
php_bin_has_sqlite() {
    local bin="$1"
    [[ -n "$bin" && -x "$bin" ]] || return 1
    "$bin" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1
}

php_has_sqlite() {
    php_bin_has_sqlite "${PHP_PATH:-}"
}

php_version_short() {
    "$PHP_PATH" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null
}

# همهٔ php های نصب‌شده را نگاه کن و اولین سالم را بردار
find_working_php() {
    local candidate
    for candidate in "$(command -v php 2>/dev/null)" /usr/bin/php /usr/bin/php8.4 \
                     /usr/bin/php8.3 /usr/bin/php8.2 /usr/bin/php8.1 /usr/local/bin/php; do
        [[ -n "$candidate" && -x "$candidate" ]] || continue
        if php_bin_has_sqlite "$candidate"; then
            PHP_PATH="$candidate"
            return 0
        fi
    done
    return 1
}

# فایل ini ای که نسخهٔ قبلی نصب‌کننده ساخته ممکن است افزونه را دوباره لود کند
# (هشدار: Module "pdo_sqlite" is already loaded) → اگر سیستم خودش دارد، پاکش کن
clean_duplicate_ini() {
    # 3.1.1 در بعضی سرورها یک ini تکراری نوشت که هشدار
    # «Module "pdo_sqlite" is already loaded» می‌دهد. اگر خودِ پکیج
    # قبلاً این افزونه را فعال کرده، فایل ما اضافی است.
    local ours
    while IFS= read -r ours; do
        [[ -z "$ours" ]] && continue
        local base ver
        base="$(basename "$(dirname "$(dirname "$ours")")")"
        ver="${base#php}"
        if compgen -G "/etc/php/${ver}/mods-available/pdo_sqlite.ini" >/dev/null 2>&1 \
           || [[ -f "/etc/php/${ver}/mods-available/pdo_sqlite.ini" ]]; then
            rm -f "$ours" && warn "removed a duplicate sqlite ini: $ours"
        fi
    done < <(find /etc/php -name '99-sibil-pdo-sqlite.ini' 2>/dev/null)
    return 0
}

# نصب درایور sqlite؛ تلاش‌های پشت‌سرهم تا یکی جواب بدهد
install_sqlite_driver() {
    # اگر همین حالا یک php سالم روی سیستم هست، بی‌دردسر همان را بردار
    if find_working_php; then
        ok "found a working php: $PHP_PATH"
        return 0
    fi

    local ver pkgs=() apt_out
    ver="$(php_version_short)"
    pkgs=(php-sqlite3)
    [[ -n "$ver" ]] && pkgs+=("php${ver}-sqlite3" "php${ver}-cli" "php${ver}-common")
    say "  installing: ${pkgs[*]}"
    local apt_opts=(-o DPkg::Lock::Timeout=180)
    apt_out="$( { apt-get "${apt_opts[@]}" update -qq \
        && DEBIAN_FRONTEND=noninteractive apt-get "${apt_opts[@]}" install -y -qq "${pkgs[@]}"; } 2>&1 )" \
        || warn "apt reported an error (continuing): $(printf '%s' "$apt_out" | tail -3 | tr '\n' ' ')"
    php_has_sqlite && return 0

    # یک php دیگر روی سیستم شاید سالم باشد
    if find_working_php; then
        ok "found a working php: $PHP_PATH"
        return 0
    fi

    # ماژول نصب است ولی خاموش → فعالش کن (بدون ساخت ini تکراری)
    local so ini already
    for so in /usr/lib/php/*/pdo_sqlite.so; do
        [[ -f "$so" ]] || continue
        already="$(grep -rl 'pdo_sqlite' /etc/php/*/mods-available/ 2>/dev/null | head -1)"
        if [[ -z "$already" ]]; then
            say "  found $so — enabling the extension"
            for ini in /etc/php/*/cli/conf.d; do
                [[ -d "$ini" && -w "$ini" ]] || continue
                printf 'extension=pdo_sqlite.so\n' >"$ini/99-sibil-pdo-sqlite.ini" 2>/dev/null || true
            done
            php_has_sqlite && return 0
        fi
        break
    done

    if command -v phpenmod >/dev/null 2>&1; then
        phpenmod -v "${ver:-8.1}" pdo_sqlite sqlite3 2>/dev/null || true
        php_has_sqlite && return 0
    fi

    # آخرین راه: یک PHP کامل و سالم از PPA
    if command -v apt-get >/dev/null 2>&1; then
        say "  installing a clean php (ondrej PPA) — this may take a minute"
        silence_needrestart
        apt-get -o DPkg::Lock::Timeout=180 install -y -qq software-properties-common </dev/null >/dev/null 2>&1 || true
        if command -v add-apt-repository >/dev/null 2>&1; then
            add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
            apt-get -o DPkg::Lock::Timeout=180 update -qq >/dev/null 2>&1 || true
        fi
        local candidate bin
        for candidate in php8.4 php8.3 php8.2 php8.1; do
            DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=180 install -y -qq \
                "$candidate-cli" "$candidate-sqlite3" >/dev/null 2>&1 || continue
            bin="/usr/bin/$candidate"
            if php_bin_has_sqlite "$bin"; then
                PHP_PATH="$bin"
                ok "using a working php: $PHP_PATH"
                return 0
            fi
        done
    fi
    return 1
}

# ترمیم عمیق php/درایور sqlite (بدون چاپ عنوان؛ از install_packages صدا زده می‌شود)
ensure_runtime() {
    pick_php
    # اگر php فعلی درایور sqlite ندارد، اول دنبال یک php سالم روی سیستم بگرد
    php_has_sqlite || find_working_php || true
    clean_duplicate_ini || true

    local need=()
    silence_needrestart
    [[ -z "$PHP_PATH" ]] && need+=(php-cli)
    command -v curl >/dev/null 2>&1 || need+=(curl)
    command -v sqlite3 >/dev/null 2>&1 || need+=(sqlite3)

    if [[ ${#need[@]} -gt 0 ]]; then
        mapfile -t need < <(printf '%s\n' "${need[@]}" | sort -u)
        say "  installing: ${need[*]}"
        if command -v apt-get >/dev/null 2>&1; then
            # DPkg::Lock::Timeout: صبر می‌کنیم unattended-upgrades قفل را آزاد کند
            run "apt-get -o DPkg::Lock::Timeout=180 update -qq </dev/null"
            run "DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get -o DPkg::Lock::Timeout=180 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y -qq ${need[*]} </dev/null >/dev/null 2>&1 || true"
        elif command -v dnf >/dev/null 2>&1; then
            run "dnf install -y -q php-cli php-pdo php-json curl sqlite"
        elif command -v apk >/dev/null 2>&1; then
            run "apk add --no-cache php83 php83-pdo_sqlite php83-sqlite3 curl sqlite"
        else
            warn "unknown package manager — install php-cli, php-sqlite3 and curl yourself"
        fi
    fi

    pick_php
    [[ -n "$PHP_PATH" ]] || die "php is missing — install php-cli + php-sqlite3 and run again"
    ok "php: $("$PHP_PATH" -v 2>/dev/null | head -1) [$PHP_PATH]"

    if ! php_has_sqlite; then
        warn "pdo_sqlite is missing — trying to install the driver"
        install_sqlite_driver || true
    fi

    if ! php_has_sqlite; then
        local ver; ver="$(php_version_short)"
        printf '\n  %s✗ %s is PHP %s but the SQLite driver (pdo_sqlite) is not loaded.%s\n' \
            "$RED" "$PHP_PATH" "${ver:-?}" "$R"
        say   "     Most likely this php was not installed by apt (custom build)."
        say   "     Fix it with these two lines, then run the installer again:"
        say   "       sudo apt-get update && sudo apt-get install -y php${ver:+$ver-}sqlite3 php-sqlite3"
        say   "       sudo bash /tmp/u.sh --update --php /usr/bin/php${ver:+$ver}"
        say   "     If the bot must keep this exact php, install its sqlite module yourself."
        die "cannot continue without pdo_sqlite"
    fi
    ok "pdo_sqlite available [$PHP_PATH]"
    "$PHP_PATH" -m 2>/dev/null | grep -qi '^mbstring$' && ok "mbstring available" \
        || warn "mbstring missing — the bot uses a built-in UTF-8 fallback"
    if "$PHP_PATH" -m 2>/dev/null | grep -qi '^curl$' || command -v curl >/dev/null 2>&1; then
        ok "http transport ready"
    else
        die "curl is required for Telegram and the panel API"
    fi
}

# آیا یک افزونهٔ php واقعاً لود شده است
php_has_module() {
    local bin="${1:-$PHP_PATH}" mod="$2"
    [[ -n "$bin" && -x "$bin" ]] || return 1
    "$bin" -r "exit(extension_loaded('$mod') ? 0 : 1);" >/dev/null 2>&1
}

# ── مرحلهٔ ۱: نصب قطعی همهٔ پیش‌نیازها، قبل از هر کار دیگر ───────────────────
install_packages() {
    step "Prerequisites"
    if [[ "${PANEL_SKIP_PREREQS:-0}" == "1" ]]; then
        pick_php
        ok "already verified in the bootstrapper — skipping"
        return 0
    fi
    local -a pkgs=(curl tar sqlite3 php-cli php-sqlite3 php-mbstring php-curl php-xml)
    silence_needrestart

    if command -v apt-get >/dev/null 2>&1; then
        run "apt-get -o DPkg::Lock::Timeout=180 update -qq </dev/null"
        # بسته‌های نسخه‌دار همان php فعلی هم امتحان می‌شوند (Ubuntu/Debian)
        local ver pkgs_ver=()
        ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
        if [[ -n "$ver" ]]; then
            pkgs_ver=("php${ver}-sqlite3" "php${ver}-mbstring" "php${ver}-curl"
                      "php${ver}-xml" "php${ver}-cli")
        fi
        say "  installing: ${pkgs[*]}"
        run "DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 apt-get -o DPkg::Lock::Timeout=180 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y -qq ${pkgs[*]} </dev/null >/dev/null 2>&1 || true"
        if [[ ${#pkgs_ver[@]} -gt 0 ]]; then
            run "DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 apt-get -o DPkg::Lock::Timeout=180 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y -qq ${pkgs_ver[*]} </dev/null >/dev/null 2>&1 || true"
        fi
    elif command -v dnf >/dev/null 2>&1; then
        run "dnf install -y -q php-cli php-pdo php-mbstring php-curl php-xml curl tar sqlite"
    elif command -v apk >/dev/null 2>&1; then
        run "apk add --no-cache php83 php83-pdo_sqlite php83-sqlite3 php83-mbstring php83-curl php83-xml curl tar sqlite"
    else
        warn "unknown package manager — install curl, tar, sqlite3, php-cli, php-sqlite3 yourself"
    fi

    # ماژول‌های حیاتی را صریحاً روشن کن (Debian/Ubuntu)
    if command -v phpenmod >/dev/null 2>&1; then
        run "phpenmod -v ALL sqlite3 pdo_sqlite mbstring curl xml json 2>/dev/null || true"
    fi

    ensure_runtime

    # ── تأیید نهایی: تا همه‌چیز سالم نباشد، نصب جلو نمی‌رود ────────────────
    local missing=() mod
    for mod in pdo_sqlite mbstring curl xml json; do
        php_has_module "$PHP_PATH" "$mod" || missing+=("$mod")
    done
    command -v curl >/dev/null 2>&1 || missing+=("curl-binary")
    command -v tar >/dev/null 2>&1 || missing+=("tar")

    if [[ ${#missing[@]} -gt 0 ]]; then
        printf '\n  %s✗ missing:%s %s\n' "$RED" "$R" "${missing[*]}"
        say   "     fix it with:"
        say   "       apt-get update && apt-get install -y php-cli php-sqlite3 php-mbstring php-curl php-xml sqlite3 curl tar"
        say   "       phpenmod -v ALL sqlite3 pdo_sqlite mbstring curl xml json"
        die "prerequisites are incomplete"
    fi

    ok "php $("$PHP_PATH" -r 'echo PHP_VERSION;' 2>/dev/null) [$PHP_PATH]"
    ok "modules: pdo_sqlite · mbstring · curl · xml · json"
    if command -v sqlite3 >/dev/null 2>&1; then
        ok "binaries: curl · tar · sqlite3"
    else
        ok "binaries: curl · tar  ${DIM}(the sqlite3 shell is optional — the panel uses pdo_sqlite)${R}"
        [[ $IS_ROOT -eq 1 ]] && say "  ${DIM}optional: apt-get install -y sqlite3${R}"
    fi
    printf '  %s✓ All prerequisites installed and verified%s\n' "$GRN$B" "$R"
}

# true only when a human can really answer (a /dev/tty node alone is not enough)
can_prompt() {
    [[ $YES -eq 0 ]] || return 1
    [[ -t 0 ]] && return 0
    { : </dev/tty; } 2>/dev/null
}

ask() {
    local prompt="$1" default="${2:-}" answer=""
    if ! can_prompt; then printf '%s' "$default"; return 0; fi
    read -r -p "  $prompt${default:+ [$default]}: " answer </dev/tty || answer=""
    printf '%s' "${answer:-$default}"
}

validate_token() {
    local token="$1"
    [[ "$token" =~ ^[0-9]{6,12}:[A-Za-z0-9_-]{25,}$ ]] || return 1
    local api
    api="$(curl -fsS --max-time 12 "https://api.telegram.org/bot$token/getMe" 2>/dev/null)"
    printf '%s' "$api" | grep -q '"ok":true' || return 1
    printf '%s' "$api" | sed -n 's/.*"username":"\([^"]*\)".*/\1/p'
}

# نخستین شناسهٔ عددی معتبر از فهرست مدیران؛ برای قفل مالک اصلی.
first_admin_id() {
    local raw="$1" item
    raw="$(printf '%s' "$raw" | tr ',\t\r\n' '    ')"
    for item in $raw; do
        if [[ "$item" =~ ^[0-9]+$ ]] && [[ "$item" != 0 ]]; then
            printf '%s' "$item"
            return 0
        fi
    done
    return 1
}

# برای نصب‌های قدیمی، فقط یک‌بار کلید صریح مالک را بدون تغییر مدیران اضافه می‌کنیم.
ensure_primary_owner_env() {
    [[ $DRY -eq 1 || ! -f "$PANEL_HOME/.env" ]] && return 0
    if grep -q '^PANEL_PRIMARY_OWNER_ID=' "$PANEL_HOME/.env"; then
        return 0
    fi
    local existing raw primary
    existing="$(grep -E '^PANEL_ADMINS=' "$PANEL_HOME/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\"')"
    raw="${ADMINS_IN:-$existing}"
    primary="$(first_admin_id "$raw" 2>/dev/null || true)"
    if [[ -n "$primary" ]]; then
        printf 'PANEL_PRIMARY_OWNER_ID="%s"\n' "$primary" >>"$PANEL_HOME/.env"
        ok "primary owner anchor added (numeric ID only)"
    else
        warn "no numeric admin ID found; primary-owner anchor will be set after an admin ID is configured"
    fi
}

prompt_all() {
    step "Configuration"
    if [[ -z "$TOKEN_IN" ]]; then
        if can_prompt; then
            say "  Create a bot with @BotFather and paste its token."
            TOKEN_IN="$(ask 'bot token' '')"
        fi
    fi
    [[ -n "$TOKEN_IN" ]] || die "a bot token is required (--token 123:ABC)"
    local uname
    if uname="$(validate_token "$TOKEN_IN")"; then
        ok "telegram accepted the token (@$uname)"
        BOT_USERNAME_IN="$uname"
    else
        warn "could not verify the token (network?) — it will be checked again on start"
    fi
    # روی نصب قبلی، یوزرنیمِ ذخیره‌شده حفظ می‌شود (هیچ‌وقت پاک نمی‌شود)
    if [[ -z "$BOT_USERNAME_IN" ]]; then
        BOT_USERNAME_IN="$(grep -h '^PANEL_BOT_USERNAME' "$PANEL_HOME/.env" 2>/dev/null \
            | cut -d= -f2- | tr -d '"' | sed 's/^@//')"
    fi
    [[ -n "$BOT_USERNAME_IN" ]] && ok "bot username: @$BOT_USERNAME_IN (used for referral links)"
    if [[ -z "$ADMINS_IN" ]] && can_prompt; then
        ADMINS_IN="$(ask 'admin numeric IDs (comma separated)')"
    fi
    [[ -n "$ADMINS_IN" ]] || warn "no admin id set — the management console stays locked"
    if [[ ! "$PRIMARY_OWNER_IN" =~ ^[0-9]+$ ]] || [[ "$PRIMARY_OWNER_IN" == 0 ]]; then
        PRIMARY_OWNER_IN="$(first_admin_id "$ADMINS_IN" 2>/dev/null || true)"
    fi
    if [[ $NO_SSL -eq 0 && -z "$DOMAIN_IN" ]] && can_prompt; then
        DOMAIN_IN="$(ask 'domain for the bot (leave empty to skip SSL)')"
    fi
    if [[ -z "$SUPPORT_IN" && -z "$(grep -h '^PANEL_SUPPORT_USERNAME' "$PANEL_HOME/.env" 2>/dev/null)" ]] && can_prompt; then
        say "  ${DIM}optional: the support handle users will chat with (without @)${R}"
        SUPPORT_IN="$(ask 'support username (optional)' '')"
    fi
    [[ -n "$SUPPORT_IN" ]] && SUPPORT_IN="${SUPPORT_IN#@}"
    if [[ -z "$CHANNEL_IN" && -z "$(grep -h '^PANEL_CHANNEL_URL' "$PANEL_HOME/.env" 2>/dev/null)" ]] && can_prompt; then
        say "  ${DIM}optional: your official news channel, e.g. https://t.me/mychannel${R}"
        CHANNEL_IN="$(ask 'official channel (optional)' '')"
    fi
    [[ -n "$SUPPORT_IN" ]] || SUPPORT_IN="$(grep -h '^PANEL_SUPPORT_USERNAME' "$PANEL_HOME/.env" 2>/dev/null | cut -d= -f2- | tr -d '"')"
    [[ -n "$SUPPORT_IN" ]] && ok "support handle: @$SUPPORT_IN"
    [[ -n "$CHANNEL_IN" ]] || CHANNEL_IN="$(grep -h '^PANEL_CHANNEL_URL' "$PANEL_HOME/.env" 2>/dev/null | cut -d= -f2- | tr -d '"')"
    [[ -n "$CHANNEL_IN" ]] || CHANNEL_IN="$CHANNEL_DEFAULT"
    ok "official channel: $CHANNEL_IN"
    [[ -n "$BRAND_IN" ]] && APP_NAME="$BRAND_IN"
}

write_env() {
    step "Writing configuration"
    run "mkdir -p '$PANEL_HOME/backups'"
    if [[ -f "$PANEL_HOME/.env" ]]; then
        ok "existing .env kept"
        if [[ -n "$TOKEN_IN" ]]; then
            if grep -q '^PANEL_BOT_TOKEN=' "$PANEL_HOME/.env"; then
                run "sed -i 's|^PANEL_BOT_TOKEN=.*|PANEL_BOT_TOKEN=\"$TOKEN_IN\"|' '$PANEL_HOME/.env'"
            else
                run "printf 'PANEL_BOT_TOKEN=\"%s\"\n' '$TOKEN_IN' >> '$PANEL_HOME/.env'"
            fi
            ok "bot token updated"
        fi
        if [[ -n "$ADMINS_IN" ]]; then
            if grep -q '^PANEL_ADMINS=' "$PANEL_HOME/.env"; then
                run "sed -i 's|^PANEL_ADMINS=.*|PANEL_ADMINS=\"$ADMINS_IN\"|' '$PANEL_HOME/.env'"
            else
                run "printf 'PANEL_ADMINS=\"%s\"\n' '$ADMINS_IN' >> '$PANEL_HOME/.env'"
            fi
            ok "admins updated"
        fi
        if [[ $DRY -eq 0 ]]; then
            grep -q '^PANEL_DOMAIN=' "$PANEL_HOME/.env" \
                && run "sed -i 's|^PANEL_DOMAIN=.*|PANEL_DOMAIN=\"$DOMAIN_IN\"|' '$PANEL_HOME/.env'" \
                || run "printf 'PANEL_DOMAIN=\"%s\"\n' '$DOMAIN_IN' >> '$PANEL_HOME/.env'"
        fi
        # یوزرنیم ربات برای لینک دعوت: اگر نبود اضافه می‌شود، اگر بود دست نمی‌خورد
        if [[ -n "$BOT_USERNAME_IN" ]] && ! grep -q '^PANEL_BOT_USERNAME=' "$PANEL_HOME/.env"; then
            run "printf 'PANEL_BOT_USERNAME=\"%s\"\\n' '$BOT_USERNAME_IN' >> '$PANEL_HOME/.env'"
            ok "bot username added to .env (referral links)"
        fi
        for pair in "PANEL_SUPPORT_USERNAME:$SUPPORT_IN" "PANEL_CHANNEL_URL:$CHANNEL_IN"; do
            local key="${pair%%:*}" val="${pair#*:}"
            [[ -z "$val" ]] && continue
            if grep -q "^$key=" "$PANEL_HOME/.env"; then
                run "sed -i 's|^$key=.*|$key=\"$val\"|' '$PANEL_HOME/.env'"
            else
                run "printf '$key=\"%s\"\\n' '$val' >> '$PANEL_HOME/.env'"
            fi
        done
        ensure_primary_owner_env
        chmod 600 "$PANEL_HOME/.env" 2>/dev/null
        return 0
    fi
    if [[ $DRY -eq 0 ]]; then
        cat >"$PANEL_HOME/.env" <<EOF
# Panel Service - configuration (this is the only file you edit)
PANEL_BOT_TOKEN="$TOKEN_IN"
PANEL_ADMINS="$ADMINS_IN"
# مالک اصلی قفل‌شده؛ از نخستین شناسهٔ PANEL_ADMINS در نصب اول گرفته می‌شود.
PANEL_PRIMARY_OWNER_ID="$PRIMARY_OWNER_IN"
PANEL_DOMAIN="$DOMAIN_IN"
PANEL_HOME="$PANEL_HOME"
PANEL_DB="$PANEL_HOME/sibil.sqlite3"
PANEL_WEB_PORT="$WEB_PORT_DEFAULT"
PANEL_WEB_URL="${DOMAIN_IN:+https://$DOMAIN_IN}"
PANEL_PHP_BIN="${PHP_PATH:-php}"
PANEL_BOT_NAME="$BRAND_IN"
PANEL_BOT_USERNAME="$BOT_USERNAME_IN"
PANEL_SUPPORT_USERNAME="$SUPPORT_IN"
PANEL_CHANNEL_URL="$CHANNEL_IN"
PANEL_UPDATE_REPO="${REPO_IN:-XuSibiL/SIBILPANEL}"
PANEL_UPDATE_BRANCH="${BRANCH_IN:-main}"
PANEL_UPDATE_URL="$UPDATE_URL_IN"
# PANEL_DRY_RUN=1
EOF
        if [[ ! -s "$PANEL_HOME/.env" ]]; then
            die "could not write $PANEL_HOME/.env"
        fi
        chmod 600 "$PANEL_HOME/.env" 2>/dev/null
        ok "configuration written: $PANEL_HOME/.env (mode 600)"
    else
        say "  [dry] would write .env"
    fi
}

copy_payload() {
    step "Installing application files"
    run "install -d -m 755 '$PANEL_HOME'"
    [[ $DRY -eq 0 ]] && [[ -f "$PANEL_HOME/sibil.php" ]] \
        && cp -f "$PANEL_HOME/sibil.php" "$PANEL_HOME/sibil.php.bak-$(date +%Y%m%d%H%M%S)" 2>/dev/null
    run "install -m 644 '$SRC_DIR/sibil.php' '$PANEL_HOME/sibil.php'"
    # اسکریپت مهاجرت جداگانه‌ی دیتابیس (اجرای دستی: php migration.php)
    [[ -f "$SRC_DIR/migration.php" ]] && run "install -m 644 '$SRC_DIR/migration.php' '$PANEL_HOME/migration.php'"
    write_htaccess
    # drop leftovers of the former Python edition (data is untouched)
    if [[ $DRY -eq 0 && -f "$PANEL_HOME/sibil.py" ]]; then
        mv -f "$PANEL_HOME/sibil.py" "$PANEL_HOME/sibil.py.removed-$(date +%Y%m%d%H%M%S)" 2>/dev/null
        ok "old python code moved aside (database and .env kept)"
    fi
    local cli_src=""
    [[ -f "$SRC_DIR/sibil" ]] && cli_src="$SRC_DIR/sibil"
    [[ -z "$cli_src" && -f "$SRC_DIR/bin/sibil" ]] && cli_src="$SRC_DIR/bin/sibil"
    if [[ -n "$cli_src" ]]; then
        [[ $IS_ROOT -eq 1 ]] && run "install -d -m 755 '$(dirname "$CLI_PATH")'"
        if [[ $IS_ROOT -eq 1 ]]; then
            run "install -m 755 '$cli_src' '$CLI_PATH'"
        elif [[ $DRY -eq 0 ]]; then
            mkdir -p "$HOME/.local/bin" 2>/dev/null
            install -m 755 "$cli_src" "$HOME/.local/bin/sibil" 2>/dev/null \
                && CLI_PATH="$HOME/.local/bin/sibil"
        else
            say "  [dry] would install the command at $HOME/.local/bin/sibil"
        fi
        # مسیر واقعی نصب داخل خودِ اسکریپت CLI نوشته می‌شود تا «sibil» همیشه خانه را بشناسد
        if [[ $DRY -eq 0 ]]; then
            local cli_dst="$CLI_PATH"
            sed -i "s|^PANEL_HOME_DEFAULT=.*|PANEL_HOME_DEFAULT=\"$PANEL_HOME\"|" "$cli_dst" 2>/dev/null || true
        fi
        # همیشه در /usr/local/bin هم لینک می‌شود تا «sibil» در هر ترمینالی کار کند
        if [[ $DRY -eq 0 ]]; then
            chmod 755 "$CLI_PATH" 2>/dev/null || true
            if [[ $IS_ROOT -eq 1 && "$CLI_PATH" != "/usr/local/bin/sibil" ]]; then
                ln -sf "$CLI_PATH" /usr/local/bin/sibil 2>/dev/null || true
            fi
            if command -v sibil >/dev/null 2>&1; then
                ok "command in PATH: $(command -v sibil)"
            else
                local bindir; bindir="$(dirname "$CLI_PATH")"
                if [[ $IS_ROOT -eq 1 ]]; then
                    ln -sf "$CLI_PATH" /usr/local/bin/sibil 2>/dev/null || true
                elif [[ -f "$HOME/.bashrc" ]] && ! grep -q 'HOME/.local/bin' "$HOME/.bashrc" 2>/dev/null; then
                    printf '\nexport PATH="$HOME/.local/bin:$PATH"\n' >>"$HOME/.bashrc" 2>/dev/null || true
                    ok "added $bindir to PATH in ~/.bashrc (open a new terminal)"
                fi
                command -v sibil >/dev/null 2>&1 \
                    && ok "command in PATH: $(command -v sibil)" \
                    || warn "the command lives at $CLI_PATH — run it directly or add $bindir to PATH"
            fi
        fi
    else
        warn "the CLI file (bin/sibil) was not found — 'sibil' will not be installed"
    fi
    [[ $DRY -eq 0 ]] && printf '%s\n' "$VERSION" >"$PANEL_HOME/VERSION" 2>/dev/null
    [[ -f "$SRC_DIR/README.md" ]] && run "install -m 644 '$SRC_DIR/README.md' '$PANEL_HOME/README.md'"
    if [[ $DRY -eq 0 && ! -f "$PANEL_HOME/sibil.php" ]]; then
        die "the code was not copied to $PANEL_HOME/sibil.php — check disk space and permissions"
    fi
    ok "bot code   : $PANEL_HOME/sibil.php"
    ok "command    : ${CLI_PATH}  (run: sibil)"
}

# قواعد امنیتی وب: هیچ‌کس نتواند .env / دیتابیس / لاگ / بکاپ را از مرورگر بخواند
write_htaccess() {
    if [[ $DRY -eq 1 ]]; then
        say "  [dry] would write $PANEL_HOME/.htaccess (deny .env, database, logs, backups)"
        return 0
    fi
    local target="$PANEL_HOME/.htaccess"
    cat >"$target" <<'HTACCESS'
# deny direct web access to private files
Options -Indexes
<FilesMatch "(\.env|\.sqlite3|\.sqlite3-.*|\.db|\.sql(\.gz)?|\.panelbak|sibil\.php\.bak.*|\.log)$">
    Require all denied
</FilesMatch>
RedirectMatch 404 /(\.env|logs/|backups/|.*\.sqlite3|.*\.sql\.gz|.*\.panelbak)
HTACCESS
    chmod 644 "$target" 2>/dev/null || true
    # هیچ PHP‌ای در پوشهٔ آپلود اجرا نمی‌شود
    mkdir -p "$PANEL_HOME/uploads" 2>/dev/null || true
    printf 'php_flag engine off\n<FilesMatch "\\.(php|phtml|phar)$">\n    Require all denied\n</FilesMatch>\n' \
        >"$PANEL_HOME/uploads/.htaccess" 2>/dev/null || true
    ok "web deny rules: $target"
}

setup_user() {
    [[ $IS_ROOT -eq 0 ]] && { warn "not root: the service runs as the current user"; return 0; }
    if ! id sibil >/dev/null 2>&1; then
        run "useradd --system --home '$PANEL_HOME' --shell /usr/sbin/nologin sibil >/dev/null 2>&1"
        ok "system user sibil created"
    fi
    run "chown -R sibil:sibil '$PANEL_HOME'"
}

write_systemd() {
    [[ $HAS_SYSTEMD -eq 0 || $IS_ROOT -eq 0 ]] && return 1
    step "Creating the systemd service"
    if [[ $DRY -eq 0 ]]; then
        local php_path="${PHP_PATH:-$(command -v php)}"
        [[ -z "$php_path" ]] && die "php not found in PATH — cannot write the service unit"
        cat >"/etc/systemd/system/${SERVICE}.service" <<EOF
[Unit]
Description=Panel Service bot (PHP edition)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=sibil
WorkingDirectory=$PANEL_HOME
Environment=PANEL_HOME=$PANEL_HOME
EnvironmentFile=$PANEL_HOME/.env
ExecStart=$php_path $PANEL_HOME/sibil.php --poll
Restart=always
RestartSec=5
# با restart، همهٔ فرزندهای همان سرویس با هم کشته می‌شوند؛ فقط یک poller می‌ماند.
KillMode=control-group
NoNewPrivileges=yes
StandardOutput=append:$PANEL_HOME/sibil.log
StandardError=append:$PANEL_HOME/sibil.log

[Install]
WantedBy=multi-user.target
EOF
        ok "service written: /etc/systemd/system/${SERVICE}.service"
    fi
    run "systemctl daemon-reload"
    run "systemctl enable ${SERVICE} >/dev/null 2>&1"
    run "systemctl enable --now ${SERVICE} >/dev/null 2>&1 || true"
    return 0
}

write_web_service() {
    [[ $HAS_SYSTEMD -eq 0 || $IS_ROOT -eq 0 ]] && return 1
    step "Creating the payment web service"
    local php_path="${PHP_PATH:-$(command -v php)}"
    local port; port="$(grep -E '^PANEL_WEB_PORT=' "$PANEL_HOME/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"')"
    port="${port:-$WEB_PORT_DEFAULT}"
    if [[ $DRY -eq 1 ]]; then
        say "  [dry] would write /etc/systemd/system/${WEB_SERVICE}.service (php -S 0.0.0.0:$port)"
        return 0
    fi
    cat >"/etc/systemd/system/${WEB_SERVICE}.service" <<EOF
[Unit]
Description=Panel Service payment callback endpoint
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=sibil
WorkingDirectory=$PANEL_HOME
Environment=PANEL_HOME=$PANEL_HOME
EnvironmentFile=$PANEL_HOME/.env
ExecStart=$php_path -S 0.0.0.0:$port $PANEL_HOME/sibil.php
Restart=always
RestartSec=5
NoNewPrivileges=yes
StandardOutput=append:$PANEL_HOME/sibil-web.log
StandardError=append:$PANEL_HOME/sibil-web.log

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable "$WEB_SERVICE" >/dev/null 2>&1
    ok "web endpoint: http://127.0.0.1:$port/pay/<gateway>"
    return 0
}

write_runner() {
    step "Creating the runner (no systemd)"
    if [[ $DRY -eq 0 ]]; then
        mkdir -p "$PANEL_HOME" 2>/dev/null
        cat >"$PANEL_HOME/run.sh" <<EOF
#!/usr/bin/env bash
cd "$PANEL_HOME" || exit 1
[[ -f sibil.pid ]] && kill "\$(cat sibil.pid)" 2>/dev/null
command -v termux-wake-lock >/dev/null 2>&1 && termux-wake-lock
PANEL_HOME="$PANEL_HOME" nohup "${PHP_PATH:-$(command -v php || echo php)}" sibil.php --poll >>sibil.log 2>&1 &
echo \$! >sibil.pid
echo "$APP_NAME started (PID \$(cat sibil.pid)) - log: $PANEL_HOME/sibil.log"
EOF
        chmod +x "$PANEL_HOME/run.sh" 2>/dev/null
        if [[ ! -f "$PANEL_HOME/run.sh" ]]; then
            die "could not create $PANEL_HOME/run.sh"
        fi
        ok "start it with: $PANEL_HOME/run.sh  or: sibil start"
    fi
}

setup_ssl_proxy() {
    [[ $NO_SSL -eq 1 || -z "$DOMAIN_IN" ]] && { warn "no domain: skipped TLS setup"; return 0; }
    step "TLS for $DOMAIN_IN"
    if ! command -v caddy >/dev/null 2>&1; then
        if command -v apt-get >/dev/null 2>&1; then
            silence_needrestart
            run "DEBIAN_FRONTEND=noninteractive apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https </dev/null >/dev/null 2>&1"
            run "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>/dev/null"
            run "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list"
            run "apt-get update -qq </dev/null && DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 apt-get install -y -qq caddy </dev/null >/dev/null"
        fi
    fi
    if command -v caddy >/dev/null 2>&1; then
        if [[ $DRY -eq 0 ]]; then
            if [[ -f /etc/caddy/Caddyfile ]] && ! grep -q "$DOMAIN_IN" /etc/caddy/Caddyfile; then
                cp -f /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.bak-$(date +%Y%m%d%H%M%S)" 2>/dev/null
                printf '\n%s {\n    reverse_proxy 127.0.0.1:%s\n}\n' "$DOMAIN_IN" "$WEB_PORT_DEFAULT" \
                    >>/etc/caddy/Caddyfile
            fi
            systemctl reload caddy 2>/dev/null || systemctl restart caddy 2>/dev/null
        fi
        ok "Caddy serves $DOMAIN_IN with an automatic certificate"
    else
        warn "caddy could not be installed — TLS skipped"
    fi
}

run_check() {
    step "Health check"
    if [[ $DRY -eq 0 ]]; then
        PANEL_HOME="$PANEL_HOME" PANEL_ENV="$PANEL_HOME/.env" PANEL_DB="$PANEL_HOME/sibil.sqlite3" \
            "${PHP_PATH:-php}" "$PANEL_HOME/sibil.php" --check 2>&1 | sed 's/^/  /'
    fi
}

start_service() {
    step "Starting the bot"
    [[ $DRY -eq 1 ]] && { say "  [dry] would start the service"; return 0; }
    stop_bot
    if [[ $HAS_SYSTEMD -eq 1 && $IS_ROOT -eq 1 ]]; then
        run "systemctl reset-failed ${SERVICE} >/dev/null 2>&1 || true"
        run "systemctl restart ${SERVICE}"
        sleep 3
        systemctl start "$WEB_SERVICE" 2>/dev/null || true
        if systemctl is-active --quiet "$SERVICE"; then
            ok "service is running: systemctl status $SERVICE"
        else
            warn "service did not stay up — last log lines:"
            tail -n 15 "$PANEL_HOME/sibil.log" 2>/dev/null | sed 's/^/    /'
        fi
    else
        [[ -f "$PANEL_HOME/run.sh" ]] || write_runner
        run "bash '$PANEL_HOME/run.sh'"
    fi
}

summary() {
    step "Done"
    say "  ${B}$APP_NAME $VERSION is installed${R}"
    say "    home    : $PANEL_HOME"
    say "    config  : $PANEL_HOME/.env"
    say "    database: $PANEL_HOME/sibil.sqlite3"
    say "    command : sibil  (status · update · backup · doctor)"
    say ""
    say "  Next: open your bot in Telegram, press /start, then"
    say "        ⚙️ پنل مدیریت → 🔌 پنل پاسارگارد → ➕ افزودن پنل"
}

ensure_web_url() {
    [[ $DRY -eq 1 || ! -f "$PANEL_HOME/.env" ]] && return 0
    local domain url
    domain="${DOMAIN_IN:-$(grep -E '^PANEL_DOMAIN=' "$PANEL_HOME/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"')}"
    [[ -z "$domain" ]] && return 0
    url="https://$domain"
    if [[ -n "$DOMAIN_IN" ]]; then
        if grep -q '^PANEL_DOMAIN=' "$PANEL_HOME/.env"; then
            sed -i "s|^PANEL_DOMAIN=.*|PANEL_DOMAIN=\"$DOMAIN_IN\"|" "$PANEL_HOME/.env"
        else
            printf 'PANEL_DOMAIN="%s"\n' "$DOMAIN_IN" >>"$PANEL_HOME/.env"
        fi
        ok "domain saved: $DOMAIN_IN"
    fi
    if grep -q '^PANEL_WEB_URL=' "$PANEL_HOME/.env"; then
        sed -i "s|^PANEL_WEB_URL=.*|PANEL_WEB_URL=\"$url\"|" "$PANEL_HOME/.env"
    else
        printf 'PANEL_WEB_URL="%s"\n' "$url" >>"$PANEL_HOME/.env"
    fi
    ok "callback base url: $url"
}

ensure_env_keys() {
    # the PHP edition reads these; older (python) .env files do not have them
    [[ $DRY -eq 1 || ! -f "$PANEL_HOME/.env" ]] && return 0
    grep -q '^PANEL_HOME=' "$PANEL_HOME/.env" \
        || printf 'PANEL_HOME="%s"\nPANEL_DB="%s/sibil.sqlite3"\n' "$PANEL_HOME" "$PANEL_HOME" >>"$PANEL_HOME/.env"
    grep -q '^PANEL_DB=' "$PANEL_HOME/.env" \
        || printf 'PANEL_DB="%s/sibil.sqlite3"\n' "$PANEL_HOME" >>"$PANEL_HOME/.env"
    if [[ -n "${PHP_PATH:-}" ]] && ! grep -q '^PANEL_PHP_BIN=' "$PANEL_HOME/.env"; then
        printf 'PANEL_PHP_BIN="%s"\n' "$PHP_PATH" >>"$PANEL_HOME/.env"
    fi
    # a stale PANEL_DB (moved install) must not hide the real database
    local configured
    configured="$(grep -E '^PANEL_DB=' "$PANEL_HOME/.env" | head -1 | cut -d= -f2- | tr -d '"')"
    if [[ -n "$configured" && "$configured" != "$PANEL_HOME/sibil.sqlite3" \
          && -f "$PANEL_HOME/sibil.sqlite3" ]]; then
        sed -i "s|^PANEL_DB=.*|PANEL_DB=\"$PANEL_HOME/sibil.sqlite3\"|" "$PANEL_HOME/.env"
        warn "PANEL_DB pointed elsewhere — using the real database in $PANEL_HOME"
    fi
}

# یونیت وقتی کهنه است: پایتون، یا مسیر php داخلش خالی/غلط است
unit_is_stale() {
    local unit="/etc/systemd/system/${SERVICE}.service"
    [[ -f "$unit" ]] || return 1
    grep -q 'sibil\.py' "$unit" && return 0
    local php_path; php_path="$(command -v php)"
    [[ -z "$php_path" ]] && return 0
    grep -qE "^ExecStart=$php_path " "$unit" || return 0
    return 1
}

do_update() {
    step "Updating code (database and configuration are kept)"
    resolve_sources
    install_packages   # نسخهٔ PHP به php-cli و php-sqlite3 نیاز دارد
    [[ -f "$PANEL_HOME/.env" ]] || die "no previous installation found in $PANEL_HOME — install first"
    copy_payload
    setup_user
    ensure_env_keys
    ensure_primary_owner_env
    ensure_web_url
    if [[ $IS_ROOT -eq 1 && $HAS_SYSTEMD -eq 1 ]]; then
        if unit_is_stale; then
            step "Rewriting the service unit for the php edition"
            write_systemd
            ok "service now runs: php $PANEL_HOME/sibil.php --poll"
        fi
        write_web_service
    fi
    [[ $DRY -eq 0 ]] && chmod 600 "$PANEL_HOME/.env" 2>/dev/null
    if [[ $DRY -eq 0 ]]; then
        PANEL_HOME="$PANEL_HOME" PANEL_ENV="$PANEL_HOME/.env" \
            "${PHP_PATH:-php}" "$PANEL_HOME/sibil.php" --migrate >/dev/null 2>&1 \
            && ok "database migrated to $VERSION schema"
    fi
    stop_bot
    run "pkill -f '$PANEL_HOME/sibil.py' 2>/dev/null || true"
    if [[ $HAS_SYSTEMD -eq 1 && $IS_ROOT -eq 1 ]]; then
        run "systemctl reset-failed ${SERVICE} >/dev/null 2>&1 || true"
        run "systemctl restart ${SERVICE}"
        run "systemctl restart ${WEB_SERVICE} 2>/dev/null || true"
    else
        [[ -f "$PANEL_HOME/run.sh" ]] || write_runner
        run "bash '$PANEL_HOME/run.sh'"
    fi
    ok "update finished (version $VERSION)"
    run_check
    exit 0
}

do_uninstall() {
    step "Uninstalling"
    local wipe=n
    if [[ $YES -eq 0 ]] && can_prompt; then
        local a
        read -r -p "  Remove everything, including the database? [y/N] " a </dev/tty || a=n
        [[ "$a" =~ ^[Yy]$ ]] && wipe=y
    fi
    [[ $HAS_SYSTEMD -eq 1 ]] && run "systemctl stop ${SERVICE} 2>/dev/null; systemctl disable ${SERVICE} 2>/dev/null; rm -f /etc/systemd/system/${SERVICE}.service; systemctl daemon-reload"
    [[ $HAS_SYSTEMD -eq 1 ]] && run "systemctl stop ${WEB_SERVICE} 2>/dev/null; systemctl disable ${WEB_SERVICE} 2>/dev/null; rm -f /etc/systemd/system/${WEB_SERVICE}.service"
    stop_bot
    run "rm -f '$CLI_PATH'"
    if [[ "$wipe" == y ]]; then
        run "rm -rf '$PANEL_HOME'"
        ok "everything removed"
    else
        ok "service removed; data kept in $PANEL_HOME"
    fi
    exit 0
}

# ── main ────────────────────────────────────────────────────────────────────
banner
[[ $MODE == check ]] && { run_check; exit 0; }
[[ $MODE == update ]] && do_update
[[ $MODE == uninstall ]] && do_uninstall

if [[ $IS_ROOT -eq 0 ]]; then
    warn "running without root: no systemd service, the bot starts under nohup"
fi

# پیش‌نیازها اول از همه؛ بعد دانلود سورس و پرسش‌ها
install_packages
resolve_sources
step "Preparing $PANEL_HOME"
if [[ $DRY -eq 0 ]]; then
    if ! mkdir -p "$PANEL_HOME" 2>/dev/null || [[ ! -w "$PANEL_HOME" ]]; then
        die "cannot create or write $PANEL_HOME — run with sudo (sudo bash $SELF) or set PANEL_HOME=/a/writable/path"
    fi
    ok "home directory ready: $PANEL_HOME"
fi
[[ $IS_ROOT -eq 0 && -z "$HOME_WAS_SET" ]] && say "  ${DIM}no root: installing under your home ($PANEL_HOME)${R}"
say "${B}Steps:${R} prerequisites → sources → configuration → files → service → TLS → start → verify"
prompt_all
copy_payload
setup_user
write_env
write_systemd || write_runner
write_web_service || true
setup_ssl_proxy
[[ $DRY -eq 0 && -n "$UPDATE_URL_IN" ]] && printf '%s\n' "$UPDATE_URL_IN" >"$PANEL_HOME/.installer-url"
run_check
start_service
[[ $DRY -eq 0 ]] && summary
rm -rf "$EXTRACT_DIR" 2>/dev/null || true
exit 0

# @@PANEL_PAYLOAD_BEGIN@@
# @@PANEL_PAYLOAD_END@@
