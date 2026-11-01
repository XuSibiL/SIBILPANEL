<?php
/**
 * Regression coverage for the API-only crypto checkout lifecycle: visibility,
 * NowPayments API confirmation, Plisio poll confirmation, and safe reporting
 * of a failed checkout without exposing provider detail to the buyer.
 */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-crypto-lifecycle-' . getmypid();
@mkdir($home, 0775, true);
file_put_contents($home . '/.env', "PANEL_BOT_TOKEN=123456:TESTTOKEN\n"
    . "PANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\nPANEL_BOT_NAME=Sibil Test\n");
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
function okcrypto(bool $condition, string $label): void
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

function crypto_outbox_text(): string
{
    return implode("\n", array_map(static fn(array $row): string => (string) ($row[1]['text'] ?? ''), Tg::$outbox));
}

set_put('web_url', 'https://panel.example.test');
set_put('swap_rates_cache', json_encode(['USDT' => 100000]));
set_put('swap_rates_at', (string) now_ts());
set_put('report_chat', '-1001234567890');
set_put('report_topics', json_encode(['error' => 777]));
set_put('report_topics_on', '1');
foreach (['nowpayment', 'plisio'] as $gateway) {
    set_put('pay_' . $gateway . '_on', '1');
    set_put('pay_' . $gateway . '_key', 'fixture-' . $gateway . '-api-key');
    set_put('pay_' . $gateway . '_min', '20000');
    set_put('pay_' . $gateway . '_max', '1000000');
    set_put('pay_' . $gateway . '_cashback', '0');
    set_put('pay_' . $gateway . '_min_fiat', '0');
}

$requests = [];
$nowCreateFailure = false;
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body)
    use (&$requests, &$nowCreateFailure): array {
    $requests[] = [$method, $url, $headers, $body];
    if ($method === 'POST' && str_contains($url, 'api.nowpayments.io/v1/invoice')) {
        if ($nowCreateFailure) {
            return [json_encode(['message' => 'provider rejected fixture-nowpayment-api-key']), '', 401];
        }
        return [json_encode(['id' => 'now-invoice-1',
            'invoice_url' => 'https://checkout.now.example.test/now-invoice-1']), '', 200];
    }
    if ($method === 'GET' && str_contains($url, 'api.nowpayments.io/v1/invoice/now-invoice-1')) {
        return [json_encode(['payment_status' => 'finished', 'price_amount' => 0.5]), '', 200];
    }
    if ($method === 'GET' && str_contains($url, 'api.plisio.net/api/v1/invoices/new?')) {
        return [json_encode(['status' => 'success', 'data' => ['txn_id' => 'plisio-txn-1',
            'invoice_url' => 'https://checkout.plisio.example.test/plisio-txn-1']]), '', 200];
    }
    if ($method === 'GET' && str_contains($url, 'api.plisio.net/api/v1/operations?')) {
        return [json_encode(['status' => 'success', 'data' => ['operations' => [[
            'txn_id' => 'plisio-txn-1', 'status' => 'completed', 'source_amount' => 0.5,
            'order_number' => 'ORDER-PLISIO',
        ]]]]), '', 200];
    }
    return [json_encode(['message' => 'unexpected fixture request']), '', 404];
};

$uid = 883001;
user_ensure($uid, 'Crypto Tester', 'crypto_tester');

echo "\n== API-key-only visibility ==\n";
[$nowReady, $nowMissing] = gw_readiness('nowpayment');
okcrypto($nowReady && $nowMissing === [] && isset(gw_available($uid)['nowpayment']),
    'NowPayments is visible with its API key and no IPN secret');
set_put('pay_nowpayment_on', '0');
okcrypto(!isset(gw_available($uid)['nowpayment']),
    'NowPayments disappears immediately when its toggle is off');
set_put('pay_nowpayment_on', '1');
okcrypto(isset(gw_available($uid)['nowpayment']),
    'NowPayments returns immediately when its toggle is on');
set_put('pay_plisio_on', '0');
okcrypto(!isset(gw_available($uid)['plisio']),
    'Plisio disappears immediately when its toggle is off');
set_put('pay_plisio_on', '1');
okcrypto(isset(gw_available($uid)['plisio']),
    'Plisio returns immediately when its toggle is on');

Tg::$outbox = [];
screen_pay_gateway($uid, null, 'nowpayment');
$nowAdminUi = json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
okcrypto(str_contains($nowAdminUi, ':key') && !str_contains($nowAdminUi, ':key2')
    && !str_contains($nowAdminUi, 'IPN secret'),
    'NowPayments screen contains only the primary API-key control');
Tg::$outbox = [];
screen_pay_gateway($uid, null, 'plisio');
$plisioAdminUi = json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
okcrypto(str_contains($plisioAdminUi, ':key') && !str_contains($plisioAdminUi, ':key2'),
    'Plisio screen contains one API-key control and no secondary-secret control');

echo "\n== NowPayments API verification and delivery ==\n";
$nowOrder = create_order($uid, 'topup', 50000, null, ['topup' => 50000]);
$nowCreated = pay_start($nowOrder, 'nowpayment');
$nowPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='nowpayment'",
    [(int) $nowOrder['id']]);
