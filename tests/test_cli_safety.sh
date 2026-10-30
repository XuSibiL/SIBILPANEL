#!/usr/bin/env bash
# Focused, non-destructive regression coverage for Sibil's shell operations.
# Everything—including mock Caddy/systemd and databases—lives in one temp dir.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLI="$ROOT/bin/sibil"
INSTALLER="$ROOT/install.sh"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/sibil-cli-test.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

passed=0
failed=0
pass() { printf 'ok %02d - %s\n' "$((++passed))" "$1"; }
fail() { printf 'not ok - %s\n' "$1" >&2; failed=$((failed + 1)); }
require_file() { [[ -f "$1" ]] || { fail "expected file: $1"; return 1; }; }
require_absent() { [[ ! -e "$1" && ! -L "$1" ]] || { fail "expected absent: $1"; return 1; }; }
require_contains() { grep -qF "$2" "$1" || { fail "expected '$2' in $1"; return 1; }; }

MOCKBIN="$TMP/mock-bin"
mkdir -p "$MOCKBIN"
cat >"$MOCKBIN/getent" <<'EOF'
#!/usr/bin/env bash
# Deterministic DNS for the test; only known public-looking test domains resolve.
if [[ "${1:-}" == ahosts && "${2:-}" =~ ^(example\.com|example\.org|installer\.example\.org|unowned\.example\.org|rollback\.example\.org)$ ]]; then
  printf '203.0.113.10 STREAM %s\n' "$2"
  exit 0
fi
exit 2
EOF
cat >"$MOCKBIN/caddy" <<'EOF'
#!/usr/bin/env bash
# Validation succeeds by default but never starts/reloads a host web server.
if [[ "${1:-}" == validate && "${SIBIL_TEST_CADDY_FAIL:-0}" == 1 ]]; then exit 1; fi
[[ "${1:-}" == validate ]] && exit 0
exit 0
EOF
cat >"$MOCKBIN/systemctl" <<'EOF'
#!/usr/bin/env bash
# A hard no-op guard: tests must never call the host systemd manager.
exit 1
EOF
cat >"$MOCKBIN/nginx" <<'EOF'
#!/usr/bin/env bash
# Validation is deterministic and never invokes host nginx.
if [[ "${1:-}" == -t ]]; then exit "${SIBIL_TEST_NGINX_FAIL:-0}"; fi
exit 0
EOF
cat >"$MOCKBIN/openssl" <<'EOF'
#!/usr/bin/env bash
case " $* " in
  *' x509 '*) printf 'notAfter=Jan 01 00:00:00 2035 GMT\n' ;;
