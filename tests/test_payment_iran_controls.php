<?php
/**
 * Focused payment regressions: Iranian gateways, daily ceilings, card receipt
 * approval/cashback, one-day expiry, safe endpoint validation and Stars.
 */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-payment-controls-' . getmypid();
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
function okcontrol(bool $condition, string $label): void
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

function outbox_control_text(): string
{
    return implode("\n", array_map(static fn(array $row): string => (string) ($row[1]['text'] ?? ''), Tg::$outbox));
}

set_put('web_url', 'https://panel.example.test');
/* Existing untouched 30-minute installations are migrated exactly once. */
set_put('pay_expire_minutes', '30');
DB::exec("DELETE FROM settings WHERE key='pay_expiry_policy_24h'");
settings_bootstrap();

echo "\n== URL, expiry and buyer guidance ==\n";
okcontrol(gw_https_url('https://gateway.example.test/api') === true, 'valid HTTPS endpoint is accepted');
okcontrol(gw_https_url('https:///missing-host') === false, 'HTTPS endpoint needs a host');
okcontrol(gw_https_url('https://user:pass@gateway.example.test') === false, 'endpoint credentials are rejected');
okcontrol(set_int('pay_expire_minutes') === 1440 && pay_expiry_human() === 'یک روز'
    && pay_variza_expiry() === '1d',
    'legacy default migrates to one day and Variza maps it to 1d');
set_put('pay_iranpay4_help', '<b>راهنمای امن</b>');
$help = pay_gateway_help_notice('iranpay4');
okcontrol(str_contains($help, '&lt;b&gt;راهنمای امن&lt;/b&gt;') && !str_contains($help, '<b>راهنمای امن</b>'),
    'gateway help is escaped before Telegram HTML rendering');
$noticeOrder = ['ref' => 'ORDER-ONE', 'payable' => 25000, 'kind' => 'topup'];
$noticePayment = ['method' => 'iranpay4', 'authority' => 'ab-token', 'amount' => 25000];
$notice = pay_created_notice($noticeOrder, $noticePayment) . $help;
okcontrol(str_contains($notice, 'یک روز') && str_contains($notice, 'Verify/Webhook')
    && !str_contains($notice, 'به کیف پول شما واریز گردید'),
    'created notice is one-day pending guidance with no premature credit claim');

/* PDO SQLite builds disagree on whether raw BEGIN IMMEDIATE is visible through
   inTransaction(). Nested project transactions must therefore remain safe on
   both older and newer server PHP builds. */
echo "\n== nested SQLite transaction compatibility ==\n";
$nestedTxOk = false;
try {
    DB::tx(static function () use (&$nestedTxOk): void {
        DB::tx(static function () use (&$nestedTxOk): void {
            $nestedTxOk = true;
        });
    });
} catch (Throwable) {
    $nestedTxOk = false;
}
okcontrol($nestedTxOk, 'nested DB transactions do not issue a second BEGIN IMMEDIATE');

/* Atomic final daily check: two independently-created provider payments can
   both exist, but only capacity available while claiming settlement is used. */
echo "\n== final daily-ceiling claim ==\n";
$dailyUid = 810001;
user_ensure($dailyUid, 'Daily Tester', 'daily');
set_put('pay_tetrapay_daily_limit', '20000');
$orderA = create_order($dailyUid, 'topup', 20000, null, ['topup' => 20000]);
$orderB = create_order($dailyUid, 'topup', 20000, null, ['topup' => 20000]);
$payA = pay_new($orderA, 'tetrapay', 20000);
$payB = pay_new($orderB, 'tetrapay', 20000);
pay_prepare($payA, $orderA);
pay_prepare($payB, $orderB);
$claimA = pay_settle((int) $payA['id'], 'tp-first', ['fixture' => 'first']);
$claimB = pay_settle((int) $payB['id'], 'tp-second', ['fixture' => 'second']);
$afterA = pay_get((int) $payA['id']);
$afterB = pay_get((int) $payB['id']);
okcontrol(($claimA['state'] ?? '') === 'success' && ($afterA['status'] ?? '') === 'paid',
    'first final settlement claims the available daily capacity');
