<?php
/** Regression: v3.18.7 per-client live-minute billing for unlimited panels. */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-online-unlimited-' . getmypid();
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

function make_hourly_unlimited_panel(int $uid, string $username, int $rate, int $created): int
{
    return DB::exec('INSERT INTO panels(tg_id, product_id, panel_username, panel_password, label, '
        . 'max_users, user_cap, status, created_at, is_unlimited, unlimited_user_price, '
        . 'unlimited_min_users, unlimited_billing_mode, hourly_price) '
        . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$uid, 0, $username, 'Pass#' . $username, 'آنلاین', 100, 100, 'active', $created,
         1, $rate, 1, UNLIMITED_BILLING_HOURLY, 999999]);
}

$tables = array_column(DB::all("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
$indexes = array_column(DB::all("SELECT name FROM sqlite_master WHERE type='index'"), 'name');
echo "\n== durable live-minute ledger ==\n";
okhu(in_array('unlimited_online_minutes', $tables, true), 'per-client minute ledger exists');
okhu(in_array('unlimited_online_billing_state', $tables, true), 'fractional-carry ledger exists');
okhu(in_array('unlimited_online_settlements', $tables, true), 'one-row-per-hour settlement ledger exists');
okhu(in_array('unlimited_online_minute_uq', $indexes, true), 'client-minute uniqueness is durable');

$uid = 881001;
user_ensure($uid, 'صورت‌حساب آنلاین', 'onlinebill');
add_balance($uid, 10000, 'test', 'seed', 'test wallet');
$hour = 1800000000 - (1800000000 % 3600);
$panelId = make_hourly_unlimited_panel($uid, 'online881', 750, $hour);
$panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]) ?? [];

/* One obsolete v3.18.6 capacity row must never affect the new price. */
unlimited_billing_unit_add($panelId, 9001, 'users', 100, 750, $hour);

$observations = [];
/* Client 101 is genuinely connected for 30 sampled minutes. */
for ($minute = 0; $minute < 30; $minute++) {
    $observations[] = ['id' => 101, 'username' => 'client-a', 'connections' => 1,
        'sampled_at' => $hour + $minute * 60 + 5];
}
/* Client 202 is distinct and connected for 15 different minutes. */
for ($minute = 45; $minute < 60; $minute++) {
    $observations[] = ['id' => 202, 'username' => 'client-b', 'connections' => 2,
        'sampled_at' => $hour + $minute * 60 + 10];
}
/* A known offline client is deliberately passed to the sampler but gets no row. */
$observations[] = ['id' => 303, 'username' => 'client-offline', 'connections' => 0,
    'sampled_at' => $hour + 20 * 60 + 10];
$firstSample = unlimited_online_record_observations($panel, $observations, $hour + 5);
/* A repeated invocation of exactly the same minute cannot double-record it. */
$repeatSample = unlimited_online_record_observations($panel, [
    ['id' => 101, 'username' => 'client-a', 'connections' => 5, 'sampled_at' => $hour + 5],
], $hour + 5);
$rows = DB::all('SELECT client_id, minute_at FROM unlimited_online_minutes WHERE panel_id=? ORDER BY client_id, minute_at',
    [$panelId]);
echo "\n== independent minute observations ==\n";
okhu($firstSample['active_minutes'] === 45 && count($rows) === 45,
    'only independently observed online client-minutes are stored');
okhu($repeatSample['active_minutes'] === 0 && count($rows) === 45,
    'same client/minute is idempotent even if it has more connections');
okhu((int) DB::val('SELECT COUNT(*) FROM unlimited_online_minutes WHERE panel_id=? AND client_id=303',
    [$panelId], 0) === 0, 'offline client has no billable minute');
okhu((int) DB::val('SELECT COUNT(DISTINCT client_id) FROM unlimited_online_minutes WHERE panel_id=?',
    [$panelId], 0) === 2, 'one client does not affect the other client ledger');

/* No report is sent while minutes are observed. It is sent only on hourly settlement. */
set_put('report_chat', '-100123456789');
Tg::$outbox = [];
$settled = unlimited_online_settle_due($panel, $hour + 3600 + 2);
$event = $settled['events'][0] ?? [];
$firstReport = unlimited_online_post_report($event);
$secondReport = unlimited_online_post_report($event);
$settlement = DB::one('SELECT * FROM unlimited_online_settlements WHERE panel_id=? AND hour_at=?',
    [$panelId, $hour]) ?? [];
$reportText = '';
foreach (Tg::$outbox as $row) {
    $reportText .= (string) ($row[1]['text'] ?? '');
}
echo "\n== hourly proration and aggregation ==\n";
okhu(($event['active_minutes'] ?? 0) === 45 && ($event['active_clients'] ?? 0) === 2,
    'hourly settlement aggregates active clients and their exact client-minutes');
