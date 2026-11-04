<?php
/** Regression: durable trial quota claims/resets and hourly-unlimited minimum balance. */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-trial-quota-' . getmypid();
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
function oktq(bool $condition, string $label): void
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

/** @return array<string,string> */
function tq_buttons(array $keyboard): array
{
    $buttons = [];
    foreach ($keyboard as $row) {
        foreach ($row as $button) {
            $buttons[(string) ($button['callback_data'] ?? '')] = (string) ($button['text'] ?? '');
        }
    }
    return $buttons;
}

function tq_callback(int $uid, string $data): void
{
    handle_callback([
        'id' => 'trial-quota-' . bin2hex(random_bytes(4)),
        'from' => ['id' => $uid, 'first_name' => 'Quota'],
        'message' => ['message_id' => 1, 'chat' => ['id' => $uid, 'type' => 'private']],
        'data' => $data,
    ]);
}

function tq_message(int $uid, string $text): void
{
    handle_message([
        'from' => ['id' => $uid, 'first_name' => 'Quota'],
        'chat' => ['id' => $uid, 'type' => 'private'],
        'message_id' => 2,
        'text' => $text,
    ]);
}

$tables = array_column(DB::all("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
$indexes = array_column(DB::all("SELECT name FROM sqlite_master WHERE type='index'"), 'name');
echo "\n== durable quota schema ==\n";
oktq(in_array('trial_quota_resets', $tables, true), 'reset marker table exists');
oktq(in_array('trial_quota_carryovers', $tables, true), 'open-claim carry-over table exists');
oktq(in_array('orders_trial_quota_idx', $indexes, true), 'trial claim query has a durable orders index');

$catId = cat_create('آزمون سهمیه', '🧪');
$trialProductId = DB::exec('INSERT INTO products(name, emoji, category, category_id, active, trial, '
    . 'created_at, max_users, device_limit, days, data_cap_gb, per_gb_price, hourly_price) '
    . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['محصول تست حجمی', '🧪', 'v2ray', $catId, 1, 1, now_ts(), 1, 1, 1, 1, 0, 0]);
set_put('admin_trial_unlimited', '0');
set_put('trial_per_user', '2');
set_put('trial_price', '777');

$uid = 771001;
user_ensure($uid, 'Trial Claim', 'trial_claim');
$payload = ['trial_gb' => 1, 'trial_hours' => 24, 'panel_username' => 'trialclaim'];
$first = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);
$second = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);
$third = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);

echo "\n== final transactional reservation ==\n";
oktq(!empty($first['ok']) && !empty($second['ok']) && empty($third['ok']),
    'only the configured two pending claims can be reserved');
oktq(trial_used($uid) === 2 && (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='trial'",
    [$uid], 0) === 2, 'pending claims count before delivery and block a third direct path');

/* Two independent PHP workers hit the final reservation at the same moment.
   The SQLite BEGIN IMMEDIATE in trial_quota_reserve_order must admit exactly one. */
$raceUid = 771009;
user_ensure($raceUid, 'Concurrent Claim', 'concurrent_claim');
set_put('trial_per_user', '1');
$raceResults = [];
if (function_exists('pcntl_fork') && function_exists('stream_socket_pair')) {
    $pipes = [];
    $pids = [];
    for ($i = 0; $i < 2; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            DB::close();
            fwrite($pair[1], 'R');
            fread($pair[1], 1);
            $result = trial_quota_reserve_order($raceUid, 777, $trialProductId, $payload);
            file_put_contents($home . '/race-' . $i . '.json', json_encode(['ok' => !empty($result['ok'])]));
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        $pipes[] = $pair[0];
        $pids[] = $pid;
    }
    foreach ($pipes as $pipe) {
        fread($pipe, 1);
    }
    foreach ($pipes as $pipe) {
        fwrite($pipe, 'G');
        fclose($pipe);
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::close();
    db_bootstrap();
    settings_bootstrap();
    for ($i = 0; $i < 2; $i++) {
        $raceResults[] = json_decode((string) @file_get_contents($home . '/race-' . $i . '.json'), true) ?: [];
        @unlink($home . '/race-' . $i . '.json');
    }
    $raceSuccesses = count(array_filter($raceResults, static fn(array $row): bool => !empty($row['ok'])));
    oktq($raceSuccesses === 1 && (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='trial'",
        [$raceUid], 0) === 1, 'concurrent final reservations cannot exceed a one-test quota');
} else {
    oktq(true, 'concurrent final reservation test skipped because pcntl is unavailable');
}
set_put('trial_per_user', '2');

order_mark((int) ($first['order']['id'] ?? 0), 'failed');
$replacement = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);
oktq(!empty($replacement['ok']) && trial_used($uid) === 2,
    'failed claims release capacity while the remaining live claims keep it reserved');
