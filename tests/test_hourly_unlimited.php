<?php
/** Regression: configurable hourly-unlimited snapshots and component meter. */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-hourly-unlimited-' . getmypid();
@mkdir($home, 0775, true);
file_put_contents($home . '/.env', "PANEL_BOT_TOKEN=123456:TESTTOKEN\n"
    . "PANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\n");
putenv('PANEL_HOME=' . $home);
putenv('PANEL_ENV=' . $home . '/.env');
require __DIR__ . '/../sibil.php';
load_env();
db_bootstrap();
settings_bootstrap();
Tg::$dry = true;
Tg::$outbox = [];

$pass = 0;
$fail = 0;
function okhu(bool $condition, string $label): void
{
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "  ok   $label\n";
        return;
    }
    ++$fail;
    echo "  FAIL $label\n";
}

$columnsProducts = array_column(DB::all('PRAGMA table_info(products)'), 'name');
$columnsPanels = array_column(DB::all('PRAGMA table_info(panels)'), 'name');
$hasUnits = (int) DB::val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='unlimited_billing_units'", [], 0) === 1;
echo "\n== compatible schema ==\n";
okhu(in_array('unlimited_billing_mode', $columnsProducts, true), 'products persist billing-mode snapshots');
okhu(in_array('unlimited_billing_mode', $columnsPanels, true), 'sold panels persist billing-mode snapshots');
okhu($hasUnits, 'component table exists for independent paid-through clocks');

$uid = 881001;
user_ensure($uid, 'صورت‌حساب', 'hourlytest');
$legacyProductId = DB::exec('INSERT INTO products(name, emoji, active, created_at, sale_type, '
    . 'per_user_price, min_users) VALUES(?,?,?,?,?,?,?)',
    ['قدیمی', '♾️', 1, now_ts(), PRODUCT_SALE_UNLIMITED_USERS, 100, 2]);
$hourlyProductId = DB::exec('INSERT INTO products(name, emoji, active, created_at, sale_type, '
    . 'per_user_price, hourly_price, min_users, unlimited_billing_mode) VALUES(?,?,?,?,?,?,?,?,?)',
    ['ساعتی', '♾️', 1, now_ts(), PRODUCT_SALE_UNLIMITED_USERS, 125, 42, 3,
     UNLIMITED_BILLING_HOURLY]);
$legacyProduct = product_get($legacyProductId) ?? [];
$hourlyProduct = product_get($hourlyProductId) ?? [];
echo "\n== immutable plan economics ==\n";
okhu(!product_is_hourly_unlimited($legacyProduct), 'existing/default unlimited product remains legacy');
okhu(product_is_hourly_unlimited($hourlyProduct), 'explicit new product is hourly');
okhu(unlimited_initial_hourly_total($hourlyProduct, 3) === 417,
    'first invoice combines fixed hourly rate plus requested users');

$now = now_ts();
$hourlyPanelId = DB::exec('INSERT INTO panels(tg_id, product_id, panel_username, panel_password, '
    . 'label, max_users, user_cap, status, created_at, is_unlimited, unlimited_user_price, '
    . 'unlimited_min_users, unlimited_billing_mode, hourly_price, billing_start_at) '
    . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [$uid, $hourlyProductId, 'hourly881', 'Pass#881', 'ساعتی', 3, 3, 'active', $now, 1,
     125, 3, UNLIMITED_BILLING_HOURLY, 42, $now]);
/* Purchase paid hour #1: it becomes due at $now; component rows are independent. */
unlimited_billing_unit_add($hourlyPanelId, 10001, 'base', 1, 42, $now);
unlimited_billing_unit_add($hourlyPanelId, 10001, 'users', 3, 125, $now);
add_balance($uid, 1000, 'test', 'seed', 'test wallet');
$hourlyPanel = DB::one('SELECT * FROM panels WHERE id=?', [$hourlyPanelId]) ?? [];
$first = unlimited_hourly_charge_once($hourlyPanel, $now);
$balanceAfterFirst = balance_of($uid);
$unitsAfterFirst = DB::all('SELECT * FROM unlimited_billing_units WHERE panel_id=? ORDER BY component', [$hourlyPanelId]);
$repeat = unlimited_hourly_charge_once($hourlyPanel, $now);
echo "\n== hourly meter ==\n";
okhu($first['charged'] === 417 && $first['hours'] === 2, 'one due period bills fixed and user components once');
okhu($balanceAfterFirst === 583, 'hourly meter debits exactly the combined rate');
okhu(count($unitsAfterFirst) === 2 && min(array_column($unitsAfterFirst, 'paid_until')) > $now,
    'paid-through clocks advance after a successful debit');