okcontrol(($claimB['state'] ?? '') === 'daily_limit' && ($afterB['status'] ?? '') === 'unpaid',
    'second final settlement cannot overrun a just-consumed daily ceiling');
okcontrol(gw_daily_paid_total('tetrapay') === 20000 && balance_of($dailyUid) === 20000,
    'only the claimed provider payment counts and credits the wallet');

/* Manual card receipts must use the same Tehran-day ceiling and cashback must
   remain idempotent even if a reviewer retries the action. */
echo "\n== card receipt ceiling and cashback ==\n";
$cardUid = 810002;
user_ensure($cardUid, 'Card Tester', 'card');
set_put('pay_card_on', '1');
set_put('card_number', '6104331002032630');
set_put('pay_card_min', '20000');
set_put('pay_card_max', '1000000');
set_put('pay_card_daily_limit', '20000');
set_put('pay_card_cashback', '10');
$orderCard1 = create_order($cardUid, 'topup', 20000, null, ['topup' => 20000]);
$receipt1 = DB::exec('INSERT INTO receipts(order_id,tg_id,amount,photo_id,ref,status,created_at) '
    . 'VALUES(?,?,?,?,?,?,?)', [(int) $orderCard1['id'], $cardUid, 20000, 'photo-one',
        (string) $orderCard1['ref'], 'pending', now_ts()]);
$review1 = review_receipt($receipt1, true, 1);
$receipt1Row = DB::one('SELECT * FROM receipts WHERE id=?', [$receipt1]);
$balanceAfterFirstReceipt = balance_of($cardUid);
$retry1 = review_receipt($receipt1, true, 1);
$orderCard2 = create_order($cardUid, 'topup', 20000, null, ['topup' => 20000]);
$receipt2 = DB::exec('INSERT INTO receipts(order_id,tg_id,amount,photo_id,ref,status,created_at) '
    . 'VALUES(?,?,?,?,?,?,?)', [(int) $orderCard2['id'], $cardUid, 20000, 'photo-two',
        (string) $orderCard2['ref'], 'pending', now_ts()]);
$review2 = review_receipt($receipt2, true, 1);
$receipt2Row = DB::one('SELECT * FROM receipts WHERE id=?', [$receipt2]);
okcontrol(($review1[0] ?? false) === true && ($receipt1Row['status'] ?? '') === 'approved',
    'approved card receipt is counted using its reviewed timestamp');
okcontrol($balanceAfterFirstReceipt === 22000 && balance_of($cardUid) === 22000
    && ($retry1[0] ?? true) === false,
    'card cashback is issued once only after successful settlement');
okcontrol(($review2[0] ?? true) === false && ($receipt2Row['status'] ?? '') === 'pending'
    && gw_daily_paid_total('card') === 20000,
    'card receipt over the concurrent daily ceiling stays pending for review');

Tg::$outbox = [];
set_put('pay_card_min', '30000');
$orderTooSmall = create_order($cardUid, 'topup', 25000, null, ['topup' => 25000]);
screen_card_payment($cardUid, null, $cardUid, $orderTooSmall);
okcontrol(str_contains(outbox_control_text(), 'خارج از بازهٔ کارت‌به‌کارت'),
    'direct card screen enforces the configured card minimum before receipt upload');

$expiredCardOrder = create_order($cardUid, 'topup', 40000, null, ['topup' => 40000]);
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts() - 1441 * 60, (int) $expiredCardOrder['id']]);
$expiredCardOrder = order_get((int) $expiredCardOrder['id']) ?? [];
Tg::$outbox = [];
screen_card_payment($cardUid, null, $cardUid, $expiredCardOrder);
$expiredCardAfter = order_get((int) $expiredCardOrder['id']) ?? [];
okcontrol(($expiredCardAfter['status'] ?? '') === 'canceled'
    && str_contains(outbox_control_text(), 'مهلت این فاکتور تمام شده'),
    'expired card selection cannot start a receipt flow or revive its order');

