<?php
declare(strict_types=1);

/* 25) مدیریت کاربران + آمار + دعوت دوستان */

function screen_users(int|string $chat, ?int $messageId, int $page = 1, string $query = ''): void
{
    purge_ghost_users();   // ردیف‌های ساختگی جستجو در فهرست نمی‌آیند
    $total = (int) DB::val('SELECT COUNT(*) FROM users WHERE 1=1' . bot_self_sql(), [], 0);
    $blocked = (int) DB::val('SELECT COUNT(*) FROM users WHERE is_blocked=1' . bot_self_sql(),
        [], 0);
    $today = (int) DB::val('SELECT COUNT(*) FROM users WHERE created_at>=?' . bot_self_sql(),
        [now_ts() - DAY], 0);
    $rich = (int) DB::val('SELECT COUNT(*) FROM users WHERE balance>0' . bot_self_sql(), [], 0);
    /* هیچ دکمهٔ شیشه‌ای و هیچ ردیف کاربری این‌جا نیست: فقط یک دکمهٔ جستجو،
       بعد از زدن آن ربات شناسه می‌خواهد و مستقیم کارت همان کاربر باز می‌شود. */
    $text = "👥 <b>مدیریت کاربران</b>\n" . SEP . "\n"
        . '📊 کل کاربران: <b>' . fa_digits($total) . "</b>\n"
        . '🟢 کیف پول دار: ' . fa_digits($rich) . "\n"
        . '🚫 مسدود: ' . fa_digits($blocked) . "\n"
        . '🆕 امروز: ' . fa_digits($today) . "\n"
        . SEP . "\n"
        . 'برای دیدن کارت یک کاربر، دکمهٔ «🔍 جستجوی کاربر» را بزنید و شناسهٔ عددی '
        . 'یا یوزرنیم او را بفرستید.';
    if ($query !== '') {
        $text .= "\n" . '🔎 جستجوی قبلی: <i>' . e($query) . '</i>';
    }
    Tg::show($chat, $messageId, $text, [
        [btn('🔍 جستجوی کاربر', 'ad:usearch', null, 'success')],
        [btn('« مدیریت', 'ad:home', null, 'danger')],
    ]);
}

/**
 * کارت کامل یک کاربر برای مدیر — همهٔ چیزهایی که در این ربات وجود دارد.
 */
function screen_user_card(int|string $chat, ?int $messageId, int $tgId): void
{
    $user = user_get($tgId);
    if ($user === null) {
        Tg::show($chat, $messageId,
            '🔎 کاربری با شناسهٔ <code>' . e((string) $tgId) . "</code> در ربات نیست.\n"
            . '<i>هیچ ردیفی هم برای این شماره ثبت نشد.</i>',
            [[btn('🔍 جستجوی دیگر', 'ad:usearch', null, 'success')],
             [btn('« کاربران', 'ad:users', null, 'danger')]]);
        return;
    }
    $name = trim((string) ($user['name'] ?? ''));
    $balance = (int) $user['balance'];
    /* شارژها: هر واریزی که کیف پول را زیاد کرده (بدون پورسانت و بازگشت وجه) */
    $deposits = (int) DB::val('SELECT COALESCE(SUM(amount),0) FROM wallet_tx '
        . "WHERE tg_id=? AND amount>0 AND kind IN ('topup','admin')", [$tgId], 0);
    $activePanels = (int) DB::val("SELECT COUNT(*) FROM panels WHERE tg_id=? "
        . "AND status IN ('active','suspended')", [$tgId], 0);
    $traffic = (int) DB::val('SELECT COALESCE(SUM(last_usage),0) FROM panels WHERE tg_id=?',
        [$tgId], 0);
    $subs = (int) DB::val('SELECT COUNT(*) FROM users WHERE referred_by=?', [$tgId], 0);
    $level = kyc_level($tgId);
    $blocked = (int) ($user['is_blocked'] ?? 0) === 1;
    $phone = trim((string) ($user['phone'] ?? ''));
    $uname = trim((string) ($user['uname'] ?? ''));
    $refBy = (int) ($user['referred_by'] ?? 0);
    $refName = '';
    if ($refBy > 0) {
        $refUser = user_get($refBy);
        $refName = $refUser !== null
            ? mb_substr(trim((string) ($refUser['name'] ?: (string) $refBy)), 0, 20)
            . ' · <code>' . $refBy . '</code>'
            : '<code>' . $refBy . '</code>';
    }

    $text = '👤 <b>اطلاعات کاربری: ' . e($name !== '' ? $name : 'بدون‌نام') . "</b>\n"
        . SEP . "\n"
        . '📌 وضعیت: ' . ($blocked ? '🚫 مسدود' : '🟢 فعال') . "\n"
        . '🔗 یوزرنیم: ' . ($uname !== '' ? '@' . e($uname) : '—') . "\n"
        . '🆔 شناسهٔ عددی: <code>' . $tgId . "</code>\n"
        . '📱 شمارهٔ موبایل: ' . ($phone !== '' ? '<code>' . e($phone) . '</code>' : '—') . "\n"
        . '🪪 سطح احراز هویت: ' . kyc_level_emoji($level) . ' سطح '
            . fa_digits($level) . ' از ' . fa_digits(KYC_MAX_LEVEL) . "\n"
        . '📅 تاریخ عضویت: ' . fa_ts((int) ($user['created_at'] ?? 0)) . "\n"
        . '🎁 معرف کاربر: ' . ($refBy > 0 ? $refName : 'ندارد (ثبت‌نام مستقیم)') . "\n"
        . '👥 تعداد زیرمجموعه: ' . fa_digits($subs) . "\n"
        . SEP . "\n"
        . "💎 <b>وضعیت مالی و سرویس‌ها:</b>\n"
        . '👛 موجودی کیف پول: <b>' . money($balance) . "</b>\n"
        . '💵 مجموع واریزها: ' . money($deposits) . "\n"
        . '📦 پنل‌های فعال: ' . fa_digits($activePanels) . "\n"
        . '📊 مجموع ترافیک مصرف‌شده: ' . gb_step_text($traffic) . " گیگابایت\n"
        . '🎁 تخفیف اختصاصی: ' . user_discount_text($user);

    $id = $tgId;
    $rows = [
        [btn('➕ افزایش موجودی', 'ad:u:' . $id . ':add', null, 'success'),
         btn('➖ کسر موجودی', 'ad:u:' . $id . ':sub', null, 'danger')],
        [btn('📦 پنل‌ها و سرویس‌های کاربر', 'ad:u:' . $id . ':panels'),
         btn('🧾 سوابق تراکنش‌ها', 'ad:u:' . $id . ':tx')],
        [btn($blocked ? '🚫 رفع مسدودی' : '🚫 مسدودسازی', 'ad:u:' . $id . ':block', null,
             $blocked ? 'success' : 'danger'),
         btn('🎁 اعطای تخفیف اختصاصی', 'ad:u:' . $id . ':gift', null, 'success')],
        [btn('🪪 تغییر دستی سطح احراز', 'ad:u:' . $id . ':kyc'),
         btn('📩 ارسال پیام به کاربر', 'ad:u:' . $id . ':msg', null, 'success')],
        [btn('🔄 همگام‌سازی و بروزرسانی', 'ad:u:' . $id . ':sync', null, 'primary'),
         btn('🔙 بازگشت', 'ad:users', null, 'danger')],
    ];
    Tg::show($chat, $messageId, $text, $rows);
}

/* 🔍 جستجوی سفارشات — جستجوی پنل مشتری و جستجوی فاکتور/تراکنش */

/** صفحهٔ اول: انتخاب نوع جستجو */
function screen_find(int|string $chat, ?int $messageId): void
{
    $panels = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status<>'deleted'", [], 0);
    $orders = (int) DB::val('SELECT COUNT(*) FROM orders', [], 0);
    $payments = (int) DB::val('SELECT COUNT(*) FROM payments', [], 0);
    Tg::show($chat, $messageId,
        "🔍 <b>جستجوی سفارشات</b>\n" . SEP . "\n"
        . '📦 پنل‌های فروخته‌شده: ' . fa_digits($panels) . "\n"
        . '🧾 فاکتورها: ' . fa_digits($orders) . ' · 💳 تراکنش‌ها: ' . fa_digits($payments)
        . "\n" . SEP . "\n"
        . 'یکی از دو نوع جستجو را انتخاب کنید:' . "\n"
        . '<i>پنل مشتری با نام کاربری یا شناسه پیدا می‌شود؛ فاکتور با شناسه، کد پیگیری '
        . 'یا کد سفارش.</i>',
        [[btn('🔍 جستجوی پنل مشتری', 'ad:find:panel', null, 'success')],
         [btn('🧾 جستجوی فاکتور و تراکنش مالی', 'ad:find:inv', null, 'success')],
         [btn('« مدیریت', 'ad:home', null, 'danger')]]);
}

