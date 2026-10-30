<?php
/** Payment gateway regression: TetraPay v1 + notification safety. */
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
/* A legacy secret may still secure an optional webhook, but production setup
   itself needs only the API key. There is intentionally no sandbox setting. */
set_put('pay_tetrapay_key2', 'test-webhook-secret');
set_put('pay_tetrapay_on', '1');

/* Trusted installation values outrank the legacy configurable web_url. */
putenv('PANEL_WEB_URL=https://runtime.example.test/sibil');
putenv('PANEL_DOMAIN=domain.example.test');
$trustedCallback = tetrapay_callback_url();
putenv('PANEL_WEB_URL');
putenv('PANEL_DOMAIN');

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
okpay(str_starts_with((string) ($request['order_id'] ?? ''), 'sibil-'), 'provider order ID is payment-specific');
okpay(tetrapay_base_url() === 'https://api.tetra98.com/api/v1'
    && $trustedCallback === 'https://runtime.example.test/sibil/pay/tetrapay',
    'TetraPay uses fixed production v1 and trusted PANEL_WEB_URL callback priority');
okpay(($request['callback_url'] ?? '') === 'https://panel.example.test/pay/tetrapay', 'HTTPS callback is generated');
okpay(($request['webhook_url'] ?? '') === 'https://panel.example.test/pay/tetrapay/webhook', 'signed webhook URL is generated only with a secret');
okpay(str_contains($headers, 'Authorization: Bearer test-api-key'), 'API authorization header is used');
okpay(($meta['tetrapay_amount_rial'] ?? '') === '250000', 'Rial amount snapshot is stored with the payment');
$createdNotice = pay_created_notice($order, $payment ?? []);
okpay(str_contains($createdNotice, 'Verify/Webhook')
    && !str_contains($createdNotice, 'به کیف پول شما واریز گردید'),
    'created notice never claims an early wallet credit');

$again = pay_start($order, 'tetrapay');
okpay(($again['reused'] ?? false) === true && count($calls) === 1, 'reselecting gateway reuses the same unpaid checkout');

$verified = pay_tetrapay_verify($payment ?? [], ['token' => 'tp_token_123456']);
$paid = $payment ? pay_get((int) $payment['id']) : null;
echo "\n== verify and idempotence ==\n";
okpay(($verified['ok'] ?? false) === true && ($verified['state'] ?? '') === 'success', 'provider verify settles the payment');
okpay(($paid['status'] ?? '') === 'paid', 'settlement marks the payment as paid');
okpay(balance_of($uid) === 25000, 'verified top-up credits exactly once');
$outboxText = implode("\n", array_map(static fn(array $row): string => (string) ($row[1]['text'] ?? ''), Tg::$outbox));
okpay(str_contains($outboxText, 'پرداخت شما با موفقیت و به‌صورت قطعی تأیید شد')
    && str_contains($outboxText, 'به کیف پول شما واریز گردید'),
    'success and wallet-credit notices are emitted only after verify');
$repeat = pay_tetrapay_verify($paid ?? [], ['token' => 'tp_token_123456']);
okpay(($repeat['state'] ?? '') === 'already' && balance_of($uid) === 25000, 'repeat verify cannot credit the wallet twice');

/* A second checkout exercises signed webhook processing and duplicate delivery. */
$order2 = create_order($uid, 'topup', 25000, null, ['topup' => 25000]);
$created2 = pay_start($order2, 'tetrapay');
$payment2 = DB::one("SELECT * FROM payments WHERE order_id=? AND method='tetrapay'", [(int) $order2['id']]);
$meta2 = $payment2 ? pay_meta($payment2) : [];
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
okpay(str_contains(tetrapay_error(['error' => ['code' => 'INVALID_CALLBACK']]), 'نصب'),
    'provider callback rejection receives a practical installation remedy');

unset($GLOBALS['panel_http_mock']);
DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
