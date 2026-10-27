<?php
/** Focused v3.18 regression: unlimited panels, clean customer labels, and roles. */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-v318-unlimited-' . getmypid();
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

$pass = 0;
$fail = 0;
function ok318(bool $condition, string $label): void
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
function flat318(array $keyboard): array
{
    $buttons = [];
    foreach ($keyboard as $row) {
        foreach ($row as $button) {
            $buttons[(string) ($button['callback_data'] ?? '')] = (string) ($button['text'] ?? '');
        }
    }
    return $buttons;
}
function callback318(int $uid, string $data): void
{
    handle_callback([
        'id' => 'v318-' . bin2hex(random_bytes(4)),
        'from' => ['id' => $uid, 'first_name' => 'آزمایش', 'username' => 'v318test'],
        'message' => ['message_id' => 1, 'chat' => ['id' => $uid, 'type' => 'private']],
        'data' => $data,
    ]);
}
function message318(int $uid, string $text): void
{
    handle_message([
        'from' => ['id' => $uid, 'first_name' => 'آزمایش', 'username' => 'v318test'],
        'chat' => ['id' => $uid, 'type' => 'private'], 'message_id' => 2, 'text' => $text,
    ]);
}

$schemaProducts = array_column(DB::all('PRAGMA table_info(products)'), 'name');
$schemaPanels = array_column(DB::all('PRAGMA table_info(panels)'), 'name');
echo "\n== durable schema ==\n";
ok318(!array_diff(['sale_type', 'per_user_price', 'min_users'], $schemaProducts),
    'products persist the unlimited sales model fields');
ok318(!array_diff(['is_unlimited', 'unlimited_user_price', 'unlimited_min_users'], $schemaPanels),
    'sold panels persist unlimited classification and rate snapshots');

$uid = 318001;
user_ensure($uid, 'آزمایش', 'v318test');
$catId = cat_create('آزمایش', '🧪');
$meteredId = DB::exec('INSERT INTO products(name, emoji, category_id, active, trial, created_at, '
    . 'hourly_price, per_gb_price, max_users) VALUES(?,?,?,?,?,?,?,?,?)',
    ['محصول متری', '📦', $catId, 1, 1, now_ts(), 1234, 5678, 10]);
$unlimitedId = DB::exec('INSERT INTO products(name, emoji, category_id, active, trial, created_at, '
    . 'sale_type, per_user_price, min_users, slots_needed) VALUES(?,?,?,?,?,?,?,?,?,?)',
    ['نامحدود هلند', '♾️', $catId, 1, 1, now_ts(), PRODUCT_SALE_UNLIMITED_USERS, 100000, 3, 1]);
DB::exec('INSERT INTO pg_panels(name, url, api_key, active, is_default, created_at) VALUES(?,?,?,?,?,?)',
    ['آزمایش', 'https://panel.test', 'test-key', 1, 1, now_ts()]);

echo "\n== labels and purchase state ==\n";
$expectedGuide = 'ℹ️ راهنما: تعداد روز (مدت اعتبار) و محدودیت تعداد دستگاه در پنل‌های نامحدود، مستقیماً از روی تنظیمات نقش (Role) در پنل پاسارگاد اعمال می‌شود. ربات فقط وظیفه ساخت تعداد یوزر/کلاینت درخواستی را بر عهده دارد.';
ok318(UNLIMITED_ROLE_GUIDE === $expectedGuide, 'the required unlimited Role guidance is exact');
Tg::$outbox = [];
screen_buy_category($uid, null, $catId);
$buttons = flat318(Tg::$outbox[array_key_last(Tg::$outbox)][1]['reply_markup']['inline_keyboard'] ?? []);
ok318(($buttons['buy:p:' . $meteredId] ?? '') === '📦 محصول متری',
    'metered customer product label is emoji plus original name only');