$expiredReceiptButtonOrder = create_order($cardUid, 'topup', 40000, null, ['topup' => 40000]);
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts() - 1441 * 60,
    (int) $expiredReceiptButtonOrder['id']]);
Tg::$outbox = [];
handle_callback(['id' => 'expired-receipt-button', 'from' => ['id' => $cardUid],
    'message' => ['message_id' => 75, 'chat' => ['id' => $cardUid, 'type' => 'private']],
    'data' => 'pay:receipt:' . (int) $expiredReceiptButtonOrder['id']]);
$expiredReceiptButtonAfter = order_get((int) $expiredReceiptButtonOrder['id']) ?? [];
okcontrol(($expiredReceiptButtonAfter['status'] ?? '') === 'canceled'
    && (State::get($cardUid)['state'] ?? '') === ''
    && str_contains(outbox_control_text(), 'مهلت ارسال رسید'),
    'expired receipt button cannot place a buyer back into the upload flow');

$expiredReceiptOrder = create_order($cardUid, 'topup', 40000, null, ['topup' => 40000]);
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts() - 1441 * 60, (int) $expiredReceiptOrder['id']]);
State::set($cardUid, 'receipt', ['order_id' => (int) $expiredReceiptOrder['id']]);
handle_message(['from' => ['id' => $cardUid, 'first_name' => 'Card'],
    'chat' => ['id' => $cardUid, 'type' => 'private'],
    'photo' => [['file_id' => 'expired-photo']]]);
$expiredReceiptAfter = order_get((int) $expiredReceiptOrder['id']) ?? [];
$expiredReceiptCount = (int) DB::val('SELECT COUNT(*) FROM receipts WHERE order_id=?',
    [(int) $expiredReceiptOrder['id']], 0);
okcontrol(($expiredReceiptAfter['status'] ?? '') === 'canceled' && $expiredReceiptCount === 0
    && (State::get($cardUid)['state'] ?? '') === '',
    'expired card receipt upload is rejected before a reviewable receipt is created');

$expiryUid = 810006;
user_ensure($expiryUid, 'Expiry Tester', 'expiry');
add_balance($expiryUid, 50000, 'test', 'expiry-seed', 'wallet seed');
$expiredWalletOrder = create_order($expiryUid, 'topup', 10000, null, ['topup' => 10000]);
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts() - 1441 * 60, (int) $expiredWalletOrder['id']]);
$expiryBalanceBefore = balance_of($expiryUid);
Tg::$outbox = [];
handle_callback(['id' => 'expired-wallet-callback', 'from' => ['id' => $expiryUid],
    'message' => ['message_id' => 77, 'chat' => ['id' => $expiryUid, 'type' => 'private']],
    'data' => 'inv:wallet:' . (int) $expiredWalletOrder['id']]);
$expiredWalletAfter = order_get((int) $expiredWalletOrder['id']) ?? [];
okcontrol(($expiredWalletAfter['status'] ?? '') === 'canceled' && balance_of($expiryUid) === $expiryBalanceBefore
    && str_contains(outbox_control_text(), 'مهلت پرداخت تمام شد'),
    'an expired invoice cannot be paid from the wallet through a stale callback');

/* AbanPay follows public Mirza's create/verify contract but trusts only the
   server-side verify response for settlement. */
