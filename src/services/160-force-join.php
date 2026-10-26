<?php
declare(strict_types=1);

/* 26) قفل عضویت اجباری کانال
   ─────────────────────────────────────────────────────────────────────────
   پیش از ذخیرهٔ کانال، ابتدا getChat اجرا می‌شود تا معلوم شود ورودی واقعاً
   channel/supergroup است؛ فقط بعد از آن getChatMember وضعیت مدیر بودن ربات را
   بررسی می‌کند. بنابراین آیدی یک ربات یا کاربر، خطای گمراه‌کنندهٔ «ربات مدیر
   نیست» تولید نمی‌کند.
*/

/** شناسهٔ عددی خود ربات — از پیش‌شمارهٔ توکن یا override خوانده می‌شود. */
function bot_numeric_id(): int
{
    $override = (int) cfg('PANEL_BOT_ID', '0');
    return $override > 0 ? $override : bot_self_id();
}

/** شناسهٔ canonical ذخیره‌شده؛ بعد از اعتبارسنجی همیشه شناسهٔ عددی تلگرام است. */
function force_channel_raw(): string
{
    return trim((string) set_get('force_channel_id', ''));
}

function force_channel_join_link(): string
{
    return trim((string) set_get('force_channel_join_link', ''));
}

/** آیا یک شناسهٔ canonical معتبر کانال/سوپرگروه ذخیره شده است؟ */
function force_channel_canonical_id(string $chat): bool
{
    return preg_match('/^-100\d{6,}$/', trim($chat)) === 1;
}

/** آیا قفل روشن است و کانال معتبرِ ذخیره‌شده‌ای دارد؟
 * شناسه‌های قدیمی @username تا مهاجرت getChat هرگز قفل را فعال نمی‌کنند. */
function force_channel_ready(): bool
{
    return set_bool('force_channel_on', false) && force_channel_canonical_id(force_channel_raw());
}

/**
 * مهاجرت بی‌خطر نصب‌های قدیمی: username ذخیره‌شده را فقط اگر Telegram همان لحظه
 * با getChat و getChatMember تأیید کند به -100… تبدیل می‌کنیم. در خطای شبکه هیچ
 * مقدار حدسی یا نامعتبر ذخیره/جایگزین نمی‌شود.
 */
function force_channel_upgrade_legacy(): void
{
    $legacy = force_channel_raw();
    if (!set_bool('force_channel_on', false) || $legacy === '' || force_channel_canonical_id($legacy)) {
        return;
    }
    [$ok, $canonical, $username, $join, $why] = force_channel_validate($legacy);
    if (!$ok) {
        log_line('warning', 'force-join legacy channel requires revalidation: ' . strip_tags($why));
        return;
    }
    set_put('force_channel_id', $canonical);
    set_put('force_channel_username', $username);
    set_put('force_channel_join_link', $join);
    log_line('info', 'force-join legacy channel migrated to canonical ID');
}

/**
 * نرمال‌سازی ورودی مدیر → [ok, chat, username, why, meta]
 *
 * پشتیبانی‌شده:
 *  - @channel / channel / t.me/channel
 *  - -100…
 *  - لینک پیام خصوصی t.me/c/<internal-id>/<message-id> → -100<internal-id>
 *  - -100… همراه با لینک join خصوصی t.me/+hash (برای دکمهٔ عضویت)
 *
 * نکتهٔ فنی: Telegram Bot API امکان تبدیل «فقط» hash لینک t.me/+… به شناسهٔ
 * کانال را ندارد. با حدس زدن چنین شناسه‌ای، قفل برای همهٔ کاربران خراب می‌شود؛
 * پس در آن حالت راهنمای دقیق می‌دهیم، نه پیام اشتباه مدیر نبودن ربات.
 */
