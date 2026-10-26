<?php
/**
 * Focused v3.17 regression tests: hierarchical RBAC, receipt targeting,
 * force-join validation ordering, and polling/menu hardening.
 *
 * Run: php tests/test_rbac_force_join.php
 */
declare(strict_types=1);

$home = sys_get_temp_dir() . '/sibil-rbac-force-' . getmypid();
@mkdir($home, 0775, true);
file_put_contents($home . '/.env', "PANEL_BOT_TOKEN=123456:TESTTOKEN\n"
    . "PANEL_HOME=$home\nPANEL_ADMINS=111,222\nPANEL_PRIMARY_OWNER_ID=111\n");
putenv('PANEL_HOME=' . $home);
putenv('PANEL_ENV=' . $home . '/.env');

require __DIR__ . '/../sibil.php';
load_env();
db_bootstrap();
settings_bootstrap();
set_put('report_chat', '-1001234567890');
Tg::$dry = true;

$pass = 0;
$fail = 0;
function check317(bool $condition, string $label, string $extra = ''): void
{
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "  ok   $label\n";
        return;
    }
    ++$fail;
    echo '  FAIL ' . $label . ($extra !== '' ? ' — ' . $extra : '') . "\n";
}

function flat_callbacks317(array $keyboard): array
{
    $out = [];
    foreach ($keyboard as $row) {
        foreach ($row as $button) {
            if (!empty($button['callback_data'])) {
                $out[] = (string) $button['callback_data'];
            }
        }
    }
    return $out;
}

function outbox_has_method317(string $method): bool
{
    foreach (Tg::$outbox as $item) {
        if (($item[0] ?? '') === $method) {
            return true;
        }
    }
    return false;
}

function direct_message317(int $uid, string $text): void
{
    handle_message([
        'from' => ['id' => $uid, 'first_name' => 'RBAC', 'username' => 'rbac_test'],
        'chat' => ['id' => $uid, 'type' => 'private'],
        'message_id' => 1,
        'text' => $text,
    ]);
}

echo "\n== RBAC roles and immutable owner ==\n";
user_ensure(111, 'مالک اصلی', 'primary_owner');
user_ensure(222, 'مدیر قدیمی', 'legacy_staff');
user_ensure(300, 'مالی', 'finance_staff');
user_ensure(301, 'پشتیبان', 'support_staff');
user_ensure(302, 'اپراتور', 'ops_staff');
user_ensure(303, 'هم‌مالک', 'coowner_staff');
user_ensure(900, 'خریدار', 'buyer');

check317(primary_owner_id() === 111, 'the primary owner is anchored in .env and DB');
check317(admin_role_of(111) === 'owner', 'the configured primary owner has owner role');
check317(admin_role_of(222) === ADMIN_ROLE_SUPPORT, 'legacy extra admin migrates to support role');

[$okFinance] = admin_role_assign(111, 300, ADMIN_ROLE_FINANCE);
[$okSupport] = admin_role_assign(111, 301, ADMIN_ROLE_SUPPORT);
[$okManager] = admin_role_assign(111, 302, ADMIN_ROLE_MANAGER);
[$okCoowner] = admin_role_assign(111, 303, ADMIN_ROLE_COOWNER);
check317($okFinance && $okSupport && $okManager && $okCoowner, 'primary owner can assign all four staff roles');
check317(admin_can(300, 'receipt') && !admin_can(300, 'users.view') && !admin_can(300, 'full'),
    'finance is restricted to receipt review');
check317(admin_can(301, 'support') && admin_can(301, 'users.view') && admin_can(301, 'stats.simple')
    && !admin_can(301, 'receipt') && !admin_can(301, 'full'),
    'support has users, messaging and simple stats only');
check317(admin_can(302, 'ops') && admin_can(302, 'ops.logs') && admin_can(302, 'support')
    && !admin_can(302, 'full') && !admin_can(302, 'receipt'),
    'manager has operations and safe logs but no financial/full access');
check317(admin_can(303, 'full') && !admin_can_manage_roles(303),
    'co-owner has full bot access but cannot manage roles');
check317(admin_can_manage_roles(111), 'only the primary owner manages roles');