/** کارت کامل یک پنل فروخته‌شده برای مدیر */
function screen_find_panel(int|string $chat, ?int $messageId, int $panelId): void
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        Tg::show($chat, $messageId,
            '🔎 پنلی با شناسهٔ <code>' . e((string) $panelId) . '</code> پیدا نشد.',
            [[btn('🔍 جستجوی دوباره', 'ad:find:panel', null, 'success')],
             [btn('« جستجوی سفارشات', 'ad:find', null, 'danger')]]);
        return;
    }
    $owner = user_get((int) $panel['tg_id']);
    $ownerLabel = $owner !== null
        ? e(mb_substr(trim((string) ($owner['name'] ?: (string) $panel['tg_id'])), 0, 24))
        . ' · <code>' . (int) $panel['tg_id'] . '</code>'
        : '<code>' . (int) $panel['tg_id'] . '</code>';
    $panelRow = panel_pg_row($panel);
    $server = $panelRow !== null
        ? e((string) $panelRow['name']) . ' — <code>'
            . e(normalize_panel_url((string) $panelRow['url'])) . '</code>'
        : '— (پنل پاسارگارد حذف شده)';
    $cap = (int) ($panel['data_cap'] ?? 0);
    $used = (int) ($panel['last_usage'] ?? 0);
    $text = '📦 <b>پنل مشتری</b>' . "\n" . SEP . "\n"
        . '👤 نام کاربری: <code>' . e((string) $panel['panel_username']) . "</code>\n"
        . '🆔 شناسهٔ پنل در ربات: <code>' . (int) $panel['id'] . "</code>\n"
        . '📌 وضعیت: ' . panel_status_label((string) $panel['status'])
        . ((int) ($panel['is_trial'] ?? 0) === 1 ? ' · 🧪 تست' : '') . "\n"
        . '📊 سقف ترافیک کل: ' . ($cap > 0 ? pg_size($cap) : 'نامحدود') . "\n"
        . '📉 ترافیک مصرفی: ' . pg_size($used)
        . ($cap > 0 ? ' (' . gb_step_text($used) . ' از ' . gb_step_text($cap) . ' گیگ)' : '')
        . "\n"
        . '📅 تاریخ ساخت: ' . fa_ts((int) ($panel['created_at'] ?? 0)) . "\n"
        . '⌛️ تاریخ انقضا: ' . fa_ts((int) ($panel['expires_at'] ?? 0)) . "\n"
        . '📱 دستگاه مجاز: ' . fa_digits((int) ($panel['device_limit'] ?? 0)) . "\n"
        . '👥 سقف کاربر (User Limit): ' . fa_digits((int) ($panel['max_users'] ?? 0)) . "\n"
        . '🌐 سرور/نود: ' . $server . "\n"
        . '👤 مالک در تلگرام: ' . $ownerLabel . "\n" . SEP . "\n"
        . '💰 قیمت هر گیگ: ' . money((int) ($panel['per_gb_price'] ?? 0))
        . ' · ⏱ ساعتی: ' . money((int) ($panel['hourly_price'] ?? 0)) . "\n";
    $id = (int) $panel['id'];
    $suspended = (string) $panel['status'] === 'suspended';
    Tg::show($chat, $messageId, $text, [
        [btn($suspended ? '🟢 فعال‌سازی پنل' : '⛔ تعلیق پنل', 'ad:find:pn:' . $id
            . ($suspended ? ':res' : ':sus'), null, $suspended ? 'success' : 'danger')],
        [btn('➕ افزایش سقف کاربر', 'ad:find:pn:' . $id . ':ulup', null, 'success'),
         btn('➖ کاهش سقف کاربر', 'ad:find:pn:' . $id . ':uldn', null, 'danger')],
        [btn('➕ تمدید حجم / تاریخ', 'ad:find:pn:' . $id . ':ext', null, 'success')],
        [btn('🗑 حذف کامل پنل', 'ad:find:pn:' . $id . ':del', null, 'danger')],
        [btn('« جستجوی سفارشات', 'ad:find', null, 'danger')],
    ]);
}

/** کارت یک فاکتور/تراکنش برای مدیر */
function screen_find_invoice(int|string $chat, ?int $messageId, string $query): void
{
    $query = trim($query);
    $digits = preg_replace('/\D/', '', $query) ?? '';
    $pay = null;
    $order = null;
    if ($digits !== '' && $digits === $query) {
        /* شناسهٔ عددی: فاکتور → سفارش */
        $pay = DB::one('SELECT * FROM payments WHERE id=?', [(int) $digits]);
        $order = DB::one('SELECT * FROM orders WHERE id=?', [(int) $digits]);
    }
    if ($pay === null) {
        $pay = DB::one('SELECT * FROM payments WHERE authority=?', [$query])
            ?? DB::one('SELECT * FROM payments WHERE ref=?', [$query]);
    }
    if ($order === null) {
        $order = DB::one('SELECT * FROM orders WHERE ref=?', [$query]);
    }
    if ($pay === null && $order === null) {
        Tg::show($chat, $messageId,
            '🔎 فاکتوری با «' . e($query) . '» پیدا نشد.' . "\n"
            . '<i>شناسهٔ فاکتور، کد پیگیری (Authority) یا کد سفارش را دقیق بفرستید.</i>',
            [[btn('🧾 جستجوی دوباره', 'ad:find:inv', null, 'success')],
             [btn('« جستجوی سفارشات', 'ad:find', null, 'danger')]]);
        return;
    }
    if ($order === null && $pay !== null) {
        $order = DB::one('SELECT * FROM orders WHERE id=?', [(int) $pay['order_id']]);
    }
    if ($pay === null && $order !== null) {
        /* اگر سفارش پیدا شد ولی فاکتورش با این عبارت نبود، آخرین تراکنش همان سفارش */
        $pay = DB::one('SELECT * FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1',
            [(int) $order['id']]);
    }
    $tgId = (int) ($order['tg_id'] ?? ($pay['tg_id'] ?? 0));
    $user = user_get($tgId);
    $userLabel = $user !== null
        ? e(mb_substr(trim((string) ($user['name'] ?: (string) $tgId)), 0, 24))
        . ' · <code>' . $tgId . '</code>'
        : '<code>' . $tgId . '</code>';
    $status = (string) ($pay['status'] ?? '');
    $statusText = match ($status) {
        'paid' => '✅ موفق',
        'unpaid' => '⏳ پرداخت نشده',
        'expire' => '⌛️ منقضی',
        'rejected' => '❌ رد شده',
        'failed' => '❌ ناموفق',
        default => $status !== '' ? $status : '—',
    };
    $text = '🧾 <b>فاکتور و تراکنش مالی</b>' . "\n" . SEP . "\n"
        . ($pay !== null ? '🆔 شناسهٔ فاکتور: <code>' . (int) $pay['id'] . "</code>\n" : '')
        . ($order !== null ? '🧾 کد سفارش: <code>' . e((string) $order['ref']) . "</code>\n"
            . '📦 نوع سفارش: ' . e(order_kind_label((string) $order['kind'])) . "\n" : '')
        . '👤 کاربر: ' . $userLabel . "\n"
        . '💵 مبلغ: <b>' . money((int) ($pay['amount'] ?? ($order['payable'] ?? 0))) . "</b>\n"
        . '💳 روش پرداخت: ' . e(pay_source_label((string) ($pay['method'] ?? '—'))) . "\n"
        . '📌 وضعیت: ' . $statusText . "\n"
        . '📅 تاریخ: ' . fa_ts((int) ($pay['created_at'] ?? ($order['created_at'] ?? 0))) . "\n"
        . ($pay !== null && !empty($pay['paid_at']) ? '✅ زمان پرداخت: '
            . fa_ts((int) $pay['paid_at']) . "\n" : '')
        . ($pay !== null && trim((string) ($pay['authority'] ?? '')) !== ''
            ? '🔖 کد پیگیری: <code>' . e((string) $pay['authority']) . "</code>\n" : '')
        . ($order !== null ? '📌 وضعیت سفارش: '
            . e(order_status_label((string) $order['status'])) . "\n" : '')
        . SEP . "\n"
        . '🔗 لینک کاربر: <a href="tg://user?id=' . $tgId . '">' . $tgId . '</a>';
    $rows = [];
    if ($user !== null) {
        $rows[] = [btn('👤 کارت کاربر', 'ad:u:' . $tgId, null, 'success')];
    }
    $rows[] = [btn('🧾 جستجوی دوباره', 'ad:find:inv', null, 'success')];
    $rows[] = [btn('« جستجوی سفارشات', 'ad:find', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** تغییر سقف کاربر یک پنل فروخته‌شده (+/-) با تلاش برای اعمال روی خود پنل */
function admin_panel_user_limit(int $panelId, int $delta): array
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return [false, 'پنل پیدا نشد.'];
    }
    $current = max(0, (int) ($panel['max_users'] ?? 0));
    $next = max(0, $current + $delta);
    if ($next === $current) {
        return [false, 'سقف کاربر تغییر نکرد.'];
    }
    DB::exec('UPDATE panels SET max_users=? WHERE id=?', [$next, $panelId]);
    $note = '';
    $panelRow = panel_pg_row($panel);
    if ($panelRow !== null) {
        try {
            (new PgPanel($panelRow))->update_admin((string) $panel['panel_username'],
                ['users_limit' => $next]);
        } catch (Throwable $ex) {
            $note = "\n⚠️ روی سرور پنل اعمال نشد: " . mb_substr($ex->getMessage(), 0, 120);
        }
    }
    log_line('info', 'admin user limit', ['panel' => $panelId, 'limit' => $next]);
    post_report('👥 <b>تغییر سقف کاربر پنل</b>' . "\n" . '📦 <code>'
        . e((string) $panel['panel_username']) . '</code>' . "\n"
        . 'سقف جدید: ' . fa_digits($next) . ' کاربر', 'panel');
    return [true, '👥 سقف کاربر روی <b>' . fa_digits($next) . '</b> تنظیم شد.' . $note];
}

/** تمدید حجم و تاریخ یک پنل فروخته‌شده */
function admin_panel_extend(int $panelId, int $gigs, int $days): array
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return [false, 'پنل پیدا نشد.'];
    }
    $gigs = max(0, $gigs);
    $days = max(0, $days);
    if ($gigs === 0 && $days === 0) {
        return [false, 'هیچ حجم و روزی اضافه نشد.'];
    }
    $cap = (int) ($panel['data_cap'] ?? 0);
    if ($gigs > 0) {
        $cap = ($cap > 0 ? $cap : (int) ($panel['last_usage'] ?? 0)) + $gigs * 1073741824;
    }
    $expires = (int) ($panel['expires_at'] ?? 0);
    if ($days > 0) {
        $base = max($expires, now_ts());
        $expires = $base + $days * DAY;
    }
    DB::exec('UPDATE panels SET data_cap=?, expires_at=? WHERE id=?',
        [$cap, $expires, $panelId]);
    $note = '';
    $panelRow = panel_pg_row($panel);
    if ($panelRow !== null) {
        try {
            $fields = ['expire' => (int) round($expires)];
            if ((int) ($panel['data_cap'] ?? 0) >= 0) {
                $fields['data_limit'] = $cap;
            }
            (new PgPanel($panelRow))->update_admin((string) $panel['panel_username'], $fields);
        } catch (Throwable $ex) {
            $note = "\n⚠️ روی سرور پنل اعمال نشد: " . mb_substr($ex->getMessage(), 0, 120);
        }
    }
    $parts = [];
    if ($gigs > 0) {
        $parts[] = fa_digits($gigs) . ' گیگابایت';
    }
    if ($days > 0) {
        $parts[] = fa_digits($days) . ' روز';
    }
    $summary = implode(' و ', $parts);
    log_line('info', 'admin extend panel', ['panel' => $panelId, 'gigs' => $gigs,
        'days' => $days]);
    post_report('➕ <b>تمدید پنل</b>' . "\n" . '📦 <code>'
        . e((string) $panel['panel_username']) . '</code>' . "\n"
        . 'مقدار: ' . $summary, 'panel');
    Tg::send((int) $panel['tg_id'], '🎁 پنل <code>' . e((string) $panel['panel_username'])
        . '</code> توسط پشتیبانی تمدید شد: <b>' . $summary . '</b>'
        . "\n" . 'انقضای جدید: ' . fa_ts($expires),
        [[btn('📦 پنل‌های من', 'menu:panels', null, 'primary')]]);
    return [true, '✅ پنل <b>' . $summary . '</b> تمدید شد.' . $note];
}