ok318(($buttons['buy:p:' . $unlimitedId] ?? '') === '♾️ نامحدود هلند',
    'unlimited customer product label is emoji plus original name only');
ok318(!str_contains(implode(' ', $buttons), '/ساعت') && !str_contains(implode(' ', $buttons), '/گیگ'),
    'customer product buttons contain no computed billing suffix');
ok318(!isset(flat318(home_kb($uid))['menu:track']), 'main menu has no visible tracking-code button');

Tg::$outbox = [];
screen_product($uid, null, $unlimitedId);
$buttons = flat318(Tg::$outbox[array_key_last(Tg::$outbox)][1]['reply_markup']['inline_keyboard'] ?? []);
ok318(isset($buttons['buy:users:' . $unlimitedId]), 'unlimited product exposes requested-user button');
ok318(!isset($buttons['buy:confirm:' . $unlimitedId]), 'unlimited product cannot use metered buy callback');
Tg::$outbox = [];
screen_product_admin($uid, null, $unlimitedId);
$adminProduct = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
ok318(str_contains((string) ($adminProduct[1]['text'] ?? ''), $expectedGuide),
    'unlimited product administration displays the required Role guidance');

echo "\n== test-service restriction ==\n";
Tg::$outbox = [];
screen_trial($uid, null, $uid);
$trialMenu = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
$trialButtons = flat318($trialMenu[1]['reply_markup']['inline_keyboard'] ?? []);
ok318(isset($trialButtons['trial:p:' . $meteredId]),
    'test-service menu keeps an eligible metered product');
ok318(!isset($trialButtons['trial:p:' . $unlimitedId]),
    'test-service menu never displays an unlimited product, even when marked trial');

Tg::$outbox = [];
screen_trial_product($uid, null, $uid, $unlimitedId);
$trialBlocked = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
ok318(str_contains((string) ($trialBlocked[1]['text'] ?? ''), 'برای سرویس تست در دسترس نیست'),
    'direct unlimited test-product screen is blocked');

State::clear($uid);
callback318($uid, 'trial:go:' . $unlimitedId);
ok318(State::get($uid)['state'] === '', 'forged unlimited test callback cannot start username selection');
callback318($uid, 'uname:trial:' . $unlimitedId . ':custom');
ok318(State::get($uid)['state'] === '', 'forged unlimited trial username callback cannot create a pending state');
$trialOrdersBefore = (int) DB::val("SELECT COUNT(*) FROM orders WHERE kind='trial'", [], 0);
start_trial_order($uid, $uid, null, $unlimitedId, 'blockedtrial');
$trialOrdersAfter = (int) DB::val("SELECT COUNT(*) FROM orders WHERE kind='trial'", [], 0);
ok318($trialOrdersAfter === $trialOrdersBefore, 'unlimited product cannot create a trial order');
$blockedTrialOrder = create_order($uid, 'trial', 0, $unlimitedId, ['panel_username' => 'blockedtrial']);
[$blockedDelivery, $blockedWhy] = deliver_panel_order($blockedTrialOrder);
ok318(!$blockedDelivery && str_contains($blockedWhy, 'نامحدود'),
    'delivery refuses a manually created unlimited trial order before panel provisioning');
ok318((int) DB::val('SELECT COUNT(*) FROM panels WHERE is_trial=1 AND is_unlimited=1', [], 0) === 0,
    'no unlimited panel is ever stored as a test panel');

callback318($uid, 'buy:users:' . $unlimitedId);
ok318(State::get($uid)['state'] === 'unlimited_initial_users', 'initial requested-user state is saved');
message318($uid, '۲');
ok318(State::get($uid)['state'] === 'unlimited_initial_users', 'initial count below min is rejected and state remains');
message318($uid, '۳');
ok318(State::get($uid)['state'] === '', 'valid initial count advances to username choice');
callback318($uid, 'uname:buyu:' . $unlimitedId . ':rand:3');
$initial = DB::one("SELECT * FROM orders WHERE tg_id=? AND kind='panel' ORDER BY id DESC LIMIT 1", [$uid]);
$initialPayload = json_decode((string) ($initial['payload'] ?? '{}'), true) ?: [];
ok318((int) ($initial['price'] ?? 0) === 300000, 'initial invoice equals requested users times per-user price');
ok318(!empty($initialPayload['unlimited_users']) && (int) ($initialPayload['user_count'] ?? 0) === 3,
    'initial order permanently records unlimited requested-user data');

