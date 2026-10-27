<?php
declare(strict_types=1);

/* 11) ADMIN — management console */

/** متن هشدار قفل پنل تا زمانی که گروه گزارشات تنظیم نشده است */
function console_lock_text(): string
{
    return "⚠️ <b>مدیر گرامی، برای شروع کار با پنل و جلوگیری از اختلال در ثبت "
        . "گزارشات خرید و فیش‌ها، ابتدا باید گروه گزارشات ربات را تنظیم فرمایید.</b>"
        . "\n" . SEP . "\n"
        . '📢 تا زمانی که گروه گزارشات ثبت نشود:' . "\n"
        . '▫️ پنل مدیریت قفل است و بقیهٔ دکمه‌ها نمایش داده نمی‌شوند' . "\n"
        . '▫️ خریدها، رسیدها، شارژها، مصرف و خطاها جایی ثبت نمی‌شوند' . "\n"
        . '▫️ بکاپ خودکار ۲۴ ساعته هم ارسال نمی‌شود' . "\n" . SEP . "\n"
        . 'برای باز شدن پنل، روی دکمهٔ زیر بزنید و آموزش گام‌به‌گام را انجام دهید.';
}

/** شناسهٔ بازکنندهٔ کنسول؛ روتر آن را در هر callback پر می‌کند. */
function console_actor_id(?int $uid = null): int
{
    if ($uid !== null && $uid > 0) {
        return $uid;
    }
    $active = (int) ($GLOBALS['panel_act_uid'] ?? 0);
    /* صفحه‌های کنسول فقط از روتر ادمین فراخوانی می‌شوند. اگر یک فراخوانی داخلی/
       تست، شناسهٔ آخرین خریدار را در global جا گذاشته باشد، آن را بازیگر کنسول
       فرض نکنیم و به مالک اصلی برگردیم؛ روتر واقعی همیشه شناسهٔ مدیر معتبر دارد. */
    return $active > 0 && admin_is_console($active) ? $active : primary_owner_id();
}

function console_home_text(?int $uid = null): string
{
    $uid = console_actor_id($uid);
    $role = admin_role_of($uid);
    $roleLabel = admin_role_label($role);
    if (report_required()) {
        return '⚙️ <b>پنل مدیریت ' . e(brand()) . '</b>' . "\n" . SEP . "\n"
            . console_lock_text();
    }

    /* سطح ۱: فقط رسید کارت‌به‌کارت، بدون آمار مالی یا تنظیمات دیگر. */
    if (admin_can($uid, 'receipt') && !admin_can($uid, 'full')) {
        $pending = (int) DB::val("SELECT COUNT(*) FROM receipts WHERE status='pending'", [], 0);
        return "💳 <b>پنل رسیدهای کارت‌به‌کارت</b>\n" . SEP . "\n"
            . 'سطح شما: ' . e($roleLabel) . "\n"
            . '🧾 رسیدهای در انتظار: <b>' . fa_digits($pending) . '</b>' . "\n\n"
            . '<i>فقط تأیید یا رد رسیدها در این سطح مجاز است.</i>';
    }

    /* سطح ۲: فقط آمار ساده و کاربران. */
    if (admin_can($uid, 'stats.simple') && !admin_can($uid, 'full')) {
        $users = (int) DB::val('SELECT COUNT(*) FROM users', [], 0);
        $today = (int) DB::val('SELECT COUNT(*) FROM users WHERE created_at>=?', [now_ts() - DAY], 0);
        $live = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='active'", [], 0);
        return "💬 <b>پنل پشتیبانی</b>\n" . SEP . "\n"
            . 'سطح شما: ' . e($roleLabel) . "\n"
            . '👥 کاربران: ' . fa_digits($users) . ' · امروز: ' . fa_digits($today) . "\n"
            . '📦 پنل‌های فعال: ' . fa_digits($live) . "\n\n"
            . '<i>از جستجوی کاربران برای پاسخ‌گویی و پیگیری استفاده کنید.</i>';
    }

    /* سطح ۳: پنل‌ها، سرورها، تمدید و لاگ امن؛ نه رازها و نه مالی. */
    if (admin_can($uid, 'ops') && !admin_can($uid, 'full')) {
        $pgs = (int) DB::val('SELECT COUNT(*) FROM pg_panels WHERE active=1', [], 0);
        $live = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='active'", [], 0);
        $susp = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='suspended'", [], 0);
        return "🛠 <b>پنل عملیات فنی</b>\n" . SEP . "\n"
            . 'سطح شما: ' . e($roleLabel) . "\n"
            . '🔌 سرور/پنل متصل: ' . fa_digits($pgs) . "\n"
            . '📦 پنل‌های فعال: ' . fa_digits($live) . ' · معلق: ' . fa_digits($susp) . "\n\n"
            . '<i>کلیدهای API، درگاه‌ها، تنظیمات ربات و دیتابیس در این سطح پنهان‌اند.</i>';
    }

    /* مالک اصلی و هم‌مالک: منوی کامل. */
    $users = (int) DB::val('SELECT COUNT(*) FROM users', [], 0);
    $activeUsers = (int) DB::val("SELECT COUNT(*) FROM users WHERE tg_id IN "
        . '(SELECT DISTINCT tg_id FROM panels WHERE status=\'active\')', [], 0);
    $live = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='active'", [], 0);
    $products = (int) DB::val('SELECT COUNT(*) FROM products', [], 0);
    $pending = (int) DB::val("SELECT COUNT(*) FROM receipts WHERE status='pending'", [], 0);
    $pgs = (int) DB::val('SELECT COUNT(*) FROM pg_panels WHERE active=1', [], 0);
    $notice = '';
    if (update_is_newer(update_remote_version(false)['version'], PANEL_VERSION)) {
        $notice .= "\n⬆️ <b>نسخهٔ تازه در گیت‌هاب موجود است</b> — «⚙️ تنظیمات ربات» → «⬆️ بروزرسانی ربات»\n";
    }
    return '⚙️ <b>پنل مدیریت ' . e(brand()) . '</b>' . "\n"
        . SEP . "\n"
        . 'سطح شما: ' . e($roleLabel) . "\n"
        . $notice
        . '👥 کاربران کل: ' . fa_digits($users) . ' | 🟢 فعال: ' . fa_digits($activeUsers) . "\n"
        . '🔌 پنل‌های متصل: ' . fa_digits($pgs) . ' | 📦 محصولات: ' . fa_digits($products) . "\n"
        . '🧾 رسیدهای در انتظار: ' . fa_digits($pending)
        . ' | 🗂 پنل‌های فروخته‌شدهٔ فعال: ' . fa_digits($live) . "\n"
        . SEP . "\n"
        . 'یک بخش را انتخاب کنید:';
}