okhu($repeat['charged'] === 0 && balance_of($uid) === 583,
    'same meter instant is idempotent and cannot double-debit');

/* New capacity starts at a later clock: it cannot be charged at the old panel period. */
unlimited_billing_unit_add($hourlyPanelId, 10002, 'users', 2, 125, $now + 7200);
$beforeFuture = balance_of($uid);
$notYet = unlimited_hourly_charge_once($hourlyPanel, $now + 3600);
$futureUnit = DB::one('SELECT * FROM unlimited_billing_units WHERE panel_id=? AND source_order_id=?',
    [$hourlyPanelId, 10002]) ?? [];
echo "\n== later capacity ==\n";
okhu($notYet['charged'] === 417, 'original capacity is charged at its next due hour');
okhu($futureUnit['paid_until'] === $now + 7200, 'later capacity has not been retroactively charged');
okhu(balance_of($uid) === $beforeFuture - 417, 'later capacity does not inflate old-period charge');

/* Insufficient balance preserves the unpaid user component, suspends once, then resumes only after it is paid. */
DB::exec('UPDATE unlimited_billing_units SET paid_until=? WHERE panel_id=? AND source_order_id=?',
    [$now + 3600, $hourlyPanelId, 10001]);
DB::exec('UPDATE unlimited_billing_units SET paid_until=? WHERE panel_id=? AND source_order_id=?',
    [$now + 7200, $hourlyPanelId, 10002]);
DB::exec('UPDATE users SET balance=0 WHERE tg_id=?', [$uid]);
$hourlyPanel = DB::one('SELECT * FROM panels WHERE id=?', [$hourlyPanelId]) ?? [];
$short = unlimited_hourly_charge_once($hourlyPanel, $now + 3600);
$shortPanel = DB::one('SELECT * FROM panels WHERE id=?', [$hourlyPanelId]) ?? [];
add_balance($uid, 1000, 'test', 'topup-after-short', 'test wallet');
$recovered = unlimited_hourly_charge_once($shortPanel, $now + 3600);
$recoveredPanel = DB::one('SELECT * FROM panels WHERE id=?', [$hourlyPanelId]) ?? [];
echo "\n== low balance handling ==\n";
okhu($short['insufficient'] && ($shortPanel['status'] ?? '') === 'suspended'
    && ($shortPanel['suspended_reason'] ?? '') === 'hourly_balance',
    'unpaid hourly component suspends only with an hourly-balance reason');
okhu($recovered['resumed'] && ($recoveredPanel['status'] ?? '') === 'active',
    'after sufficient wallet funding, paid hourly component resumes the service');

/* Legacy unlimited panels remain economically untouched by the new meter. */
$legacyPanelId = DB::exec('INSERT INTO panels(tg_id, product_id, panel_username, panel_password, '
    . 'label, max_users, user_cap, status, created_at, is_unlimited, unlimited_user_price, '
    . 'unlimited_min_users) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
    [$uid, $legacyProductId, 'legacy881', 'Pass#882', 'قدیمی', 2, 2, 'active', $now, 1, 100, 2]);
$legacyPanel = DB::one('SELECT * FROM panels WHERE id=?', [$legacyPanelId]) ?? [];
$legacyMeter = unlimited_hourly_charge_once($legacyPanel, $now + 999999);
echo "\n== legacy preservation ==\n";
okhu(!panel_is_hourly_unlimited($legacyPanel) && $legacyMeter['charged'] === 0,
    'legacy sold panel never enters the new hourly meter');

DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