/** حذف کامل یک پنل توسط مدیر: روی پاسارگارد و در دیتابیس ربات */
function admin_panel_delete(int $panelId, int $adminId = 0): array
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return [false, 'پنل پیدا نشد.'];
    }
    $username = (string) $panel['panel_username'];
    $tgId = (int) $panel['tg_id'];
    $panelRow = panel_pg_row($panel);
    $note = '';
    $apiOk = true;
    if ($panelRow !== null) {
        try {
            (new PgPanel($panelRow))->delete_admin_with_users($username);
        } catch (Throwable $ex) {
            $apiOk = str_contains($ex->getMessage(), '404');
            if (!$apiOk) {
                $note = mb_substr($ex->getMessage(), 0, 140);
            }
        }
    }
    DB::exec('DELETE FROM panels WHERE id=?', [$panelId]);
    DB::exec('DELETE FROM tg_state WHERE data LIKE ?', ['%"panel_id":' . $panelId . '%']);
    log_line('info', 'admin deleted panel', ['panel' => $panelId, 'by' => $adminId]);
    post_report('🗑 <b>حذف پنل توسط مدیر</b>' . "\n" . '👤 کاربر <code>' . $tgId . '</code>'
        . "\n" . '📦 <code>' . e($username) . '</code>', 'panel');
    Tg::send($tgId, '🗑 پنل <code>' . e($username) . '</code> توسط پشتیبانی حذف شد.');
    return [true, '🗑 پنل <code>' . e($username) . '</code> حذف شد.'
        . ($apiOk ? '' : "\n⚠️ روی سرور پنل حذف نشد: " . e($note))];
}

/* پیام همگانی: ارسال، فروارد، مخاطب، پین، آمار */

const BC_SEGMENTS = [
    'all'      => '👥 همهٔ کاربران',
    'buyers'   => '🛒 مشتریانی که خرید کردند',
    'nobuy'    => '🚫 کاربرانی که خرید نکردند',
    'inactive' => '😴 کاربرانی که چند روز استفاده نکردند',
    'active'   => '🔥 کاربران فعال چند روز اخیر',
];

function bc_segment(): string
{
    $seg = set_get('bc_segment', 'all');
    return isset(BC_SEGMENTS[$seg]) ? $seg : 'all';
}

/** فهرست شناسهٔ کاربرانِ یک دسته */
function bc_targets(string $segment, bool $onlyAllowed = true): array
{
    $days = max(1, set_int('bc_inactive_days', 7));
    $since = now_ts() - $days * DAY;
    $sql = 'SELECT tg_id FROM users WHERE is_blocked=0' . bot_self_sql();
    $params = [];
    switch ($segment) {
        case 'buyers':
            $sql .= " AND tg_id IN (SELECT tg_id FROM orders WHERE kind='panel' "
                . "AND status='delivered')";
            break;
        case 'nobuy':
            $sql .= " AND tg_id NOT IN (SELECT tg_id FROM orders "
                . "WHERE kind='panel' AND status='delivered')";
            break;
        case 'inactive':
            $sql .= ' AND (last_seen=0 OR last_seen<?)';
            $params[] = $since;
            break;
        case 'active':
            $sql .= ' AND last_seen>=?';
            $params[] = $since;
            break;
        default:
            break;
    }
    $ids = [];
    foreach (DB::all($sql, $params) as $row) {
        $ids[] = (int) $row['tg_id'];
    }
    return $ids;
}

/**
 * یک ارسال تک‌نفره با تحمل خطا:
 *  · اگر تلگرام «مسدود کرده» بگوید → blocked
 *  · اگر 429 بدهد → به اندازهٔ retry_after صبر می‌کند و دوباره می‌فرستد
 *  خروجی: [موفق؟، مسدود؟، message_id]
 */
function bc_send_one(int $tgId, string $mode, string $text, int $fromChat, int $messageId,
                     int $tries = 3): array
{
    for ($attempt = 1; $attempt <= $tries; $attempt++) {
        $params = $mode === 'copy'
            ? ['chat_id' => $tgId, 'from_chat_id' => $fromChat, 'message_id' => $messageId]
            : ['chat_id' => $tgId, 'text' => $text, 'parse_mode' => 'HTML',
               'disable_web_page_preview' => true];
        $method = $mode === 'copy' ? 'copyMessage' : 'sendMessage';
        $res = Tg::call($method, $params);
        if (Tg::$dry) {
            return [true, false, count(Tg::$outbox)];
        }
        $okSend = is_array($res) && ($res['ok'] ?? false) === true;
        if ($okSend) {
            $sentId = (int) ($res['result']['message_id'] ?? 0);
            return [true, false, $sentId];
        }
        $description = is_array($res) ? (string) ($res['description'] ?? '') : '';
        if (bc_blocked_error($description)) {
            return [false, true, 0];
        }
        $retryAfter = (int) ($res['parameters']['retry_after'] ?? 0);
        if ($retryAfter > 0 || stripos($description, 'too many requests') !== false) {
            /* 429: صبر می‌کنیم و همان کاربر را دوباره می‌زنیم — هیچ‌وقت رها نمی‌شود */
            sleep(max(1, min(60, $retryAfter > 0 ? $retryAfter : 2)));
            usleep(50000);
            continue;
        }
        return [false, false, 0];
    }
    return [false, false, 0];
}

/** آیا خطای تلگرام یعنی «کاربر ربات را مسدود کرده»؟ */
function bc_blocked_error(string $description): bool
{
    $needle = mb_strtolower($description);
    foreach (['blocked by the user', 'user is deactivated', 'bot was blocked',
              'chat not found', 'user not found', 'peer_id_invalid', 'bot cannot initiate'] as $hint) {
        if (str_contains($needle, $hint)) {
            return true;
        }
    }
    return false;
}

/**
 * ارسال همگانی — غیرهمزمان و بی‌خطر.
 *  · بین هر پیام ۵۰ میلی‌ثانیه فاصله می‌گذارد (محدودیت تلگرام)
 *  · کاربرانی که ربات را مسدود کرده‌اند رد می‌شوند و شمرده می‌شوند
 *  · متن نهایی: «تعداد کل: X | موفق: Y | مسدود شده توسط کاربر: Z»
 */
function bc_run(array $targets, string $mode, string $text = '', int $fromChat = 0,
                int $messageId = 0, bool $pin = false): array
{
    $sent = 0;
    $failed = 0;
    $blocked = 0;
    $jobId = (string) set_get('bc_job_id', '');
    foreach ($targets as $index => $tgId) {
        [$okSend, $isBlocked, $lastId] = bc_send_one((int) $tgId, $mode, $text, $fromChat,
            $messageId);
        if ($okSend) {
            $sent++;
            if ($pin && $lastId > 0) {
                Tg::pin((int) $tgId, $lastId);
            }
        } elseif ($isBlocked) {
            $blocked++;
        } else {
            $failed++;
        }
        /* پیشرفت کار برای صفحهٔ «پیام همگانی» و جلوگیری از تایم‌اوت */
        if ($jobId !== '') {
            bc_job_update($index + 1, $sent, $failed, $blocked);
        }
        usleep(50000);   // ۵۰ میلی‌ثانیه بین هر ارسال
    }
    $stats = ['at' => now_ts(), 'sent' => $sent, 'failed' => $failed, 'blocked' => $blocked,
              'segment' => bc_segment(), 'mode' => $mode, 'total' => count($targets)];
    set_put('bc_last', json_encode($stats, JSON_UNESCAPED_UNICODE));
    set_put('bc_last_at', (string) now_ts());
    return $stats;
}

/** یک خط گزارش همگانی — دقیقاً همان قالبی که مدیر می‌خواهد */
function bc_report_line(array $stats): string
{
    return 'تعداد کل: ' . fa_digits((int) ($stats['total'] ?? 0))
        . ' | موفق: ' . fa_digits((int) ($stats['sent'] ?? 0))
        . ' | مسدود شده توسط کاربر: ' . fa_digits((int) ($stats['blocked'] ?? 0));
}

/** متن گزارش نهایی همگانی برای مدیر و گروه گزارشات */
function bc_report_text(array $stats, string $title, string $segment = ''): string
{
    return $title . "\n"
        . ($segment !== '' ? '🎯 ' . $segment . "\n" : '')
        . '✅ ' . bc_report_line($stats)
        . ((int) ($stats['failed'] ?? 0) > 0
            ? "\n" . '⚠️ ناموفق (غیر از مسدود): ' . fa_digits((int) $stats['failed']) : '');
}

/* ── کار پس‌زمینهٔ ارسال همگانی ─────────────────────────────────────────── */

/** ثبت یک کار همگانی در تنظیمات (تا صفحه همیشه بداند چه چیزی در جریان است) */
function bc_job_start(array $targets, string $mode, string $text, int $fromChat,
                      int $messageId, bool $pin, int $adminId, string $title,
                      string $segment): string
{
    $jobId = 'bc' . now_ts() . mt_rand(100, 999);
    $job = ['id' => $jobId, 'mode' => $mode, 'text' => $text, 'from_chat' => $fromChat,
            'message_id' => $messageId, 'pin' => $pin ? 1 : 0, 'admin' => $adminId,
            'title' => $title, 'segment' => $segment, 'targets' => array_values($targets),
            'cursor' => 0, 'sent' => 0, 'failed' => 0, 'blocked' => 0,
            'total' => count($targets), 'started_at' => now_ts(), 'done' => 0];
    set_put('bc_job', json_encode($job, JSON_UNESCAPED_UNICODE));
    set_put('bc_job_id', $jobId);
    return $jobId;
}