function console_kb(?int $uid = null): array
{
    $uid = console_actor_id($uid);
    if (report_required()) {
        if (admin_can($uid, 'full')) {
            return kb_clean([
                [btn('📢 تنظیم گپ گزارشات (الزامی)', 'ad:report:setup', null, 'success')],
            ]);
        }
        return kb_clean([
            [btn('🔄 بررسی دوباره', 'ad:home', null, 'primary')],
            [btn('🔙 بازگشت به منوی کاربری', 'home', null, 'danger')],
        ]);
    }
    if (admin_can($uid, 'receipt') && !admin_can($uid, 'full')) {
        return kb_clean([
            [btn('🧾 رسیدهای کارت‌به‌کارت', 'ad:receipts', null, 'success')],
            [btn('🔙 بازگشت به منوی کاربری', 'home', null, 'danger')],
        ]);
    }
    if (admin_can($uid, 'stats.simple') && !admin_can($uid, 'full')) {
        return kb_clean([
            [btn('👥 کاربران و پاسخ‌گویی', 'ad:users', null, 'primary'),
             btn('📊 آمار ساده', 'ad:stats', null, 'primary')],
            [btn('🔙 بازگشت به منوی کاربری', 'home', null, 'danger')],
        ]);
    }
    if (admin_can($uid, 'ops') && !admin_can($uid, 'full')) {
        return kb_clean([
            [btn('🔌 سرورها و پنل‌ها', 'ad:panel', null, 'primary'),
             btn('🏢 پنل‌های فروخته‌شده', 'ad:panels', null, 'primary')],
            [btn('🔍 جستجو / تمدید پنل مشتری', 'ad:find', null, 'success'),
             btn('📜 لاگ عملیات', 'ad:ops:logs', null, 'primary')],
            [btn('🔙 بازگشت به منوی کاربری', 'home', null, 'danger')],
        ]);
    }
    $rows = [
        [btn('📊 آمار و گزارشات', 'ad:stats', null, 'primary'),
         btn('🧾 رسیدها و مالی', 'ad:finance', null, 'primary')],
        [btn('👥 مدیریت کاربران', 'ad:users', null, 'primary'),
         btn('📦 محصولات و دسته‌ها', 'ad:cats', null, 'primary')],
        [btn('🔌 پنل‌های پاسارگاد', 'ad:panel', null, 'primary'),
         btn('⚙️ تنظیمات ربات', 'ad:settings', null, 'primary')],
        [btn('🔍 جستجوی سفارشات', 'ad:find', null, 'primary'),
         btn('📢 پیام همگانی', 'ad:broadcast', null, 'success')],
        [btn('📢 قفل کانال اجباری', 'ad:fj', null, 'success')],
    ];
    /* فقط مالک اصلی می‌تواند به نقش‌ها دست بزند؛ هم‌مالک منوی کامل دارد اما
       هرگز نمی‌تواند مالک یا هم‌مالک دیگری را عزل کند. */
    if (admin_can_manage_roles($uid)) {
        $rows[] = [btn('👥 تعیین مالک و ادمین‌ها', 'ad:staff', null, 'success')];
    }
    $rows[] = [btn('🔙 بازگشت به منوی کاربری', 'home', null, 'danger')];
    return kb_clean($rows);
}

function group_kb(string $group): array
{
    $rows = match ($group) {
        'sales' => [
            [btn('📊 آمار', 'ad:stats'), btn('🧾 رسیدها', 'ad:receipts')],
            [btn('💳 مالی', 'ad:finance'), btn('👥 کاربران', 'ad:users')],
        ],
        'shop' => [
            [btn('🗂 دسته‌بندی‌ها', 'ad:cats', null, 'success')],
            [btn('📦 همهٔ محصولات', 'ad:products'), btn('🧩 اسلات‌ها', 'ad:slots')],
        ],
        'panels' => [
            [btn('🔌 لیست پنل‌ها', 'ad:panel', null, 'success')],
            [btn('🏢 پنل‌های فروخته‌شده', 'ad:panels')],
        ],
        default => [
            [btn('💳 درگاه‌های پرداخت', 'ad:pay', null, 'success')],
            [btn('⚙️ تنظیمات', 'ad:settings'), btn('📢 پیام همگانی', 'ad:broadcast')],
            [btn('📢 تنظیم گپ گزارشات', 'ad:report'), btn('💾 بکاپ', 'ad:backup')],
            [btn('❓ راهنما', 'ad:help')],
        ],
    };
    $rows[] = [btn('« مدیریت', 'ad:home', null, 'danger')];
    return $rows;
}

function screen_console(int|string $chat, ?int $messageId, ?int $uid = null): void
{
    $GLOBALS['panel_console_root'] = true;
    try {
        $actor = console_actor_id($uid);
        Tg::show($chat, $messageId, console_home_text($actor), console_kb($actor));
    } finally {
        $GLOBALS['panel_console_root'] = false;
    }
}

/** آخرین لاگ‌ها برای اپراتور؛ رازهای شناخته‌شده پیش از نمایش ماسک می‌شوند. */
function ops_log_safe_line(string $line): string
{
    $line = preg_replace('/\b\d{6,12}:[A-Za-z0-9_-]{20,}\b/', '[BOT_TOKEN_HIDDEN]', $line) ?? $line;
    $line = preg_replace('/\b(?:pg_key|apikey)-[A-Za-z0-9_-]+\b/i', '[API_KEY_HIDDEN]', $line) ?? $line;
    $line = preg_replace('/\b(?:ghp|github_pat)_[A-Za-z0-9_]+\b/i', '[GITHUB_TOKEN_HIDDEN]', $line) ?? $line;
    /* پاسخ خام API گاهی JSON یا query string حاوی راز دارد. */
    $line = preg_replace('/([?&](?:token|api[_-]?key|key|secret|password)=)[^&\s]+/i',
        '$1[SECRET_HIDDEN]', $line) ?? $line;
    $line = preg_replace('/((?:api[_-]?key|token|secret|password|authorization)\s*["\']?\s*[:=]\s*["\']?)[^,\s"\'}]+/i',
        '$1[SECRET_HIDDEN]', $line) ?? $line;
    $line = preg_replace('/\b(?:sqlite|mysql|pgsql):[^\s]+/i', '[DATABASE_DSN_HIDDEN]', $line) ?? $line;
    return $line;
}