echo "\n== AbanPay authoritative create and verify ==\n";
$abanCalls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$abanCalls): array {
    $abanCalls[] = [$method, $url, $headers, $body];
    $request = json_decode((string) $body, true) ?: [];
    if ($method === 'POST' && $url === 'https://aban.example.test/api/create') {
        return [json_encode(['payment_link' => 'https://pay.example.test/aban-token',
            'authority' => 'aban-token', 'invoice_id' => 'inv-' . ($request['order_id'] ?? '')]), '', 201];
    }
    if ($method === 'POST' && $url === 'https://aban.example.test/api/verify') {
        return [json_encode(['success' => true, 'order_id' => $request['order_id'] ?? '',
            'amount' => $request['amount'] ?? 0, 'reference' => 'aban-ref-1']), '', 200];
    }
    if ($method === 'POST' && str_ends_with($url, '/pg/v4/payment/verify.json')) {
        return [json_encode(['data' => ['code' => 100, 'ref_id' => 'zarin-poll-ref']]), '', 200];
    }
    if ($method === 'POST' && str_ends_with($url, '/api/v2/verify')) {
        return [json_encode(['code' => '1']), '', 200];
    }
    return [json_encode(['error' => 'not found']), '', 404];
};
set_put('pay_iranpay4_on', '1');
set_put('pay_iranpay4_key', 'aban-api-key');
set_put('pay_iranpay4_endpoint', 'https://aban.example.test/api');
set_put('pay_iranpay4_daily_limit', '0');
$abanUid = 810003;
user_ensure($abanUid, 'Aban Tester', 'aban');
$abanOrder = create_order($abanUid, 'topup', 25000, null, ['topup' => 25000]);
$abanCreate = pay_start($abanOrder, 'iranpay4');
$abanPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay4'", [(int) $abanOrder['id']]);
$abanCreateBody = json_decode((string) ($abanCalls[0][3] ?? ''), true) ?: [];
$abanHeaders = implode("\n", $abanCalls[0][2] ?? []);
$abanInvoiceLookup = pay_abanpay_find_by_invoice('inv-' . (string) $abanOrder['ref']);
$abanBalanceBeforeVerify = balance_of($abanUid);
$abanVerify = pay_abanpay_verify($abanPayment ?? [], [
    'order_id' => (string) $abanOrder['ref'], 'authority' => 'aban-token',
]);
okcontrol(($abanCreate['ok'] ?? false) === true && ($abanCreate['url'] ?? '') === 'https://pay.example.test/aban-token',
    'AbanPay creates the hosted payment link');
okcontrol(($abanCreateBody['order_id'] ?? '') === (string) $abanOrder['ref']
    && ($abanCreateBody['user_id'] ?? '') === (string) $abanUid
    && ($abanCreateBody['callback_url'] ?? '') === 'https://panel.example.test/pay/iranpay4'
    && str_contains($abanHeaders, 'Authorization: Bearer aban-api-key'),
    'AbanPay create payload, buyer binding, and bearer authorization match Mirza adapter contract');
okcontrol($abanBalanceBeforeVerify === 0 && (int) ($abanInvoiceLookup['id'] ?? 0) === (int) ($abanPayment['id'] ?? 0),
    'AbanPay creation alone does not produce an early wallet credit and retains invoice lookup binding');
okcontrol(($abanVerify['state'] ?? '') === 'success' && balance_of($abanUid) === 25000,
    'AbanPay verify matching order and amount settles exactly once');

/* The deployed adapter may deliver a provider JSON body rather than a browser
   query/form return. Nested data remains only a lookup hint; verify performs
   the authoritative order and amount check. */
$abanJsonOrder = create_order($abanUid, 'topup', 25000, null, ['topup' => 25000]);
$abanJsonCreate = pay_start($abanJsonOrder, 'iranpay4');
$abanJsonPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay4'",
    [(int) $abanJsonOrder['id']]);
$abanJsonFields = web_callback_fields(['get' => [], 'post' => [], 'raw' => '{fixture}',
    'json' => ['event' => 'payment.paid', 'data' => ['order_id' => (string) $abanJsonOrder['ref'],
        'authority' => 'aban-token']]]);
$abanJsonBefore = balance_of($abanUid);
$abanJsonVerify = pay_abanpay_verify($abanJsonPayment ?? [], $abanJsonFields);
$abanJsonRepeat = pay_abanpay_verify(pay_get((int) ($abanJsonPayment['id'] ?? 0)) ?? [], $abanJsonFields);
okcontrol(($abanJsonCreate['ok'] ?? false) === true
    && ($abanJsonFields['order_id'] ?? '') === (string) $abanJsonOrder['ref']
    && ($abanJsonVerify['state'] ?? '') === 'success'
    && ($abanJsonRepeat['state'] ?? '') === 'already'
    && balance_of($abanUid) === $abanJsonBefore + 25000,
    'nested JSON Aban callback is server-verified and credits its bound top-up once');

