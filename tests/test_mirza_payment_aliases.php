<?php
/**
 * Regression coverage for the file-shaped callbacks used by updated Mirza
 * installations and connector bots.  All callback fields remain untrusted;
 * the test proves the aliases enter the existing provider verification and
 * exactly-once delivery path instead of merely returning a cosmetic marker.
 */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-mirza-aliases-' . getmypid();
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
set_put('web_url', 'https://panel.example.test');

$pass = 0;
$fail = 0;
function okalias(bool $condition, string $label): void
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

/** Invoke the single-router callback handler with a request that looks like a
 * browser/provider GET. JSON webhooks are separately covered by the existing
 * signed Variza tests; this directly proves their legacy path maps correctly. */
function mirza_alias_request(string $path, array $get = [], array $post = [], array $headers = []): string
{
    $_GET = $get;
    $_POST = $post;
    $_SERVER = array_merge([
        'REQUEST_URI' => $path,
        'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
        'HTTP_ACCEPT' => 'text/html',
    ], $headers);
    ob_start();
    web_handle();
    return (string) ob_get_clean();
}

echo "\n== exact updated-Mirza callback paths ==\n";
$paths = [
    'iranpay1' => '/payment/iranpay1.php',
    'iranpay2' => '/payment/iranpay2.php',
    'iranpay4' => '/payment/iranpay4.php',
    'zarinpal' => '/payment/zarinpal.php',
    'aqayepardakht' => '/payment/aqayepardakht.php',
    'variza' => '/payment/variza.php',
];
foreach ($paths as $gateway => $path) {
    okalias(mirza_payment_callback_path($gateway) === $path
        && pay_callback_url($gateway) === 'https://panel.example.test' . $path,
        $gateway . ' publishes the exact Mirza payment-file callback');
}
okalias(mirza_payment_route_alias('/payment/variza_webhook.php') === 'variza/webhook'
    && mirza_payment_route_alias('/payment/iranpay4.php') === 'iranpay4',
    'Variza webhook and Aban callback files map into authoritative SIBIL routes');
okalias(mirza_payment_callback_path('tetrapay') === null
    && pay_callback_url('tetrapay') === 'https://panel.example.test/pay/tetrapay'
    && mirza_payment_route_alias('/payment/tetrapay.php') === null,
    'TetraPay remains outside the Mirza file-compatibility layer');

/* This is the harmless probe response emitted by the source Mirza files. It
   lets an external connector identify the domain before a checkout exists. */
$abanProbe = mirza_alias_request('/payment/iranpay4.php');
$abanUnknown = mirza_alias_request('/payment/iranpay4.php?order_id=missing', ['order_id' => 'missing']);
$cubeProbe = mirza_alias_request('/payment/iranpay2.php', [], [], ['HTTP_ACCEPT' => 'application/json']);
$varizaProbe = mirza_alias_request('/payment/variza.php');
okalias(str_contains($abanProbe, 'شناسه سفارش ارسال نشد.')
    && str_contains($abanUnknown, 'این سفارش پیدا نشد.'),
    'Aban alias mirrors Mirza’s harmless file and unknown-order probe responses');
okalias($cubeProbe === '{"status":false}' && str_contains($varizaProbe, 'شناسه سفارش ارسال نشد.'),
    'Cube and Variza aliases mirror their Mirza probe responses without settlement');

/* AbanGateway: create uses the source Mirza /create payload and the legacy
   file callback must server-verify exactly once before delivery. */
echo "\n== Aban legacy-file callback settles authoritatively ==\n";
$abanCalls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$abanCalls): array {
    $abanCalls[] = [$method, $url, $headers, $body];
    $request = json_decode((string) $body, true) ?: [];
    if ($url === 'https://aban.example.test/bridge/create') {
        return [json_encode(['payment_link' => 'https://pay.example.test/aban-1',
            'authority' => 'aban-authority-1']), '', 200];
    }
    if ($url === 'https://aban.example.test/bridge/verify') {
        return [json_encode(['success' => true, 'order_id' => (string) ($request['order_id'] ?? ''),
            'amount' => (int) ($request['amount'] ?? 0), 'authority' => 'aban-authority-1']), '', 200];
    }
    return [json_encode(['success' => false]), '', 404];
};
set_put('pay_iranpay4_on', '1');
set_put('pay_iranpay4_key', 'aban-connection-key');
set_put('pay_iranpay4_endpoint', 'https://aban.example.test/bridge');
set_put('pay_iranpay4_daily_limit', '0');
$abanUid = 910001;
user_ensure($abanUid, 'Aban Alias', 'aban_alias');
$abanOrder = create_order($abanUid, 'topup', 25000, null, ['topup' => 25000]);
$abanStart = pay_start($abanOrder, 'iranpay4');
$abanCreateBody = json_decode((string) ($abanCalls[0][3] ?? ''), true) ?: [];
$abanReturn = mirza_alias_request('/payment/iranpay4.php?order_id=' . urlencode((string) $abanOrder['ref'])
    . '&authority=aban-authority-1', ['order_id' => (string) $abanOrder['ref'], 'authority' => 'aban-authority-1']);
$abanPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay4'", [(int) $abanOrder['id']]);
$abanAgain = mirza_alias_request('/payment/iranpay4.php?order_id=' . urlencode((string) $abanOrder['ref'])
    . '&authority=aban-authority-1', ['order_id' => (string) $abanOrder['ref'], 'authority' => 'aban-authority-1']);
$abanTx = (int) DB::val("SELECT COUNT(*) FROM wallet_tx WHERE tg_id=? AND kind='topup' AND ref=?",
    [$abanUid, (string) $abanOrder['id']], 0);
okalias(($abanStart['ok'] ?? false) === true
    && ($abanCreateBody['callback_url'] ?? '') === 'https://panel.example.test/payment/iranpay4.php'
    && ($abanCreateBody['user_id'] ?? '') === (string) $abanUid,
    'Aban creation sends the updated-Mirza buyer binding and physical callback path');
okalias(($abanPayment['status'] ?? '') === 'paid' && balance_of($abanUid) === 25000
    && $abanTx === 1 && str_contains($abanReturn, 'پرداخت موفق')
    && str_contains($abanAgain, 'قبلاً تأیید شده'),
    'Aban file callback verifies provider data then delivers exactly once');

/* The same verified callback enters pay_settle(), not a top-up-only shortcut.
   A slot is a real purchasable product kind whose local delivery is simple
   enough to exercise here without needing a live PasarGuard server. */
$slotCountBefore = (int) DB::val('SELECT COUNT(*) FROM slots WHERE tg_id=?', [$abanUid], 0);
$slotOrder = create_order($abanUid, 'slot', 40000, null, ['count' => 1]);
$slotStart = pay_start($slotOrder, 'iranpay4');
$slotReturn = mirza_alias_request('/payment/iranpay4.php?order_id=' . urlencode((string) $slotOrder['ref'])
    . '&authority=aban-authority-1', ['order_id' => (string) $slotOrder['ref'], 'authority' => 'aban-authority-1']);
$slotAgain = mirza_alias_request('/payment/iranpay4.php?order_id=' . urlencode((string) $slotOrder['ref'])
    . '&authority=aban-authority-1', ['order_id' => (string) $slotOrder['ref'], 'authority' => 'aban-authority-1']);
$slotDelivered = order_get((int) $slotOrder['id']) ?? [];
$slotCountAfter = (int) DB::val('SELECT COUNT(*) FROM slots WHERE tg_id=?', [$abanUid], 0);
okalias(($slotStart['ok'] ?? false) === true && ($slotDelivered['status'] ?? '') === 'delivered'
    && $slotCountAfter === $slotCountBefore + 1 && str_contains($slotReturn, 'پرداخت موفق')
    && str_contains($slotAgain, 'قبلاً تأیید شده'),
    'an authoritative Aban callback immediately delivers a matching product exactly once');

/* CubePay: updated Mirza signs its callback. The /payment/iranpay2.php alias
   must preserve the HMAC check and order binding rather than trusting a URL. */
