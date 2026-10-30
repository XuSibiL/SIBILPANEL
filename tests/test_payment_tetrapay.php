<?php
/** Payment gateway regression: documented TetraPay v1 return/verify safety. */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-payment-tetrapay-' . getmypid();
@mkdir($home, 0775, true);
file_put_contents($home . '/.env', "PANEL_BOT_TOKEN=123456:TESTTOKEN\n"
    . "PANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\n"
    . "PANEL_BOT_NAME=Sibil Test\n");
putenv('PANEL_HOME=' . $home);
putenv('PANEL_ENV=' . $home . '/.env');
require __DIR__ . '/../sibil.php';
load_env();
db_bootstrap();
settings_bootstrap();
Tg::$dry = true;
Tg::$outbox = [];
/* Tetra's documented bot callback must name this exact merchant bot. */
set_put('bot_username', 'SibilTestBot');
unset($GLOBALS['__bot_username_cache']);

$pass = 0;
$fail = 0;
function okpay(bool $condition, string $label): void
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

set_put('web_url', 'https://panel.example.test');
set_put('pay_tetrapay_key', 'test-api-key');
/* A stale legacy value must not alter the documented v1 checkout request:
   production setup itself needs only the API key and HTTPS return URL. */
set_put('pay_tetrapay_key2', 'test-webhook-secret');
set_put('pay_tetrapay_on', '1');

/* Keep the older public website callback harmless for pre-existing links. New
   Tetra bot checkouts use Telegram's documented t.me return URL instead. */
$balanceBeforeProbe = balance_of(771001);
$_SERVER = ['REQUEST_URI' => '/pay/tetrapay', 'REQUEST_METHOD' => 'GET'];
$_GET = [];
$_POST = [];
http_response_code(200);
ob_start();
web_handle();
$probeHtml = (string) ob_get_clean();
/* Tetra may validate callback_url with a generated token before its create
   response gives SIBIL that token. Unknown tokens must get a harmless 200,
   not a 404 that the provider interprets as INVALID_CALLBACK. */
$_SERVER = ['REQUEST_URI' => '/pay/tetrapay?token=provider_probe_token&status=created',
    'REQUEST_METHOD' => 'GET'];
$_GET = ['token' => 'provider_probe_token', 'status' => 'created'];
$_POST = [];
http_response_code(200);
ob_start();
web_handle();
$unknownProbeHtml = (string) ob_get_clean();
echo "\n== callback preflight ==\n";
okpay(str_contains($probeHtml, 'payment return is online'),
    'legacy public Tetra callback probe receives a healthy response');
okpay(str_contains($probeHtml, 'No wallet or order has been credited')
    && balance_of(771001) === $balanceBeforeProbe,
    'callback health response cannot credit a wallet or claim payment success');
okpay(http_response_code() === 200 && str_contains($unknownProbeHtml, 'payment return is online')
    && balance_of(771001) === $balanceBeforeProbe,
    'unknown legacy token callback receives non-settling HTTP 200');
okpay(tetrapay_bot_username() === 'SibilTestBot'
    && tetrapay_start_payload_valid('tp_17_abcdefghijklmnop')
    && !tetrapay_start_payload_valid('paid-17'),
    'Tetra bot callback requires a valid exact Telegram username and start payload');

$calls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = [$method, $url, $headers, $body];
    if ($method === 'POST' && str_ends_with($url, '/payments')) {
        $request = json_decode((string) $body, true) ?: [];
        return [json_encode(['data' => [
            'token' => 'tp_token_123456',
            'payment_url' => 'https://pay.example.test/tp_token_123456',
            'bot_payment_url' => 'https://t.me/TetraBot?start=tp_token_123456',
            'order_id' => $request['order_id'] ?? '',
            'amount' => $request['amount'] ?? '',
        ]], JSON_UNESCAPED_UNICODE), '', 201];
    }
    if ($method === 'POST' && str_contains($url, '/payments/tp_token_123456/verify')) {
        $payment = DB::one("SELECT * FROM payments WHERE authority='tp_token_123456' ORDER BY id DESC LIMIT 1");
        $meta = $payment ? pay_meta($payment) : [];
        return [json_encode(['data' => [
            'status' => 'paid',
            'order_id' => $meta['tetrapay_order_id'] ?? '',
            'amount' => $meta['tetrapay_amount_rial'] ?? '',
            'received_amount' => $meta['tetrapay_amount_rial'] ?? '',
            'transaction_id' => 'tp-ref-100',
        ]], JSON_UNESCAPED_UNICODE), '', 200];
    }
    return [json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'not found']]), '', 404];
};

$uid = 771001;
user_ensure($uid, 'تستر', 'tester');
$order = create_order($uid, 'topup', 25000, null, ['topup' => 25000]);

$created = pay_start($order, 'tetrapay');
$payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='tetrapay'", [(int) $order['id']]);
$meta = $payment ? pay_meta($payment) : [];
$request = json_decode((string) ($calls[0][3] ?? ''), true) ?: [];
$headers = implode("\n", $calls[0][2] ?? []);

