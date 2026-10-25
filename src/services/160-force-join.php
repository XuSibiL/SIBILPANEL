<?php
declare(strict_types=1);

/* 26) قفل عضویت اجباری کانال
   ─────────────────────────────────────────────────────────────────────────
   مدیر از مسیر «⚙️ پنل مدیریت ➔ 📢 قفل کانال اجباری» کانال را وصل می‌کند.
   پیش از ذخیره، ربات با getChatMember بررسی می‌کند که خودش در آن کانال
   «مدیر» باشد؛ وگرنه قفل هرگز کار نمی‌کند و کاربر بی‌گناه بسته می‌شود.
   مالک و ادمین‌ها هیچ‌وقت قفل نمی‌شوند.
*/

/** شناسهٔ عددی خود ربات — از پیش‌شمارهٔ توکن خوانده می‌شود */
function bot_numeric_id(): int
{
    static $id = null;
    if ($id === null) {
        $id = 0;
        $token = bot_token();
        if ($token !== '' && str_contains($token, ':')) {
            $id = (int) substr($token, 0, (int) strpos($token, ':'));
        }
        $override = (int) cfg('PANEL_BOT_ID', '0');
        if ($override > 0) {
            $id = $override;
        }
    }
    return $id;
}

/** مقدار خام ذخیره‌شده (@name یا لینک t.me یا شناسهٔ عددی) */
function force_channel_raw(): string
{
    return trim((string) set_get('force_channel_id', ''));
}

/** آیا قفل روشن است و کانالی هم تنظیم شده است؟ */
function force_channel_ready(): bool
{
    return set_bool('force_channel_on', false) && force_channel_raw() !== '';
}

/**
 * تجزیهٔ ورودی مدیر → [ok, chat, username, why]
 * ورودی مجاز: `@name` · `name` · `https://t.me/name` · `-100…`
 */
function force_channel_parse(string $raw): array
{
    $raw = trim($raw);
    $raw = trim($raw, "\"'<> \t\n\r");
    if ($raw === '') {
        return [false, '', '', 'آیدی یا لینک کانال را بفرستید.'];
    }
    if (preg_match('~^-100\d{6,}$~', $raw)) {
        return [true, $raw, '', ''];
    }
    if (preg_match('~^(?:https?://)?(?:www\.)?t\.me/(?:s/)?([A-Za-z][A-Za-z0-9_]{3,31})/?$~i', $raw, $m)) {
        return [true, '@' . $m[1], $m[1], ''];
    }
    if (preg_match('~^@?([A-Za-z][A-Za-z0-9_]{3,31})$~', $raw, $m)) {
        return [true, '@' . $m[1], $m[1], ''];
    }
    return [false, '', '', 'این آیدی معتبر نیست. نمونهٔ درست: @my_channel یا https://t.me/my_channel'];
}

/** لینک عمومی کانال برای دکمهٔ «📢 عضویت در کانال» */
function force_channel_link(string $raw = ''): string
{
    $raw = $raw !== '' ? $raw : force_channel_raw();
    if ($raw === '') {
        return '';
    }
    [$ok, $chat, $username] = force_channel_parse($raw);
    if (!$ok) {
        return '';
    }
    if ($username !== '') {
        return 'https://t.me/' . $username;
    }
    /* شناسهٔ عددی: نام کاربری عمومی را از خود تلگرام می‌پرسیم */
    $info = Tg::getChat($chat);
    if (is_array($info) && !empty($info['username'])) {
        return 'https://t.me/' . (string) $info['username'];
    }
    if (is_array($info) && !empty($info['invite_link'])) {
        return (string) $info['invite_link'];
    }
    return '';
}

/**
 * آیا ربات در این کانال مدیر است؟ → [ok, why]
 * بدون این بررسی، قفل برای همهٔ کاربران بی‌اثر می‌شد.
 */
function force_channel_bot_admin(string $chat): array
{
    $botId = bot_numeric_id();
    if ($botId === 0) {
        return [false, 'شناسهٔ ربات خوانده نشد؛ توکن ربات را بررسی کنید.'];
    }
    $res = Tg::call('getChatMember', ['chat_id' => $chat, 'user_id' => $botId]);
    if (Tg::$dry) {
        return [true, ''];
    }
    $member = is_array($res) ? ($res['result'] ?? null) : null;
    if (!is_array($member)) {
        return [false, 'ربات به این کانال دسترسی ندارد. اول ربات را در کانال «مدیر» کنید، '
            . 'بعد آیدی را بفرستید.'];
    }
    $status = (string) ($member['status'] ?? '');
    if (!in_array($status, ['administrator', 'creator'], true)) {
        return [false, 'ربات در این کانال مدیر نیست. در «مدیریت کانال ➔ مدیران» ربات را '
            . 'با دسترسی دیدن پیام‌ها اضافه کنید و دوباره بفرستید.'];
    }
    return [true, ''];
}