function bc_job_get(): ?array
{
    $raw = (string) set_get('bc_job', '');
    if ($raw === '') {
        return null;
    }
    $job = json_decode($raw, true);
    return is_array($job) ? $job : null;
}

/** به‌روزرسانی پیشرفت کار (برای صفحهٔ مدیریت) */
function bc_job_update(int $cursor, int $sent, int $failed, int $blocked, bool $done = false): void
{
    $job = bc_job_get();
    if ($job === null) {
        return;
    }
    $job['cursor'] = $cursor;
    $job['sent'] = $sent;
    $job['failed'] = $failed;
    $job['blocked'] = $blocked;
    $job['done'] = $done ? 1 : 0;
    if ($done) {
        $job['finished_at'] = now_ts();
    }
    set_put('bc_job', json_encode($job, JSON_UNESCAPED_UNICODE));
}

/**
 * یک تیک از کار پس‌زمینه: تا سقف زمانی مشخص ارسال می‌کند و برمی‌گردد.
 * وقتی کار تمام شد، گزارش برای مدیر و گروه گزارشات می‌رود.
 * خروجی: true اگر کار تمام شده باشد.
 */
function bc_job_tick(int $budgetSeconds = 45): bool
{
    $job = bc_job_get();
    if ($job === null || (int) ($job['done'] ?? 0) === 1) {
        return true;
    }
    $targets = array_map('intval', (array) ($job['targets'] ?? []));
    $cursor = (int) ($job['cursor'] ?? 0);
    $sent = (int) ($job['sent'] ?? 0);
    $failed = (int) ($job['failed'] ?? 0);
    $blocked = (int) ($job['blocked'] ?? 0);
    $started = microtime(true);
    $mode = (string) ($job['mode'] ?? 'text');
    $count = count($targets);
    while ($cursor < $count && (microtime(true) - $started) < $budgetSeconds) {
        [$okSend, $isBlocked, $lastId] = bc_send_one($targets[$cursor], $mode,
            (string) ($job['text'] ?? ''), (int) ($job['from_chat'] ?? 0),
            (int) ($job['message_id'] ?? 0));
        if ($okSend) {
            $sent++;
            if (!empty($job['pin']) && $lastId > 0) {
                Tg::pin($targets[$cursor], $lastId);
            }
        } elseif ($isBlocked) {
            $blocked++;
        } else {
            $failed++;
        }
        $cursor++;
        bc_job_update($cursor, $sent, $failed, $blocked);
        usleep(50000);
    }
    if ($cursor < $count) {
        return false;   // بقیه در تیک بعدی / فرایند بعدی
    }
    bc_job_update($cursor, $sent, $failed, $blocked, true);
    $stats = ['total' => $count, 'sent' => $sent, 'failed' => $failed, 'blocked' => $blocked,
              'at' => now_ts()];
    set_put('bc_last', json_encode($stats, JSON_UNESCAPED_UNICODE));
    set_put('bc_last_at', (string) now_ts());
    $title = (string) ($job['title'] ?? '📣 ارسال همگانی تمام شد');
    $body = bc_report_text($stats, $title, (string) ($job['segment'] ?? ''));
    $adminId = (int) ($job['admin'] ?? 0);
    if ($adminId > 0) {
        Tg::send($adminId, $body, [[btn('« پیام همگانی', 'ad:bc', null, 'success')]]);
    }
    post_report($body, 'general');
    set_put('bc_job_id', '');
    return true;
}

/**
 * اجرای کار همگانی در پس‌زمینه (فرایند جدا).
 * اگر اجرای پس‌زمینه ممکن نبود، همان‌جا (همگام) ادامه می‌دهد تا کار گم نشود.
 */
function bc_job_dispatch(): bool
{
    $job = bc_job_get();
    if ($job === null || (int) ($job['done'] ?? 0) === 1) {
        return false;
    }
    if (Tg::$dry) {
        /* در تست، بدون فرایند جدا: همان‌جا تا آخر می‌رود */
        while (!bc_job_tick(3600)) {
        }
        return true;
    }
    $script = panel_root() . '/sibil.php';
    $log = panel_root() . '/sibil.log';
    $inner = 'cd ' . escapeshellarg(panel_root())
        . ' && nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' --broadcast >> ' . escapeshellarg($log) . ' 2>&1 &';
    @shell_exec('nohup bash -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');
    return true;
}

function screen_broadcast(int|string $chat, ?int $messageId, string $notice = ''): void
{
    $segment = bc_segment();
    $days = max(1, set_int('bc_inactive_days', 7));
    $targets = bc_targets($segment);
    $last = json_decode((string) set_get('bc_last', '{}'), true);
    $lastAt = (int) set_get('bc_last_at', '0');
    $text = '📣 <b>پیام همگانی</b>' . "\n" . str_repeat('─', 14) . "\n"
        . '🎯 مخاطب: <b>' . BC_SEGMENTS[$segment] . '</b> · '
        . fa_digits(count($targets)) . " نفر\n"
        . '📅 ملاک «بی‌استفاده»: ' . fa_digits($days) . " روز\n"
        . '📌 سنجاق بعد از ارسال: ' . (set_bool('bc_pin', false) ? '🟢 روشن' : '🔴 خاموش') . "\n";
    if (is_array($last) && (int) ($last['sent'] ?? 0) >= 0 && $lastAt > 0) {
        $text .= "\n<b>آخرین ارسال</b>\n"
            . '🕒 ' . fa_ts($lastAt) . "\n"
            . '🎯 ' . (BC_SEGMENTS[(string) ($last['segment'] ?? 'all')] ?? '—') . "\n"
            . '✅ ' . bc_report_line($last) . "\n";
    }
    $job = bc_job_get();
    if ($job !== null && (int) ($job['done'] ?? 0) === 0) {
        $text .= "\n🚀 <b>ارسال در جریان است</b>\n"
            . '📤 ' . fa_digits((int) ($job['cursor'] ?? 0)) . ' از '
            . fa_digits((int) ($job['total'] ?? 0)) . "\n"
            . '✅ ' . fa_digits((int) ($job['sent'] ?? 0))
            . ' · ⛔️ ' . fa_digits((int) ($job['blocked'] ?? 0))
            . ' · ❌ ' . fa_digits((int) ($job['failed'] ?? 0)) . "\n";
    }
    if ($notice !== '') {
        $text .= "\n" . e($notice);
    }
    Tg::show($chat, $messageId, $text, [
        [btn('📨 ارسال همگانی', 'ad:bc:text', null, 'success'),
         btn('🔁 فروارد همگانی', 'ad:bc:fwd')],
        [btn('🎯 انتخاب مخاطب', 'ad:bc:seg'),
         btn('📅 روزهای بی‌استفاده', 'ad:set:bc_inactive_days')],
        [btn((set_bool('bc_pin', false) ? '📌 سنجاق: روشن' : '📌 سنجاق: خاموش'),
            'ad:bc:pin')],
        [btn('📌 لغو پیام‌های سنجاق‌شده', 'ad:bc:unpin', null, 'danger')],
        [btn('« کاربران', 'ad:users', null, 'danger')],
    ]);
}

function screen_stats(int|string $chat, ?int $messageId): void
{
    $today = finance_stats(1);
    $week = finance_stats(7);
    $month = finance_stats(30);
    $users = (int) DB::val('SELECT COUNT(*) FROM users', [], 0);
    $newUsers = (int) DB::val('SELECT COUNT(*) FROM users WHERE created_at>=?',
        [period_start(1)], 0);
    $panels = (int) DB::val('SELECT COUNT(*) FROM panels', [], 0);
    $live = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='active'", [], 0);
    $susp = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='suspended'", [], 0);
    $pending = (int) DB::val("SELECT COUNT(*) FROM receipts WHERE status='pending'", [], 0);
    $products = (int) DB::val('SELECT COUNT(*) FROM products WHERE active=1', [], 0);
    $text = "📊 <b>آمار</b>\n\n"
        . "👥 کاربران: <b>" . fa_digits($users) . '</b> · امروز ' . fa_digits($newUsers) . "\n"
        . '📦 پنل‌ها: ' . fa_digits($panels) . ' · فعال ' . fa_digits($live)
        . ' · تعلیق ' . fa_digits($susp) . "\n"
        . '🛒 محصول فعال: ' . fa_digits($products) . "\n"
        . '🧾 رسید در انتظار: ' . fa_digits($pending) . "\n\n"
        . "💰 فروش امروز: <b>" . money($today['sales']) . '</b> (' . fa_digits($today['count'])
        . " سفارش)\n"
        . '📅 ۷ روز: ' . money($week['sales']) . "\n"
        . '🗓 ۳۰ روز: ' . money($month['sales']) . "\n\n";
    $top = DB::all("SELECT COALESCE(p.name,'—') AS name, COUNT(*) AS n, "
        . 'COALESCE(SUM(o.payable),0) AS total FROM orders o '
        . "LEFT JOIN products p ON p.id=o.product_id WHERE o.status='delivered' "
        . 'GROUP BY o.product_id ORDER BY n DESC LIMIT 3');
    if ($top) {
        $text .= "<b>پرفروش‌ترین‌ها</b>\n";
        foreach ($top as $row) {
            $text .= '· ' . e((string) $row['name']) . ' — ' . fa_digits((int) $row['n'])
                . ' · ' . money((int) $row['total']) . "\n";
        }
    }
    Tg::show($chat, $messageId, $text, [
        [btn('📊 گزارش فروش', 'ad:fin:sales:today'), btn('🧾 رسیدها', 'ad:receipts')],
        [btn('🔄 به‌روزرسانی', 'ad:stats')],
        [btn('« مدیریت', 'ad:home', null, 'danger')],
    ]);
}