order_mark((int) ($second['order']['id'] ?? 0), 'delivered');
order_mark((int) ($replacement['order']['id'] ?? 0), 'delivered');
$deliveredIds = [(int) ($second['order']['id'] ?? 0), (int) ($replacement['order']['id'] ?? 0)];
$reset = trial_quota_reset($uid, 1);
$statusesAfterReset = DB::all("SELECT id, status FROM orders WHERE id IN (?,?) ORDER BY id", $deliveredIds);

echo "\n== administrator reset preserves history ==\n";
oktq(trial_used($uid) === 0 && (int) ($reset['active_claims'] ?? -1) === 0,
    'reset gives a fresh quota after historical delivered tests');
oktq(count($statusesAfterReset) === 2
    && (string) ($statusesAfterReset[0]['status'] ?? '') === 'delivered'
    && (string) ($statusesAfterReset[1]['status'] ?? '') === 'delivered',
    'reset does not falsify delivered trial orders as canceled');
$afterResetOne = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);
$afterResetTwo = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);
$afterResetThree = trial_quota_reserve_order($uid, 777, $trialProductId, $payload);
oktq(!empty($afterResetOne['ok']) && !empty($afterResetTwo['ok']) && empty($afterResetThree['ok']),
    'the new quota is again capped at the configured count');

$carryUid = 771002;
user_ensure($carryUid, 'Open Claim', 'open_claim');
set_put('trial_per_user', '1');
$open = trial_quota_reserve_order($carryUid, 777, $trialProductId, $payload);
$carryReset = trial_quota_reset($carryUid, 1);
order_mark((int) ($open['order']['id'] ?? 0), 'delivered');
$carriedCount = trial_used($carryUid);
$secondCarryReset = trial_quota_reset($carryUid, 1);

echo "\n== reset cannot lose an in-flight claim ==\n";
oktq(!empty($open['ok']) && (int) ($carryReset['active_claims'] ?? 0) === 1,
    'a live pending/review claim is retained across a reset');
oktq($carriedCount === 1, 'that pre-reset open order still consumes quota if delivered later');
oktq(trial_used($carryUid) === 0 && (int) ($secondCarryReset['active_claims'] ?? -1) === 0,
    'a later explicit reset can intentionally make that delivered history historical');

$staleUid = 771003;
user_ensure($staleUid, 'Stale Claim', 'stale_claim');
$stale = create_order($staleUid, 'trial', 777, $trialProductId, $payload);
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [trial_quota_pending_cutoff() - 1, (int) $stale['id']]);
trial_quota_reset($staleUid, 1);
oktq((string) DB::val('SELECT status FROM orders WHERE id=?', [(int) $stale['id']], '') === 'canceled',
    'only an expired pending trial is cleared by reset');