echo "\n== create ==\n";
okpay(($created['ok'] ?? false) === true, 'TetraPay creates a hosted checkout');
okpay(($created['url'] ?? '') === 'https://pay.example.test/tp_token_123456', 'hosted URL is preserved');
okpay(($created['alt_url'] ?? '') === 'https://t.me/TetraBot?start=tp_token_123456', 'Telegram checkout URL is available');
okpay(($request['amount'] ?? '') === '250000', 'internal toman amount is sent to TetraPay as Rial');
okpay(str_starts_with((string) ($request['order_id'] ?? ''), 'sibil-')
    && strlen((string) ($request['order_id'] ?? '')) <= 64
    && preg_match('/^[A-Za-z0-9_.:-]+$/D', (string) ($request['order_id'] ?? '')) === 1,
    'provider order ID is payment-specific and meets Tetra v1 syntax/length');
$longTetraOrder = tetrapay_external_order_id(['ref' => str_repeat('A', 120)], ['id' => 987654]);
okpay(strlen($longTetraOrder) <= 64 && str_ends_with($longTetraOrder, '-987654'),
    'long legacy order references retain the local payment id inside Tetra’s 64-character limit');
$startPayload = (string) ($meta['tetrapay_start'] ?? '');
okpay(tetrapay_base_url() === 'https://api.tetra98.com/api/v1',
    'TetraPay uses its fixed production v1 API endpoint');
okpay(tetrapay_start_payload_valid($startPayload)
    && ($request['callback_url'] ?? '') === 'https://t.me/SibilTestBot?start=' . $startPayload,
    'TetraPay receives the documented registered-bot Telegram callback URL');
okpay(!str_contains((string) ($request['callback_url'] ?? ''), '/pay/tetrapay')
    && strlen((string) ($request['callback_url'] ?? '')) <= 128,
    'Tetra bot callback never substitutes the public website return path');
okpay(array_keys($request) === ['amount', 'order_id', 'callback_url']
    && !isset($request['webhook_url']) && !isset($request['allow_partial']),
    'Tetra v1 create request contains only documented amount, order_id, and callback_url fields');
okpay(str_contains($headers, 'Authorization: Bearer test-api-key'), 'API authorization header is used');
okpay(($meta['tetrapay_amount_rial'] ?? '') === '250000', 'Rial amount snapshot is stored with the payment');
$createdNotice = pay_created_notice($order, $payment ?? []);
okpay(str_contains($createdNotice, 'Verify/Webhook')
    && !str_contains($createdNotice, 'به کیف پول شما واریز گردید'),
    'created notice never claims an early wallet credit');

$again = pay_start($order, 'tetrapay');
okpay(($again['reused'] ?? false) === true && count($calls) === 1, 'reselecting gateway reuses the same unpaid checkout');

Tg::$outbox = [];
handle_message([
    'from' => ['id' => $uid, 'first_name' => 'تستر', 'username' => 'tester'],
    'chat' => ['id' => $uid, 'type' => 'private'], 'message_id' => 11,
    'text' => '/start ' . $startPayload,
]);
$paid = $payment ? pay_get((int) $payment['id']) : null;
echo "\n== Telegram return, verify and idempotence ==\n";
okpay(($paid['status'] ?? '') === 'paid', 'Telegram callback settles only after provider verify');
okpay(balance_of($uid) === 25000, 'verified Telegram return credits exactly once');
$outboxText = implode("\n", array_map(static fn(array $row): string => (string) ($row[1]['text'] ?? ''), Tg::$outbox));
okpay(str_contains($outboxText, 'پرداخت شما با موفقیت و به‌صورت قطعی تأیید شد')
    && str_contains($outboxText, 'به کیف پول شما واریز گردید'),
    'success and wallet-credit notices are emitted only after verify');
$repeat = pay_tetrapay_verify($paid ?? [], ['token' => 'tp_token_123456']);
okpay(($repeat['state'] ?? '') === 'already' && balance_of($uid) === 25000, 'repeat verify cannot credit the wallet twice');
Tg::$outbox = [];
handle_message([
    'from' => ['id' => $uid, 'first_name' => 'تستر', 'username' => 'tester'],
    'chat' => ['id' => $uid, 'type' => 'private'], 'message_id' => 12,
    'text' => '/start ' . $startPayload,
]);
okpay(balance_of($uid) === 25000, 'reopened Telegram return cannot credit a second time');

/* A legacy signed webhook remains server-verified and idempotent, even though new v1 checkout creation no longer sends a webhook_url. */
$order2 = create_order($uid, 'topup', 25000, null, ['topup' => 25000]);
$created2 = pay_start($order2, 'tetrapay');
$payment2 = DB::one("SELECT * FROM payments WHERE order_id=? AND method='tetrapay'", [(int) $order2['id']]);
$meta2 = $payment2 ? pay_meta($payment2) : [];
$otherUid = $uid + 1;
$beforeForeignStart = count($calls);
handle_message([
    'from' => ['id' => $otherUid, 'first_name' => 'دیگری', 'username' => 'other'],
    'chat' => ['id' => $otherUid, 'type' => 'private'], 'message_id' => 13,
    'text' => '/start ' . (string) ($meta2['tetrapay_start'] ?? ''),
]);
$payment2AfterForeign = $payment2 ? pay_get((int) $payment2['id']) : null;
okpay(($payment2AfterForeign['status'] ?? '') === 'unpaid' && count($calls) === $beforeForeignStart,
    'another Telegram user cannot inspect or verify a buyer-bound Tetra return');