$abanMismatchOrder = create_order($abanUid, 'topup', 25000, null, ['topup' => 25000]);
$abanMismatchCreate = pay_start($abanMismatchOrder, 'iranpay4');
$abanMismatchPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay4'",
    [(int) $abanMismatchOrder['id']]);
$abanMismatchBefore = balance_of($abanUid);
$abanMismatch = pay_abanpay_verify($abanMismatchPayment ?? [],
    ['order_id' => 'OTHER-ORDER', 'authority' => 'aban-token']);
okcontrol(($abanMismatchCreate['ok'] ?? false) === true && ($abanMismatch['ok'] ?? true) === false
    && ($abanMismatch['state'] ?? '') === 'notfound'
    && balance_of($abanUid) === $abanMismatchBefore,
    'mismatched Aban callback data cannot select or credit another order');
/* Keep this deliberately invalid fixture out of the later real-provider poll;
   a provider-side verify for its own legitimate order would of course be able
   to settle it, independently of the rejected forged callback. */
if ($abanMismatchPayment !== null) {
    DB::exec("UPDATE payments SET status='expire' WHERE id=?", [(int) $abanMismatchPayment['id']]);
    DB::exec("UPDATE orders SET status='canceled' WHERE id=?", [(int) $abanMismatchOrder['id']]);
}

/* A missed callback must be repaired by the bounded authoritative polling
   fallback. Clear the current metadata snapshot to exercise legacy rows too. */
$abanPollOrder = create_order($abanUid, 'topup', 25000, null, ['topup' => 25000]);
$abanPollCreate = pay_start($abanPollOrder, 'iranpay4');
$abanPollPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay4'",
    [(int) $abanPollOrder['id']]);
$abanPollMeta = $abanPollPayment ? pay_meta($abanPollPayment) : [];
unset($abanPollMeta['order_ref']);
if ($abanPollPayment !== null) {
    /* Older SIBIL rows stored the local order reference when the adapter did
       not return an opaque authority. Reconciliation must retain Mirza's blank
       authority verify form while binding the order on the server. */
    DB::exec('UPDATE payments SET authority=? WHERE id=?', [(string) $abanPollOrder['ref'],
        (int) $abanPollPayment['id']]);
    pay_save_meta((int) $abanPollPayment['id'], $abanPollMeta);
}
$abanPollBefore = balance_of($abanUid);
$abanPollSettled = payments_poll();
$abanPollAgain = payments_poll();
$abanPollAfter = $abanPollPayment ? pay_get((int) $abanPollPayment['id']) : null;
$abanPollOrderAfter = order_get((int) $abanPollOrder['id']) ?? [];
$abanPollTx = (int) DB::val("SELECT COUNT(*) FROM wallet_tx WHERE tg_id=? AND kind='topup' AND ref=?",
    [$abanUid, (string) ($abanPollOrder['id'] ?? 0)], 0);
okcontrol(($abanPollCreate['ok'] ?? false) === true && $abanPollSettled >= 1 && $abanPollAgain === 0
    && ($abanPollAfter['status'] ?? '') === 'paid' && ($abanPollOrderAfter['status'] ?? '') === 'delivered'
    && balance_of($abanUid) === $abanPollBefore + 25000 && $abanPollTx === 1,
    'Aban authoritative poll repairs a missed callback once, including a legacy row without order metadata');

/* ZarinPal and AghayePardakht save their provider authorities at creation, so
   their documented server verify APIs are also eligible for safe fallback
   reconciliation rather than trusting a missing browser return. */