function force_channel_parse(string $raw): array
{
    $raw = trim(trim($raw), "\"'<> \t\n\r");
    if ($raw === '') {
        return [false, '', '', 'آیدی یا لینک کانال را بفرستید.', []];
    }

    $meta = ['join_link' => ''];
    if (preg_match('~(?:https?://)?(?:www\.)?t\.me/(?:\+|joinchat/)([A-Za-z0-9_-]{8,})/?~i', $raw, $m)) {
        $meta['join_link'] = 'https://t.me/+' . $m[1];
    }

    /* لینکِ پیام یک کانال خصوصی، برخلاف لینک دعوت، internal id را آشکار می‌کند. */
    if (preg_match('~(?:https?://)?(?:www\.)?t\.me/c/(\d{6,})(?:/\d+)?/?~i', $raw, $m)) {
        return [true, '-100' . $m[1], '', '', $meta];
    }
    /* اجازهٔ «-100… + لینک خصوصی» برای کانال خصوصی و دکمهٔ join. */
    if (preg_match('~(^|\s)(-100\d{6,})(?:\s|$)~', $raw, $m)) {
        return [true, $m[2], '', '', $meta];
    }
    /* Bot API نمی‌تواند hash دعوت خصوصی را به chat_id تبدیل کند. */
    if ($meta['join_link'] !== '') {
        return [false, '', '', "لینک عضویت خصوصی به‌تنهایی شناسهٔ عددی کانال را نشان نمی‌دهد.\n"
            . 'ربات را مدیر کنید و شناسهٔ <code>-100…</code> را بفرستید؛ یا هر دو را در یک پیام بفرستید:'
            . "\n<code>-1001234567890 https://t.me/+...</code>", $meta];
    }
    if (preg_match('~^(?:https?://)?(?:www\.)?t\.me/(?:s/)?([A-Za-z][A-Za-z0-9_]{3,31})/?$~i', $raw, $m)) {
        return [true, '@' . $m[1], $m[1], '', $meta];
    }
    if (preg_match('~^@?([A-Za-z][A-Za-z0-9_]{3,31})$~', $raw, $m)) {
        return [true, '@' . $m[1], $m[1], '', $meta];
    }
    return [false, '', '', 'این آیدی معتبر نیست. نمونهٔ درست: <code>@my_channel</code> یا '
        . '<code>https://t.me/my_channel</code> یا <code>-1001234567890</code>.', $meta];
}

/**
 * اعتبارسنجی کامل: parse → getChat(type) → getChatMember(bot administrator).
 * خروجی: [ok, canonicalId, username, joinLink, why].
 */
function force_channel_validate(string $raw): array
{
    [$parsed, $chat, $username, $why, $meta] = force_channel_parse($raw);
    if (!$parsed) {
        return [false, '', '', '', $why];
    }

    /* مهم: پیش از getChatMember حتماً نوع چت را از تلگرام می‌گیریم. */
    $info = Tg::getChat($chat);
    if (Tg::$dry && $info === null) {
        /* تست خشک باید همان مسیر اعتبارسنجی را بدون شبکه طی کند. */
        $info = ['id' => '-1001234567890', 'type' => 'channel', 'username' => $username];
    }
    /* برخی تست‌های قدیمی فقط type را stub می‌کنند؛ برای آن‌ها شناسهٔ canonical
       مصنوعی می‌گذاریم تا همان قاعدهٔ «فقط -100… ذخیره شود» حفظ شود. */
    if (Tg::$dry && is_array($info) && empty($info['id'])
        && in_array((string) ($info['type'] ?? ''), ['channel', 'supergroup'], true)) {
        $info['id'] = '-1001234567890';
    }
    if (!is_array($info)) {
        $own = trim(ltrim(bot_username(), '@'));
        if ($username !== '' && $own !== '' && strcasecmp($username, $own) === 0) {
            return [false, '', '', '', '⚠️ این شناسه مربوط به خودِ ربات است، نه کانال. '
                . 'فقط کانال یا سوپرگروه را وارد کنید.'];
        }
        return [false, '', '', '', 'کانال با این شناسه پیدا نشد یا ربات هنوز به آن دسترسی ندارد. '
            . 'اگر شناسهٔ کاربر یا ربات فرستاده‌اید، یک کانال/سوپرگروه بفرستید؛ سپس ربات را مدیر کنید.'];
    }

    $type = (string) ($info['type'] ?? '');
    if (!in_array($type, ['channel', 'supergroup'], true)) {
        $typeWhy = match ($type) {
            'private' => '⚠️ این شناسه مربوط به یک کاربر یا ربات خصوصی است، نه کانال. '
                . 'فقط کانال یا سوپرگروه پذیرفته می‌شود.',
            'group' => '⚠️ این شناسه مربوط به گروه معمولی است، نه کانال یا سوپرگروه. '
                . 'یک کانال یا سوپرگروه انتخاب کنید.',
            default => '⚠️ این شناسه مربوط به کانال یا سوپرگروه معتبر نیست. '
                . 'فقط کانال یا سوپرگروه پذیرفته می‌شود.',
        };
        return [false, '', '', '', $typeWhy];
    }

    $canonical = trim((string) ($info['id'] ?? $chat));
    if (!force_channel_canonical_id($canonical)) {
        return [false, '', '', '', 'شناسهٔ عددی معتبر کانال از تلگرام برنگشت؛ دوباره تلاش کنید.'];
    }
    $liveUsername = trim(ltrim((string) ($info['username'] ?? $username), '@'));
    $join = trim((string) ($meta['join_link'] ?? ''));
    if ($join === '' && $liveUsername !== '') {
        $join = 'https://t.me/' . $liveUsername;
    }
    if ($join === '' && !empty($info['invite_link'])) {
        $join = trim((string) $info['invite_link']);
    }

    [$admin, $adminWhy] = force_channel_bot_admin($canonical);
    if (!$admin) {
        return [false, '', '', '', $adminWhy];
    }
    return [true, $canonical, $liveUsername, $join, ''];
}