[$primaryDemote, $primaryDemoteText] = admin_role_assign(111, 111, ADMIN_ROLE_FINANCE);
[$primaryRemove, $primaryRemoveText] = admin_role_remove(111, 111);
[$coownerRemove] = admin_role_remove(303, 302);
check317(!$primaryDemote && str_contains($primaryDemoteText, 'قفل'), 'primary owner cannot be demoted');
check317(!$primaryRemove && str_contains($primaryRemoveText, 'قفل'), 'primary owner cannot be removed');
check317(!$coownerRemove, 'a co-owner cannot remove an operator or another co-owner');

$targets = receipt_targets();
sort($targets);
check317($targets === [111, 300, 303], 'receipt cards target only primary owner, finance and co-owner',
    implode(',', $targets));

/* هر دریافت‌کننده کارت خودش را دارد و بستن رسید باید همگی را بی‌دکمه کند. */
$orderId317 = DB::exec('INSERT INTO orders(tg_id, kind, price, payable, ref, created_at) VALUES(?,?,?,?,?,?)',
    [900, 'topup', 10000, 10000, 'RBAC-RCPT', now_ts()]);
$receiptId317 = DB::exec('INSERT INTO receipts(tg_id, order_id, amount, photo_id, ref, status, created_at) VALUES(?,?,?,?,?,?,?)',
    [900, $orderId317, 10000, 'rbac-photo-file-id', 'RBAC-RCPT', 'pending', now_ts()]);
Tg::$outbox = [];
receipt_post_card($receiptId317);
$receiptCards317 = DB::all('SELECT chat_id FROM receipt_cards WHERE receipt_id=? ORDER BY CAST(chat_id AS INTEGER)',
    [$receiptId317]);
$receiptChats317 = array_map(static fn(array $row): int => (int) $row['chat_id'], $receiptCards317);
$receiptPhotos317 = array_values(array_filter(Tg::$outbox,
    static fn(array $entry): bool => ($entry[0] ?? '') === 'sendPhoto'));
check317($receiptChats317 === [111, 300, 303] && count($receiptPhotos317) === 3,
    'each authorised receipt reviewer gets a separately tracked private card');
Tg::$outbox = [];
receipt_post_card($receiptId317);
check317(!outbox_has_method317('sendPhoto'), 'receipt-card retry does not duplicate cards for existing reviewers');
Tg::$outbox = [];
receipt_close_card($receiptId317, false, 300, 'test close');
$closedChats317 = [];
foreach (Tg::$outbox as $entry) {
    if (($entry[0] ?? '') === 'editMessageCaption') {
        $closedChats317[] = (int) ($entry[1]['chat_id'] ?? 0);
    }
}
sort($closedChats317);
check317($closedChats317 === [111, 300, 303],
    'closing a receipt removes review controls from every recipient card');

check317(admin_callback_allowed(300, 'ad:receipts'), 'finance can open receipt review');
check317(!admin_callback_allowed(300, 'ad:users'), 'finance cannot open user management');
check317(admin_callback_allowed(301, 'ad:u:900:msg'), 'support can message a user');
check317(!admin_callback_allowed(301, 'ad:u:900:tx'), 'support cannot inspect wallet transactions');
check317(admin_callback_allowed(302, 'ad:find:pn:5:ext'), 'manager can extend a sold panel');
check317(!admin_callback_allowed(302, 'ad:find:pn:5:del'), 'manager cannot delete a sold panel');
check317(!admin_callback_allowed(302, 'ad:pg:5:key'), 'manager cannot change an API key');
check317(!admin_callback_allowed(302, 'ad:pg:5:edit'), 'manager cannot edit server connection settings');
check317(!admin_callback_allowed(302, 'ad:settings'), 'manager cannot open bot/database settings');
check317(admin_callback_allowed(303, 'ad:settings'), 'co-owner retains full owner capabilities');
check317(!admin_callback_allowed(303, 'ad:staff'), 'co-owner cannot alter access roles');
check317(admin_callback_allowed(111, 'ad:staff'), 'primary owner can open staff management');
[$directReceiptReview, $directReceiptNote] = review_receipt(1, true, 900);
check317(!$directReceiptReview && str_contains($directReceiptNote, 'دسترسی'),
    'receipt service itself rejects a non-finance caller');
[$firstReview317] = review_receipt($receiptId317, false, 300);
[$secondReview317, $secondReviewNote317] = review_receipt($receiptId317, false, 303);
check317($firstReview317 && !$secondReview317,
    'only one eligible reviewer can claim a receipt concurrently', $secondReviewNote317);