/** تنظیمات — گروه‌بندی‌شده، همه با دکمه */
const SETTING_GROUPS = [
    'trial' => [
        'title' => '⏱ مهلت و محدودیت تست',
        'items' => [
            'trial_on'        => ['وضعیت تست', 'bool', 0],
            'trial_gb'        => ['مقدار تست (گیگ)', 'int', 0],
            'trial_hours'     => ['زمان تست (ساعت)', 'int', 0],
            'trial_per_user'  => ['دفعات تست مجاز هر کاربر تلگرام', 'int', 0],
            'trial_user_limit' => ['👥 سقف ساخت کاربر در پنل تست', 'int', 0],
            'trial_price'     => ['هزینهٔ تست (تومان) — 0 = رایگان', 'int', 0],
            'trial_in_panels' => ['نمایش پنل تست در «پنل‌های من»', 'bool', 0],
            'admin_trial_unlimited' => ['تست نامحدود برای مالک و ادمین‌ها', 'bool', 0],
        ],
    ],
    'billing' => [
        'title' => '⏱ قوانین کسر موجودی و توقف خودکار',
        'items' => [
            'billing_hourly'  => ['محاسبهٔ ساعتی', 'bool', 0],
            'billing_per_gb'  => ['محاسبهٔ حجمی', 'bool', 0],
            'suspend_on_zero' => ['توقف پنل با موجودی صفر', 'bool', 0],
            'meter_grace'     => ['مهلت قبل از توقف (ثانیه)', 'int', 0],
        ],
    ],
    'devices' => [
        'title' => '📱 کنترل تخلف دستگاه',
        'items' => [
            'device_guard_on'      => ['کنترل خودکار تخلف دستگاه', 'bool', 0],
            'device_max_devices'   => ['حداکثر دستگاه همزمان روی هر کانفیگ', 'int', 0],
            'device_cut_minutes'   => ['مدت غیرفعال‌سازی همان کاربر (دقیقه)', 'int', 0],
        ],
    ],
    'paneltest' => [
        'title' => '⚙️ تنظیمات اتصال پنل',
        'items' => [
            'panel_test_timeout' => ['مهلت پاسخ پنل (ثانیه)', 'int', 0],
            'panel_pass_len'     => ['طول رمز پنل ساخته‌شده (حرف)', 'int', 0],
        ],
    ],
    'backup' => [
        'title' => '💾 بکاپ خودکار',
        'items' => [
            'backup_on'    => ['بکاپ خودکار', 'bool', 0],
            'backup_at_hour' => ['ساعت بکاپ خودکار (۰ تا ۲۳ به وقت ایران)', 'int', 0],
            'backup_chat'  => ['شناسهٔ گروه بکاپ (خالی = گروه گزارشات)', 'text', 0],
            'backup_env'   => ['ارسال فایل تنظیمات (.env) همراه بکاپ', 'bool', 0],
        ],
    ],
    'kyc' => [
        'title' => '🪪 احراز هویت',
        'items' => [
            'kyc_on'         => ['احراز هویت اجباری برای کارت‌به‌کارت', 'bool', 0],
            'kyc_l0_daily'   => ['سقف روزانه بدون احراز (تومان)', 'int', 0],
            'kyc_l0_monthly' => ['سقف ماهانه بدون احراز (تومان)', 'int', 0],
            'kyc_l1_daily'   => ['سقف روزانه سطح ۱ (تومان)', 'int', 0],
            'kyc_l1_monthly' => ['سقف ماهانه سطح ۱ (تومان)', 'int', 0],
            'kyc_l2_daily'   => ['سقف روزانه سطح ۲ (تومان)', 'int', 0],
            'kyc_l2_monthly' => ['سقف ماهانه سطح ۲ (تومان)', 'int', 0],
            'kyc_topic_1'    => ['شناسهٔ تاپیک سطح ۱', 'int', 0],
            'kyc_topic_2'    => ['شناسهٔ تاپیک سطح ۲', 'int', 0],
            'kyc_banner_file' => ['عکس نمونهٔ کارت سطح ۱ (file_id)', 'text', 0],
            'kyc_banner2_file' => ['عکس نمونهٔ سلفی سطح ۲ (file_id)', 'text', 0],
        ],
    ],
    'texts' => [
        'title' => '📝 متن دکمه‌های منو',
        'items' => [
            'text_sell'               => ['خرید پنل', 'text', 0],
            'text_Purchased_services' => ['پنل‌های من', 'text', 0],
            'text_Add_Balance'        => ['کیف پول', 'text', 0],
            'text_usertest'           => ['تست پنل', 'text', 0],
            'text_orders'             => ['سفارش‌های من', 'text', 0],
            'text_affiliates'         => ['دعوت دوستان', 'text', 0],
            'text_support'            => ['پشتیبانی', 'text', 0],
            'text_help'               => ['راهنما', 'text', 0],
            'text_Discount'           => ['کد هدیه', 'text', 0],
        ],
    ],
    'report' => [
        'title' => '📢 گزارش و پشتیبانی',
        'items' => [
            'bot_name'     => ['نام ربات (در پیام‌ها و گزارش‌ها)', 'text', 0],
            'support_link' => ['📞 تنظیم آیدی پشتیبانی (@name یا لینک t.me)', 'text', 0],
            'channel_link' => ['لینک کانال رسمی (خبر و بروزرسانی)', 'text', 0],
            'owner_id'     => ['مالک ربات (تنها کسی که پنل مدیریت را می‌بیند)', 'int', 0],
        ],
    ],
];

/** گروه تنظیمات مربوط به یک کلید (برای دکمهٔ بازگشت) */
function setting_group_of(string $key): string
{
    foreach (SETTING_GROUPS as $group => $spec) {
        if (array_key_exists($key, $spec['items'])) {
            return $group;
        }
    }
    if (in_array($key, ['card_number', 'card_holder', 'min_topup', 'max_topup',
                        'auto_confirm_receipt', 'report_channel'], true)) {
        return 'card';
    }
    if (in_array($key, ['slots_free', 'slot_price', 'max_slots'], true)) {
        return 'billing';
    }
    if (in_array($key, ['stars_rate', 'web_port', 'web_url', 'support_link', 'channel_link'], true)) {
        return 'report';
    }
    if (in_array($key, ['pay_expire_minutes', 'discount_enabled', 'crypto_links'], true)) {
        return 'finset';
    }
    return 'home';
}

function setting_label(string $key, ?string $label = null): string
{
    if ($label !== null) {
        return $label;
    }
    return SETTING_LABELS[$key] ?? $key;
}

const SETTING_LABELS = [
    'card_number' => 'شماره کارت', 'card_holder' => 'نام صاحب کارت',
    'report_chat' => 'گروه گزارشات', 'backup_on' => 'بکاپ خودکار',
    'backup_at_hour' => 'ساعت بکاپ خودکار', 'backup_chat' => 'گروه بکاپ',
    'panel_pass_len' => 'طول رمز پنل', 'owner_id' => 'شناسهٔ عددی مالک ربات',
    'trial_in_panels' => 'نمایش پنل تست در پنل‌های من',
    'device_guard_on' => 'کنترل تخلف دستگاه',
    'device_cut_minutes' => 'مدت غیرفعال‌سازی همان کاربر (دقیقه)',
    'device_max_devices' => 'حداکثر دستگاه همزمان',
    'backup_ext' => 'پسوند فایل بکاپ',
    'bc_inactive_days' => 'روزهای بی‌استفاده بودن کاربر',
    'bc_segment' => 'مخاطب پیام همگانی',
    'bc_pin' => 'سنجاق پیام همگانی',
    'min_topup' => 'حداقل شارژ', 'max_topup' => 'حداکثر شارژ',
    'auto_confirm_receipt' => 'تأیید خودکار رسید', 'report_channel' => 'کانال گزارش',
    'bot_name' => 'نام ربات', 'support_link' => 'لینک پشتیبانی',
    'channel_link' => 'لینک کانال', 'web_url' => 'آدرس وب',
    'trial_on' => 'وضعیت تست پنل', 'trial_gb' => 'مقدار تست (گیگ)',
    'trial_hours' => 'زمان تست (ساعت)',
    'trial_per_user' => 'دفعات تست مجاز هر کاربر تلگرام',
    'trial_user_limit' => 'سقف ساخت کاربر در پنل تست',
    'trial_price' => 'هزینهٔ تست', 'billing_hourly' => 'محاسبهٔ ساعتی',
    'prepaid_hours' => 'ساعت‌های پیش‌پرداخت اول پنل',
    'force_channel_on' => 'قفل عضویت اجباری کانال',
    'force_channel_id' => 'کانال اجباری',
    'billing_per_gb' => 'محاسبهٔ حجمی', 'suspend_on_zero' => 'توقف پنل با موجودی صفر',
    'meter_grace' => 'مهلت قبل از توقف', 'panel_test_timeout' => 'مهلت پاسخ پنل (ثانیه)',
    'panel_test_amount' => 'مبلغ تست اتصال', 'slots_free' => 'اسلات رایگان',
    'slot_price' => 'قیمت هر اسلات', 'max_slots' => 'سقف اسلات هر کاربر',
    'stars_rate' => 'نرخ استارز', 'web_port' => 'پورت وب',
    'pay_expire_minutes' => 'مهلت پرداخت (دقیقه)', 'discount_enabled' => 'کد تخفیف',
    'crypto_links' => 'صرافی‌های داخلی فاکتور کریپتو (با | جدا کنید)',
    'ref_percent' => 'درصد دعوت دوستان', 'trial_in_panels' => 'نمایش تست در پنل‌های من',
    'kyc_on' => 'احراز هویت (KYC)', 'kyc_l0_daily' => 'سقف روزانه بدون احراز',
    'kyc_l0_monthly' => 'سقف ماهانه بدون احراز',
    'kyc_l1_daily' => 'سقف روزانه سطح ۱', 'kyc_l1_monthly' => 'سقف ماهانه سطح ۱',
    'kyc_l2_daily' => 'سقف روزانه سطح ۲', 'kyc_l2_monthly' => 'سقف ماهانه سطح ۲',
    'kyc_topic_1' => 'تاپیک سطح ۱ در گروه گزارشات',
    'kyc_topic_2' => 'تاپیک سطح ۲ در گروه گزارشات',
    'kyc_banner_file' => 'عکس نمونهٔ کارت سطح ۱ (file_id)',
    'kyc_banner2_file' => 'عکس نمونهٔ سلفی سطح ۲ (file_id)',
    'text_sell' => 'متن دکمهٔ خرید', 'text_Purchased_services' => 'متن دکمهٔ پنل‌های من',
    'text_Add_Balance' => 'متن دکمهٔ کیف پول', 'text_usertest' => 'متن دکمهٔ تست پنل',
    'text_orders' => 'متن دکمهٔ سفارش‌ها', 'text_affiliates' => 'متن دکمهٔ دعوت دوستان',
    'text_support' => 'متن دکمهٔ پشتیبانی', 'text_help' => 'متن دکمهٔ راهنما',
    'text_Discount' => 'متن دکمهٔ کد هدیه',
];