$customUid = 771004;
user_ensure($customUid, 'Custom Path', 'custom_path');
set_put('trial_per_user', '1');
set_put('trial_price', '777');
State::clear($customUid);
tq_callback($customUid, 'trial:go:' . $trialProductId);
tq_callback($customUid, 'uname:trial:' . $trialProductId . ':custom');
tq_message($customUid, 'customquota');
$customOrders = (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='trial'", [$customUid], 0);
start_trial_order($customUid, $customUid, null, $trialProductId, 'directquota');
$customOrdersAfterDirect = (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='trial'", [$customUid], 0);

echo "\n== delayed and custom-username paths ==\n";
oktq($customOrders === 1 && $customOrdersAfterDirect === 1,
    'custom username reserves at final creation and a later direct call cannot bypass it');

$hourlyProductId = DB::exec('INSERT INTO products(name, emoji, category, category_id, active, trial, '
    . 'created_at, sale_type, per_user_price, min_users, unlimited_billing_mode, min_balance, '
    . 'device_limit, slots_needed) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['نامحدود ساعتی حداقل موجودی', '♾️', 'v2ray', $catId, 1, 0, now_ts(),
     PRODUCT_SALE_UNLIMITED_USERS, 250, 1, UNLIMITED_BILLING_HOURLY, 600, 1, 1]);
$legacyProductId = DB::exec('INSERT INTO products(name, emoji, category, category_id, active, created_at, '
    . 'sale_type, per_user_price, min_users, unlimited_billing_mode, min_balance) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
    ['نامحدود قدیمی', '♾️', 'v2ray', $catId, 1, now_ts(), PRODUCT_SALE_UNLIMITED_USERS,
     250, 1, UNLIMITED_BILLING_LEGACY, 600]);

Tg::$outbox = [];
screen_product_admin(1, null, $hourlyProductId);
$hourlyAdmin = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
$hourlyButtons = tq_buttons($hourlyAdmin[1]['reply_markup']['inline_keyboard'] ?? []);
Tg::$outbox = [];
screen_product_admin(1, null, $legacyProductId);
$legacyAdmin = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
$legacyButtons = tq_buttons($legacyAdmin[1]['reply_markup']['inline_keyboard'] ?? []);
Tg::$outbox = [];
screen_product(771005, null, $hourlyProductId);
$hourlyCustomer = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];

$buyer = 771005;
user_ensure($buyer, 'Hourly Buyer', 'hourly_buyer');
$beforePurchase = (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='panel'", [$buyer], 0);
start_panel_order($buyer, $buyer, null, $hourlyProductId, 'hourlyminbal', 1);
$blockedPurchase = (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='panel'", [$buyer], 0);
add_balance($buyer, 600, 'test', 'hourly-minimum', 'test wallet');
start_panel_order($buyer, $buyer, null, $hourlyProductId, 'hourlyminbalok', 1);
$allowedOrder = DB::one("SELECT * FROM orders WHERE tg_id=? AND kind='panel' ORDER BY id DESC LIMIT 1", [$buyer]) ?? [];

echo "\n== hourly unlimited minimum wallet balance ==\n";
oktq(isset($hourlyButtons['ad:pf:' . $hourlyProductId . ':minbal'])
    && !isset($legacyButtons['ad:pf:' . $legacyProductId . ':minbal']),
    'only hourly unlimited administration exposes the minimum-balance editor');
oktq(str_contains((string) ($hourlyAdmin[1]['text'] ?? ''), 'حداقل موجودی کیف پول')
    && str_contains((string) ($hourlyCustomer[1]['text'] ?? ''), 'حداقل موجودی کیف پول'),
    'hourly unlimited admin and customer cards show the configured wallet threshold');
oktq($blockedPurchase === $beforePurchase, 'hourly unlimited purchase is blocked below its minimum wallet balance');
oktq((int) ($allowedOrder['product_id'] ?? 0) === $hourlyProductId
    && (int) ($allowedOrder['price'] ?? -1) === 0,
    'hourly unlimited purchase proceeds after the required wallet balance is present');

Tg::$outbox = [];
user_ensure(1, 'Owner', 'owner');
screen_user_card(1, null, $uid);
$userCard = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
$userButtons = tq_buttons($userCard[1]['reply_markup']['inline_keyboard'] ?? []);
echo "\n== administrator control ==\n";
oktq(isset($userButtons['ad:u:' . $uid . ':trialreset']), 'full administrator user card exposes the ریست تست control');

DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