function screen_ops_logs(int|string $chat, ?int $messageId): void
{
    $paths = [log_dir() . '/bot_error.log', log_dir() . '/device_guard.log', panel_home() . '/sibil.log'];
    $lines = [];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $all = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($all, -8) as $line) {
            $lines[] = ops_log_safe_line((string) $line);
        }
    }
    $body = $lines ? implode("\n", array_slice($lines, -20)) : 'لاگ قابل نمایشی ثبت نشده است.';
    $body = mb_substr($body, 0, 3200);
    Tg::show($chat, $messageId, "📜 <b>لاگ عملیات</b>\n" . SEP . "\n<pre>"
        . e($body) . '</pre>',
        [[btn('🔄 به‌روزرسانی', 'ad:ops:logs', null, 'primary')],
         [btn('« مدیریت', 'ad:home', null, 'danger')]]);
}

/** نام کوتاه مدیر، نخست به‌صورت متن ساده برای دکمه و سپس HTML امن برای پیام. */
function admin_staff_plain_display(int $targetId): string
{
    $user = user_get($targetId);
    if ($user === null) {
        return (string) $targetId;
    }
    $name = trim((string) ($user['name'] ?? ''));
    $uname = trim((string) ($user['uname'] ?? ''));
    $label = $name !== '' ? mb_substr($name, 0, 22) : ($uname !== '' ? '@' . $uname : 'کاربر');
    return $label . ' · ' . $targetId;
}

function admin_staff_display(int $targetId): string
{
    $user = user_get($targetId);
    if ($user === null) {
        return '<code>' . $targetId . '</code>';
    }
    $name = trim((string) ($user['name'] ?? ''));
    $uname = trim((string) ($user['uname'] ?? ''));
    $label = $name !== '' ? mb_substr($name, 0, 22) : ($uname !== '' ? '@' . $uname : 'کاربر');
    return e($label) . ' · <code>' . $targetId . '</code>';
}