function screen_settings(int|string $chat, ?int $messageId, string $group = 'home'): void
{
    if ($group === 'backup') {
        screen_backup($chat, $messageId);
        return;
    }
    if ($group === 'home') {
        /* ⚙️ تنظیمات ربات — فقط پیکربندی کلی سیستم، دو ستونه و بدون دکمهٔ تکراری.
           «اتصال پنل» و «تست پنل» از اینجا حذف شده‌اند؛ همه‌چیزِ پنل داخل
           «🔌 پنل‌های پاسارگاد» و داشبورد همان پنل است. */
        Tg::show($chat, $messageId,
            "⚙️ <b>تنظیمات ربات</b>\n" . SEP . "\n"
            . 'هر بخش پیکربندی کلی سیستم است. تنظیمات هر پنل داخل داشبورد همان پنل است.'
            . "\n" . SEP . "\n" . 'یک بخش را انتخاب کنید:',
            [[btn('⏱ مهلت و محدودیت تست', 'ad:setg:trial', null, 'success'),
              btn('🪪 تنظیمات احراز هویت (KYC)', 'ad:setg:kyc')],
             [btn('💳 درگاه‌های پرداخت', 'ad:pay', null, 'success'),
              btn('🏷 کد تخفیف و دعوت دوستان', 'ad:setg:gift')],
             [btn('💾 بکاپ و گزارشات', 'ad:setg:data'),
              btn('👑 تعیین مالک و ادمین‌ها', 'ad:setg:owners')],
             [btn('📱 کنترل دستگاه‌ها', 'ad:setg:devices'),
              btn('🧩 اسلات‌ها', 'ad:slots')],
             [btn('📝 متن دکمه‌های منو', 'ad:setg:texts'),
              btn('📢 گزارش و پشتیبانی', 'ad:setg:report')],
             [btn('⬆️ بروزرسانی ربات', 'ad:upd', null, 'primary')],
             [btn('« بازگشت به مدیریت', 'ad:home', null, 'danger')]]);
        return;
    }
    if ($group === 'gift') {
        /* 🏷 کد تخفیف و دعوت دوستان — یک صفحه، دو زیربخش کاملاً جدا.
           اسم صفحه و اسم دکمه یکی است تا هیچ ابهامی نماند. */
        $discCount = (int) DB::val('SELECT COUNT(*) FROM discounts', [], 0);
        $activeDisc = (int) DB::val('SELECT COUNT(*) FROM discounts WHERE active=1', [], 0);
        Tg::show($chat, $messageId,
            "🏷 <b>کد تخفیف و دعوت دوستان</b>\n" . SEP . "\n"
            . '🏷 کد تخفیف: ' . (set_bool('discount_enabled', true) ? '🟢 فعال' : '🔴 خاموش')
            . ' — ' . fa_digits($activeDisc) . ' کد فعال از ' . fa_digits($discCount) . "\n"
            . '👥 سیستم دعوت: ' . (set_bool('ref_enabled', true) ? '🟢 فعال' : '🔴 خاموش')
            . ' — ' . (ref_mode() === 'cash'
                ? 'پاداش نقدی ' . money(set_int('ref_cash', 10000))
                : fa_digits(set_int('ref_percent', 10)) . '٪ واریزی اول و دوم') . "\n"
            . SEP . "\n"
            . '<i>این دو بخش از هم جدا هستند: کد تخفیف روی خرید پنل اعمال می‌شود و '
            . 'سیستم دعوت پاداش معرف را می‌دهد.</i>',
            [[btn('🏷 مدیریت کدهای تخفیف', 'ad:fin:disc', null, 'success')],
             [btn('👥 تنظیمات سیستم دعوت (رفرال)', 'ad:ref', null, 'success')],
             [btn('« تنظیمات', 'ad:setg:home', null, 'danger')]]);
        return;
    }
    if ($group === 'data') {
        /* بکاپ و گزارشات */
        $last = (int) set_get('last_backup_at', '0');
        Tg::show($chat, $messageId,
            "💾 <b>بکاپ و گزارشات</b>\n" . SEP . "\n"
            . '📢 گروه گزارشات: ' . (report_ready() ? '✅ تنظیم شده' : '🔴 تنظیم نشده') . "\n"
            . '💾 بکاپ خودکار: ' . (set_bool('backup_on', true) ? '🟢 روشن' : '🔴 خاموش')
            . ' — ساعت ' . fa_digits(max(0, min(23, set_int('backup_at_hour', 0)))) . "\n"
            . '⏱ آخرین بکاپ: ' . fa_ts($last) . "\n" . SEP . "\n"
            . '<i>بکاپ هر ۲۴ ساعت یک‌بار و فقط داده‌ها است؛ آمادهٔ بازیابی.</i>',
            [[btn('📢 تنظیم گپ گزارشات', 'ad:report', null, 'success'),
              btn('💾 بکاپ', 'ad:backup')],
             [btn('« تنظیمات', 'ad:setg:home', null, 'danger')]]);
        return;
    }
    if ($group === 'owners') {
        /* مالک و ادمین‌ها */
        $owner = owner_id();
        $staff = staff_ids();
        $text = "👑 <b>تعیین مالک و ادمین‌ها</b>\n" . SEP . "\n"
            . '👑 مالک ربات: ' . ($owner > 0 ? '<code>' . $owner . '</code>' : '—') . "\n"
            . '🛡 ادمین‌ها: ' . count($staff) . ' حساب' . "\n";
        foreach ($staff as $id) {
            $text .= '· <code>' . $id . '</code>' . ($id === $owner ? ' 👑' : '') . "\n";
        }
        $text .= SEP . "\n"
            . '<i>تنها مالک ربات «⚙️ پنل مدیریت» را می‌بیند؛ ادمین‌ها فقط گزارش می‌گیرند.</i>';
        Tg::show($chat, $messageId, $text,
            [[btn('✏️ تغییر مالک', 'ad:set:owner_id', null, 'success')],
             [btn('« تنظیمات', 'ad:setg:home', null, 'danger')]]);
        return;
    }
    if ($group === 'card') {
        /* صفحهٔ تکراری حذف شد؛ کارت‌به‌کارت فقط داخل «درگاه‌های پرداخت» است */
        screen_pay_gateway($chat, $messageId, 'card');
        return;
    }
    if ($group === 'finset') {
        screen_fin_settings($chat, $messageId);
        return;
    }
    if (isset(SETTING_GROUPS[$group])) {
        $spec = SETTING_GROUPS[$group];
        $text = $spec['title'] . "\n\n";
        $rows = [];
        $row = [];
        foreach ($spec['items'] as $key => $meta) {
            [$label, $type] = $meta;
            if ($type === 'bool') {
                $state = set_bool($key, true);
                $text .= $label . ': ' . ($state ? '🟢 روشن' : '🔴 خاموش') . "\n";
                $rows[] = [btn(($state ? '🔴 ' : '🟢 ') . $label, 'ad:tgl:' . $key . ':' . $group,
                    null, $state ? 'danger' : 'success')];
                continue;
            }
            $shown = isset(MENU_TEXTS[$key]) ? menu_label($key) : set_get($key);
            $text .= $label . ': <b>' . e($shown) . "</b>\n";
            $row[] = btn('✏️ ' . $label, 'ad:set:' . $key);
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        if ($group === 'paneltest') {
            /* تست اتصال فقط داخل داشبورد خودِ پنل است؛ اینجا فقط تنظیمات */
            $rows[] = [btn('⚡ رفتن به لیست پنل‌ها', 'ad:panel', null, 'success')];
        }
        if ($group === 'kyc') {
            $rows[] = [btn('🧾 درخواست‌های در انتظار', 'kyc:pending', null, 'success')];
            $rows[] = [btn('🧵 ساخت / بررسی تاپیک‌های احراز هویت', 'kyc:topics',
                null, 'success')];
            $rows[] = [btn('🖼 تغییر عکس نمونهٔ کارت', 'kyc:banner')];
            $text .= "\n" . 'گروه گزارشات: ' . (report_ready()
                ? '✅ تنظیم شده' : '🔴 تنظیم نشده') . "\n"
                . 'تاپیک سطح ۱: ' . (kyc_topic_id(1) ?: '—')
                . ' · سطح ۲: ' . (kyc_topic_id(2) ?: '—')
                ;
        }
        $rows[] = [btn('« تنظیمات', 'ad:setg:home', null, 'danger')];
        Tg::show($chat, $messageId, $text, $rows);
        return;
    }
    screen_settings($chat, $messageId, 'home');
}

/**
 * آموزش گام‌به‌گام تنظیم گروه گزارشات.
 * تا وقتی این گروه ثبت نشود، پنل مدیریت قفل می‌ماند و گزارشی ثبت نمی‌شود.
 */
function screen_report_setup(int|string $chat, ?int $messageId, string $notice = ''): void
{
    $current = report_chat_id();
    $status = $current !== '' ? '<code>' . e($current) . '</code>' : 'تنظیم نشده ❌';
    $text = "📣 <b>تنظیم گروه رسمی گزارشات و اعلان‌ها</b>\n" . SEP . "\n"
        . 'در این بخش می‌توانید آیدی عددی سوپرگروه اختصاصی اعلان‌های ربات را ثبت کنید.'
        . "\n\n"
        . "📋 <b>آموزش گام‌به‌گام:</b>\n"
        . '۱. ابتدا یک گروه در تلگرام بسازید.' . "\n"
        . '۲. ربات @myidbot را عضو گروه کرده و دستور <code>/getgroupid@myidbot</code> را '
        . 'داخل گروه ارسال کنید تا آیدی عددی گروه (که با <code>-100</code> شروع می‌شود) را '
        . 'دریافت نمایید.' . "\n"
        . '۳. ربات پنل خود را داخل گروه عضو کرده و دسترسی <b>ادمین کامل (Administrator)</b> '
        . 'برای ارسال پیام به آن بدهید.' . "\n"
        . '۴. <i>(پیشنهادی)</i> در صورت تمایل، از بخش تنظیمات گروه، حالت تاپیک '
        . '(Topics / Forum) را فعال نمایید.' . "\n"
        . '۵. آیدی عددی استخراج‌شده (مثال: <code>-1003861909935</code>) را همین‌جا در چت '
        . 'ربات ارسال فرمایید.' . "\n\n"
        . '📌 وضعیت فعلی: ' . $status;
    if ($notice !== '') {
        $text .= "\n\n" . $notice;
    }
    $rows = [[btn('✍️ ارسال شناسهٔ گروه', 'ad:report:input', null, 'success')]];
    if ($current !== '') {
        $rows[] = [btn('🧪 ارسال پیام آزمایشی', 'ad:report:test')];
    }
    $rows[] = [btn('🔙 انصراف / بازگشت', 'ad:home', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_report(int|string $chat, ?int $messageId, string $notice = ''): void
{
    $text = "📢 <b>گروه گزارشات</b>\n\n" . report_status_text();
    if ($notice !== '') {
        $text .= "\n\n" . e($notice);
    }
    $text .= "\n\n<i>همهٔ خریدها، شارژها، رسیدها، مصرف، تعلیق‌ها و خطاها "
        . 'در تاپیک خودشان ارسال می‌شوند.</i>';
    $rows = [];
    if (!report_ready()) {
        $rows[] = [btn('📢 تنظیم گپ گزارشات (الزامی)', 'ad:report:setup', null, 'success')];
    } else {
        $rows[] = [btn('🔁 ساخت تاپیک‌های باقی‌مانده', 'ad:report:topics', null, 'success')];
        $rows[] = [btn('🧪 ارسال پیام آزمایشی', 'ad:report:test'),
                   btn('✏️ تغییر شناسهٔ گروه', 'ad:report:setup')];
    }
    $rows[] = [btn('« تنظیمات', 'ad:setg:home', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_backup(int|string $chat, ?int $messageId, string $notice = ''): void
{
    $on = set_bool('backup_on', true);
    $hour = max(0, min(23, set_int('backup_at_hour', 0)));
    $last = (int) set_get('last_backup_at', '0');
    $chatId = trim(set_get('backup_chat'));
    if ($chatId === '') {
        $chatId = report_chat_id();
    }
    $health = backup_health();
    $text = "💾 <b>بکاپ خودکار</b>\n" . SEP . "\n"
        . 'وضعیت: ' . ($on ? '🟢 روشن' : '🔴 خاموش') . "\n"
        . 'زمان‌بندی: <b>هر ۲۴ ساعت</b> — ساعت ' . sprintf('%02d:00', $hour)
        . " به وقت ایران\n"
        . 'نام فایل: <code>sibil-backup-' . backup_stamp() . '.sqlite3</code>' . "\n"
        . 'محتوای بکاپ: <b>فقط داده</b> (کاربر، کیف پول، تراکنش، رسید، پنل، احراز)'
        . ' — سورس نه' . "\n"
        . 'فایل تنظیمات (.env): ' . (backup_with_env() ? '🟢 همراه بکاپ' : '🔴 ارسال نمی‌شود')
        . "\n"
        . 'مقصد: ' . ($chatId !== '' ? '<code>' . e($chatId) . '</code>'
            : '<i>تنظیم نشده — از گروه گزارشات استفاده کنید</i>') . "\n"
        . 'آخرین بکاپ: ' . ($last > 0 ? fa_ts($last) : 'هنوز انجام نشده') . "\n"
        . '🩺 سلامت دیتابیس: ' . ($health['ok'] ? 'سالم ✅'
            : 'هشدار ⚠️ (' . e($health['integrity']) . ')') . "\n" . SEP . "\n"
        . '<i>فایل بعد از ارسال از سرور پاک می‌شود. برای بازگردانی: همین فایل را '
        . 'برای ربات بفرستید و تأیید کنید، یا در ترمینال '
        . '<code>sibil restore /path/sibil-backup-' . backup_stamp() . '.sqlite3</code> '
        . 'را اجرا کنید.</i>';
    if ($notice !== '') {
        $text .= "\n\n" . e($notice);
    }
    Tg::show($chat, $messageId, $text, [
        [btn('📤 بکاپ فوری', 'ad:backup:now', null, 'success')],
        [btn($on ? '🔴 خاموش کنید' : '🟢 روشن کنید', 'ad:tgl:backup_on:backup', null,
            $on ? 'danger' : 'success'),
         btn('⏰ ساعت بکاپ', 'ad:set:backup_at_hour')],
        [btn('📢 گروه بکاپ', 'ad:set:backup_chat')],
        [btn(backup_with_env() ? '🔴 بدون فایل تنظیمات' : '🟢 همراه فایل تنظیمات',
            'ad:tgl:backup_env:backup', null, backup_with_env() ? 'danger' : 'success')],
        [btn('« مدیریت', 'ad:home', null, 'danger')],
    ]);
}

function screen_referral_settings(int|string $chat, ?int $messageId): void
{
    $enabled = set_bool('ref_enabled', true);
    $mode = ref_mode();
    $percent = set_int('ref_percent', 10);
    $cash = set_int('ref_cash', 10000);
    $maxDep = ref_max_deposits();
    $min = set_int('ref_min', 0);
    $invited = (int) DB::val('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL', [], 0);
    $paid = (int) DB::val('SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE kind=?', ['ref'], 0);
    $top = DB::all('SELECT referred_by, COUNT(*) AS n FROM users WHERE referred_by IS NOT NULL '
        . 'GROUP BY referred_by ORDER BY n DESC LIMIT 5');
    $text = "🎁 <b>دعوت دوستان</b>\n" . SEP . "\n"
        . 'وضعیت: ' . ($enabled ? '🟢 فعال' : '🔴 خاموش') . "\n"
        . 'نوع پاداش: ' . ($mode === 'cash' ? '💵 نقدی ثابت' : '💯 درصدی از واریزها') . "\n";
    $text .= $mode === 'cash'
        ? 'مبلغ پاداش هر دعوت: <b>' . money($cash) . '</b> (' . (ref_cash_when() === 'join'
            ? 'همان لحظهٔ استارت با لینک' : 'با اولین خرید یا شارژ زیرمجموعه') . ")\n"
        : 'درصد پورسانت: <b>' . fa_digits($percent) . "٪</b> — فقط واریزی اول و دوم\n";
    $text .= 'حداقل مبلغ واریز برای پورسانت: ' . money($min) . "\n"
        . 'دعوت‌شده‌ها: ' . fa_digits($invited) . ' · پاداش پرداخت‌شده: ' . money($paid) . "\n"
        . SEP . "\n";
    if ($top) {
        $text .= "<b>بهترین دعوت‌کننده‌ها</b>\n";
        foreach ($top as $row) {
            $user = user_get((int) $row['referred_by']);
            $text .= '· ' . e(mb_substr((string) ($user['name'] ?? (string) $row['referred_by']), 0, 18))
                . ' — ' . fa_digits((int) $row['n']) . " نفر\n";
        }
    } else {
        $text .= 'هنوز کسی کسی را دعوت نکرده.';
    }
    $buttons = [
        [btn($enabled ? '🔴 خاموش کنید' : '🟢 روشن کنید', 'ad:ref:toggle', null,
            $enabled ? 'danger' : 'success')],
        [btn(($mode === 'cash' ? '✅ ' : '') . '💵 نقدی ثابت', 'ad:ref:mode:cash',
             null, $mode === 'cash' ? 'success' : 'primary'),
         btn(($mode === 'percent' ? '✅ ' : '') . '💯 درصدی', 'ad:ref:mode:percent',
             null, $mode === 'percent' ? 'success' : 'primary')],
        [btn('🎯 زمان پرداخت پاداش نقدی: ' . (ref_cash_when() === 'join'
            ? 'لحظهٔ عضویت' : 'اولین خرید'), 'ad:ref:when', null, 'success')],
        [btn('💵 مبلغ پاداش نقدی', 'ad:ref:cash'), btn('💯 درصد پورسانت', 'ad:ref:percent')],
        [btn('💵 حداقل واریز', 'ad:ref:min'),
         btn('🔢 تعداد واریزی پورسانت', 'ad:ref:maxdep')],
        [btn('« تنظیمات', 'ad:setg:gift', null, 'danger')],
    ];
    Tg::show($chat, $messageId, $text, $buttons);
}

/**
 * زمان پرداخت پاداش نقدی — انتخاب مدیر:
 *  · 'join'     → همان لحظهٔ عضویت با لینک دعوت
 *  · 'purchase' → با اولین خرید/شارژ زیرمجموعه
 */
function ref_cash_when(): string
{
    $when = (string) set_get('ref_cash_when', 'join');
    return in_array($when, ['join', 'purchase'], true) ? $when : 'join';
}

/** حالت پاداش دعوت: percent (درصد واریزها) یا cash (نقدی ثابت) */
function ref_mode(): string
{
    $mode = set_get('ref_mode', 'percent');
    return in_array($mode, ['percent', 'cash'], true) ? $mode : 'percent';
}

/** چند واریزی اول مشمول پورسانت است (قانون طلایی: ۲) */
function ref_max_deposits(): int
{
    $max = set_int('ref_max_deposits', 2);
    return $max <= 0 ? 2 : $max;
}

/** افزایش شمارندهٔ واریزی‌های یک کاربر و برگرداندن مقدار تازه */
function deposit_count_up(int $tgId): int
{
    DB::exec('UPDATE users SET deposit_count = COALESCE(deposit_count,0) + 1 WHERE tg_id=?',
        [$tgId]);
    return (int) DB::val('SELECT COALESCE(deposit_count,0) FROM users WHERE tg_id=?',
        [$tgId], 0);
}

/* ── لینک دعوت ──────────────────────────────────────────────────────────── */

/** پیشوند استاندارد پارامتر استارت برای دعوت */
const REF_PREFIX = 'ref_';

/** لینک اختصاصی دعوت یک کاربر: https://t.me/<bot>?start=ref_<id> */
function ref_link(int $tgId): string
{
    $handle = bot_username();
    if ($handle === '' || $tgId <= 0) {
        return '';
    }
    return 'https://t.me/' . $handle . '?start=' . REF_PREFIX . $tgId;
}

/** پارامتر استارت را به شناسهٔ معرف تبدیل می‌کند (ref_123 · ref-123 · 123) */
function ref_parse_start(string $payload): int
{
    $payload = trim($payload);
    if ($payload === '') {
        return 0;
    }
    if (preg_match('~^ref[_-]?(\d{4,})$~i', $payload, $m)) {
        return (int) $m[1];
    }
    if (ctype_digit($payload) && strlen($payload) >= 4) {
        return (int) $payload;
    }
    return 0;
}

/** لینک اشتراک‌گذاری تلگرام برای فرستادن دعوت به دیگران */
function ref_share_url(string $link, string $text = ''): string
{
    if ($link === '') {
        return '';
    }
    $url = 'https://t.me/share/url?url=' . rawurlencode($link);
    if ($text !== '') {
        $url .= '&text=' . rawurlencode($text);
    }
    return $url;
}

/**
 * پاداش نقدی ثابت — به‌محض اینکه کاربری با لینک دعوت وارد ربات می‌شود.
 * هر دعوت‌شده فقط یک‌بار (ref_cash_paid) و فقط در حالت cash.
 */
function referral_welcome_cash(int $newUserId): int
{
    if (!set_bool('ref_enabled', true) || ref_mode() !== 'cash') {
        return 0;
    }
    $user = user_get($newUserId);
    if ($user === null) {
        return 0;
    }
    $referrer = (int) ($user['referred_by'] ?? 0);
    if ($referrer <= 0 || $referrer === $newUserId) {
        return 0;
    }
    if ((int) ($user['ref_cash_paid'] ?? 0) === 1) {
        return 0;   // یک‌بار پرداخت شده
    }
    $cash = max(0, set_int('ref_cash', 10000));
    if ($cash <= 0) {
        return 0;
    }
    /* قفل اتمی: پرداخت دوباره ممکن نیست */
    $claimed = DB::update('UPDATE users SET ref_cash_paid=1, '
        . 'ref_reward_total=COALESCE(ref_reward_total,0)+? WHERE tg_id=? AND '
        . 'COALESCE(ref_cash_paid,0)=0', [$cash, $newUserId]);
    if ($claimed === 0) {
        return 0;
    }
    add_balance($referrer, $cash, 'ref', (string) $newUserId, 'پاداش نقدی دعوت ' . $newUserId);
    log_line('info', 'referral cash reward', ['referrer' => $referrer, 'invited' => $newUserId,
        'amount' => $cash]);
    Tg::send($referrer, '🎉 کاربری با لینک دعوت شما عضو شد و مبلغ <b>' . money($cash)
        . "</b> به کیف پول شما اضافه گردید.\n\n"
        . '💰 موجودی جدید: <b>' . money(balance_of($referrer)) . '</b>');
    post_report('🎁 <b>پاداش دعوت (نقدی)</b>' . "\n"
        . '👤 معرف: <code>' . $referrer . "</code>\n"
        . '🆕 دعوت‌شده: <code>' . $newUserId . "</code>\n"
        . '💵 مبلغ: ' . money($cash), 'sales');
    return $cash;
}

/**
 * پورسانت واریزی — روی شارژ کیف پول.
 * قانون طلایی: فقط واریزی اول و دوم هر کاربر؛ از سومی به بعد هیچ پورسانتی نیست.
 * خروجی: مبلغ پاداشی که به معرف داده شد (۰ = پرداخت نشد).
 */
function referral_deposit_payout(int $tgId, int $amount): int
{
    if (!set_bool('ref_enabled', true) || ref_mode() !== 'percent') {
        return 0;
    }
    $user = user_get($tgId);
    if ($user === null) {
        return 0;
    }
    $referrer = (int) ($user['referred_by'] ?? 0);
    if ($referrer <= 0 || $referrer === $tgId) {
        return 0;
    }
    if ($amount < set_int('ref_min', 0)) {
        return 0;
    }
    $which = (int) DB::val('SELECT COALESCE(deposit_count,0) FROM users WHERE tg_id=?',
        [$tgId], 0);
    if ($which < 1 || $which > ref_max_deposits()) {
        return 0;   // واریزی سوم به بعد: بدون پورسانت
    }
    $percent = max(0, min(100, set_int('ref_percent', 10)));
    $reward = intdiv($amount * $percent, 100);
    if ($reward <= 0) {
        return 0;
    }
    DB::exec('UPDATE users SET ref_reward_total=COALESCE(ref_reward_total,0)+? WHERE tg_id=?',
        [$reward, $tgId]);   // جمع پاداش‌هایی که این کاربر برای معرفش آورده
    DB::exec('UPDATE users SET ref_paid=1 WHERE tg_id=?', [$tgId]);
    add_balance($referrer, $reward, 'ref', (string) $tgId,
        'پورسانت واریزی ' . $which . ' کاربر ' . $tgId);
    log_line('info', 'referral deposit commission', ['referrer' => $referrer,
        'invited' => $tgId, 'deposit' => $which, 'amount' => $reward]);
    Tg::send($referrer, '🎉 کاربری که با لینک دعوت شما عضو شده بود واریزی شمارهٔ '
        . fa_digits($which) . ' را انجام داد و مبلغ <b>' . money($reward)
        . "</b> به کیف پول شما اضافه گردید.\n\n"
        . '💰 موجودی جدید: <b>' . money(balance_of($referrer)) . '</b>');
    post_report('🎁 <b>پورسانت دعوت</b>' . "\n"
        . '👤 معرف: <code>' . $referrer . "</code>\n"
        . '🆕 دعوت‌شده: <code>' . $tgId . "</code>\n"
        . '🔢 واریزی شماره: ' . fa_digits($which) . "\n"
        . '💵 مبلغ واریز: ' . money($amount) . "\n"
        . '🎁 پورسانت: ' . money($reward), 'sales');
    return $reward;
}

/** نسخهٔ قدیمی (سازگاری): پاداش اولین خرید — فقط اگر هیچ پاداشی پرداخت نشده باشد */
function referral_payout(array $order): void
{
    $tgId = (int) $order['tg_id'];
    $user = user_get($tgId);
    if ($user === null || (int) ($user['ref_paid'] ?? 0) === 1) {
        return;
    }
    if ((int) ($user['deposit_count'] ?? 0) > 0) {
        return;   // پورسانت واریزی جای این پاداش را گرفته است
    }
    if (ref_mode() !== 'percent') {
        return;
    }
    $amount = (int) $order['payable'];
    if ($amount < set_int('ref_min', 0)) {
        return;
    }
    $percent = set_int('ref_percent', 10);
    $reward = intdiv($amount * $percent, 100);
    DB::exec('UPDATE users SET ref_paid=1 WHERE tg_id=?', [$tgId]);
    if ($reward <= 0) {
        return;
    }
    $referrer = (int) $user['referred_by'];
    add_balance($referrer, $reward, 'ref', (string) $order['id'],
        'پاداش دعوت کاربر ' . $tgId);
    Tg::send($referrer, '🎁 <b>' . money($reward) . "</b> پاداش دعوت به کیف پولتان اضافه شد.\n"
        . 'یکی از دوستانت با لینک شما خرید کرد.');
}

/* ── تخفیف اختصاصی یک کاربر (اعطایی از کارت مدیریت) ─────────────────── */

/** درصد تخفیف اختصاصی فعال یک کاربر */
function user_discount_percent(int $tgId): int
{
    $row = DB::one('SELECT discount_percent, discount_until FROM users WHERE tg_id=?', [$tgId]);
    if ($row === null) {
        return 0;
    }
    $until = (int) ($row['discount_until'] ?? 0);
    if ($until > 0 && $until <= now_ts()) {
        return 0;   // منقضی شده
    }
    return max(0, min(100, (int) ($row['discount_percent'] ?? 0)));
}

/** متن کوتاه تخفیف اختصاصی برای کارت کاربر */
function user_discount_text(array $user): string
{
    $percent = user_discount_percent((int) $user['tg_id']);
    if ($percent <= 0) {
        return 'ندارد';
    }
    $until = (int) ($user['discount_until'] ?? 0);
    return fa_digits($percent) . '٪' . ($until > 0 ? ' (تا ' . fa_ts($until) . ')' : '');
}

/** اعطای تخفیف اختصاصی: درصد + تعداد روز (۰ = بی‌پایان) */
function user_discount_grant(int $tgId, int $percent, int $days): array
{
    $percent = max(0, min(100, $percent));
    if ($percent === 0) {
        DB::exec('UPDATE users SET discount_percent=0, discount_until=NULL WHERE tg_id=?',
            [$tgId]);
        return [true, 'تخفیف اختصاصی این کاربر حذف شد.'];
    }
    $until = $days > 0 ? now_ts() + $days * DAY : null;
    DB::exec('UPDATE users SET discount_percent=?, discount_until=? WHERE tg_id=?',
        [$percent, $until, $tgId]);
    return [true, '🎁 تخفیف اختصاصی <b>' . fa_digits($percent) . '</b>٪ ثبت شد'
        . ($until !== null ? ' تا ' . fa_ts($until) : ' (بی‌پایان)') . '.'];
}

/** تخفیف شخصی روی مبلغ یک سفارش خرید (کیف پول یا درگاه) */
function user_discount_amount(int $tgId, int $price): int
{
    $percent = user_discount_percent($tgId);
    if ($percent <= 0 || $price <= 0) {
        return 0;
    }
    return (int) floor($price * $percent / 100);
}