/**
 * عضویت یک کاربر → true عضو است، false عضو نیست، null نامعلوم (تلگرام جواب نداد)
 * در حالت نامعلوم قفل باز می‌ماند تا کسی بی‌دلیل بیرون نماند.
 */
function force_channel_member(int $uid): ?bool
{
    $raw = force_channel_raw();
    if ($raw === '' || $uid <= 0) {
        return null;
    }
    [, $chat] = force_channel_parse($raw);
    if ($chat === '') {
        return null;
    }
    if (Tg::$dry) {
        /* در تست، پاسخ ساختگی getChatMember از Tg::$memberStub خوانده می‌شود */
        if (Tg::$memberStub !== null) {
            return Tg::$memberStub;
        }
        return null;
    }
    $res = Tg::call('getChatMember', ['chat_id' => $chat, 'user_id' => $uid]);
    $member = is_array($res) ? ($res['result'] ?? null) : null;
    if (!is_array($member)) {
        return null;
    }
    $status = (string) ($member['status'] ?? '');
    if (in_array($status, ['creator', 'administrator', 'member'], true)) {
        return true;
    }
    if ($status === 'restricted') {
        return !empty($member['is_member']);
    }
    return false;   // left · kicked
}

/** آیا این کاربر باید پشت قفل بماند؟ مالک و ادمین‌ها هرگز */
function force_join_blocked(int $uid): bool
{
    if (!force_channel_ready()) {
        return false;
    }
    if (is_owner($uid) || is_admin($uid)) {
        return false;
    }
    return force_channel_member($uid) === false;
}

/** متن قفل — همان متنی که در همهٔ بخش‌ها نشان داده می‌شود */
function force_join_text(): string
{
    return "⚠️ <b>کاربر گرامی!</b>\n\n"
        . 'برای استفاده از ربات، ابتدا باید در کانال رسمی ما عضو شوید.' . "\n"
        . 'پس از عضویت، دکمهٔ «تایید عضویت 🔄» را بزنید تا دسترسی شما باز شود.' . "\n\n"
        . '<i>اگر عضو هستید و باز هم این پیام را می‌بینید، یک‌بار روی «تایید عضویت 🔄» بزنید.</i>';
}

/** دکمه‌های قفل: عضویت (شیشه‌ای) + تایید */
function force_join_kb(): array
{
    $rows = [];
    $link = force_channel_link();
    if ($link !== '') {
        $rows[] = [btn('📢 عضویت در کانال', null, $link, 'success')];
    }
    $rows[] = [btn('تایید عضویت 🔄', 'fj:check', null, 'primary')];
    return $rows;
}

/** نمایش صفحهٔ قفل (برای روترها) */
function force_join_show(int|string $chat, ?int $messageId = null): void
{
    Tg::show($chat, $messageId, force_join_text(), force_join_kb());
}

/** صفحهٔ مدیریت قفل — وضعیت + سه دکمه */
function force_channel_admin_kb(): array
{
    $on = force_channel_ready();
    return kb_clean([
        [btn($on ? '🔘 وضعیت قفل: روشن 🟢' : '🔘 وضعیت قفل: خاموش 🔴', 'fj:on', null,
            $on ? 'danger' : 'success')],
        [btn('🔗 تنظیم آیدی / لینک کانال', 'fj:set', null, 'primary')],
        [btn('🗑 حذف کانال اجباری', 'fj:del', null, 'danger')],
        [btn('« بازگشت به مدیریت', 'ad:home', null, 'danger')],
    ]);
}

function force_channel_admin_text(): string
{
    $raw = force_channel_raw();
    $linked = $raw !== '' ? '<code>' . e($raw) . '</code>' : '🔴 تنظیم نشده';
    $state = force_channel_ready() ? '🟢 روشن' : '🔴 خاموش';
    return "📢 <b>قفل کانال اجباری</b>\n" . SEP . "\n"
        . 'وضعیت: ' . $state . "\n"
        . 'کانال: ' . $linked . "\n"
        . 'لینک عضویت: ' . (force_channel_link() !== ''
            ? '<code>' . e(force_channel_link()) . '</code>' : '—') . "\n"
        . SEP . "\n"
        . "📋 <b>راهنمای مدیر</b>\n"
        . '۱) ربات را در کانال خود <b>مدیر</b> کنید (دسترسی دیدن پیام‌ها).' . "\n"
        . '۲) دکمهٔ «🔗 تنظیم آیدی / لینک کانال» را بزنید و آیدی را بفرستید:' . "\n"
        . '   · <code>@channel</code> یا <code>https://t.me/channel</code>' . "\n"
        . '   · یا شناسهٔ عددی کانال، مثل <code>-1001234567890</code>' . "\n"
        . '۳) ربات خودش بررسی می‌کند که مدیر کانال باشد؛ اگر نبود ذخیره نمی‌کند.' . "\n\n"
        . '<i>مالک و ادمین‌های ربات هیچ‌وقت قفل نمی‌شوند.</i>';
}
