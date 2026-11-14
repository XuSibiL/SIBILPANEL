<?php
/** Offline regressions: full Stars handshake and per-server customer login path. */
declare(strict_types=1);
$home = sys_get_temp_dir() . '/sibil-stars-login-' . getmypid();
@mkdir($home, 0700, true);
file_put_contents($home . '/.env', "PANEL_BOT_TOKEN=123456:TESTTOKEN\nPANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\n");
putenv('PANEL_HOME=' . $home); putenv('PANEL_ENV=' . $home . '/.env');
require __DIR__ . '/../sibil.php';
load_env(); db_bootstrap(); settings_bootstrap();
Tg::$dry = true;
$passed = 0; $failed = 0;
function okstars(bool $yes, string $label): void { global $passed, $failed; if ($yes) { ++$passed; echo "  ok $label\n"; } else { ++$failed; echo "FAIL $label\n"; } }
Tg::$callStub = static function (string $method, array $params): ?array {
    if ($method === 'createInvoiceLink') return ['ok' => true, 'result' => 'https://t.me/$mockStarsInvoice'];
    if ($method === 'getUpdates') return ['ok' => true, 'result' => []];
    return ['ok' => true, 'result' => ['message_id' => 1]];
};
set_put('pay_stars_on', '1'); set_put('pay_stars_daily_limit', '0'); set_put('pay_stars_cashback', '0');
set_put('swap_rates_cache', json_encode(['USDT' => 100000])); set_put('swap_rates_at', (string) now_ts());
$uid = 9189001; user_ensure($uid, 'Stars Buyer', 'starsbuyer');
$order = create_order($uid, 'topup', 250000, null, ['topup' => 250000]);
$link = pay_stars($order);
$payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='stars'", [(int) $order['id']]);
$meta = pay_meta($payment);
$query = ['id' => 'checkout-query-1', 'from' => ['id' => $uid],
    'invoice_payload' => $meta['stars_payload'], 'currency' => 'XTR', 'total_amount' => $meta['stars_amount']];
function answerstars(array $q): array {
    Tg::$outbox = []; handle_update(['pre_checkout_query' => $q]);
    $answers = array_values(array_filter(Tg::$outbox, static fn(array $r): bool => $r[0] === 'answerPreCheckoutQuery'));
    okstars(count($answers) === 1, 'one direct answer for query ' . (string) ($q['id'] ?? ''));
    return $answers[0][1] ?? [];
}
okstars(($link['ok'] ?? false) && $payment['status'] === 'unpaid', 'invoice creation remains unpaid');
Tg::$outbox = []; Tg::getUpdates(0);
okstars(in_array('pre_checkout_query', Tg::$outbox[0][1]['allowed_updates'] ?? [], true), 'polling explicitly subscribes to pre-checkout');
$answer = answerstars($query);
okstars(($answer['ok'] ?? false) === true, 'valid owner, payload, currency and snapshot accepted');
okstars(balance_of($uid) === 0 && pay_get((int) $payment['id'])['status'] === 'unpaid', 'pre-checkout never credits wallet');
foreach (['owner', 'amount', 'currency', 'payload', 'missing'] as $case) {
    $q = $query; $q['id'] .= '-' . $case;
    if ($case === 'owner') $q['from']['id']++;
    if ($case === 'amount') $q['total_amount']++;
    if ($case === 'currency') $q['currency'] = 'USD';
    if ($case === 'payload') $q['invoice_payload'] = 'order:999999:payment:999999';
    if ($case === 'missing') unset($q['invoice_payload']);
    $answer = answerstars($q); okstars(($answer['ok'] ?? true) === false && !empty($answer['error_message']), 'rejects ' . $case);
}
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts() - 7200, (int) $order['id']]);
$answer = answerstars($query); okstars(($answer['ok'] ?? true) === false, 'expired order is rejected before charging');
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts(), (int) $order['id']]);
set_put('pay_stars_daily_limit', '1');
$answer = answerstars($query); okstars(($answer['ok'] ?? true) === false, 'daily limit checked without a monetary write');
set_put('pay_stars_daily_limit', '0');
$successful = ['from' => ['id' => $uid], 'chat' => ['id' => $uid, 'type' => 'private'], 'successful_payment' => [
    'invoice_payload' => $meta['stars_payload'], 'currency' => 'XTR', 'total_amount' => $meta['stars_amount'],
    'telegram_payment_charge_id' => 'charge-stars-v3189']];
$wrong = $successful; $wrong['from']['id']++;
pay_stars_success($wrong); okstars(balance_of($uid) === 0, 'successful receipt for another buyer cannot credit');
$wrong = $successful; $wrong['successful_payment']['total_amount']++;
pay_stars_success($wrong); okstars(balance_of($uid) === 0, 'wrong paid amount cannot credit');
DB::exec('UPDATE users SET is_blocked=1 WHERE tg_id=?', [$uid]);
handle_update(['message' => $successful]);
okstars(balance_of($uid) === 250000 && pay_get((int) $payment['id'])['status'] === 'paid', 'already-charged receipt is not swallowed by blocked-account UI');
handle_update(['message' => $successful]);
okstars(balance_of($uid) === 250000, 'redelivered receipt never credits twice');
$answer = answerstars($query); okstars(($answer['ok'] ?? true) === false, 'paid invoice cannot be charged again');

Tg::$outbox = [];
handle_updates_batch([
    ['update_id' => 200, 'message' => ['from' => ['id' => $uid], 'chat' => ['id' => $uid, 'type' => 'private'], 'text' => '/start']],
    ['update_id' => 199, 'pre_checkout_query' => $query],
]);
okstars((Tg::$outbox[0][0] ?? '') === 'answerPreCheckoutQuery', 'pre-checkout runs before regular messages in the same batch');
Tg::$outbox = []; Tg::getUpdates(0);
okstars((Tg::$outbox[0][1]['offset'] ?? 0) === 201, 'prioritized batch advances to maximum update id without rewind');