echo "\n== provider-authoritative fallback reconciliation ==\n";
set_put('pay_zarinpal_key', 'zarin-poll-key');
set_put('pay_aqayepardakht_key', 'aqaye-poll-key');
$zarinUid = 810007;
$aqayeUid = 810008;
user_ensure($zarinUid, 'Zarin Poll', 'zarinpoll');
user_ensure($aqayeUid, 'Aqaye Poll', 'aqayepoll');
$zarinOrder = create_order($zarinUid, 'topup', 25000, null, ['topup' => 25000]);
$aqayeOrder = create_order($aqayeUid, 'topup', 25000, null, ['topup' => 25000]);
$zarinPayment = pay_new($zarinOrder, 'zarinpal', 25000);
$aqayePayment = pay_new($aqayeOrder, 'aqayepardakht', 25000);
pay_prepare($zarinPayment, $zarinOrder);
pay_prepare($aqayePayment, $aqayeOrder);
pay_set_authority((int) $zarinPayment['id'], 'zarin-poll-authority');
pay_set_authority((int) $aqayePayment['id'], 'aqaye-poll-transid');
$providerPoll = payments_poll();
$providerPollAgain = payments_poll();
$zarinAfter = pay_get((int) $zarinPayment['id']) ?? [];
$aqayeAfter = pay_get((int) $aqayePayment['id']) ?? [];
okcontrol($providerPoll >= 2 && $providerPollAgain === 0
    && ($zarinAfter['status'] ?? '') === 'paid' && ($aqayeAfter['status'] ?? '') === 'paid'
    && balance_of($zarinUid) === 25000 && balance_of($aqayeUid) === 25000,
    'stored provider authorities let ZarinPal and AghayePardakht verify and credit exactly once without a return page');

$abanLateOrder = create_order($abanUid, 'topup', 25000, null, ['topup' => 25000]);
$abanLateCreate = pay_start($abanLateOrder, 'iranpay4');
$abanLatePayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='iranpay4'",
    [(int) $abanLateOrder['id']]);
DB::exec('UPDATE orders SET created_at=? WHERE id=?', [now_ts() - 1441 * 60, (int) $abanLateOrder['id']]);
$abanBalanceBeforeLateVerify = balance_of($abanUid);
$abanLateVerify = pay_abanpay_verify($abanLatePayment ?? [], [
    'order_id' => (string) $abanLateOrder['ref'], 'authority' => 'aban-token',
]);
$abanLatePaymentAfter = $abanLatePayment ? pay_get((int) $abanLatePayment['id']) : null;
$abanLateOrderAfter = order_get((int) $abanLateOrder['id']) ?? [];
okcontrol(($abanLateCreate['ok'] ?? false) === true && ($abanLateVerify['state'] ?? '') === 'expired'
    && ($abanLatePaymentAfter['status'] ?? '') === 'expire'
    && ($abanLateOrderAfter['status'] ?? '') === 'canceled'
    && balance_of($abanUid) === $abanBalanceBeforeLateVerify,
    'a provider-confirmed AbanPay callback after the one-day deadline expires and cancels without auto-credit');

/* Variza maps SIBIL's default one-day policy to the documented 1d expiry and
   accepts only a signed payment.paid webhook. */