/** لینک عضویت ذخیره‌شده. برای کانال عمومی، بدون هیچ API دیگری ساخته می‌شود. */
function force_channel_link(string $raw = ''): string
{
    if ($raw === '') {
        $saved = force_channel_join_link();
        if ($saved !== '') {
            return $saved;
        }
        $username = trim(ltrim(set_get('force_channel_username', ''), '@'));
        if ($username !== '') {
            return 'https://t.me/' . $username;
        }
        $raw = force_channel_raw();
    }
    if ($raw === '') {
        return '';
    }
    [$ok, $chat, $username, , $meta] = force_channel_parse($raw);
    if (!$ok) {
        return '';
    }
    if ($username !== '') {
        return 'https://t.me/' . $username;
    }
    if (!empty($meta['join_link'])) {
        return (string) $meta['join_link'];
    }
    return '';
}

/**
 * بررسی مدیر بودن ربات. این تابع عمداً getChat صدا نمی‌زند: caller باید اول
 * force_channel_validate را انجام دهد تا نوع ورودی اعتبارسنجی شده باشد.
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
 * عضویت یک کاربر → true عضو است، false عضو نیست، null نامعلوم (تلگرام جواب نداد).
 * در حالت نامعلوم قفل باز می‌ماند تا کسی بی‌دلیل بیرون نماند.
 */
function force_channel_member(int $uid): ?bool
{
    $chat = force_channel_raw();
    if ($chat === '' || $uid <= 0) {
        return null;
    }
    if (Tg::$dry) {
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
    return false;
}

/** آیا این کاربر باید پشت قفل بماند؟ تمام نقش‌های ادمین هرگز قفل نمی‌شوند. */
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

function force_join_text(): string
{
    return "⚠️ <b>کاربر گرامی!</b>\n\n"
        . 'برای استفاده از ربات، ابتدا باید در کانال رسمی ما عضو شوید.' . "\n"
        . 'پس از عضویت، دکمهٔ «تایید عضویت 🔄» را بزنید تا دسترسی شما باز شود.' . "\n\n"
        . '<i>اگر عضو هستید و باز هم این پیام را می‌بینید، یک‌بار روی «تایید عضویت 🔄» بزنید.</i>';
}

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

function force_join_show(int|string $chat, ?int $messageId = null): void
{
    Tg::show($chat, $messageId, force_join_text(), force_join_kb());
}

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
    $link = force_channel_link();
    $state = force_channel_ready() ? '🟢 روشن' : '🔴 خاموش';
    return "📢 <b>قفل کانال اجباری</b>\n" . SEP . "\n"
        . 'وضعیت: ' . $state . "\n"
        . 'شناسهٔ تأییدشده: ' . $linked . "\n"
        . 'لینک عضویت: ' . ($link !== '' ? '<code>' . e($link) . '</code>' : '—') . "\n"
        . SEP . "\n"
        . "📋 <b>راهنمای مدیر</b>\n"
        . '۱) ربات را در کانال خود <b>مدیر</b> کنید (دسترسی دیدن پیام‌ها).' . "\n"
        . '۲) دکمهٔ «🔗 تنظیم آیدی / لینک کانال» را بزنید و یکی از این‌ها را بفرستید:' . "\n"
        . '   · <code>@channel</code> یا <code>https://t.me/channel</code>' . "\n"
        . '   · شناسهٔ عددی <code>-1001234567890</code>' . "\n"
        . '   · برای کانال خصوصی: <code>-100… https://t.me/+…</code>' . "\n"
        . '۳) ربات اول نوع چت، بعد مدیر بودن خودش را بررسی می‌کند؛ اگر نبود ذخیره نمی‌کند.' . "\n\n"
        . '<i>لینک خصوصیِ تنها قابل تبدیل به شناسهٔ کانال نیست؛ این محدودیت Telegram Bot API است.</i>';
}
