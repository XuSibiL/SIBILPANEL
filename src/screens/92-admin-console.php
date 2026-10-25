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

function console_home_text(): string
{
    if (report_required()) {
        /* قفل اجباری: هیچ شمارنده و آماری تا ثبت گروه گزارشات نشان داده نمی‌شود */
        return '⚙️ <b>پنل مدیریت ' . e(brand()) . '</b>' . "\n" . SEP . "\n"
            . console_lock_text();
    }
    $users = (int) DB::val('SELECT COUNT(*) FROM users', [], 0);
    $activeUsers = (int) DB::val("SELECT COUNT(*) FROM users WHERE tg_id IN "
        . '(SELECT DISTINCT tg_id FROM panels WHERE status=\'active\')', [], 0);
    $live = (int) DB::val("SELECT COUNT(*) FROM panels WHERE status='active'", [], 0);
    $products = (int) DB::val('SELECT COUNT(*) FROM products', [], 0);
    $pending = (int) DB::val("SELECT COUNT(*) FROM receipts WHERE status='pending'", [], 0);
    $pgs = (int) DB::val('SELECT COUNT(*) FROM pg_panels WHERE active=1', [], 0);
    $notice = '';
    if (update_is_newer(update_remote_version()['version'], PANEL_VERSION)) {
        $notice .= "\n⬆️ <b>نسخهٔ تازه در گیت‌هاب موجود است</b> — «⚙️ تنظیمات ربات» → «⬆️ بروزرسانی ربات»\n";
    }
    return '⚙️ <b>پنل مدیریت ' . e(brand()) . '</b>' . "\n"
        . SEP . "\n"
        . $notice
        . '👥 کاربران کل: ' . fa_digits($users) . ' | 🟢 فعال: ' . fa_digits($activeUsers) . "\n"
        . '🔌 پنل‌های متصل: ' . fa_digits($pgs) . ' | 📦 محصولات: ' . fa_digits($products) . "\n"
        . '🧾 رسیدهای در انتظار: ' . fa_digits($pending)
        . ' | 🗂 پنل‌های فروخته‌شدهٔ فعال: ' . fa_digits($live) . "\n"
        . SEP . "\n"
        . 'یک بخش را انتخاب کنید:';
}