echo "\n== Variza one-day signed webhook ==\n";
$varizaCalls = [];
$GLOBALS['panel_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$varizaCalls): array {
    $varizaCalls[] = [$method, $url, $headers, $body];
    if ($method === 'POST' && $url === 'https://variza.ir/api/v1/pay') {
        return [json_encode(['pay_url' => 'https://pay.example.test/variza-slug',
            'slug' => 'variza-slug']), '', 201];
    }
    return [json_encode(['error' => 'not found']), '', 404];
};
set_put('pay_variza_on', '1');
set_put('pay_variza_key', 'variza-token');
set_put('pay_variza_key2', 'variza-secret');
set_put('pay_variza_daily_limit', '0');
$varizaUid = 810004;
user_ensure($varizaUid, 'Variza Tester', 'variza');
$varizaOrder = create_order($varizaUid, 'topup', 25000, null, ['topup' => 25000]);
$varizaCreate = pay_start($varizaOrder, 'variza');
$varizaPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='variza'", [(int) $varizaOrder['id']]);
$varizaPayload = json_decode((string) ($varizaCalls[0][3] ?? ''), true) ?: [];
$hookBody = ['event' => 'payment.paid', 'slug' => 'variza-slug', 'amount' => 25000];
$hookRaw = json_encode($hookBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$hookSignature = 'sha256=' . hash_hmac('sha256', (string) $hookRaw, 'variza-secret');
$varizaHook = pay_variza_webhook($hookBody, (string) $hookRaw, $hookSignature);
$varizaAgain = pay_variza_webhook($hookBody, (string) $hookRaw, $hookSignature);
$varizaBad = pay_variza_webhook($hookBody, (string) $hookRaw, 'sha256=' . str_repeat('0', 64));
okcontrol(($varizaCreate['ok'] ?? false) === true && ($varizaPayload['expires_in'] ?? '') === '1d',
    'Variza creation uses its documented one-day expiry value');
okcontrol(($varizaPayload['return_url'] ?? '') === 'https://panel.example.test/pay/variza?order=' . urlencode((string) $varizaOrder['ref'])
    && ($varizaPayment['authority'] ?? '') === 'variza-slug',
    'Variza stores its slug and return route without trusting browser settlement');
okcontrol(($varizaHook['state'] ?? '') === 'success' && balance_of($varizaUid) === 25000,
    'valid Variza payment.paid webhook settles the wallet once');
okcontrol(($varizaAgain['text'] ?? '') === 'already' && ($varizaBad['code'] ?? 0) === 403,
    'Variza webhook retries are idempotent and invalid signatures are rejected');

/* Stars needs a pending local payment row before Telegram later delivers the
   trusted successful_payment update; this exercises the repaired link flow. */
echo "\n== Telegram Stars local invoice binding ==\n";
$starApiCalls = [];
Tg::$callStub = static function (string $method, array $params) use (&$starApiCalls): ?array {
    $starApiCalls[] = [$method, $params];
    if ($method === 'createInvoiceLink') {
        return ['ok' => true, 'result' => 'https://t.me/SibilTestBot?start=stars-fixture'];
    }
    return ['ok' => true, 'result' => ['message_id' => count($starApiCalls)]];
};
set_put('pay_stars_on', '1');
set_put('pay_stars_daily_limit', '0');
$starsUid = 810005;
user_ensure($starsUid, 'Stars Tester', 'stars');
$starsOrder = create_order($starsUid, 'topup', 20000, null, ['topup' => 20000]);
$starsCreate = pay_start($starsOrder, 'stars');
$starsPayment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='stars'", [(int) $starsOrder['id']]);
$starsMeta = $starsPayment ? pay_meta($starsPayment) : [];
$starsAgain = pay_start($starsOrder, 'stars');
pay_stars_success([
    'from' => ['id' => $starsUid],
    'successful_payment' => [
        'invoice_payload' => (string) ($starsMeta['create']['invoice_payload'] ?? ''),
        'currency' => 'XTR',
        'total_amount' => (int) ($starsMeta['stars_amount'] ?? 0),
        'telegram_payment_charge_id' => 'tg-charge-810005',
    ],
]);
$starsPaid = $starsPayment ? pay_get((int) $starsPayment['id']) : null;
pay_stars_success([
    'from' => ['id' => $starsUid],
    'successful_payment' => [
        'invoice_payload' => (string) ($starsMeta['create']['invoice_payload'] ?? ''),
        'currency' => 'XTR',
        'total_amount' => (int) ($starsMeta['stars_amount'] ?? 0),
        'telegram_payment_charge_id' => 'tg-charge-810005',
    ],
]);
okcontrol(($starsCreate['ok'] ?? false) === true && ($starsPayment['status'] ?? '') === 'unpaid'
    && str_contains((string) ($starsMeta['create']['invoice_payload'] ?? ''), ':payment:'),
    'Stars link creation persists a payment-bound pending invoice');
okcontrol(($starsAgain['reused'] ?? false) === true && count(array_filter($starApiCalls,
    static fn(array $call): bool => $call[0] === 'createInvoiceLink')) === 1,
    'reselecting Stars reuses the same pending Telegram invoice');
okcontrol(($starsPaid['status'] ?? '') === 'paid' && balance_of($starsUid) === 20000,
    'trusted successful_payment settles the bound Stars payment once');
Tg::$callStub = null;

unset($GLOBALS['panel_http_mock']);
DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