$financeKb = flat_callbacks317(console_kb(300));
$supportKb = flat_callbacks317(console_kb(301));
$managerKb = flat_callbacks317(console_kb(302));
$ownerKb = flat_callbacks317(console_kb(111));
$coownerKb = flat_callbacks317(console_kb(303));
check317(count($financeKb) === 2 && in_array('ad:receipts', $financeKb, true)
    && !in_array('ad:finance', $financeKb, true) && !in_array('ad:users', $financeKb, true),
    'finance console has only receipt review and user-home return', implode(',', $financeKb));
check317(in_array('ad:users', $supportKb, true) && in_array('ad:stats', $supportKb, true)
    && !in_array('ad:finance', $supportKb, true), 'support console omits financial controls');
check317(in_array('ad:panel', $managerKb, true) && in_array('ad:ops:logs', $managerKb, true)
    && !in_array('ad:settings', $managerKb, true), 'manager console exposes operations without settings');
check317(in_array('ad:staff', $ownerKb, true), 'primary console exposes the staff-management screen');
check317(!in_array('ad:staff', $coownerKb, true), 'co-owner console hides staff-management screen');

$GLOBALS['panel_act_uid'] = 301;
Tg::$outbox = [];
screen_user_card(301, null, 900);
$userCard = '';
foreach (Tg::$outbox as $item) {
    $userCard .= (string) ($item[1]['text'] ?? '');
}
check317(!str_contains($userCard, 'موجودی کیف پول') && !str_contains($userCard, 'شمارهٔ موبایل')
    && !str_contains($userCard, 'سطح احراز هویت'), 'support user card redacts financial, phone and KYC data');
$GLOBALS['panel_act_uid'] = 111;

$masked = ops_log_safe_line('token=secret-value api_key=pg_key_abcdef sqlite:/secret/app.sqlite3');
check317(!str_contains($masked, 'secret-value') && !str_contains($masked, 'pg_key_abcdef')
    && !str_contains($masked, 'app.sqlite3'), 'operator log scrubber hides tokens, API keys and DB DSNs');

echo "\n== Force-join normalisation and validation order ==\n";
[$parseOk1, $parseChat1, $parseUser1] = force_channel_parse('@public_channel');
[$parseOk2, $parseChat2, $parseUser2] = force_channel_parse('https://t.me/public_channel');
[$parseOk3, $parseChat3] = force_channel_parse('-1001234567890');
[$parseOk4, $parseChat4] = force_channel_parse('https://t.me/c/1234567890/42');
[$parseOk5, $parseChat5, , , $parseMeta5] = force_channel_parse('-1001234567890 https://t.me/+AbCdEfGhIj');
[$inviteOnlyOk, , , $inviteOnlyWhy] = force_channel_parse('https://t.me/+AbCdEfGhIj');
check317($parseOk1 && $parseChat1 === '@public_channel' && $parseUser1 === 'public_channel',
    '@channel is parsed');
check317($parseOk2 && $parseChat2 === '@public_channel' && $parseUser2 === 'public_channel',
    'public t.me/channel is parsed');
check317($parseOk3 && $parseChat3 === '-1001234567890', 'numeric -100 channel ID is parsed');
check317($parseOk4 && $parseChat4 === '-1001234567890', 't.me/c internal link is converted to canonical candidate');
check317($parseOk5 && $parseChat5 === '-1001234567890'
    && ($parseMeta5['join_link'] ?? '') === 'https://t.me/+AbCdEfGhIj',
    'numeric ID plus private join link is accepted as a pair');
check317(!$inviteOnlyOk && str_contains($inviteOnlyWhy, 'به‌تنهایی'),
    'an invite hash alone is rejected with actionable Persian guidance');

set_put('force_channel_on', '1');
set_put('force_channel_id', '@legacy_public_channel');
check317(!force_channel_ready(), 'an unvalidated legacy username cannot arm the join lock');
Tg::$chatStub = ['id' => '-1007777777777', 'type' => 'channel', 'username' => 'legacy_public_channel'];
force_channel_upgrade_legacy();
check317(set_get('force_channel_id') === '-1007777777777' && force_channel_ready(),
    'a validated legacy public channel is migrated to canonical ID at startup');