echo "\n== CubePay signed legacy-file callback ==\n";
$cubeCalls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$cubeCalls): array {
    $cubeCalls[] = [$method, $url, $headers, $body];
    if ($url === 'https://cubevps.ir/pay/create-order.php') {
        return [json_encode(['payment_link' => 'https://pay.example.test/cube-1',
            'payment_id' => 'cube-payment-1']), '', 200];
    }
    return [json_encode(['success' => false]), '', 404];
};
set_put('pay_iranpay2_on', '1');
set_put('pay_iranpay2_key', 'cube-callback-secret');
set_put('pay_iranpay2_daily_limit', '0');
$cubeUid = 910002;
user_ensure($cubeUid, 'Cube Alias', 'cube_alias');
$cubeOrder = create_order($cubeUid, 'topup', 30000, null, ['topup' => 30000]);
$cubeStart = pay_start($cubeOrder, 'iranpay2');
$cubeCreateBody = json_decode((string) ($cubeCalls[0][3] ?? ''), true) ?: [];
$cubeFields = ['order_id' => (string) $cubeOrder['ref'], 'status' => 'paid', 'amount' => '30000'];
$cubeFields['sig'] = hash_hmac('sha256', $cubeFields['order_id'] . '|paid|30000', 'cube-callback-secret');
$cubeReturn = mirza_alias_request('/payment/iranpay2.php?' . http_build_query($cubeFields), $cubeFields);
$cubePayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay2'", [(int) $cubeOrder['id']]);
$cubeTx = (int) DB::val("SELECT COUNT(*) FROM wallet_tx WHERE tg_id=? AND kind='topup' AND ref=?",
    [$cubeUid, (string) $cubeOrder['id']], 0);
okalias(($cubeStart['ok'] ?? false) === true
    && ($cubeCreateBody['callback_url'] ?? '') === 'https://panel.example.test/payment/iranpay2.php',
    'CubePay creation advertises the exact Mirza callback file');
okalias(($cubePayment['status'] ?? '') === 'paid' && balance_of($cubeUid) === 30000
    && $cubeTx === 1 && str_contains($cubeReturn, 'پرداخت موفق'),
    'CubePay alias accepts only a valid order-bound signature and settles once');

/* Variza browser returns are intentionally non-settling. Its separate signed
   webhook uses the mapped /payment/variza_webhook.php route and is verified by
   pay_variza_webhook() before it can enter the same delivery code. */
echo "\n== Variza return and signed webhook contract ==\n";
$varizaCalls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$varizaCalls): array {
    $varizaCalls[] = [$method, $url, $headers, $body];
    if ($url === 'https://variza.ir/api/v1/pay') {
        return [json_encode(['pay_url' => 'https://pay.example.test/variza-1', 'slug' => 'variza-slug-1']), '', 201];
    }
    return [json_encode(['success' => false]), '', 404];
};
set_put('pay_variza_on', '1');
set_put('pay_variza_key', 'variza-token');
set_put('pay_variza_key2', 'variza-webhook-secret');
set_put('pay_variza_daily_limit', '0');
$varizaUid = 910003;
user_ensure($varizaUid, 'Variza Alias', 'variza_alias');
$varizaOrder = create_order($varizaUid, 'topup', 35000, null, ['topup' => 35000]);
$varizaStart = pay_start($varizaOrder, 'variza');
$varizaCreateBody = json_decode((string) ($varizaCalls[0][3] ?? ''), true) ?: [];
$varizaReturn = mirza_alias_request('/payment/variza.php?order=' . urlencode((string) $varizaOrder['ref']),
    ['order' => (string) $varizaOrder['ref']]);
$varizaPending = DB::one("SELECT * FROM payments WHERE order_id=? AND method='variza'", [(int) $varizaOrder['id']]);
$hookBody = ['event' => 'payment.paid', 'slug' => 'variza-slug-1', 'amount' => 35000];
$hookRaw = json_encode($hookBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$hookSignature = 'sha256=' . hash_hmac('sha256', (string) $hookRaw, 'variza-webhook-secret');
$varizaHook = pay_variza_webhook($hookBody, (string) $hookRaw, $hookSignature);
$varizaPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='variza'", [(int) $varizaOrder['id']]);
$varizaTx = (int) DB::val("SELECT COUNT(*) FROM wallet_tx WHERE tg_id=? AND kind='topup' AND ref=?",
    [$varizaUid, (string) $varizaOrder['id']], 0);
okalias(($varizaStart['ok'] ?? false) === true
    && ($varizaCreateBody['return_url'] ?? '') === 'https://panel.example.test/payment/variza.php?order='
        . urlencode((string) $varizaOrder['ref']),
    'Variza creation uses the exact Mirza browser-return file');
okalias(($varizaPending['status'] ?? '') === 'unpaid' && str_contains($varizaReturn, 'در انتظار تأیید')
    && ($varizaHook['state'] ?? '') === 'success' && ($varizaPayment['status'] ?? '') === 'paid'
    && balance_of($varizaUid) === 35000 && $varizaTx === 1,
    'Variza return cannot settle; the signed webhook settles the matching order once');

unset($GLOBALS['panel_http_mock']);
$_GET = $_POST = $_SERVER = [];
DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