/** فهرست نقش‌های مدیریتی؛ تنها مالک اصلی به این صفحه دسترسی دارد. */
function screen_admin_staff(int|string $chat, ?int $messageId, int $page = 1): void
{
    $actor = console_actor_id();
    if (!admin_can_manage_roles($actor)) {
        Tg::show($chat, $messageId, '⛔️ فقط مالک اصلی می‌تواند سطح دسترسی تعیین کند.',
            [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
        return;
    }
    $all = admin_role_rows();
    $perPage = 7;
    $pages = max(1, (int) ceil(count($all) / $perPage));
    $page = max(1, min($page, $pages));
    $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
    $primary = primary_owner_id();
    $text = '👥 <b>تعیین مالک و ادمین‌ها</b>' . "\n" . SEP . "\n"
        . '👑 مالک اصلیِ قفل‌شده: <code>' . $primary . "</code>\n"
        . '<i>شناسهٔ مالک اصلی در .env و پایگاه‌داده همگام است؛ حذف یا تنزل آن ممکن نیست.</i>'
        . "\n\n" . 'مدیران فعال: <b>' . fa_digits(count($all)) . '</b>'
        . ' · صفحه ' . fa_digits($page) . ' از ' . fa_digits($pages) . "\n"
        . SEP . "\n" . 'روی هر نفر بزنید تا سطح دسترسی‌اش را ببینید یا تغییر دهید.';
    $rows = [];
    foreach ($slice as $row) {
        $id = (int) ($row['tg_id'] ?? 0);
        $role = (string) ($row['role'] ?? '');
        if ($id <= 0) {
            continue;
        }
        $lock = $role === 'owner' ? ' 🔒' : '';
        $rows[] = [btn(admin_role_label($role) . $lock . ' · ' . admin_staff_plain_display($id),
            'ad:staff:member:' . $id, null, $role === 'owner' ? 'primary' : 'auto')];
    }
    $rows[] = [btn('➕ افزودن ادمین با شناسهٔ عددی', 'ad:staff:add', null, 'success')];
    $nav = [];
    if ($page > 1) {
        $nav[] = btn('› صفحهٔ قبل', 'ad:staff:page:' . ($page - 1));
    }
    if ($page < $pages) {
        $nav[] = btn('صفحهٔ بعد ‹', 'ad:staff:page:' . ($page + 1));
    }
    if ($nav !== []) {
        $rows[] = $nav;
    }
    $rows[] = [btn('« مدیریت', 'ad:home', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** کارت یک مدیر و انتخاب سطح او. */
function screen_admin_staff_member(int|string $chat, ?int $messageId, int $targetId): void
{
    $primary = primary_owner_id();
    $role = admin_role_of($targetId);
    if ($targetId <= 0 || $role === '') {
        screen_admin_staff($chat, $messageId);
        return;
    }
    if ($targetId === $primary) {
        Tg::show($chat, $messageId,
            '👑 <b>مالک اصلی</b>' . "\n" . SEP . "\n"
            . 'حساب: ' . admin_staff_display($targetId) . "\n"
            . 'سطح: ' . admin_role_label('owner') . "\n\n"
            . '<i>این شناسه از .env خوانده و در DB همگام می‌شود؛ هیچ ادمینی، حتی هم‌مالک، '
            . 'نمی‌تواند آن را حذف یا تنزل دهد.</i>',
            [[btn('« فهرست مدیران', 'ad:staff', null, 'danger')]]);
        return;
    }
    $text = '👤 <b>سطح دسترسی مدیر</b>' . "\n" . SEP . "\n"
        . 'حساب: ' . admin_staff_display($targetId) . "\n"
        . 'سطح فعلی: <b>' . admin_role_label($role) . "</b>\n\n"
        . 'یک سطح را انتخاب کنید. تغییر سطح همان لحظه اعمال می‌شود و ویزاردهای قبلی بسته می‌شوند.';
    $rows = [];
    foreach (admin_role_choices() as $key => $label) {
        $rows[] = [btn(($key === $role ? '✅ ' : '') . $label,
            'ad:staff:assign:' . $targetId . ':' . $key, null,
            $key === $role ? 'success' : 'primary')];
    }
    $rows[] = [btn('🗑 حذف کامل دسترسی', 'ad:staff:remove:' . $targetId, null, 'danger')];
    $rows[] = [btn('« فهرست مدیران', 'ad:staff', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** مرحلهٔ دوم افزودن مدیر پس از دریافت شناسهٔ عددی. */
function screen_admin_staff_role_picker(int|string $chat, ?int $messageId, int $targetId): void
{
    if ($targetId <= 0) {
        screen_admin_staff($chat, $messageId);
        return;
    }
    if (admin_is_primary($targetId)) {
        screen_admin_staff_member($chat, $messageId, $targetId);
        return;
    }
    $current = admin_role_of($targetId);
    $rows = [];
    foreach (admin_role_choices() as $key => $label) {
        $rows[] = [btn(($key === $current ? '✅ ' : '') . $label,
            'ad:staff:assign:' . $targetId . ':' . $key, null,
            $key === $current ? 'success' : 'primary')];
    }
    $rows[] = [btn('« فهرست مدیران', 'ad:staff', null, 'danger')];
    Tg::show($chat, $messageId, '➕ <b>افزودن یا تغییر ادمین</b>' . "\n" . SEP . "\n"
        . 'حساب: ' . admin_staff_display($targetId) . "\n\n"
        . 'اکنون سطح دسترسی را انتخاب کنید:', $rows);
}

/** callbackهای مدیریت نقش؛ گارد مرکزی نیز پیش از این تابع مالک اصلی را کنترل می‌کند. */
function admin_staff_callback(int $uid, int|string $chat, ?int $messageId, string $cbId,
                              array $parts): void
{
    if (!admin_can_manage_roles($uid)) {
        Tg::answer($cbId, '⛔️ فقط مالک اصلی مجاز است.', true);
        screen_console($chat, $messageId, $uid);
        return;
    }
    $op = (string) ($parts[2] ?? '');
    if ($op === '' || $op === 'page') {
        screen_admin_staff($chat, $messageId, (int) ($parts[3] ?? 1));
        return;
    }
    if ($op === 'add') {
        wiz_start($uid, 'admin_staff_target', [
            step_def('tg_id', 'شناسهٔ عددی تلگرام ادمین را بفرستید.' . "\n"
                . '<i>نام کاربری کافی نیست؛ فقط عدد معتبر تلگرام را وارد کنید.</i>',
                'int', null, '👥 افزودن ادمین'),
        ]);
        return;
    }
    $targetId = (int) ($parts[3] ?? 0);
    if ($op === 'member') {
        screen_admin_staff_member($chat, $messageId, $targetId);
        return;
    }
    if ($op === 'assign') {
        $role = (string) ($parts[4] ?? '');
        [$ok, $note] = admin_role_assign($uid, $targetId, $role);
        Tg::answer($cbId, $note, !$ok);
        if ($ok) {
            screen_admin_staff_member($chat, $messageId, $targetId);
        } else {
            screen_admin_staff($chat, $messageId);
        }
        return;
    }
    if ($op === 'remove') {
        if ($targetId <= 0 || admin_is_primary($targetId)) {
            Tg::answer($cbId, 'مالک اصلی قفل است.', true);
            screen_admin_staff_member($chat, $messageId, $targetId);
            return;
        }
        Tg::show($chat, $messageId, '🗑 <b>حذف دسترسی ادمین</b>' . "\n\n"
            . 'دسترسی «' . admin_staff_display($targetId) . '» کامل حذف شود؟' . "\n"
            . '<i>اگر هم‌مالک باشد نیز فقط مالک اصلی می‌تواند او را حذف کند.</i>',
            [[btn('🗑 بله، حذف شود', 'ad:staff:removeok:' . $targetId, null, 'danger')],
             [btn('« انصراف', 'ad:staff:member:' . $targetId, null, 'primary')]]);
        return;
    }
    if ($op === 'removeok') {
        [$ok, $note] = admin_role_remove($uid, $targetId);
        Tg::answer($cbId, $note, !$ok);
        screen_admin_staff($chat, $messageId);
        return;
    }
    screen_admin_staff($chat, $messageId);
}

function screen_categories(int|string $chat, ?int $messageId): void
{
    $cats = cat_list();
    if (!$cats) {
        Tg::show($chat, $messageId,
            "🗂 <b>دسته‌بندی‌ها</b>\n\nهنوز دسته‌ای نساخته‌ای.\n"
            . 'اول یک دسته بساز (مثلاً «حجمی» یا «تانل»)، بعد داخلش محصول اضافه کنید.',
            [[btn('➕ دستهٔ جدید', 'ad:cat:new', null, 'success')],
             [btn('« مدیریت', 'ad:home', null, 'danger')]]);
        return;
    }
    $counts = [];
    foreach (DB::all('SELECT category_id, COUNT(*) AS n FROM products '
        . 'WHERE category_id IS NOT NULL GROUP BY category_id') as $row) {
        $counts[(int) $row['category_id']] = (int) $row['n'];
    }
    $rows = [];
    foreach ($cats as $cat) {
        $mark = (int) $cat['active'] === 1 ? '' : ' ⛔️';
        $rows[] = [btn(cat_label($cat) . ' (' . fa_digits($counts[(int) $cat['id']] ?? 0) . ")$mark",
            'ad:cat:' . $cat['id'], null, (int) $cat['active'] === 1 ? 'primary' : 'danger')];
    }
    $rows[] = [btn('➕ دستهٔ جدید', 'ad:cat:new', null, 'success')];
    $rows[] = [btn('« مدیریت', 'ad:home', null, 'danger')];
    Tg::show($chat, $messageId,
        "🗂 <b>دسته‌بندی‌ها</b>\n" . SEP . "\n"
        . 'روی هر دسته بزنید تا محصول‌هایش را ببینید یا اضافه کنید.' . "\n" . SEP . "\n"
        . 'برای ساخت دستهٔ تازه: ➕ دستهٔ جدید', $rows);
}

function screen_category(int|string $chat, ?int $messageId, int $catId): void
{
    $cat = cat_get($catId);
    if ($cat === null) {
        screen_categories($chat, $messageId);
        return;
    }
    $products = DB::all('SELECT * FROM products WHERE category_id=? ORDER BY sort, id', [$catId]);
    $text = cat_label($cat) . ' · <b>' . e((string) $cat['name']) . "</b>\n\n";
    $rows = [];
    foreach ($products as $product) {
        $text .= ((int) $product['active'] === 1 ? '✅' : '⛔️') . ' ' . $product['emoji'] . ' <b>'
            . e($product['name']) . "</b>\n";
        if (product_is_unlimited($product)) {
            $text .= '   ♾️ نامحدود · 💵 هر کاربر ' . money(unlimited_user_price($product))
                . ' · 👥 حداقل ' . fa_digits(unlimited_min_users($product)) . "\n\n";
        } else {
            $cap = (float) $product['data_cap_gb'] > 0
                ? fa_digits((float) $product['data_cap_gb']) . 'GB' : 'نامحدود';
            $text .= '   ⏱ ' . money((int) $product['hourly_price']) . ' /ساعت · 💵 '
                . money((int) $product['per_gb_price']) . " /گیگ\n"
                . '   👤 ' . fa_digits((int) $product['max_users']) . ' کاربر · 📱 '
                . fa_digits((int) $product['device_limit']) . ' دستگاه · 📦 ' . $cap
                . ' · ⏳ ' . fa_digits((int) $product['days']) . " روز\n\n";
        }
        $rows[] = [btn($product['emoji'] . ' ' . $product['name'], 'ad:prod:' . $product['id'],
            null, (int) $product['active'] === 1 ? 'primary' : 'danger')];
    }
    if (!$products) {
        $text .= "هنوز محصولی در این دسته نیست.";
    }
    $text .= "\n📦 تعداد: " . fa_digits(count($products));
    $rows[] = [btn('➕ محصول جدید', 'ad:cat:' . $catId . ':new', null, 'success')];
    $rows[] = [btn('✏️ نام دسته', 'ad:cat:' . $catId . ':edit'),
               btn((int) $cat['active'] === 1 ? '🔴 خاموش' : '🟢 روشن',
                   'ad:cat:' . $catId . ':toggle', null, 'danger')];
    $rows[] = [btn('🗑 حذف دسته', 'ad:cat:' . $catId . ':del', null, 'danger')];
    $rows[] = [btn('« دسته‌ها', 'ad:cats', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_product_admin(int|string $chat, ?int $messageId, int $productId): void
{
    $product = product_get($productId);
    if ($product === null) {
        screen_categories($chat, $messageId);
        return;
    }
    $panel = $product['panel_id']
        ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $product['panel_id']]) : null;
    $cat = $product['category_id'] ? cat_get((int) $product['category_id']) : null;
    $base = $product['emoji'] . ' <b>' . e((string) $product['name']) . '</b> '
        . ((int) $product['active'] === 1 ? '✅' : '⛔️') . "\n"
        . '🗂 دسته: ' . cat_label($cat) . ' · 🔌 پنل: '
        . e($panel['name'] ?? 'پیش‌فرض') . "\n\n";
    if (product_is_unlimited($product)) {
        $text = $base
            . '♾️ <b>نوع فروش: پنل نامحدود بر پایهٔ تعداد کاربر</b>' . "\n"
            . '💵 قیمت هر کاربر: <b>' . money(unlimited_user_price($product)) . "</b>\n"
            . '👥 حداقل تعداد کاربر در هر خرید/شارژ: <b>'
                . fa_digits(unlimited_min_users($product)) . "</b>\n"
            . '🎭 نقش پنل: '
            . role_title((int) $product['panel_id'], (int) $product['role_id']) . "\n\n"
            . UNLIMITED_ROLE_GUIDE;
        $rows = [
            [btn('💵 قیمت هر کاربر', "ad:pf:$productId:peruser"),
             btn('👥 حداقل تعداد کاربر', "ad:pf:$productId:minusers")],
            [btn('✏️ نام', "ad:pf:$productId:name"), btn('🎨 ایموجی', "ad:pf:$productId:emoji")],
            [btn('🔌 پنل مقصد', "ad:prod:$productId:sp"),
             btn('🎭 نقش پنل', 'ad:rolep:' . $productId)],
            [btn((int) $product['active'] === 1 ? '🔴 خاموش کنید' : '🟢 روشن کنید',
                "ad:prod:$productId:toggle", null, (int) $product['active'] === 1 ? 'danger' : 'success')],
            [btn('🗑 حذف محصول', "ad:prod:$productId:del", null, 'danger')],
            [btn('« بازگشت به دسته', 'ad:cat:' . (int) ($product['category_id'] ?? 0), null, 'danger')],
        ];
        Tg::show($chat, $messageId, $text, $rows);
        return;
    }
    $cap = (float) $product['data_cap_gb'] > 0
        ? fa_digits((float) $product['data_cap_gb']) . ' گیگ' : 'نامحدود';
    $text = $base
        . '⏱ اجارهٔ ساعتی: <b>' . money((int) $product['hourly_price']) . "</b>\n"
        . '💵 قیمت هر گیگ: <b>' . money((int) $product['per_gb_price']) . "</b>\n"
        . '💰 حداقل موجودی: ' . money((int) $product['min_balance']) . "\n"
        . '👤 سقف کاربر: ' . fa_digits((int) $product['max_users']) . ' · 📱 دستگاه: '
        . fa_digits((int) $product['device_limit']) . "\n"
        . '⏳ اعتبار: ' . fa_digits((int) $product['days']) . ' روز · 📦 حجم: ' . $cap . "\n"
        . '🎭 نقش پنل: '
        . role_title((int) $product['panel_id'], (int) $product['role_id']) . "\n"
        . '🏷 پیشوند: <code>' . e($product['panel_prefix']) . '</code>';
    $rows = [
        [btn('⏱ قیمت ساعتی', "ad:pf:$productId:hourly"),
         btn('💵 قیمت گیگی', "ad:pf:$productId:pergb")],
        [btn('👤 سقف کاربر', "ad:pf:$productId:users"),
         btn('📱 دستگاه', "ad:pf:$productId:devices")],
        [btn('➕ بلاک کاربر', "ad:pf:$productId:block"),
         btn('💵 قیمت بلاک', "ad:pf:$productId:blockprice")],
        [btn('⏳ روز اعتبار', "ad:pf:$productId:days"),
         btn('📦 سقف حجم', "ad:pf:$productId:cap")],
        [btn('✏️ نام', "ad:pf:$productId:name"),
         btn('🎨 ایموجی', "ad:pf:$productId:emoji")],
        [btn('🏷 پیشوند نام پنل', "ad:pf:$productId:prefix")],
        [btn('💰 حداقل موجودی', "ad:pf:$productId:minbal"),
         btn('🔌 پنل مقصد', "ad:prod:$productId:sp")],
        [btn('🎭 نقش پنل', 'ad:rolep:' . $productId)],
        [btn((int) $product['active'] === 1 ? '🔴 خاموش کنید' : '🟢 روشن کنید',
            "ad:prod:$productId:toggle", null, (int) $product['active'] === 1 ? 'danger' : 'success')],
        [btn('🗑 حذف محصول', "ad:prod:$productId:del", null, 'danger')],
        [btn('« بازگشت به دسته', 'ad:cat:' . (int) ($product['category_id'] ?? 0), null, 'danger')],
    ];
    Tg::show($chat, $messageId, $text, $rows);
}

const PRODUCT_FIELDS = [
    'name'      => ['نام محصول', 'text', 'name'],
    'emoji'     => ['ایموجی', 'text', 'emoji'],
    'hourly'    => ['اجارهٔ ساعتی پنل (تومان) — 0 = بدون اجاره', 'int', 'hourly_price'],
    'pergb'     => ['قیمت هر گیگ مصرف (تومان)', 'int', 'per_gb_price'],
    'peruser'   => ['قیمت هر کاربر پنل نامحدود (تومان)', 'int', 'per_user_price'],
    'minusers'  => ['حداقل تعداد کاربر پنل نامحدود', 'int', 'min_users'],
    'minbal'    => ['حداقل موجودی کیف پول (تومان)', 'int', 'min_balance'],
    'users'     => ['سقف کاربر پنل', 'int', 'max_users'],
    'devices'   => ['تعداد دستگاه برای هر کاربر', 'int', 'device_limit'],
    'days'      => ['مدت اعتبار پنل (روز)', 'int', 'days'],
    'cap'       => ['سقف حجم پنل (گیگ) — 0 = نامحدود', 'float', 'data_cap_gb'],
    'prefix'    => ['پیشوند نام کاربری پنل‌های ساخته‌شده (حروف و عدد)', 'text', 'panel_prefix'],
    'role'      => ['شناسهٔ نقش پاسارگارد', 'int', 'role_id'],
    'panel'     => ['شمارهٔ پنل مقصد', 'int', 'panel_id'],
    'block'     => ['تعداد کاربر در هر بلاک اضافه (مثلاً 1000)', 'int', 'extra_block_size'],
    'blockprice' => ['قیمت هر بلاک کاربر اضافه (تومان) — 0 = غیرفعال', 'int',
                     'extra_block_price'],
];

function screen_pg_panels(int|string $chat, ?int $messageId): void
{
    $canFull = admin_can(console_actor_id(), 'full');
    $panels = DB::all('SELECT * FROM pg_panels ORDER BY is_default DESC, id');
    if (!$panels) {
        $rows = [[btn('« مدیریت', 'ad:home', null, 'danger')]];
        $hint = 'هنوز هیچ پنلی وصل نیست.';
        if ($canFull) {
            $rows = [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')],
                [btn('⚙️ تنظیمات اتصال', 'ad:setg:paneltest')],
                [btn('« مدیریت', 'ad:home', null, 'danger')]];
            $hint .= "\nبا «➕ افزودن پنل» نام، آدرس و <b>کلید API</b> پنل را بدهید.";
        } else {
            $hint .= "\n<i>افزودن پنل و کلید API فقط برای مالک یا هم‌مالک مجاز است.</i>";
        }
        Tg::show($chat, $messageId, "🔌 <b>پنل‌های پاسارگارد</b>\n\n" . $hint, $rows);
        return;
    }
    $text = "🔌 <b>پنل‌های پاسارگارد</b>\n" . SEP . "\n"
        . 'هر پنل یک داشبورد اختصاصی دارد: آمار زنده، سرور، فروش و کنترل دستگاه.' . "\n"
        . SEP . "\n\n";
    /* تست اتصال دستی حذف شد؛ اتصال هر پنل همان لحظهٔ ورود به داشبورد
       خودکار بررسی می‌شود */
    $rows = $canFull ? [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')]] : [];
    foreach ($panels as $panel) {
        $flags = [];
        if ((int) $panel['is_default'] === 1) {
            $flags[] = 'پیش‌فرض ⭐';
        }
        if ((int) $panel['active'] !== 1) {
            $flags[] = 'خاموش 🔴';
        }
        $text .= '· <b>' . e((string) $panel['name']) . '</b>'
            . ($flags ? ' (' . implode(' · ', $flags) . ')' : '') . "\n"
            . '   <code>' . e(normalize_panel_url((string) $panel['url'])) . '</code>' . "\n"
            . '   اتصال: 🔑 API Key' . (trim((string) ($panel['api_key'] ?? '')) === ''
                ? ' — <i>کلید ثبت نشده</i>' : '')
            . ((int) $panel['last_ok_at'] ? '' : ' — <i>بدون تست موفق</i>') . "\n\n";
        $rows[] = [btn(((int) $panel['is_default'] === 1 ? '⭐ ' : '') . $panel['name'],
            'ad:pg:' . $panel['id'], null, (int) $panel['active'] === 1 ? 'primary' : 'danger')];
    }
    if ($canFull) {
        $rows[] = [btn('⚙️ تنظیمات اتصال', 'ad:setg:paneltest')];
    }
    $rows[] = [btn('« مدیریت', 'ad:home', null, 'danger')];
    Tg::show($chat, $messageId, $text . 'روی هر پنل بزنید تا داشبوردش باز شود.', $rows);
}

/**
 * آمار زندهٔ یک پنل پاسارگارد برای داشبورد مدیریت.
 * خروجی: online, users, active_users, version, mem_total, mem_used, devices, error
 */
function pg_panel_dashboard_data(array $panel): array
{
    $out = ['online' => false, 'users' => 0, 'active_users' => 0, 'version' => '',
            'mem_total' => 0, 'mem_used' => 0, 'devices' => 0, 'error' => ''];
    try {
        $client = new PgPanel($panel);
        $info = $client->admin_info((string) ($panel['username'] ?? ''));
        $sys = $client->system_info();
        $out['online'] = true;
        $out['users'] = (int) ($info['total_users'] ?? ($sys['total_user'] ?? 0));
        $out['active_users'] = (int) ($info['users_active'] ?? ($sys['users_active'] ?? 0));
        $out['devices'] = (int) ($info['hwid_count'] ?? 0);
        $out['version'] = (string) ($sys['version'] ?? ($sys['details']['version'] ?? ''));
        $out['mem_total'] = (int) ($sys['mem_total'] ?? 0);
        $out['mem_used'] = (int) ($sys['mem_used'] ?? 0);
        DB::exec('UPDATE pg_panels SET last_ok_at=?, last_error=NULL WHERE id=?',
            [now_ts(), (int) $panel['id']]);
    } catch (Throwable $ex) {
        $out['error'] = mb_substr($ex->getMessage(), 0, 160);
        DB::exec('UPDATE pg_panels SET last_error=? WHERE id=?',
            [$out['error'], (int) $panel['id']]);
    }
    return $out;
}

/** آمار فروش همین پنل: تعداد و مبلغ (فقط سفارش‌های تحویل‌شده) */
function pg_panel_sales(int $pgPanelId): array
{
    $row = DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(o.payable),0) AS total '
        . 'FROM panels p LEFT JOIN orders o ON o.id=p.order_id '
        . "WHERE p.pg_panel_id=? AND p.is_trial=0 AND (o.status IS NULL OR o.status='delivered')",
        [$pgPanelId]);
    return ['count' => (int) ($row['n'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
}

/** حجم را برای نمایش داشبورد: بایت → گیگابایت/مگابایت/ترابایت */
function pg_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '—';
    }
    if ($bytes >= 1099511627776) {
        return number_format($bytes / 1099511627776, 2) . ' ترابایت';
    }
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' گیگابایت';
    }
    return number_format($bytes / 1048576, 1) . ' مگابایت';
}

/**
 * 📊 داشبورد اختصاصی هر پنل پاسارگارد:
 * آمار زنده از API + فروش همین پنل + همهٔ کارهای مدیریتی در یک صفحه.
 */
function screen_pg_panel(int|string $chat, ?int $messageId, int $panelId): void
{
    $canFull = admin_can(console_actor_id(), 'full');
    $panel = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        screen_pg_panels($chat, $messageId);
        return;
    }
    $live = pg_panel_dashboard_data($panel);
    $sales = pg_panel_sales($panelId);
    $products = (int) DB::val('SELECT COUNT(*) FROM products WHERE panel_id=?', [$panelId], 0);
    $sold = (int) DB::val('SELECT COUNT(*) FROM panels WHERE pg_panel_id=?', [$panelId], 0);
    $traffic = (int) DB::val('SELECT COALESCE(SUM(last_usage),0) FROM panels WHERE pg_panel_id=?',
        [$panelId], 0);
    $serviceSummary = $canFull
        ? '💰 <b>وضعیت فروش این پنل</b>' . "\n"
            . '• تعداد کل فروش: ' . fa_digits($sales['count']) . ' عدد' . "\n"
            . '• مجموع فروش: ' . money($sales['total']) . "\n"
            . '• محصولات روی این پنل: ' . fa_digits($products)
            . ' · پنل‌های فروخته‌شده: ' . fa_digits($sold) . "\n"
        : '📦 <b>وضعیت سرویس‌های این پنل</b>' . "\n"
            . '• محصولات روی این پنل: ' . fa_digits($products)
            . ' · پنل‌های فروخته‌شده: ' . fa_digits($sold) . "\n";
    $offlineHint = $canFull
        ? 'می‌توانید پنل را از ربات حذف کنید.'
        : 'با مالک یا هم‌مالک برای تغییر اتصال هماهنگ کنید.';

    /* ورود به داشبورد = تست خودکار اتصال؛ هیچ دکمهٔ تست دستی لازم نیست */
    $head = '📊 <b>آمار و وضعیت پنل: ' . e((string) $panel['name']) . '</b>' . "\n"
        . SEP . "\n";
    if (!$live['online']) {
        $text = $head
            . '⚠️ خطا در برقراری ارتباط با پنل پاسارگاد (سرور در دسترس نیست یا اطلاعات '
            . 'لاگین نامعتبر است).' . "\n\n"
            . ($live['error'] !== '' ? 'جزئیات خطا: <code>'
                . e(mb_substr(ops_log_safe_line((string) $live['error']), 0, 160)) . "</code>\n\n" : '')
            . '📡 وضعیت اتصال: قطع ❌' . "\n"
            . SEP . "\n"
            . 'آدرس ثبت‌شده: <code>' . e(normalize_panel_url((string) $panel['url'])) . '</code>'
            . "\n"
            . 'آخرین اتصال موفق: ' . fa_ts((int) $panel['last_ok_at']) . "\n"
            . 'وضعیت در ربات: ' . ((int) $panel['active'] === 1 ? '🟢 روشن' : '🔴 خاموش')
            . ((int) $panel['is_default'] === 1 ? ' · پیش‌فرض ⭐' : '') . "\n"
            . SEP . "\n"
            . '<i>آمار جعلی یا خالی نمایش داده نمی‌شود. اگر سرور از کار افتاده، '
            . $offlineHint . '</i>';
    } else {
        $text = $head
            . '📡 وضعیت اتصال: متصل و آنلاین ✅' . "\n"
            . '👥 تعداد کل کاربران پنل: ' . fa_digits($live['users']) . ' نفر' . "\n"
            . '🟢 کاربران فعال: ' . fa_digits($live['active_users']) . ' نفر' . "\n"
            . '📱 دستگاه‌های متصل (HWID): ' . fa_digits($live['devices']) . ' دستگاه' . "\n"
            . '📦 نسخه پنل پاسارگارد: ' . ($live['version'] !== '' ? 'v' . e($live['version']) : '—')
            . "\n\n"
            . '🖥 <b>مشخصات سرور</b>' . "\n"
            . '• رم کل سرور: ' . pg_size($live['mem_total']) . "\n"
            . '• مصرف رم پنل: ' . pg_size($live['mem_used']) . "\n"
            . '• ترافیک مصرف‌شدهٔ پنل‌های فروخته‌شده: ' . pg_size($traffic) . "\n\n"
            . $serviceSummary
            . SEP . "\n"
            . 'آدرس: <code>' . e(normalize_panel_url((string) $panel['url'])) . '</code>' . "\n"
            . 'وضعیت در ربات: ' . ((int) $panel['active'] === 1 ? '🟢 روشن' : '🔴 خاموش')
            . ((int) $panel['is_default'] === 1 ? ' · پیش‌فرض ⭐' : '') . "\n"
            . 'آخرین اتصال موفق: ' . fa_ts((int) $panel['last_ok_at']) . "\n"
            . (!empty($panel['last_error']) ? 'آخرین خطا: <code>'
                . e(mb_substr(ops_log_safe_line((string) $panel['last_error']), 0, 160)) . "</code>\n" : '')
            . "\n" . 'برای مدیریت این پنل یکی از گزینه‌های زیر را انتخاب کنید:';
    }

    /* اپراتور فقط ابزارهای روزانهٔ سرور را می‌بیند؛ اتصال و ساختار سرور
       (آدرس، کلید، حذف، دسته، پیش‌فرض و روشن/خاموش) برای مالک/هم‌مالک است. */
    $rows = [
        [btn('🔄 بروزرسانی آمار', 'ad:pg:' . $panelId, null, 'success'),
         btn('📱 کنترل دستگاه‌ها (HWID)', 'ad:pg:' . $panelId . ':dev')],
        [btn('🔄 همگام‌سازی کاربران', 'ad:pg:' . $panelId . ':sync'),
         btn('🎭 نقش‌ها', 'ad:pg:' . $panelId . ':roles')],
    ];
    if ($canFull) {
        $rows[] = [btn('🗑 حذف این پنل از ربات', 'ad:pg:' . $panelId . ':del', null, 'danger')];
        $rows[] = [btn('✏️ ویرایش نام / آدرس', 'ad:pg:' . $panelId . ':edit'),
            btn('🔑 تغییر کلید API', 'ad:pg:' . $panelId . ':key', null, 'success')];
        $rows[] = [btn('🗂 دسته', 'ad:pg:' . $panelId . ':cat')];
        $rows[] = [btn('⭐ پیش‌فرض', 'ad:pg:' . $panelId . ':default'),
            btn((int) $panel['active'] === 1 ? '🔴 خاموش کنید' : '🟢 روشن کنید',
                'ad:pg:' . $panelId . ':toggle', null, 'danger')];
    }
    $rows[] = [btn('🔙 بازگشت به لیست پنل‌ها', 'ad:panel', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/**
 * 📱 کنترل دستگاه‌های یک پنل پاسارگارد (از داخل داشبورد همان پنل).
 * محدودسازی، مثل همیشه، فقط روی همان کاربر متخلف است.
 */
function screen_pg_devices(int|string $chat, ?int $messageId, int $panelId): void
{
    $canFull = admin_can(console_actor_id(), 'full');
    $panel = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        screen_pg_panels($chat, $messageId);
        return;
    }
    $guard = set_bool('device_guard_on', true);
    $maxDevices = max(1, set_int('device_max_devices', 10));
    $minutes = max(1, set_int('device_cut_minutes', 10));
    $limited = DB::all('SELECT * FROM temp_restricted_users WHERE panel_id IN '
        . '(SELECT id FROM panels WHERE pg_panel_id=?) ORDER BY unban_at ASC', [$panelId]);
    $live = pg_panel_dashboard_data($panel);
    $text = '📱 <b>کنترل دستگاه‌ها — ' . e((string) $panel['name']) . '</b>' . "\n"
        . SEP . "\n"
        . 'دستگاه‌های متصل اکنون: ' . fa_digits($live['devices']) . "\n"
        . 'سقف مجاز هر کانفیگ: ' . fa_digits($maxDevices) . ' دستگاه' . "\n"
        . 'مدت جریمه: ' . fa_digits($minutes) . ' دقیقه' . "\n"
        . '⚙️ کنترل خودکار: ' . ($guard ? '🟢 روشن' : '🔴 خاموش') . "\n"
        . SEP . "\n"
        . '<i>در صورت عبور از سقف، فقط همان کاربر به مدت ' . fa_digits($minutes)
        . ' دقیقه غیرفعال می‌شود و اتصالاتش ریست می‌گردد.</i>' . "\n";
    if ($limited !== []) {
        $text .= "\n⏳ <b>در حال جریمه</b>:" . "\n";
        foreach ($limited as $row) {
            $leftMin = (int) max(0, ceil(((int) $row['unban_at'] - now_ts()) / 60));
            $text .= '· <code>' . e((string) $row['username']) . '</code> — '
                . fa_digits($leftMin) . ' دقیقه مانده' . "\n";
        }
    }
    $rows = [[btn('🔄 بررسی دوباره', 'ad:pg:' . $panelId . ':dev', null, 'success')]];
    if ($canFull) {
        $rows[] = [btn($guard ? '🔴 خاموش کردن کنترل' : '🟢 روشن کردن کنترل',
            'ad:tgl:device_guard_on:devices', null, $guard ? 'danger' : 'success')];
        $rows[] = [btn('✏️ سقف دستگاه', 'ad:set:device_max_devices'),
            btn('✏️ مدت جریمه', 'ad:set:device_cut_minutes')];
    }
    $rows[] = [btn('« داشبورد پنل', 'ad:pg:' . $panelId, null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