Tg::$chatStub = ['id' => '-1005555555555', 'type' => 'channel', 'username' => 'public_channel'];
Tg::$outbox = [];
[$valid, $canonical, $username, $joinLink, $validWhy] = force_channel_validate('@public_channel');
$methods = array_map(static fn(array $entry): string => (string) ($entry[0] ?? ''), Tg::$outbox);
check317($valid && $canonical === '-1005555555555' && $username === 'public_channel'
    && $joinLink === 'https://t.me/public_channel' && $validWhy === '',
    'getChat returns a canonical numeric ID before storage');
check317(($methods[0] ?? '') === 'getChat' && in_array('getChatMember', $methods, true),
    'force-join validation calls getChat before getChatMember');

Tg::$chatStub = ['id' => '42', 'type' => 'private'];
Tg::$outbox = [];
[$privateOk, , , , $privateWhy] = force_channel_validate('@some_bot');
check317(!$privateOk && str_contains($privateWhy, 'کاربر یا ربات خصوصی')
    && !outbox_has_method317('getChatMember'), 'private user/bot is rejected before membership lookup');

Tg::$chatStub = ['id' => '-123456', 'type' => 'group'];
Tg::$outbox = [];
[$groupOk, , , , $groupWhy] = force_channel_validate('@normal_group');
check317(!$groupOk && str_contains($groupWhy, 'گروه معمولی')
    && !outbox_has_method317('getChatMember'), 'normal group is rejected before membership lookup');

/* The message flow never replaces an existing canonical channel after a bad type. */
set_put('force_channel_id', '-1001111111111');
set_put('force_channel_on', '1');
State::set(111, 'fj_channel');
Tg::$chatStub = ['id' => '42', 'type' => 'private'];
direct_message317(111, '@not_a_channel');
check317(set_get('force_channel_id') === '-1001111111111', 'invalid force-join input is never saved');

State::set(111, 'fj_channel');
Tg::$chatStub = ['id' => '-1005555555555', 'type' => 'supergroup', 'username' => 'public_channel'];
direct_message317(111, 'https://t.me/public_channel');
check317(set_get('force_channel_id') === '-1005555555555'
    && set_get('force_channel_username') === 'public_channel'
    && set_get('force_channel_join_link') === 'https://t.me/public_channel',
    'only validated canonical ID, username and join link are persisted');

Tg::$chatStub = null;

echo "\n== Menu reset and single-poller guard ==\n";
Tg::$outbox = [];
bot_menu_button_sync();
$firstMenuCalls = array_values(array_filter(Tg::$outbox,
    static fn(array $entry): bool => ($entry[0] ?? '') === 'setChatMenuButton'));
check317(count($firstMenuCalls) === 1
    && (($firstMenuCalls[0][1]['menu_button']['type'] ?? '') === 'commands'),
    'startup uses official setChatMenuButton with menu_button.type=commands');
Tg::$outbox = [];
bot_menu_button_sync();
check317(outbox_has_method317('setChatMenuButton'),
    'menu reset is safely idempotent and reasserted on later startup');

$lockPath = $home . '/.poll.lock';
$externalLock = fopen($lockPath, 'c+');
$externalHeld = $externalLock !== false && flock($externalLock, LOCK_EX | LOCK_NB);
$secondAllowed = polling_lock_acquire();
check317($externalHeld && !$secondAllowed, 'a second polling worker is refused while the lock is held');
if (is_resource($externalLock)) {
    flock($externalLock, LOCK_UN);
    fclose($externalLock);
}
check317(polling_lock_acquire(), 'polling lock can be acquired after the first worker exits');
polling_lock_release();

$cli = (string) file_get_contents(__DIR__ . '/../bin/sibil');
$installer = (string) file_get_contents(__DIR__ . '/../install.sh');
check317(str_contains($cli, 'deleteWebhook?drop_pending_updates=true')
    && str_contains($installer, 'KillMode=control-group'),
    'updater/service hardening clears webhook updates and kills stale worker groups');

@unlink($home . '/sibil.sqlite3');
@unlink($home . '/.env');
@unlink($home . '/.poll.lock');
@rmdir($home);

echo "\n----------------------------------------\n";
echo "passed: $pass   failed: $fail\n";
echo $fail === 0 ? "RESULT: OK\n" : "RESULT: FAILED\n";
exit($fail === 0 ? 0 : 1);
