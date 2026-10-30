<?php
/**
 * Regression coverage for Mirza 0.9-compatible buyer payment layout and the
 * deliberately small TetraPay/Aban administration surface.
 */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-mirza-selector-' . getmypid();
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
function okselector(bool $condition, string $label): void
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

/** @return array<int,array<int,array<string,mixed>>> */
function selector_last_keyboard(): array
{
    $last = Tg::$outbox[count(Tg::$outbox) - 1] ?? [];
    return is_array($last[1]['reply_markup']['inline_keyboard'] ?? null)
        ? $last[1]['reply_markup']['inline_keyboard'] : [];
}

set_put('web_url', 'https://panel.example.test');
$uid = 885001;
user_ensure($uid, 'Mirza Selector', 'mirza_selector');
set_put('card_number', '6104331002032630');
set_put('pay_card_on', '1');

/* Enable every public Mirza-compatible route plus SIBIL's Tetra extension. */
foreach (['plisio', 'nowpayment', 'trx', 'iranpay1', 'iranpay2', 'iranpay4',
    'tetrapay', 'aqayepardakht', 'zarinpal', 'variza', 'stars'] as $gateway) {
    set_put('pay_' . $gateway . '_on', '1');
}
foreach (['plisio', 'nowpayment', 'trx', 'iranpay1', 'iranpay2', 'iranpay4',
    'tetrapay', 'aqayepardakht', 'zarinpal', 'variza'] as $gateway) {
    set_put('pay_' . $gateway . '_key', 'fixture-' . $gateway . '-key');
}
set_put('pay_nowpayment_key2', 'fixture-now-ipn');
set_put('pay_trx_key2', 'TFixtureWalletAddress');
set_put('pay_variza_key2', 'fixture-variza-webhook');
set_put('pay_iranpay4_endpoint', 'https://aban.example.test/api');

$order = create_order($uid, 'topup', 50000, null, ['topup' => 50000]);
screen_gateways($uid, null, $order);
$rows = selector_last_keyboard();
$callbacks = [];
$texts = [];
$singleColumn = true;
foreach ($rows as $row) {
    $singleColumn = $singleColumn && count($row) === 1;
    foreach ($row as $button) {
        $callbacks[] = (string) ($button['callback_data'] ?? '');
        $texts[] = (string) ($button['text'] ?? '');
    }
}
$expectedGateways = ['card', 'plisio', 'nowpayment', 'trx', 'iranpay1', 'iranpay2',
    'iranpay4', 'tetrapay', 'aqayepardakht', 'zarinpal', 'variza', 'stars'];
$expectedCallbacks = array_map(static fn(string $gw): string => 'inv:pay:' . $order['id'] . ':' . $gw,
    $expectedGateways);
$expectedCallbacks[] = 'inv:back:' . $order['id'];
$expectedTexts = array_map(static fn(string $gw): string => mirza_payment_button_label($gw), $expectedGateways);
$expectedTexts[] = '❌ بستن لیست';

echo "\n== Mirza buyer selector ==\n";
okselector($singleColumn, 'every available gateway occupies exactly one Mirza-style row');
okselector($callbacks === $expectedCallbacks, 'gateway order and final close callback match the Mirza selector');
okselector($texts === $expectedTexts, 'gateway captions and exact close-list caption match Mirza');
okselector(!in_array('nav:back', $callbacks, true) && !in_array('nav:back:user', $callbacks, true),
    'no generic back row is appended after the close-list row');

$invoiceButtons = invoice_kb($order, $uid);
$invoiceCallbacks = [];
foreach ($invoiceButtons as $row) {
    foreach ($row as $button) {
        $invoiceCallbacks[] = (string) ($button['callback_data'] ?? '');
    }
}
okselector(in_array('inv:gw:' . $order['id'], $invoiceCallbacks, true)
    && !array_filter($invoiceCallbacks, static fn(string $data): bool => str_starts_with($data, 'inv:pay:')),
    'pre-invoice opens one payment selector instead of a divergent direct gateway layout');

echo "\n== focused gateway administration ==\n";
Tg::$outbox = [];
screen_pay_gateway($uid, null, 'tetrapay');
$tetraUi = json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
okselector(str_contains($tetraUi, ':key') && !str_contains($tetraUi, ':key2')
    && !str_contains($tetraUi, ':endpoint') && !str_contains($tetraUi, 'سندباکس'),
    'TetraPay setup has one API key and no sandbox, endpoint, or second-key control');

Tg::$outbox = [];
screen_pay_gateway($uid, null, 'iranpay4');
$abanUi = json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
Tg::$outbox = [];
screen_pay_gateway($uid, null, 'zarinpal');
$zarinUi = json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
okselector(str_contains($abanUi, ':endpoint') && !str_contains($zarinUi, ':endpoint'),
    'only Aban Gateway exposes a separately configurable service address');
set_put('pay_zarinpal_endpoint', 'https://untrusted.example.test');
okselector(gw_endpoint('zarinpal', gw_endpoint_default('zarinpal')) === 'https://payment.zarinpal.com'
    && pay_abanpay_endpoint() === 'https://aban.example.test/api',
    'non-Aban endpoint overrides are ignored while strict Aban HTTPS endpoint is retained');

DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