okhu(($event['amount'] ?? -1) === 562 && ($event['remainder_after'] ?? -1) === 30,
    '750 toman/hour × 45 active minutes deducts 562 and carries the exact half-toman');
okhu(balance_of($uid) === 9438, 'wallet debit ignores the 100-slot capacity row and charges only active time');
okhu($firstReport && !$secondReport && substr_count($reportText, 'صورتحساب زمان آنلاین پنل نامحدود') === 1,
    'reports group receives one aggregate hourly report, never a minute-by-minute stream');
okhu(str_contains($reportText, '۴۵ دقیقه') || str_contains($reportText, '45 دقیقه'),
    'aggregate report contains active-minute information');
okhu(($settlement['status'] ?? '') === 'settled' && (int) ($settlement['reported_at'] ?? 0) > 0,
    'settlement/report marker prevents another report for the same sold panel/hour');

/* A full active hour must deduct the exact configured hourly price. */
$uid2 = 881002;
user_ensure($uid2, 'یک ساعت کامل', 'fullhour');
add_balance($uid2, 2000, 'test', 'seed-full', 'test wallet');
$panel2Id = make_hourly_unlimited_panel($uid2, 'full881', 750, $hour);
$panel2 = DB::one('SELECT * FROM panels WHERE id=?', [$panel2Id]) ?? [];
$full = [];
for ($minute = 0; $minute < 60; $minute++) {
    $full[] = ['id' => 404, 'username' => 'always-on', 'connections' => 1,
        'sampled_at' => $hour + $minute * 60 + 1];
}
unlimited_online_record_observations($panel2, $full, $hour);
$fullSettled = unlimited_online_settle_due($panel2, $hour + 3600 + 2);
$fullEvent = $fullSettled['events'][0] ?? [];
echo "\n== full-hour exact rate ==\n";
okhu(($fullEvent['active_minutes'] ?? 0) === 60 && ($fullEvent['amount'] ?? 0) === 750,
    'one full active client-hour deducts the full configured 750 toman rate');
okhu(balance_of($uid2) === 1250, 'full-hour deduction is isolated to its panel owner');

/* A temporarily empty wallet keeps the exact hour pending and later settles it;
   the active minute is never discarded or billed twice. */
$uid3 = 881003;
user_ensure($uid3, 'مانده ناکافی', 'shortwallet');
$panel3Id = make_hourly_unlimited_panel($uid3, 'short881', 750, $hour);
$panel3 = DB::one('SELECT * FROM panels WHERE id=?', [$panel3Id]) ?? [];
unlimited_online_record_observations($panel3, [
    ['id' => 505, 'username' => 'short-client', 'connections' => 1, 'sampled_at' => $hour + 60],
], $hour + 60);
$pending = unlimited_online_settle_due($panel3, $hour + 3600 + 2);
$balanceWhilePending = balance_of($uid3);
add_balance($uid3, 1000, 'test', 'topup', 'test wallet');
$paidLater = unlimited_online_settle_due($panel3, $hour + 3600 + 5);
$pendingRow = DB::one('SELECT * FROM unlimited_online_settlements WHERE panel_id=? AND hour_at=?',
    [$panel3Id, $hour]) ?? [];
echo "\n== insufficient balance preserves the client minute ==\n";
okhu(($pending['pending'] ?? false) && $balanceWhilePending === 0,
    'unpaid hourly aggregate stays pending instead of erasing or rounding its minute');
okhu(($paidLater['events'][0]['amount'] ?? 0) === 12 && ($pendingRow['status'] ?? '') === 'settled',
    'after top-up the same one-minute aggregate settles once using exact carry');
okhu(balance_of($uid3) === 988, 'late settlement does not duplicate the client minute');

/* Existing volume billing behavior is exercised by test_metered_product_callbacks;
   this test asserts the new live worker leaves a legacy unlimited snapshot alone. */
$legacyId = DB::exec('INSERT INTO panels(tg_id, product_id, panel_username, panel_password, label, '
    . 'status, created_at, is_unlimited, unlimited_user_price, unlimited_billing_mode) VALUES(?,?,?,?,?,?,?,?,?,?)',
    [$uid, 0, 'legacy881', 'Pass#legacy', 'قدیمی', 'active', $hour, 1, 750, UNLIMITED_BILLING_LEGACY]);
$legacy = DB::one('SELECT * FROM panels WHERE id=?', [$legacyId]) ?? [];
$legacyObserve = unlimited_online_record_observations($legacy, [
    ['id' => 999, 'username' => 'legacy', 'connections' => 1, 'sampled_at' => $hour + 1],
]);
echo "\n== compatibility boundaries ==\n";
okhu($legacyObserve['active_minutes'] === 0,
    'legacy unlimited snapshot does not enter the new live-minute engine');

@unlink($home . '/sibil.sqlite3');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail > 0 ? 1 : 0);