$nowHook = pay_nowpay_webhook(['invoice_id' => 'now-invoice-1', 'order_id' => (string) $nowOrder['ref']],
    '{"untrusted":true}', 'definitely-not-an-ipn-secret-signature');
$nowAfter = $nowPayment ? pay_get((int) $nowPayment['id']) : null;
$nowOrderAfter = order_get((int) $nowOrder['id']) ?? [];
okcrypto(($nowCreated['ok'] ?? false) === true && ($nowCreated['url'] ?? '') === 'https://checkout.now.example.test/now-invoice-1',
    'NowPayments creates one HTTPS checkout from only its API key');
okcrypto(($nowHook['ok'] ?? false) === true && ($nowHook['text'] ?? '') === 'success'
    && ($nowAfter['status'] ?? '') === 'paid' && ($nowOrderAfter['status'] ?? '') === 'delivered',
    'an unsigned NowPayments notification settles only after the official API reports finished');
okcrypto(balance_of($uid) === 50000,
    'confirmed NowPayments top-up is delivered to the wallet exactly once');

$nowRepeat = pay_nowpay_webhook(['invoice_id' => 'now-invoice-1'], '{}', 'still-not-a-secret');
okcrypto(($nowRepeat['text'] ?? '') === 'already' && balance_of($uid) === 50000,
    'repeat NowPayments notifications cannot credit the wallet twice');

echo "\n== Plisio poll verification and delivery ==\n";
$plisioOrder = create_order($uid, 'topup', 50000, null, ['topup' => 50000]);
/* The fixture operation must refer to the actual, generated order reference. */
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body)
    use (&$requests, &$nowCreateFailure, $plisioOrder): array {
    $requests[] = [$method, $url, $headers, $body];
    if ($method === 'POST' && str_contains($url, 'api.nowpayments.io/v1/invoice')) {
        if ($nowCreateFailure) {
            return [json_encode(['message' => 'provider rejected fixture-nowpayment-api-key']), '', 401];
        }
        return [json_encode(['id' => 'now-invoice-1',
            'invoice_url' => 'https://checkout.now.example.test/now-invoice-1']), '', 200];
    }
    if ($method === 'GET' && str_contains($url, 'api.nowpayments.io/v1/invoice/now-invoice-1')) {
        return [json_encode(['payment_status' => 'finished', 'price_amount' => 0.5]), '', 200];
    }
    if ($method === 'GET' && str_contains($url, 'api.plisio.net/api/v1/invoices/new?')) {
        return [json_encode(['status' => 'success', 'data' => ['txn_id' => 'plisio-txn-1',
            'invoice_url' => 'https://checkout.plisio.example.test/plisio-txn-1']]), '', 200];
    }
    if ($method === 'GET' && str_contains($url, 'api.plisio.net/api/v1/operations?')) {
        return [json_encode(['status' => 'success', 'data' => ['operations' => [[
            'txn_id' => 'plisio-txn-1', 'status' => 'completed', 'source_amount' => 0.5,
            'order_number' => (string) $plisioOrder['ref'],
        ]]]]), '', 200];
    }
    return [json_encode(['message' => 'unexpected fixture request']), '', 404];
};
$plisioCreated = pay_start($plisioOrder, 'plisio');
$plisioPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='plisio'",
    [(int) $plisioOrder['id']]);
$pollSettled = payments_poll();
$plisioAfter = $plisioPayment ? pay_get((int) $plisioPayment['id']) : null;
$plisioOrderAfter = order_get((int) $plisioOrder['id']) ?? [];
okcrypto(($plisioCreated['ok'] ?? false) === true && ($plisioCreated['url'] ?? '') === 'https://checkout.plisio.example.test/plisio-txn-1',
    'Plisio creates an HTTPS checkout from its one API key');
okcrypto($pollSettled === 1 && ($plisioAfter['status'] ?? '') === 'paid'
    && ($plisioOrderAfter['status'] ?? '') === 'delivered' && balance_of($uid) === 100000,
    'the periodic Plisio API check confirms payment and delivers the wallet top-up');

echo "\n== Safe crypto checkout failure reporting ==\n";
$nowCreateFailure = true;
Tg::$outbox = [];
$failedOrder = create_order($uid, 'topup', 50000, null, ['topup' => 50000]);
$failed = pay_start($failedOrder, 'nowpayment');
$failedPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='nowpayment'",
    [(int) $failedOrder['id']]);
$reportText = crypto_outbox_text();
$reportParams = Tg::$outbox[0][1] ?? [];
okcrypto(($failed['ok'] ?? true) === false && ($failed['text'] ?? '') === 'مشکلی در ایجاد لینک پرداخت پیش آمد.'
    && ($failedPayment['status'] ?? '') === 'expire',
    'failed crypto checkout expires its local row and gives the buyer only the generic error');
okcrypto(str_contains($reportText, 'گزارش خطا در ساخت لینک NowPayments')
    && str_contains($reportText, 'HTTP 401') && str_contains($reportText, 'provider rejected')
    && !str_contains($reportText, 'fixture-nowpayment-api-key')
    && ($reportParams['chat_id'] ?? '') === '-1001234567890'
    && ($reportParams['message_thread_id'] ?? 0) === 777,
    'exact safe provider reason is posted to the reports error topic without the API key');

unset($GLOBALS['panel_http_mock']);
DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