echo "\n== sold-panel snapshot and recharge ==\n";
$panelId = DB::exec('INSERT INTO panels(tg_id, product_id, panel_username, panel_password, label, '
    . 'max_users, user_cap, device_limit, status, created_at, is_unlimited, '
    . 'unlimited_user_price, unlimited_min_users) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [$uid, $unlimitedId, 'unlimited318', 'Pass#318', 'نامحدود', 3, 3, 0, 'active', now_ts(), 1, 100000, 3]);
/* تغییر محصول نباید نرخ شارژ پنل فروخته‌شده را تغییر بدهد. */
DB::exec('UPDATE products SET per_user_price=?, min_users=? WHERE id=?', [999999, 9, $unlimitedId]);
Tg::$outbox = [];
screen_panel_detail($uid, null, $panelId);
$detail = Tg::$outbox[array_key_last(Tg::$outbox)] ?? [];
$buttons = flat318($detail[1]['reply_markup']['inline_keyboard'] ?? []);
ok318(isset($buttons['pn:' . $panelId . ':users']), 'active unlimited panel exposes recharge/add-users button');
ok318(!isset($buttons['pn:' . $panelId . ':sec']), 'unlimited panel omits bot device-management button');
ok318(str_contains((string) ($detail[1]['text'] ?? ''), 'طبق تنظیمات نقش در پاسارگارد'),
    'unlimited panel says role owns validity and device limits');

callback318($uid, 'pn:' . $panelId . ':users');
message318($uid, '۲');
ok318(State::get($uid)['state'] === 'unlimited_add_users', 'recharge count below sold-panel min is rejected');
message318($uid, '۴');
$recharge = DB::one("SELECT * FROM orders WHERE tg_id=? AND kind='users' ORDER BY id DESC LIMIT 1", [$uid]);
$rechargePayload = json_decode((string) ($recharge['payload'] ?? '{}'), true) ?: [];
ok318((int) ($recharge['price'] ?? 0) === 400000, 'recharge keeps the sold-panel per-user price snapshot');
ok318(!empty($rechargePayload['unlimited_users']) && (int) ($rechargePayload['user_count'] ?? 0) === 4,
    'recharge order records arbitrary requested users explicitly');

$panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
$charge = panel_charge_once($panel, 8 * USAGE_BILL_BYTES, true, true);
ok318((int) $charge['charged'] === 0 && !$charge['suspended'],
    'unlimited panel never receives local traffic/hourly charges or balance suspension');
panels_balance_enforce();
ok318((string) DB::val('SELECT status FROM panels WHERE id=?', [$panelId]) === 'active',
    'zero wallet does not balance-suspend an unlimited panel');

$roles = roles_normalize([
    ['id' => 1, 'name' => 'مالک', 'is_owner' => true, 'active' => true],
    ['id' => 44, 'name' => 'Netherlands اختصاصی', 'active' => true],
    ['id' => 45, 'name' => 'خاموش', 'active' => false],
]);
$roleButtons = flat318(role_buttons(0, 0, 'ad:role', 1, $roles));
ok318(count($roles) === 2 && isset($roleButtons['ad:role:0:44:0']),
    'active Netherlands role survives normalization and is displayed');

@unlink($home . '/sibil.sqlite3');
@unlink($home . '/.env');
@rmdir($home);
echo "\npassed: $pass   failed: $fail\n";
exit($fail === 0 ? 0 : 1);