$event = [
    'event_id' => 'evt_test_1',
    'event' => 'payment.paid',
    'status' => 'paid',
    'token' => 'tp_token_123456',
    'order_id' => $meta2['tetrapay_order_id'] ?? '',
    'amount' => $meta2['tetrapay_amount_rial'] ?? '',
];
$raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$timestamp = time();
$signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $raw, 'test-webhook-secret');
$hook = pay_tetrapay_webhook($event, $raw, $signature);
$hookAgain = pay_tetrapay_webhook($event, $raw, $signature);
$bad = pay_tetrapay_webhook($event, $raw, 't=' . $timestamp . ',v1=' . str_repeat('0', 64));
echo "\n== signed webhook ==\n";
okpay(($created2['ok'] ?? false) === true, 'second checkout is created for webhook test');
okpay(($hook['ok'] ?? false) === true && ($hook['code'] ?? 0) === 200, 'valid signed webhook verifies then settles');
okpay(($hookAgain['ok'] ?? false) === true && ($hookAgain['text'] ?? '') === 'already', 'duplicate webhook is idempotent');
okpay(balance_of($uid) === 50000, 'webhook adds the second top-up exactly once');
okpay(($bad['ok'] ?? true) === false && ($bad['code'] ?? 0) === 403, 'invalid webhook signature is rejected');

/* Simulate the narrow failure window this release repairs: provider verify
   already made a Tetra payment paid, but the process stopped before wallet
   delivery. The buyer-bound Telegram return can only re-enter the durable
   local top-up claim; it performs no new provider verify and cannot duplicate. */
$recoveryOrder = create_order($uid, 'topup', 25000, null, ['topup' => 25000]);
$recoveryPayment = pay_new($recoveryOrder, 'tetrapay', 25000);
pay_prepare($recoveryPayment, $recoveryOrder);
$recoveryPayment = pay_get((int) $recoveryPayment['id']) ?? $recoveryPayment;
$recoveryStart = tetrapay_start_payload($recoveryPayment);
DB::exec("UPDATE payments SET status='paid', paid_at=? WHERE id=?", [now_ts(), (int) $recoveryPayment['id']]);
$recoveryCallsBefore = count($calls);
/* The five-minute server sweep is the callback-independent recovery route. */
$recoverySweep = payments_poll();
$recoverySweepAgain = payments_poll();
/* A later buyer return sees only the already-authoritative paid row; it cannot
   invoke provider verification or create another credit. */
$recoveryFirst = pay_tetrapay_start_return($uid, $uid, $recoveryStart);
$recoveryAgain = pay_tetrapay_start_return($uid, $uid, $recoveryStart);
$recoveryOrderAfter = order_get((int) $recoveryOrder['id']) ?? [];
$recoveryTx = (int) DB::val("SELECT COUNT(*) FROM wallet_tx WHERE tg_id=? AND kind='topup' AND ref=?",
    [$uid, (string) $recoveryOrder['id']], 0);
echo "\n== paid Tetra delivery recovery ==\n";
okpay($recoverySweep === 1 && $recoverySweepAgain === 0 && $recoveryFirst && $recoveryAgain
    && ($recoveryOrderAfter['status'] ?? '') === 'delivered'
    && balance_of($uid) === 75000 && $recoveryTx === 1 && count($calls) === $recoveryCallsBefore,
    'a paid Tetra row with pending wallet delivery is repaired once by the server sweep without treating Telegram return as payment proof');

/* The administrative check is intentionally local-only: no checkout is created. */
$beforeCheck = count($calls);
$safe = gw_live_test('tetrapay');
echo "\n== safe admin check ==\n";
okpay(($safe['ok'] ?? false) === true, 'safe admin check validates configured TetraPay endpoints');
okpay(count($calls) === $beforeCheck, 'safe admin check never contacts or creates a provider payment');

Tg::$outbox = [];
screen_pay_gateway($uid, null, 'tetrapay');
$adminUi = json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
okpay(str_contains($adminUi, 'کلید اصلی') && !str_contains($adminUi, ':endpoint')
    && !str_contains($adminUi, ':key2') && !str_contains($adminUi, 'سندباکس'),
    'TetraPay admin setup exposes one API key with no endpoint, key2, or sandbox control');
$callbackError = tetrapay_error(['error' => ['code' => 'INVALID_CALLBACK']]);
okpay(str_contains($callbackError, 'https://t.me/') && str_contains($callbackError, '@SibilTestBot'),
    'provider callback rejection names the documented Telegram-bot return and actual bot');

unset($GLOBALS['panel_http_mock']);
DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