function console_kb(): array
{
    if (report_required()) {
        /* فقط یک دکمه: تنظیم گروه گزارشات — بقیهٔ مدیریت قفل است */
        return kb_clean([
            [btn('📢 تنظیم گپ گزارشات (الزامی)', 'ad:report:setup', null, 'success')],
        ]);
    }
    /* منوی مدیریت — دقیقاً دو ستون، بدون دکمهٔ تکراری و بدون دکمهٔ شیشه‌ای */
    return kb_clean([
        [btn('📊 آمار و گزارشات', 'ad:stats', null, 'primary'),
         btn('🧾 رسیدها و مالی', 'ad:finance', null, 'primary')],
        [btn('👥 مدیریت کاربران', 'ad:users', null, 'primary'),
         btn('📦 محصولات و دسته‌ها', 'ad:cats', null, 'primary')],
        [btn('🔌 پنل‌های پاسارگاد', 'ad:panel', null, 'primary'),
         btn('⚙️ تنظیمات ربات', 'ad:settings', null, 'primary')],
        [btn('🔍 جستجوی سفارشات', 'ad:find', null, 'primary'),
         btn('📢 پیام همگانی', 'ad:broadcast', null, 'success')],
        [btn('📢 قفل کانال اجباری', 'ad:fj', null, 'success')],
        [btn('🔙 بازگشت به منوی کاربری', 'home', null, 'danger')],
    ]);
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

function screen_console(int|string $chat, ?int $messageId): void
{
    /* پنل مدیریت نقطهٔ شروع است؛ دکمهٔ «بازگشت» روی آن معنا ندارد */
    $GLOBALS['panel_console_root'] = true;
    try {
        Tg::show($chat, $messageId, console_home_text(), console_kb());
    } finally {
        $GLOBALS['panel_console_root'] = false;
    }
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
        $cap = (float) $product['data_cap_gb'] > 0
            ? fa_digits((float) $product['data_cap_gb']) . 'GB' : 'نامحدود';
        $text .= ((int) $product['active'] === 1 ? '✅' : '⛔️') . ' ' . $product['emoji'] . ' <b>'
            . e($product['name']) . "</b>\n"
            . '   ⏱ ' . money((int) $product['hourly_price']) . ' /ساعت · 💵 '
            . money((int) $product['per_gb_price']) . " /گیگ\n"
            . '   👤 ' . fa_digits((int) $product['max_users']) . ' کاربر · 📱 '
            . fa_digits((int) $product['device_limit']) . ' دستگاه · 📦 ' . $cap
            . ' · ⏳ ' . fa_digits((int) $product['days']) . " روز\n\n";
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
    $cap = (float) $product['data_cap_gb'] > 0
        ? fa_digits((float) $product['data_cap_gb']) . ' گیگ' : 'نامحدود';
    $text = $product['emoji'] . ' <b>' . e($product['name']) . '</b> '
        . ((int) $product['active'] === 1 ? '✅' : '⛔️') . "\n"
        . '🗂 دسته: ' . cat_label($cat) . ' · 🔌 پنل: '
        . e($panel['name'] ?? 'پیش‌فرض') . "\n\n"
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
    $panels = DB::all('SELECT * FROM pg_panels ORDER BY is_default DESC, id');
    if (!$panels) {
        Tg::show($chat, $messageId,
            "🔌 <b>پنل‌های پاسارگارد</b>\n\nهنوز هیچ پنلی وصل نیست.\n"
            . 'با «➕ افزودن پنل» نام، آدرس و <b>کلید API</b> پنل را بدهید.',
            [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')],
             [btn('⚙️ تنظیمات اتصال', 'ad:setg:paneltest')],
             [btn('« مدیریت', 'ad:home', null, 'danger')]]);
        return;
    }
    $text = "🔌 <b>پنل‌های پاسارگارد</b>\n" . SEP . "\n"
        . 'هر پنل یک داشبورد اختصاصی دارد: آمار زنده، سرور، فروش و کنترل دستگاه.' . "\n"
        . SEP . "\n\n";
    /* تست اتصال دستی حذف شد؛ اتصال هر پنل همان لحظهٔ ورود به داشبورد
       خودکار بررسی می‌شود */
    $rows = [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')]];
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
    $rows[] = [btn('⚙️ تنظیمات اتصال', 'ad:setg:paneltest')];
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

    /* ورود به داشبورد = تست خودکار اتصال؛ هیچ دکمهٔ تست دستی لازم نیست */
    $head = '📊 <b>آمار و وضعیت پنل: ' . e((string) $panel['name']) . '</b>' . "\n"
        . SEP . "\n";
    if (!$live['online']) {
        $text = $head
            . '⚠️ خطا در برقراری ارتباط با پنل پاسارگاد (سرور در دسترس نیست یا اطلاعات '
            . 'لاگین نامعتبر است).' . "\n\n"
            . ($live['error'] !== '' ? 'جزئیات خطا: <code>'
                . e(mb_substr($live['error'], 0, 160)) . "</code>\n\n" : '')
            . '📡 وضعیت اتصال: قطع ❌' . "\n"
            . SEP . "\n"
            . 'آدرس ثبت‌شده: <code>' . e(normalize_panel_url((string) $panel['url'])) . '</code>'
            . "\n"
            . 'آخرین اتصال موفق: ' . fa_ts((int) $panel['last_ok_at']) . "\n"
            . 'وضعیت در ربات: ' . ((int) $panel['active'] === 1 ? '🟢 روشن' : '🔴 خاموش')
            . ((int) $panel['is_default'] === 1 ? ' · پیش‌فرض ⭐' : '') . "\n"
            . SEP . "\n"
            . '<i>آمار جعلی یا خالی نمایش داده نمی‌شود. اگر سرور از کار افتاده، همین‌جا '
            . 'می‌توانید پنل را از ربات حذف کنید.</i>';
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
            . '💰 <b>وضعیت فروش این پنل</b>' . "\n"
            . '• تعداد کل فروش: ' . fa_digits($sales['count']) . ' عدد' . "\n"
            . '• مجموع فروش: ' . money($sales['total']) . "\n"
            . '• محصولات روی این پنل: ' . fa_digits($products)
            . ' · پنل‌های فروخته‌شده: ' . fa_digits($sold) . "\n"
            . SEP . "\n"
            . 'آدرس: <code>' . e(normalize_panel_url((string) $panel['url'])) . '</code>' . "\n"
            . 'وضعیت در ربات: ' . ((int) $panel['active'] === 1 ? '🟢 روشن' : '🔴 خاموش')
            . ((int) $panel['is_default'] === 1 ? ' · پیش‌فرض ⭐' : '') . "\n"
            . 'آخرین اتصال موفق: ' . fa_ts((int) $panel['last_ok_at']) . "\n"
            . (!empty($panel['last_error']) ? 'آخرین خطا: <code>'
                . e(mb_substr((string) $panel['last_error'], 0, 160)) . "</code>\n" : '')
            . "\n" . 'برای مدیریت این پنل یکی از گزینه‌های زیر را انتخاب کنید:';
    }

    Tg::show($chat, $messageId, $text, [
        [btn('🔄 بروزرسانی آمار', 'ad:pg:' . $panelId, null, 'success'),
         btn('🗑 حذف این پنل از ربات', 'ad:pg:' . $panelId . ':del', null, 'danger')],
        [btn('📱 کنترل دستگاه‌ها (HWID)', 'ad:pg:' . $panelId . ':dev'),
         btn('✏️ ویرایش نام / آدرس', 'ad:pg:' . $panelId . ':edit')],
        [btn('🔄 همگام‌سازی کاربران', 'ad:pg:' . $panelId . ':sync'),
         btn('🎭 نقش‌ها', 'ad:pg:' . $panelId . ':roles')],
        [btn('🗂 دسته', 'ad:pg:' . $panelId . ':cat')],
        [btn('⭐ پیش‌فرض', 'ad:pg:' . $panelId . ':default'),
         btn((int) $panel['active'] === 1 ? '🔴 خاموش کنید' : '🟢 روشن کنید',
             'ad:pg:' . $panelId . ':toggle', null, 'danger')],
        [btn('🔑 تغییر کلید API', 'ad:pg:' . $panelId . ':key', null, 'success')],
        [btn('🔙 بازگشت به لیست پنل‌ها', 'ad:panel', null, 'danger')],
    ]);
}

/**
 * 📱 کنترل دستگاه‌های یک پنل پاسارگارد (از داخل داشبورد همان پنل).
 * محدودسازی، مثل همیشه، فقط روی همان کاربر متخلف است.
 */
function screen_pg_devices(int|string $chat, ?int $messageId, int $panelId): void
{
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
    $rows = [
        [btn('🔄 بررسی دوباره', 'ad:pg:' . $panelId . ':dev', null, 'success')],
        [btn($guard ? '🔴 خاموش کردن کنترل' : '🟢 روشن کردن کنترل',
            'ad:tgl:device_guard_on:devices', null, $guard ? 'danger' : 'success')],
        [btn('✏️ سقف دستگاه', 'ad:set:device_max_devices'),
         btn('✏️ مدت جریمه', 'ad:set:device_cut_minutes')],
        [btn('« داشبورد پنل', 'ad:pg:' . $panelId, null, 'danger')],
    ];
    Tg::show($chat, $messageId, $text, $rows);
}