esac
exit 0
EOF
cat >"$MOCKBIN/curl" <<'EOF'
#!/usr/bin/env bash
# Tests are offline. A caller can explicitly provide one tarball to exercise
# the real CLI update-copy path; all other probes return no remote payload.
[[ -n "${SIBIL_TEST_CURL_LOG:-}" ]] && printf '%s\n' "$*" >>"$SIBIL_TEST_CURL_LOG"
out=""
while [[ $# -gt 0 ]]; do
  if [[ "$1" == "-o" ]]; then out="${2:-}"; shift 2; continue; fi
  shift
done
if [[ -n "${SIBIL_TEST_UPDATE_ARCHIVE:-}" && -n "$out" ]]; then
  cp "$SIBIL_TEST_UPDATE_ARCHIVE" "$out"
fi
exit 0
EOF
cat >"$MOCKBIN/nohup" <<'EOF'
#!/usr/bin/env bash
# An installer update may create its non-systemd runner. Do not launch a poller
# during this filesystem-only safety regression.
exit 0
EOF
chmod 755 "$MOCKBIN"/*
export PATH="$MOCKBIN:$PATH"

HOME1="$TMP/home"
SYSTEMD="$TMP/systemd"
CADDY="$TMP/caddy/Caddyfile"
CADCONF="$TMP/caddy/conf.d"
NGINX_ENABLED="$TMP/nginx/enabled"
NGINX_AVAILABLE="$TMP/nginx/available"
NGINX_CONF="$TMP/nginx/conf.d"
CRON="$TMP/cron.d"
CLI_DST="$TMP/bin/sibil"
BACKUPS="$TMP/backups"
mkdir -p "$HOME1" "$HOME1/bin" "$SYSTEMD" "$CADCONF" "$NGINX_ENABLED" "$NGINX_AVAILABLE" "$NGINX_CONF" "$CRON" "$(dirname "$CLI_DST")" "$BACKUPS"
printf '{}\n# legacy example.org must survive\n' >"$CADDY"
printf 'SIBILPANEL-OWNED\n' >"$HOME1/.sibil-owned"
cat >"$HOME1/.env" <<'EOF'
PANEL_HOME="REPLACED_AT_TEST_RUNTIME"
PANEL_DOMAIN="example.com"
PANEL_WEB_URL="https://example.com"
PANEL_WEB_PORT="8080"
EOF
sed -i "s|REPLACED_AT_TEST_RUNTIME|$HOME1|" "$HOME1/.env"
cat >"$HOME1/sibil.php" <<'PHP'
<?php
const PANEL_VERSION = 'test-shell';
$db = getenv('PANEL_DB') ?: (__DIR__ . '/sibil.sqlite3');
if (in_array('--dump', $argv, true)) {
    $target = $argv[array_search('--dump', $argv, true) + 1] ?? '';
    if ($target !== '' && is_file($db)) { copy($db, $target); exit(0); }
    exit(1);
}
if (in_array('--migrate', $argv, true)) {
    if (getenv('TEST_FAIL_MIGRATE') === '1') { exit(9); }
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
    exit(0);
}
if (in_array('--check', $argv, true)) { exit(0); }
PHP

cli() {
  PANEL_HOME="$HOME1" \
  PANEL_CLI_PATH="$CLI_DST" \
  SIBIL_SYSTEMD_DIR="$SYSTEMD" \
  SIBIL_CADDYFILE="$CADDY" \
  SIBIL_CADDY_CONF_DIR="$CADCONF" \
  SIBIL_NGINX_SITES_ENABLED_DIR="$NGINX_ENABLED" \
  SIBIL_NGINX_SITES_AVAILABLE_DIR="$NGINX_AVAILABLE" \
  SIBIL_NGINX_CONF_DIR="$NGINX_CONF" \
  SIBIL_CRON_DIR="$CRON" \
  SIBIL_UNINSTALL_BACKUP_DIR="$BACKUPS" \
  PANEL_BACKUP_DIR="$BACKUPS" \
  SIBIL_TEST_CURL_LOG="$TMP/curl.log" \
  SIBIL_DISABLE_SYSTEMD=1 \
  bash "$CLI" "$@"
}

# The read-only dashboard is also what the interactive SIBIL menu renders.
# It must carry the new branded status view rather than the old plain menu.
cli dashboard >"$TMP/dashboard.log" 2>&1
require_contains "$TMP/dashboard.log" "SIBIL PANEL"
require_contains "$TMP/dashboard.log" "advanced server dashboard"
require_contains "$TMP/dashboard.log" "SIBIL"
if grep -qF "Panel Service" "$TMP/dashboard.log"; then
  fail "dashboard retained the obsolete Panel Service branding"
else
  pass "dashboard renders the advanced SIBIL PANEL status view"
fi

# Option 2 runs update from the old process, then must exec the freshly written
# $PANEL_HOME/bin/sibil instead of redrawing the stale menu. Stub only the
# download/update internals; cmd_menu and its re-exec path are the real source.
OLD_MENU_CLI="$TMP/old-menu-sibil"
cp "$CLI" "$OLD_MENU_CLI"
python3 - "$OLD_MENU_CLI" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
s = p.read_text()
start = s.index('github_update() {')
end = s.index('\n}\n\ncmd_update()', start) + 3
stub = '''github_update() {
    cat > "$PANEL_HOME/bin/sibil" <<'NEWCLI'
#!/usr/bin/env bash
if [[ "${1:-}" == "menu" ]]; then
  printf 'NEW-CLI-DASHBOARD\\n'
fi
NEWCLI
    chmod 700 "$PANEL_HOME/bin/sibil"
    return 0
}
'''
p.write_text(s[:start] + stub + s[end:])
PY
if command -v script >/dev/null 2>&1; then
  if ! printf '2\n' | env \
    PATH="$PATH" PANEL_HOME="$HOME1" PANEL_CLI_PATH="$CLI_DST" \
    SIBIL_SYSTEMD_DIR="$SYSTEMD" SIBIL_CADDYFILE="$CADDY" SIBIL_CADDY_CONF_DIR="$CADCONF" \
    SIBIL_NGINX_SITES_ENABLED_DIR="$NGINX_ENABLED" SIBIL_NGINX_SITES_AVAILABLE_DIR="$NGINX_AVAILABLE" \
    SIBIL_NGINX_CONF_DIR="$NGINX_CONF" SIBIL_CRON_DIR="$CRON" \
    SIBIL_UNINSTALL_BACKUP_DIR="$BACKUPS" PANEL_BACKUP_DIR="$BACKUPS" \
    SIBIL_TEST_CURL_LOG="$TMP/curl.log" SIBIL_DISABLE_SYSTEMD=1 PANEL_NO_PAUSE=1 TERM=xterm \
    script -qec "bash '$OLD_MENU_CLI' menu" "$TMP/update-menu.typescript" >"$TMP/update-menu.log" 2>&1; then
    cat "$TMP/update-menu.log" >&2
    fail "menu update simulation failed"
  elif grep -qF "NEW-CLI-DASHBOARD" "$TMP/update-menu.log"; then
    pass "interactive option 2 execs the freshly installed SIBIL CLI"
  else
    fail "option 2 did not execute the freshly installed CLI"
  fi
else
  require_contains "$CLI" 'exec bash "$fresh" menu' && pass "update option contains the fresh-CLI re-exec guard"
fi

# Regression for the reported deployment defect: a real legacy global launcher
# must become an owned link to the newly installed canonical dashboard after a
# normal installer --update, while an unrelated same-name command is untouched.
UPDATE_HOME="$TMP/update-home"
LEGACY_GLOBAL="$TMP/global/sibil"
UNOWNED_GLOBAL="$TMP/unowned/sibil"
mkdir -p "$UPDATE_HOME" "$(dirname "$LEGACY_GLOBAL")" "$(dirname "$UNOWNED_GLOBAL")"
cat >"$UPDATE_HOME/.env" <<EOF
PANEL_HOME="$UPDATE_HOME"
PANEL_DB="$UPDATE_HOME/sibil.sqlite3"
PANEL_DOMAIN="example.org"
PANEL_WEB_URL="https://example.org/old-panel"
PANEL_WEB_PORT="8080"
PANEL_ADMINS="100"
PANEL_PRIMARY_OWNER_ID="100"
EOF
printf 'SIBILPANEL-OWNED\n' >"$UPDATE_HOME/.sibil-owned"
cat >"$UPDATE_HOME/sibil.php" <<'PHP'
<?php
const PANEL_VERSION = 'legacy-test';
PHP
cat >"$LEGACY_GLOBAL" <<EOF
#!/usr/bin/env bash
# SIBILPANEL-OWNED
# Panel Service · v3.18.3 (legacy launcher fixture)
PANEL_HOME_DEFAULT="\${PANEL_HOME_DEFAULT:-$UPDATE_HOME}"
printf 'LEGACY-PANEL-SERVICE\n'
EOF
cat >"$UNOWNED_GLOBAL" <<'EOF'
#!/usr/bin/env bash
printf 'UNRELATED-COMMAND\n'
EOF
chmod 755 "$LEGACY_GLOBAL" "$UNOWNED_GLOBAL"
"$LEGACY_GLOBAL" >"$TMP/legacy-before.log"
require_contains "$TMP/legacy-before.log" "LEGACY-PANEL-SERVICE"
if ! env \
  PATH="$PATH" PANEL_HOME="$UPDATE_HOME" PANEL_CLI_PATH="$LEGACY_GLOBAL" \
  SIBIL_GLOBAL_CLI_PATH="$UNOWNED_GLOBAL" SIBIL_SYSTEMD_DIR="$SYSTEMD" \
  SIBIL_CADDYFILE="$CADDY" SIBIL_CADDY_CONF_DIR="$CADCONF" \
  SIBIL_NGINX_SITES_ENABLED_DIR="$NGINX_ENABLED" SIBIL_NGINX_SITES_AVAILABLE_DIR="$NGINX_AVAILABLE" \
  SIBIL_NGINX_CONF_DIR="$NGINX_CONF" SIBIL_CRON_DIR="$CRON" \
  SIBIL_UNINSTALL_BACKUP_DIR="$BACKUPS" PANEL_BACKUP_DIR="$BACKUPS" \
  SIBIL_DISABLE_SYSTEMD=1 PANEL_NO_PAUSE=1 TERM=dumb \
  bash "$INSTALLER" --update >"$TMP/legacy-installer-update.log" 2>&1; then
  cat "$TMP/legacy-installer-update.log" >&2
  fail "installer update failed for a legacy global launcher"
elif [[ -L "$LEGACY_GLOBAL" && "$(readlink "$LEGACY_GLOBAL")" == "$UPDATE_HOME/bin/sibil" ]] \
    && "$LEGACY_GLOBAL" dashboard >"$TMP/legacy-after.log" 2>&1 \
    && grep -qF "SIBIL PANEL" "$TMP/legacy-after.log" \
    && ! grep -qF "Panel Service" "$TMP/legacy-after.log"; then
  pass "legacy global launcher is atomically replaced by the current SIBIL dashboard"
else
  cat "$TMP/legacy-installer-update.log" "$TMP/legacy-after.log" 2>/dev/null >&2 || true
  fail "legacy global launcher did not reach the current dashboard"
fi
require_contains "$UPDATE_HOME/bin/sibil" "SIBILPANEL-OWNED" \
  && pass "installer keeps one marked canonical dashboard inside PANEL_HOME"
require_contains "$UNOWNED_GLOBAL" "UNRELATED-COMMAND" \
  && [[ ! -L "$UNOWNED_GLOBAL" ]] \
  && pass "installer refuses to overwrite an unrelated global command"

# The regular update engine used by interactive menu option 2 must perform the
# same launcher refresh, rather than only replacing hidden PANEL_HOME files.
CURRENT_GLOBAL="$TMP/current-global/sibil"
ARCHIVE_ROOT="$TMP/current-update-archive/SIBILPANEL-main"
mkdir -p "$(dirname "$CURRENT_GLOBAL")" "$ARCHIVE_ROOT"
cp "$CLI" "$CURRENT_GLOBAL"
chmod 755 "$CURRENT_GLOBAL"
# Use a real codeload-shaped archive of this checkout; mock curl supplies it
# only to the download requested by github_update().
for item in sibil.php migration.php install.sh deploy.sh README.md LICENSE VERSION src bin build docs .github dist; do
  [[ -e "$ROOT/$item" ]] && cp -a "$ROOT/$item" "$ARCHIVE_ROOT/"
done
tar -czf "$TMP/current-update.tar.gz" -C "$(dirname "$ARCHIVE_ROOT")" "$(basename "$ARCHIVE_ROOT")"
if ! env \
  PATH="$PATH" PANEL_HOME="$UPDATE_HOME" PANEL_CLI_PATH="$CURRENT_GLOBAL" \
  PANEL_UPDATE_ARCHIVE_URL="https://fixture.invalid/current-update.tar.gz" \
  SIBIL_TEST_UPDATE_ARCHIVE="$TMP/current-update.tar.gz" SIBIL_TEST_CURL_LOG="$TMP/current-update-curl.log" \
  SIBIL_SYSTEMD_DIR="$SYSTEMD" SIBIL_CADDYFILE="$CADDY" SIBIL_CADDY_CONF_DIR="$CADCONF" \
  SIBIL_NGINX_SITES_ENABLED_DIR="$NGINX_ENABLED" SIBIL_NGINX_SITES_AVAILABLE_DIR="$NGINX_AVAILABLE" \
  SIBIL_NGINX_CONF_DIR="$NGINX_CONF" SIBIL_CRON_DIR="$CRON" \
  SIBIL_UNINSTALL_BACKUP_DIR="$BACKUPS" PANEL_BACKUP_DIR="$BACKUPS" \
  SIBIL_DISABLE_SYSTEMD=1 PANEL_NO_PAUSE=1 TERM=dumb \
  bash "$CURRENT_GLOBAL" update --force >"$TMP/current-global-update.log" 2>&1; then
  cat "$TMP/current-global-update.log" >&2
  fail "normal CLI update failed in launcher-refresh regression"
elif [[ -L "$CURRENT_GLOBAL" && "$(readlink "$CURRENT_GLOBAL")" == "$UPDATE_HOME/bin/sibil" ]] \
    && "$CURRENT_GLOBAL" dashboard >"$TMP/current-global-dashboard.log" 2>&1 \
    && grep -qF "SIBIL PANEL" "$TMP/current-global-dashboard.log"; then
  pass "normal update refreshes the active owned global launcher before returning to dashboard"
else
  cat "$TMP/current-global-update.log" "$TMP/current-global-dashboard.log" 2>/dev/null >&2 || true
  fail "normal update left the active global launcher stale"
fi

# Domain validation/confirmation logic must never silently write a bad domain.
old_env="$(cat "$HOME1/.env")"
if cli domain change bad.invalid --yes >"$TMP/domain-bad.log" 2>&1; then
  fail "unresolvable domain change should fail without --force"
else
  [[ "$(cat "$HOME1/.env")" == "$old_env" ]] && pass "domain change rejects an unresolved DNS name without changing config" || fail "failed domain change changed .env"
fi
cli domain change example.org >"$TMP/domain-cancel.log" 2>&1
require_contains "$TMP/domain-cancel.log" "cancelled" && require_contains "$HOME1/.env" 'PANEL_DOMAIN="example.com"' && pass "domain change requires confirmation"
cli domain change example.org --yes >"$TMP/domain-ok.log" 2>&1
require_contains "$HOME1/.env" 'PANEL_DOMAIN="example.org"' && require_contains "$HOME1/.env" 'PANEL_WEB_URL="https://example.org"' && pass "confirmed domain change updates callback values"
cli domain health example.org >"$TMP/domain-health.log" 2>&1
require_contains "$TMP/domain-health.log" "TetraPay callback"
require_contains "$TMP/curl.log" "https://example.org/pay/tetrapay" && pass "domain health checks the public non-settling TetraPay callback path"
cli domain change bad.invalid --force --yes >"$TMP/domain-force.log" 2>&1
require_contains "$HOME1/.env" 'PANEL_DOMAIN="bad.invalid"' && pass "DNS bypass requires explicit --force and accepts either option order"
cli domain change example.org --yes >/dev/null 2>&1

# A fake Caddy validates the ownership-only fragment model without touching host Caddy.
if ! cli ssl issue >"$TMP/ssl.log" 2>&1; then
  cat "$TMP/ssl.log" >&2
  fail "SSL issue should succeed with an isolated Caddy mock"
else
  require_contains "$CADDY" "BEGIN SIBILPANEL-OWNED IMPORT"
  require_file "$CADCONF/sibil-example.org.caddy"
  require_contains "$CADCONF/sibil-example.org.caddy" "SIBILPANEL-OWNED"
  pass "SSL issue writes only the marked Caddy import and owned fragment"
fi
# A validation failure must restore both Caddyfile and the newly proposed site.
caddy_before="$(cat "$CADDY")"
sed -i 's/PANEL_DOMAIN="example.org"/PANEL_DOMAIN="rollback.example.org"/' "$HOME1/.env"
if SIBIL_TEST_CADDY_FAIL=1 cli ssl issue >"$TMP/ssl-rollback.log" 2>&1; then
  fail "Caddy validation failure should fail SSL issue"
else
  [[ "$(cat "$CADDY")" == "$caddy_before" ]] && require_absent "$CADCONF/sibil-rollback.example.org.caddy" && pass "failed Caddy validation restores prior configuration" || fail "failed Caddy validation left changes behind"
fi
sed -i 's/PANEL_DOMAIN="rollback.example.org"/PANEL_DOMAIN="example.org"/' "$HOME1/.env"
printf 'unowned config\n' >"$CADCONF/sibil-unowned.example.org.caddy"
sed -i 's/PANEL_DOMAIN="example.org"/PANEL_DOMAIN="unowned.example.org"/' "$HOME1/.env"
if cli ssl issue >"$TMP/ssl-unowned.log" 2>&1; then
  fail "SSL issue must refuse an unowned Caddy fragment"
else
  require_contains "$CADCONF/sibil-unowned.example.org.caddy" "unowned config" && pass "SSL issue preserves an unowned Caddy fragment"
fi
sed -i 's/PANEL_DOMAIN="unowned.example.org"/PANEL_DOMAIN="example.org"/' "$HOME1/.env"
# Remove this deliberately unowned test fixture before asserting an otherwise-empty
# owned fragment directory can have its dedicated import removed.
rm -f "$CADCONF/sibil-unowned.example.org.caddy"

# Service-only removal must delete marked units, retain unmarked ones, and retain data.
printf '# SIBILPANEL-OWNED\nWorkingDirectory=%s\nExecStart=%s/sibil.php --poll\n' "$HOME1" "$HOME1" >"$SYSTEMD/sibil.service"
printf '# unrelated service\n' >"$SYSTEMD/sibil-web.service"
cli remove >"$TMP/remove.log" 2>&1
require_absent "$SYSTEMD/sibil.service"
require_file "$SYSTEMD/sibil-web.service"
require_file "$HOME1/.sibil-owned"
pass "remove deletes only the proven Sibil unit and keeps data"

# Import: validate/migrate/swap a SQLite file and verify user data survives.
php -r '
  $p=new PDO("sqlite:".$argv[1]);
  $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES(1,\"old\");");
' "$HOME1/sibil.sqlite3"
SOURCE_DB="$TMP/legacy.sqlite3"
php -r '
  $p=new PDO("sqlite:".$argv[1]);
  $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE panels (id INTEGER); CREATE TABLE orders (id INTEGER); CREATE TABLE wallet_tx (id INTEGER);");
  $p->exec("INSERT INTO users VALUES(7,\"restored\"); INSERT INTO panels VALUES(1); INSERT INTO orders VALUES(1); INSERT INTO wallet_tx VALUES(1);");
' "$SOURCE_DB"
cli import "$SOURCE_DB" >"$TMP/import-ok.log" 2>&1
[[ "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT name FROM users WHERE id=7")->fetchColumn();' "$HOME1/sibil.sqlite3")" == restored ]] && pass "SQLite import migrates and restores user data" || fail "import did not restore expected user"
require_contains "$TMP/import-ok.log" "imported and migrated safely"
printf 'not sqlite\n' >"$TMP/not-a-db.sql"
if cli import "$TMP/not-a-db.sql" >"$TMP/import-bad.log" 2>&1; then
  fail "SQL text must not be executed as an import"
else
  [[ "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT name FROM users WHERE id=7")->fetchColumn();' "$HOME1/sibil.sqlite3")" == restored ]] && pass "invalid import leaves current database intact" || fail "invalid import changed database"
fi
ROLLBACK_DB="$TMP/rollback.sqlite3"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES(9,\"must-not-land\");");' "$ROLLBACK_DB"
if TEST_FAIL_MIGRATE=1 cli import "$ROLLBACK_DB" >"$TMP/import-rollback.log" 2>&1; then
  fail "failed migration should cause import rollback"
else
  [[ "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT name FROM users WHERE id=7")->fetchColumn();' "$HOME1/sibil.sqlite3")" == restored ]] && pass "failed import migration rolls back prior database" || fail "failed import migration did not roll back"
fi

# Exercise the actual assembled Sibil migration path, not just the shell swap
# harness: an older compatible SQLite schema must retain its users and gain the
# current migration columns after import.
REAL_HOME="$TMP/real-home"
REAL_SOURCE="$TMP/real-source"
REAL_BACKUPS="$TMP/real-backups"
mkdir -p "$REAL_HOME" "$REAL_SOURCE" "$REAL_BACKUPS"
cp "$ROOT/sibil.php" "$REAL_HOME/sibil.php"
cp "$ROOT/sibil.php" "$REAL_SOURCE/sibil.php"
printf 'SIBILPANEL-OWNED\n' >"$REAL_HOME/.sibil-owned"
cat >"$REAL_HOME/.env" <<EOF
PANEL_HOME="$REAL_HOME"
PANEL_DB="$REAL_HOME/sibil.sqlite3"
PANEL_ADMINS="100"
PANEL_PRIMARY_OWNER_ID="100"
EOF
cat >"$REAL_SOURCE/.env" <<EOF
PANEL_HOME="$REAL_SOURCE"
PANEL_DB="$REAL_SOURCE/sibil.sqlite3"
PANEL_ADMINS="100"
PANEL_PRIMARY_OWNER_ID="100"
EOF
PANEL_HOME="$REAL_HOME" PANEL_ENV="$REAL_HOME/.env" PANEL_DB="$REAL_HOME/sibil.sqlite3" php "$REAL_HOME/sibil.php" --migrate >/dev/null 2>&1
PANEL_HOME="$REAL_SOURCE" PANEL_ENV="$REAL_SOURCE/.env" PANEL_DB="$REAL_SOURCE/sibil.sqlite3" php "$REAL_SOURCE/sibil.php" --migrate >/dev/null 2>&1
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("INSERT INTO users(tg_id,name,uname,balance,created_at) VALUES(9,\"old\",\"old\",0,1)");' "$REAL_HOME/sibil.sqlite3"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("INSERT INTO users(tg_id,name,uname,balance,created_at) VALUES(42,\"legacy-restored\",\"legacy\",123,1)"); foreach(["ALTER TABLE users DROP COLUMN kyc_level", "ALTER TABLE users DROP COLUMN kyc_phone", "ALTER TABLE products DROP COLUMN per_user_price", "ALTER TABLE products DROP COLUMN min_users", "ALTER TABLE products DROP COLUMN unlimited_billing_mode"] as $sql) { $p->exec($sql); }' "$REAL_SOURCE/sibil.sqlite3"
real_cli() {
  PANEL_HOME="$REAL_HOME" PANEL_CLI_PATH="$TMP/real-bin/sibil" SIBIL_SYSTEMD_DIR="$TMP/real-systemd" \
  SIBIL_CADDYFILE="$TMP/real-caddy/Caddyfile" SIBIL_CADDY_CONF_DIR="$TMP/real-caddy/conf.d" \
  SIBIL_DISABLE_SYSTEMD=1 PANEL_BACKUP_DIR="$REAL_BACKUPS" \
  bash "$CLI" "$@"
}
real_cli import "$REAL_SOURCE/sibil.sqlite3" >"$TMP/import-real.log" 2>&1
real_result="$(php -r '$p=new PDO("sqlite:".$argv[1]); $u=$p->query("SELECT name||\":\"||balance FROM users WHERE tg_id=42")->fetchColumn(); $cols=[]; foreach($p->query("PRAGMA table_info(products)") as $r) $cols[]=$r[1]; echo $u."|".(int)in_array("unlimited_billing_mode", $cols, true);' "$REAL_HOME/sibil.sqlite3")"
[[ "$real_result" == 'legacy-restored:123|1' ]] && pass "actual Sibil migration imports a legacy-compatible database without losing users" || fail "actual Sibil migration import did not preserve legacy user/schema data"

# A failed nginx validation must restore the Sibil fragment and leave source/data
# intact rather than reporting a successful uninstall.
NFAIL_HOME="$TMP/nginx-failure-home"
NFAIL_NGINX="$TMP/nginx-failure/conf.d"
NFAIL_CADDY="$TMP/nginx-failure/Caddyfile"
NFAIL_BACKUPS="$TMP/nginx-failure/backups"
mkdir -p "$NFAIL_HOME" "$NFAIL_NGINX" "$NFAIL_BACKUPS"
printf 'SIBILPANEL-OWNED\n' >"$NFAIL_HOME/.sibil-owned"
printf 'PANEL_DOMAIN="nfail.example.org"\n' >"$NFAIL_HOME/.env"
printf "<?php const PANEL_VERSION = 'test';\n" >"$NFAIL_HOME/sibil.php"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1);");' "$NFAIL_HOME/sibil.sqlite3"
printf '{}\n' >"$NFAIL_CADDY"
printf '# SIBILPANEL-OWNED\nserver { }\n' >"$NFAIL_NGINX/sibil-nfail.example.org.conf"
if SIBIL_TEST_NGINX_FAIL=1 PANEL_HOME="$NFAIL_HOME" PANEL_CLI_PATH="$TMP/nginx-failure/sibil" SIBIL_SYSTEMD_DIR="$TMP/nginx-failure/systemd" SIBIL_CADDYFILE="$NFAIL_CADDY" SIBIL_CADDY_CONF_DIR="$TMP/nginx-failure/caddy-conf" SIBIL_NGINX_CONF_DIR="$NFAIL_NGINX" SIBIL_NGINX_SITES_ENABLED_DIR="$TMP/nginx-failure/enabled" SIBIL_NGINX_SITES_AVAILABLE_DIR="$TMP/nginx-failure/available" SIBIL_CRON_DIR="$TMP/nginx-failure/cron" SIBIL_UNINSTALL_BACKUP_DIR="$NFAIL_BACKUPS" SIBIL_DISABLE_SYSTEMD=1 bash "$CLI" uninstall --yes >"$TMP/nginx-rollback.log" 2>&1; then
  fail "nginx validation failure should stop uninstall"
else
  require_file "$NFAIL_HOME/.sibil-owned"
  require_file "$NFAIL_NGINX/sibil-nfail.example.org.conf"
  pass "failed nginx validation restores owned config and preserves source data"
fi

# Full CLI uninstall works in an isolated tree only and preserves unmarked config.
# A configured DB outside the installation home is explicitly preserved rather
# than being inferred as an owned file merely because it is named in .env.
EXTERNAL_DB="$TMP/external-configured.sqlite3"
cp "$HOME1/sibil.sqlite3" "$EXTERNAL_DB"
printf 'PANEL_DB="%s"\n' "$EXTERNAL_DB" >>"$HOME1/.env"
printf '# SIBILPANEL-OWNED\nWorkingDirectory=%s\nExecStart=%s/sibil.php --poll\n' "$HOME1" "$HOME1" >"$SYSTEMD/sibil.service"
printf '# unrelated service\n' >"$SYSTEMD/sibil-web.service"
# Legacy generated CLI proof remains supported only when it points to this exact home.
printf '#!/usr/bin/env bash\nPANEL_HOME_DEFAULT="%s"\n' "$HOME1" >"$CLI_DST"
printf '# SIBILPANEL-OWNED\nserver { }\n' >"$NGINX_ENABLED/sibil-example.org"
printf '# unrelated nginx config\n' >"$NGINX_CONF/sibil-example.org.conf"
printf '# SIBILPANEL-OWNED\n* * * * * php %s/sibil.php --meter\n' "$HOME1" >"$CRON/sibil"
printf '# unrelated cron\n' >"$CRON/sibilpanel"
cli uninstall --yes >"$TMP/uninstall-cli.log" 2>&1
require_absent "$HOME1"
require_file "$EXTERNAL_DB"
require_absent "$CLI_DST"
require_absent "$CADCONF/sibil-example.org.caddy"
require_absent "$NGINX_ENABLED/sibil-example.org"
require_absent "$CRON/sibil"
require_file "$CRON/sibilpanel"
require_file "$SYSTEMD/sibil-web.service"
require_file "$NGINX_CONF/sibil-example.org.conf"
require_contains "$CADDY" "legacy example.org must survive"
if grep -qF 'BEGIN SIBILPANEL-OWNED IMPORT' "$CADDY"; then
  fail "CLI uninstall left an unused owned Caddy import behind"
fi
CLI_RECOVERY="$(find "$BACKUPS" -type f -name sibil.sqlite3 -print -quit)"
if [[ -n "$CLI_RECOVERY" && "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT name FROM users WHERE id=7")->fetchColumn();' "$CLI_RECOVERY")" == restored ]]; then
  pass "CLI uninstall removes only owned resources and makes a verified recovery copy"
else
  fail "CLI uninstall did not make a usable recovery copy"
fi

# Installer uninstall receives the same ownership constraints, in a separate tree.
INST_HOME="$TMP/installer-home"
INST_SYSTEMD="$TMP/installer-systemd"
INST_CADDY="$TMP/installer-caddy/Caddyfile"
INST_CONF="$TMP/installer-caddy/conf.d"
INST_NGINX_ENABLED="$TMP/installer-nginx/enabled"
INST_NGINX_AVAILABLE="$TMP/installer-nginx/available"
INST_NGINX_CONF="$TMP/installer-nginx/conf.d"
INST_CRON="$TMP/installer-cron.d"
INST_CLI="$TMP/installer-bin/sibil"
INST_BACKUPS="$TMP/installer-backups"
mkdir -p "$INST_HOME" "$INST_SYSTEMD" "$INST_CONF" "$INST_NGINX_ENABLED" "$INST_NGINX_AVAILABLE" "$INST_NGINX_CONF" "$INST_CRON" "$(dirname "$INST_CLI")" "$INST_BACKUPS"
printf 'SIBILPANEL-OWNED\n' >"$INST_HOME/.sibil-owned"
printf 'PANEL_DOMAIN="installer.example.org"\n' >"$INST_HOME/.env"
printf "<?php const PANEL_VERSION = 'test';\n" >"$INST_HOME/sibil.php"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES(55,\"installer-recovery\");");' "$INST_HOME/sibil.sqlite3"
printf '# SIBILPANEL-OWNED\n' >"$INST_SYSTEMD/sibil.service"
printf '# unrelated service\n' >"$INST_SYSTEMD/sibil-web.service"
printf '#!/usr/bin/env bash\nPANEL_HOME_DEFAULT="%s"\n' "$INST_HOME" >"$INST_CLI"
cat >"$INST_CADDY" <<'EOF'
{}
# installer.example.org legacy must survive
# BEGIN SIBILPANEL-OWNED IMPORT
# SIBILPANEL-OWNED
import REPLACED_INSTALLER_CONF/*.caddy
# END SIBILPANEL-OWNED IMPORT
EOF
sed -i "s|REPLACED_INSTALLER_CONF|$INST_CONF|" "$INST_CADDY"
printf '# SIBILPANEL-OWNED\n' >"$INST_CONF/sibil-installer.example.org.caddy"
printf '# SIBILPANEL-OWNED\nserver { }\n' >"$INST_NGINX_CONF/sibil-installer.example.org.conf"
printf '# unrelated nginx config\n' >"$INST_NGINX_AVAILABLE/sibil-installer.example.org"
printf '# SIBILPANEL-OWNED\n* * * * * php %s/sibil.php --meter\n' "$INST_HOME" >"$INST_CRON/sibil"
printf '# unrelated cron\n' >"$INST_CRON/sibilpanel"
PANEL_HOME="$INST_HOME" PANEL_CLI_PATH="$INST_CLI" SIBIL_SYSTEMD_DIR="$INST_SYSTEMD" \
SIBIL_CADDYFILE="$INST_CADDY" SIBIL_CADDY_CONF_DIR="$INST_CONF" SIBIL_NGINX_SITES_ENABLED_DIR="$INST_NGINX_ENABLED" SIBIL_NGINX_SITES_AVAILABLE_DIR="$INST_NGINX_AVAILABLE" SIBIL_NGINX_CONF_DIR="$INST_NGINX_CONF" SIBIL_CRON_DIR="$INST_CRON" SIBIL_UNINSTALL_BACKUP_DIR="$INST_BACKUPS" SIBIL_DISABLE_SYSTEMD=1 \
bash "$INSTALLER" --uninstall --yes >"$TMP/uninstall-installer.log" 2>&1
require_absent "$INST_HOME"
require_absent "$INST_CLI"
require_absent "$INST_SYSTEMD/sibil.service"
require_file "$INST_SYSTEMD/sibil-web.service"
require_absent "$INST_CONF/sibil-installer.example.org.caddy"
require_absent "$INST_NGINX_CONF/sibil-installer.example.org.conf"
require_file "$INST_NGINX_AVAILABLE/sibil-installer.example.org"
require_absent "$INST_CRON/sibil"
require_file "$INST_CRON/sibilpanel"
require_contains "$INST_CADDY" "installer.example.org legacy must survive"
if grep -qF 'BEGIN SIBILPANEL-OWNED IMPORT' "$INST_CADDY"; then
  fail "installer uninstall left an unused owned Caddy import behind"
fi
INST_RECOVERY="$(find "$INST_BACKUPS" -type f -name sibil.sqlite3 -print -quit)"
if [[ -n "$INST_RECOVERY" && "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT name FROM users WHERE id=55")->fetchColumn();' "$INST_RECOVERY")" == installer-recovery ]]; then
  pass "installer uninstall removes only isolated owned resources and preserves a recovery copy"
else
  fail "installer uninstall did not preserve a usable recovery copy"
fi

printf '1..%d\n' "$passed"
[[ "$failed" -eq 0 ]]
