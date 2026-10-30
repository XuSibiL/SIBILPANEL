<?php
/** Regression: enabled metered products must open a parseable purchase card. */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-metered-product-' . getmypid();
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
function okmetered(bool $condition, string $label): void
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

/** @return array<string,mixed> */
function metered_last_message(): array
{
    for ($i = count(Tg::$outbox) - 1; $i >= 0; --$i) {
        $row = Tg::$outbox[$i] ?? [];
        if (in_array((string) ($row[0] ?? ''), ['sendMessage', 'editMessageText'], true)) {
            return is_array($row[1] ?? null) ? $row[1] : [];
        }
    }
    return [];
}

/** @return list<string> */
function metered_callbacks(array $message): array
{
    $out = [];
    foreach (($message['reply_markup']['inline_keyboard'] ?? []) as $row) {
        foreach ($row as $button) {
            if (isset($button['callback_data'])) {
                $out[] = (string) $button['callback_data'];
            }
        }
    }
    return $out;
}

$uid = 919001;
user_ensure($uid, 'Metered Buyer', 'metered_buyer');
$productId = DB::exec('INSERT INTO products(name, emoji, category, category_id, active, created_at, '
    . 'max_users, device_limit, days, data_cap_gb, per_gb_price, hourly_price, min_balance, slots_needed) '
    . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['پلن حجمی قابل خرید', '🌐', 'v2ray', 0, 1, now_ts(), 3, 2, 30, 80, 1300, 250, 0, 1]);

/* This is the exact callback emitted by the enabled product button. */
handle_callback([
    'id' => 'metered-product-card',
    'from' => ['id' => $uid, 'first_name' => 'Metered'],
    'message' => ['chat' => ['id' => $uid], 'message_id' => 51],
    'data' => 'buy:p:' . $productId,
]);
$card = metered_last_message();
$text = (string) ($card['text'] ?? '');
$callbacks = metered_callbacks($card);

echo "\n== metered product card ==\n";
okmetered(($card['chat_id'] ?? 0) === $uid && ($card['message_id'] ?? 0) === 51,
    'enabled product callback edits the current purchase message');
okmetered(str_contains($text, '<b>پلن حجمی قابل خرید</b>')
    && substr_count($text, '<b>') === substr_count($text, '</b>'),
    'metered product title has balanced Telegram HTML and can be rendered');
okmetered(str_contains($text, '80 گیگ') && str_contains($text, '1,300 تومان')
    && str_contains($text, '250 تومان'),
    'metered product card preserves its configured volume, per-GB and hourly prices');
okmetered(in_array('buy:confirm:' . $productId, $callbacks, true)
    && in_array('buy:exact:' . $productId, $callbacks, true),
    'metered product card exposes both purchase and configured-price callbacks');

Tg::$outbox = [];
handle_callback([
    'id' => 'metered-product-confirm',
    'from' => ['id' => $uid, 'first_name' => 'Metered'],
    'message' => ['chat' => ['id' => $uid], 'message_id' => 51],
    'data' => 'buy:confirm:' . $productId,
]);
$choice = metered_last_message();
$choiceCallbacks = metered_callbacks($choice);

echo "\n== metered purchase continuation ==\n";
okmetered(str_contains((string) ($choice['text'] ?? ''), 'نام کاربری پنل را چطور انتخاب می‌کنید؟'),
    'purchase callback reaches the normal username-choice screen');
okmetered(in_array('uname:buy:' . $productId . ':rand', $choiceCallbacks, true)
    && in_array('uname:buy:' . $productId . ':custom', $choiceCallbacks, true),
    'metered purchase flow retains random and custom username actions');

DB::close();
@unlink($home . '/sibil.sqlite3');
@unlink($home . '/sibil.sqlite3-wal');
@unlink($home . '/sibil.sqlite3-shm');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