$cases = [
    ['https://mrsibilman.ir:8000', '', 'https://mrsibilman.ir:8000/dashboard/'],
    ['https://mrsibilman.ir:8000', '/sibilbord/', 'https://mrsibilman.ir:8000/sibilbord/'],
    ['https://mrsibilman.ir:8000', '/dashboard/sibiljkhh', 'https://mrsibilman.ir:8000/dashboard/sibiljkhh'],
    ['https://mrsibilman.ir', '/sibilbord/', 'https://mrsibilman.ir/sibilbord/'],
    ['https://mrsibilman.ir:443', '/sibilbord/', 'https://mrsibilman.ir:443/sibilbord/'],
    ['https://mrsibilman.ir:8000/dashboard/', '/sibilbord/', 'https://mrsibilman.ir:8000/sibilbord/'],
    ['https://mrsibilman.ir:8000/some-old-ui', '/sibilbord/', 'https://mrsibilman.ir:8000/sibilbord/'],
    ['https://mrsibilman.ir', '/', 'https://mrsibilman.ir/'],
    ['https://[2001:db8::1]:8000', '/sibilbord/', 'https://[2001:db8::1]:8000/sibilbord/'],
];
foreach ($cases as [$base, $path, $expected]) okstars(panel_dashboard_url($base, $path) === $expected, 'customer URL: ' . $expected);
foreach (['//evil.test/', 'https://evil.test/', '/../x', '/a/../b', '/%2e%2e/a', '/%2fevil.test', '/a?next=x', '/a#b', '/a\\b', '/a%0ab', '/two words'] as $bad)
    okstars(panel_dashboard_path_normalize($bad) === null, 'unsafe path rejected: ' . $bad);
okstars(panel_dashboard_path_normalize('-') === '/dashboard/', 'reset uses default dashboard');
$pid = DB::exec('INSERT INTO pg_panels(name,url,api_key,created_at) VALUES(?,?,?,?)', ['Customer path', 'https://mrsibilman.ir:8000', 'mock-key', now_ts()]);
$row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$pid]);
okstars($row['dashboard_path'] === '/dashboard/', 'fresh connected panel gets default path');
wiz_finish(1, ['flow' => 'pg_loginpath', 'values' => ['dashboard_path' => '/sibilbord/'], 'ctx' => ['panel_id' => $pid]]);
$row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$pid]);
okstars($row['dashboard_path'] === '/sibilbord/' && $row['url'] === 'https://mrsibilman.ir:8000' && $row['api_key'] === 'mock-key', 'wizard saves only customer path, not API origin/key');
okstars(admin_callback_capability('ad:pg:' . $pid . ':loginpath') === 'full', 'callback requires full owner/co-owner permission');
wiz_finish($uid, ['flow' => 'pg_loginpath', 'values' => ['dashboard_path' => '/hijack/'], 'ctx' => ['panel_id' => $pid]]);
okstars(DB::val('SELECT dashboard_path FROM pg_panels WHERE id=?', [$pid]) === '/sibilbord/', 'wizard rechecks permission at save');
wiz_finish(1, ['flow' => 'pg_loginpath', 'values' => ['dashboard_path' => 'https://evil.test/'], 'ctx' => ['panel_id' => $pid]]);
okstars(DB::val('SELECT dashboard_path FROM pg_panels WHERE id=?', [$pid]) === '/sibilbord/', 'invalid path leaves saved setting unchanged');
// Exercise the actual delivery text, not only the URL helper.
DB::exec('UPDATE users SET is_blocked=0 WHERE tg_id=?', [$uid]);
$apiUrls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$apiUrls): array {
    $apiUrls[] = $url;
    if ($method === 'POST' && str_ends_with($url, '/api/admin')) {
        return [json_encode(['id' => 901, 'username' => json_decode((string) $body, true)['username']]), '', 200];
    }
    return [json_encode(['admins' => []]), '', 200];
};
$productId = DB::exec('INSERT INTO products(name,role_id,panel_id,created_at,data_cap_gb) VALUES(?,?,?,?,?)',
    ['Delivery path', 1, $pid, now_ts(), 5]);
foreach (['trial', 'buy'] as $kind) {
    $orderDelivery = create_order($uid, $kind, 0, $productId, ['trial_gb' => 5, 'trial_hours' => 23, 'panel_username' => 'path_' . $kind]);
    [$deliveryOk, $deliveryText] = deliver_panel_order($orderDelivery);
    okstars($deliveryOk && str_contains($deliveryText, 'https://mrsibilman.ir:8000/sibilbord/'), $kind . ' delivery uses connected panel custom path');
}
okstars(count(array_filter($apiUrls, static fn(string $url): bool => str_contains($url, '/sibilbord/'))) === 0, 'custom UI path never leaks into API calls');
unset($GLOBALS['panel_http_mock']);

// Simulate an existing installation predating the new additive column.
DB::exec('ALTER TABLE pg_panels DROP COLUMN dashboard_path'); db_bootstrap(); db_bootstrap();
$row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$pid]);
okstars($row['dashboard_path'] === '/dashboard/' && $row['api_key'] === 'mock-key', 'old-schema migration is additive, idempotent and preserves connection');
Tg::$callStub = null; DB::close();
foreach (glob($home . '/*') ?: [] as $p) if (is_file($p)) @unlink($p);
@unlink($home . '/.env'); @rmdir($home);
echo "\n$passed passed; $failed failed\n"; exit($failed ? 1 : 0);
