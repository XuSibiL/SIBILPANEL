<?php
declare(strict_types=1);

/* 9) User-facing screens */

function home_text(int $uid): string
{
    $user = user_get($uid);
    $name = e((string) ($user['name'] ?? 'دوست عزیز'));
    [$free, $bought, $used] = slots_total($uid);
    return "👋 سلام <b>{$name}</b>\n\n"
        . 'به <b>' . e(bot_name()) . "</b> خوش آمدید.\n"
        . "پنل‌های خود را اینجا بسازید و مدیریت کنید؛\n"
        . "پرداخت فقط بر اساس میزان مصرف است.\n\n"
        . '🧩 اسلات: ' . fa_digits($free + $bought) . ' | مصرف‌شده: ' . fa_digits($used) . "\n"
        . '💰 موجودی: <b>' . money(balance_of($uid)) . "</b>\n"
        . '🪪 شناسهٔ شما: <code>' . $uid . '</code>';
}

/*
   برچسب پیش‌فرض دکمه‌های منو. هر برچسب از پنل مدیریت قابل تغییر است.
*/
const MENU_TEXTS = [
    'text_sell' => '🛒 خرید پنل',
    'text_Purchased_services' => '📦 پنل‌های من',
    'text_Add_Balance' => '💰 کیف پول',
    'text_usertest' => '🧪 تست پنل',
    'text_orders' => '🧾 سفارش‌های من',
    'text_affiliates' => '🎁 دعوت دوستان',
    'text_support' => '☎️ پشتیبانی',
    'text_help' => '🎓 راهنما',
    'text_Discount' => '🎁 کد هدیه',
];

/** برچسب منو: مقدار ذخیره‌شده در تنظیمات، وگرنه پیش‌فرض بالا */
function menu_label(string $key): string
{
    $value = trim((string) set_get($key));
    if ($value === '') {
        return MENU_TEXTS[$key] ?? $key;
    }
    return $value;
}

function home_kb(int $uid): array
{
    /* منوی اصلی: همهٔ دکمه‌ها آبی و با ترتیب ثابت */
    $rows = [[btn(menu_label('text_sell'), 'menu:buy', null, 'primary')]];
    $rows[] = [btn(menu_label('text_Purchased_services'), 'menu:panels', null, 'primary'),
               btn(menu_label('text_Add_Balance'), 'menu:wallet', null, 'primary')];
    if (set_bool('trial_on', true)) {
        $rows[] = [btn(menu_label('text_usertest'), 'menu:trial', null, 'primary')];
    }
    $rows[] = [btn(menu_label('text_affiliates'), 'menu:ref', null, 'primary'),
               btn(menu_label('text_support'), 'menu:support', null, 'primary')];
    $rows[] = [btn(menu_label('text_help'), 'help:home', null, 'primary')];
    if (is_staff($uid)) {
        /* مالک و ادمین‌ها حساب فروشگاه کامل دارند و در ضمن دکمهٔ مدیریت هم می‌بینند */
        $rows[] = [btn('⚙️ پنل مدیریت', 'ad:home', null, 'success')];
    }
    return kb_clean($rows);
}

function show_home(int|string $chat, ?int $messageId, int $uid): void
{
    Tg::show($chat, $messageId, home_text($uid), home_kb($uid));
}

function buy_categories_kb(): array
{
    $activeCats = [];
    foreach (cat_list(true) as $cat) {
        $activeCats[(int) $cat['id']] = $cat;
    }
    $counts = [];
    foreach (products_list(true) as $product) {
        $cid = (int) ($product['category_id'] ?? 0);
        $counts[$cid] = ($counts[$cid] ?? 0) + 1;
    }
    $rows = [];
    if (isset($counts[0])) {
        $rows[] = [btn('🗂 دستهٔ سایر محصولات', 'buy:cat:0')];
    }
    foreach ($activeCats as $cid => $cat) {
        $count = $counts[$cid] ?? 0;
        if ($count <= 0) {
            continue; // دستهٔ خالی نمایش داده نمی‌شود
        }
        $rows[] = [btn(cat_label($cat), "buy:cat:$cid", null, 'primary')];
    }
    $rows[] = [btn('« بازگشت', 'home', null, 'danger')];
    return $rows;
}

function screen_buy(int|string $chat, ?int $messageId, int $uid): void
{
    if (!set_bool('sales_open', true)) {
        Tg::show($chat, $messageId, '⛔️ فروش موقتاً بسته است.', home_kb($uid));
        return;
    }
    $rows = buy_categories_kb();
    if (count($rows) === 1) {
        Tg::show($chat, $messageId,
            "😕 هنوز محصولی برای فروش نیست.\nکمی بعد سر بزنید.", home_kb($uid));
        return;
    }
    [$free, $bought, $used] = slots_total($uid);
    Tg::show($chat, $messageId,
        "🛒 <b>خرید پنل</b>\n\n"
        . '🧩 اسلات آزاد: <b>' . fa_digits(slots_available($uid)) . "</b>\n\n"
        . 'یک دسته را انتخاب کنید:', $rows);
}

function screen_buy_category(int|string $chat, ?int $messageId, int $categoryId): void
{
    $products = [];
    foreach (products_list(true) as $product) {
        if ((int) ($product['category_id'] ?? 0) === $categoryId) {
            $products[] = $product;
        }
    }
    $cat = $categoryId ? cat_get($categoryId) : null;
    if ($categoryId && ($cat === null || (int) $cat['active'] !== 1)) {
        Tg::show($chat, $messageId, 'این دسته فعلاً بسته است.',
            [[btn('« بازگشت', 'menu:buy', null, 'danger')]]);
        return;
    }
    $rows = [];
    foreach ($products as $product) {
        /* فقط ایموجی و نام اصلی تنظیم‌شده؛ هیچ قیمت/نرخ محاسبه‌شده‌ای روی دکمه نیست. */
        $rows[] = [btn((string) $product['emoji'] . ' ' . (string) $product['name'],
            'buy:p:' . $product['id'])];
    }
    if (!$rows) {
        Tg::show($chat, $messageId,
            (cat_label($cat) ?? '🗂') . "\n\n"
            . 'این دسته هنوز محصولی ندارد؛ به‌زودی اضافه می‌شود.',
            [[btn('« دسته‌های دیگر', 'menu:buy', null, 'danger')]]);
        return;
    }
    $rows[] = [btn('« بازگشت', 'menu:buy', null, 'danger')];
    Tg::show($chat, $messageId,
        ($cat === null ? '🗂 سایر محصولات' : cat_label($cat)) . "\n\nیک محصول را انتخاب کنید:",
        $rows);
}

function product_detail_text(array $product): string
{
    if (product_is_unlimited($product)) {
        return $product['emoji'] . ' <b>' . e((string) $product['name']) . "</b>\n\n"
            . '♾️ <b>پنل نامحدود</b>' . "\n"
            . '💵 قیمت هر کاربر: <b>' . money(unlimited_user_price($product)) . "</b>\n"
            . '👥 حداقل تعداد کاربر در هر سفارش: <b>'
                . fa_digits(unlimited_min_users($product)) . "</b>\n"
            . '🧩 اسلات لازم: ' . fa_digits((int) $product['slots_needed']) . "\n\n"
            . 'تعداد کاربر دلخواهتان را وارد می‌کنید و مبلغ فاکتور برابر است با تعداد کاربر × قیمت هر کاربر.'
            . "\n\n"
            . '<i>مدت اعتبار و محدودیت دستگاه‌ها مستقیماً از تنظیمات نقش در پنل پاسارگارد اعمال می‌شود.</i>';
    }
    $cap = (float) $product['data_cap_gb'] > 0
        ? fa_digits((float) $product['data_cap_gb']) . ' گیگ' : 'نامحدود';
    $hours = prepaid_hours();
    $daily = ((int) $product['hourly_price']) * 24;
    $prepaid = panel_prepaid_amount($product);
    $setup = max(0, set_int('panel_setup_fee', 0));
    return $product['emoji'] . ' <b>' . e($product['name']) . "</b>\n\n"
        . '👤 تعداد کاربر: ' . fa_digits((int) $product['max_users']) . "\n"
        . '📦 سقف حجم پنل: ' . $cap . "\n"
        . '📱 دستگاه هر کاربر: ' . fa_digits((int) $product['device_limit']) . "\n"
        . '⏳ اعتبار پنل: ' . fa_digits((int) $product['days']) . " روز\n"
        . '💵 قیمت هر گیگ: ' . money((int) $product['per_gb_price']) . "\n"
        . '⏱ اجاره / ساعت: ' . money((int) $product['hourly_price']) . "\n"
        . '🧩 اسلات لازم: ' . fa_digits((int) $product['slots_needed']) . "\n"
        . '💰 حداقل موجودی لازم: ' . money((int) $product['min_balance']) . "\n\n"
        . '📊 حدود هزینه: روزانه ≈ ' . money($daily) . "\n\n"
        . '🧾 <b>پرداخت هنگام خرید:</b>' . "\n"
        . ($setup > 0 ? '• هزینهٔ ساخت پنل: ' . money($setup) . "\n" : '')
        . ($prepaid > 0
            ? '• پیش‌پرداخت ' . fa_digits($hours) . ' ساعت اول: <b>' . money($prepaid) . '</b>'
                . "\n" . '  (غیرقابل استرداد — کسر ساعتی از ساعت ' . fa_digits($hours)
                . ' به بعد شروع می‌شود)' . "\n"
            : '• پیش‌پرداخت اولیه ندارد.' . "\n")
        . "\n" . '💳 <b>پس از آن:</b> بر اساس گیگ مصرف و ساعت اجاره، خودکار از کیف پول کم می‌شود.';
}

function screen_product(int|string $chat, ?int $messageId, int $productId): void
{
    $product = product_get($productId);
    if ($product === null || (int) $product['active'] !== 1) {
        Tg::show($chat, $messageId, 'این محصول موجود نیست.',
            [[btn('« بازگشت', 'menu:buy', null, 'danger')]]);
        return;
    }
    $rows = [];
    if (product_is_unlimited($product)) {
        $rows[] = [btn('👥 تعداد کاربر دلخواه', 'buy:users:' . $productId, null, 'success')];
    } else {
        $rows[] = [btn('🟧 خرید این پنل', 'buy:confirm:' . $productId, null, 'success')];
        $rows[] = [btn('🔢 قیمت دقیق', 'buy:exact:' . $productId)];
    }
    $rows[] = [btn('« بازگشت', 'buy:cat:' . (int) ($product['category_id'] ?? 0), null, 'danger')];
    Tg::show($chat, $messageId, product_detail_text($product), $rows);
}

/** ثانیه‌های باقی‌مانده تا بسته‌شدن فاکتور (۰ = بسته شده) */
function order_seconds_left(array $order): int
{
    $minutes = max(1, set_int('pay_expire_minutes', 30));
    $made = (int) ($order['created_at'] ?? now_ts());
    return max(0, ($made + $minutes * 60) - now_ts());
}

/** خط شمارش معکوس واقعی زیر فاکتور */
function order_expiry_line(array $order): string
{
    $left = order_seconds_left($order);
    if ($left <= 0) {
        return '⌛️ <b>مهلت این فاکتور تمام شده است.</b>' . "\n";
    }
    /* شمارش پله‌پله: هر ۱۵ دقیقه یک پله کم می‌شود (۳۰ → ۱۵ → ۰) */
    $step = 15;
    $minutes = max(1, set_int('pay_expire_minutes', 30));
    $remaining = (int) ceil($left / ($step * 60)) * $step;
    $remaining = min($remaining, $minutes);
    if ($remaining <= 0) {
        return '⌛️ <b>مهلت این فاکتور تمام شده است.</b>' . "\n";
    }
    return '⏳ مهلت پرداخت: <b>' . $remaining . ' دقیقه</b>' . "\n";
}

/**
 * ساعت‌های پیش‌پرداخت اولیهٔ هر پنل (غیرقابل استرداد). پیش‌فرض ۲۴ ساعت.
 */
function prepaid_hours(): int
{
    return max(0, min(168, set_int('prepaid_hours', 24)));
}

/** مبلغ پیش‌پرداخت = قیمت ساعتی × ساعت‌های پیش‌پرداخت */
function panel_prepaid_amount(array $product): int
{
    $hourly = max(0, (int) ($product['hourly_price'] ?? 0));
    if ($hourly === 0 || !set_bool('billing_hourly', true)) {
        return 0;
    }
    return $hourly * prepaid_hours();
}

/** ثانیه‌های باقی‌مانده از پیش‌پرداخت (۰ = تمام شده و کسر ساعتی فعال است) */
function panel_prepaid_remaining(array $panel): int
{
    $start = (int) ($panel['billing_start_at'] ?? 0);
    return $start > 0 ? max(0, $start - now_ts()) : 0;
}

/**
 * مبلغ فاکتور خرید پنل = هزینهٔ ساخت + پیش‌پرداخت ساعت‌های اول.
 * فاکتور یک پلن غیررایگان هرگز صفر نمی‌شود (باگ «مبلغ: ۰ تومان»).
 * خروجی: [کل, هزینهٔ ساخت, پیش‌پرداخت]
 */
function panel_order_price(array $product): array
{
    $setup = max(0, set_int('panel_setup_fee', 0));
    $prepaid = panel_prepaid_amount($product);
    return [$setup + $prepaid, $setup, $prepaid];
}

/**
 * ریز فاکتور خرید پنل: هزینهٔ ساخت + پیش‌پرداخت ساعت‌های اول.
 * غیرقابل استرداد بودن پیش‌پرداخت همین‌جا به کاربر گفته می‌شود.
 */
function panel_order_breakdown(array $product, int $setupFee, int $prepaid): string
{
    $lines = '';
    if ($setupFee > 0) {
        $lines .= '🧾 هزینهٔ ساخت پنل: <b>' . money($setupFee) . "</b>\n";
    }
    if ($prepaid > 0) {
        $hours = prepaid_hours();
        $lines .= '💰 پیش‌پرداخت ' . fa_digits($hours) . ' ساعت اول: <b>'
            . money($prepaid) . '</b>' . "\n"
            . '   (ساعتی ' . money((int) $product['hourly_price']) . ' × '
            . fa_digits($hours) . " ساعت)\n"
            . '<i>این مبلغ بابت ' . fa_digits($hours) . ' ساعت اول پنل است و با حذف پنل '
            . 'پیش از پایان این مدت بازگردانده نمی‌شود.</i>' . "\n";
    }
    return $lines !== '' ? $lines . "\n" : '';
}

function invoice_text(array $order, string $title, string $detail = ''): string
{
    $text = '🧾 <b>' . e($title) . "</b>\n\n"
        . 'کد سفارش: <code>' . e($order['ref']) . "</code>\n"
        . 'مبلغ: <b>' . money((int) $order['payable']) . "</b>\n"
        . order_expiry_line($order);
    if ((int) $order['discount'] > 0) {
        $text .= 'تخفیف: ' . money((int) $order['discount']) . "\n";
    }
    if ($detail !== '') {
        $text .= "\n" . $detail . "\n";
    }
    return $text;
}

/** کد تخفیف فقط برای خرید است، هرگز برای شارژ کیف پول */
function discount_allowed_for(string $orderKind): bool
{
    return in_array($orderKind, ['panel', 'users', 'slot', 'renew'], true);
}

/**
 * دکمه‌های پرداخت یک فاکتور.
 * فقط روش‌های پرداختِ روشن و آماده نمایش داده می‌شوند؛
 * برای شارژ کیف پول، «پرداخت از کیف پول» و «کد تخفیف» نمایش داده نمی‌شود.
 */
function invoice_kb(array $order, int $uid): array
{
    $rows = [];
    $kind = (string) $order['kind'];
    if ((int) $order['payable'] === 0) {
        return [
            [btn('✅ فعال‌سازی سفارش رایگان', 'inv:zero:' . $order['id'], null, 'primary')],
            [btn('« لغو', 'home', null, 'danger')],
        ];
    }
    if ($kind !== 'topup') {
        $balance = balance_of($uid);
        $rows[] = [btn('💰 پرداخت از کیف پول (' . money($balance) . ')',
            'inv:wallet:' . $order['id'], null, 'primary')];
    }
    foreach (gw_available($uid) as $gwId => $meta) {
        $rows[] = [btn($meta['emoji'] . ' ' . $meta['label'],
            'inv:pay:' . $order['id'] . ':' . $gwId, null, 'primary')];
    }
    if (discount_allowed_for($kind) && (int) $order['discount'] === 0) {
        $rows[] = [btn('🏷 کد تخفیف', 'inv:code:' . $order['id'], null, 'primary')];
    }
    $rows[] = [btn('« لغو', 'home', null, 'danger')];
    if (count($rows) === 1) {
        $rows = [];
        if (support_username() !== '') {
            $rows[] = [btn('☎️ پشتیبانی', null,
                'https://t.me/' . support_username(), 'primary')];
        }
        $rows[] = [btn('« لغو', 'home', null, 'danger')];
    }
    return $rows;
}

/**
 * «6104331002032630 سید ابوالفضل موسوی سیاوش» → [شماره، نام]
 * ارقام فارسی/عربی، فاصله، خط تیره و نقطه هم پذیرفته می‌شود.
 */
function parse_card_input(string $text): array
{
    $text = trim(str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','‌'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9',' '],
        $text));
    if (preg_match('/(?<![0-9])((?:[0-9][ -]?){16})(?![0-9])/', $text, $m)) {
        $card = preg_replace('/[^0-9]/', '', $m[1]) ?? '';
        $rest = trim(str_replace($m[0], ' ', $text));
    } elseif (preg_match('/(?<![0-9])([0-9]{10,19})(?![0-9])/', $text, $m)) {
        $card = $m[1];
        $rest = trim(str_replace($m[0], ' ', $text));
    } else {
        return ['', ''];
    }
    $holder = trim(preg_replace('/\s{2,}/u', ' ', $rest) ?? '');
    /* trim بایتی روی متن فارسی، حرف را نصف می‌کند؛ پس با regex یونیکد پاک می‌کنیم */
    $holder = preg_replace('/^[\s\-:|,،]+|[\s\-:|,،]+$/u', '', $holder) ?? '';
    return [$card, mb_substr($holder, 0, 60)];
}

/** صفحهٔ کارت‌به‌کارت: شماره کارت + انتظار برای عکس رسید */
function screen_card_payment(int|string $chat, ?int $messageId, int $uid, array $order): void
{
    $card = set_get('card_number');
    if ($card === '') {
        Tg::show($chat, $messageId,
            'شمارهٔ کارت هنوز تنظیم نشده؛ با پشتیبانی تماس بگیرید.',
            [[btn('« بازگشت', 'home', null, 'danger')]]);
        return;
    }
    $toman = (int) $order['payable'];
    /* ── گارد KYC: سقف واریز و کارت احرازشده، پیش از ورود به پرداخت ── */
    $kycBlock = kyc_guard($uid, $toman, (int) $order['id']);
    if ($kycBlock !== null) {
        Tg::show($chat, $messageId, $kycBlock['text'], $kycBlock['kb']);
        return;
    }
    State::set($uid, 'receipt', ['order_id' => (int) $order['id']]);
    Tg::show($chat, $messageId,
        "💳 <b>کارت به کارت</b>\n\n"
        . 'مبلغ: <b>' . money($toman) . '</b>' . "\n"
        . 'شماره کارت: <code>' . e($card) . "</code>\n"
        . 'صاحب کارت: ' . e(set_get('card_holder')) . "\n"
        . 'کد سفارش: <code>' . e((string) $order['ref']) . "</code>\n\n"
        . order_expiry_line($order)
        . "\n" . 'بعد از واریز، <b>عکس رسید</b> را همین‌جا بفرستید.',
        [[copy_btn('📋 کپی شماره کارت', $card)],
         [copy_btn('📋 کپی مبلغ (' . rial_text($toman) . ')', rial_digits($toman))],
         [btn('✅ واریز کردم، ارسال رسید', 'pay:receipt:' . (int) $order['id'], null, 'success')],
         [btn('« لغو', 'home', null, 'danger')]]);
}

/** ورود تعداد کاربر برای خرید نخست پنل نامحدود. */
function screen_unlimited_initial_users(int|string $chat, ?int $messageId, int $uid,
                                        int $productId): void
{
    $product = product_get($productId);
    if ($product === null || (int) $product['active'] !== 1 || !product_is_unlimited($product)) {
        Tg::show($chat, $messageId, 'این محصول نامحدود و فعال نیست.',
            [[btn('« بازگشت', 'menu:buy', null, 'danger')]]);
        return;
    }
    State::set($uid, 'unlimited_initial_users', ['product' => $productId]);
    Tg::show($chat, $messageId,
        '👥 <b>تعداد کاربر دلخواه</b>' . "\n\n"
        . 'تعداد کاربر موردنیاز را فقط به‌صورت عدد بفرستید.' . "\n"
        . 'حداقل مجاز: <b>' . fa_digits(unlimited_min_users($product)) . '</b> کاربر' . "\n"
        . 'قیمت هر کاربر: <b>' . money(unlimited_user_price($product)) . '</b>' . "\n\n"
        . '<i>مثال: ' . fa_digits(unlimited_min_users($product)) . '</i>' . "\n"
        . 'برای لغو: <code>لغو</code>',
        [[btn('« بازگشت', 'buy:p:' . $productId, null, 'danger')]]);
}

/** شروع سفارش خرید پنل با نام کاربری انتخاب‌شده */
function start_panel_order(int $uid, int|string $chat, ?int $messageId, int $productId,
                           string $username, int $unlimitedUsers = 0): void
{
    $product = product_get($productId);
    if ($product === null || (int) $product['active'] !== 1) {
        Tg::show($chat, $messageId, 'این محصول موجود نیست.',
            [[btn('« بازگشت', 'menu:buy', null, 'danger')]]);
        return;
    }
    if (slots_available($uid) < (int) $product['slots_needed']) {
        Tg::show($chat, $messageId,
            '🧩 اسلات آزاد کافی ندارید.' . "\n" . 'از «🧩 اسلات‌ها» اسلات بخر.',
            [[btn('🧩 اسلات‌ها', 'menu:slots', null, 'success')],
             [btn('« بازگشت', 'menu:buy', null, 'danger')]]);
        return;
    }
    $minBalance = (int) $product['min_balance'];
    if ($minBalance > 0 && balance_of($uid) < $minBalance) {
        Tg::show($chat, $messageId,
            '💰 <b>حداقل موجودی لازم</b>' . "\n\n"
            . 'برای این محصول باید حداقل <b>' . money($minBalance) . '</b> موجودی داشته باشید.'
            . "\n" . 'موجودی فعلی: ' . money(balance_of($uid)) . "\n\n"
            . 'کیف پولتان را شارژ کنید و دوباره بیا.',
            [[btn('💳 شارژ کیف پول', 'menu:wallet', null, 'success')],
             [btn('« بازگشت', 'menu:buy', null, 'danger')]]);
        return;
    }
    if (product_is_unlimited($product)) {
        [$valid, $why] = unlimited_user_count_check($product, $unlimitedUsers);
        if (!$valid) {
            Tg::show($chat, $messageId, '❌ ' . $why,
                [[btn('👥 ورود تعداد کاربر', 'buy:users:' . $productId, null, 'success')],
                 [btn('« بازگشت', 'buy:p:' . $productId, null, 'danger')]]);
            return;
        }
        $price = unlimited_user_order_total($product, $unlimitedUsers);
        $payload = [
            'product' => $productId,
            'panel_username' => $username,
            'sale_type' => PRODUCT_SALE_UNLIMITED_USERS,
            'unlimited_users' => true,
            'user_count' => $unlimitedUsers,
            'per_user_price' => unlimited_user_price($product),
            'min_users' => unlimited_min_users($product),
        ];
        $order = create_order($uid, 'panel', $price, $productId, $payload);
        Tg::show($chat, $messageId,
            invoice_text($order, 'خرید پنل نامحدود',
                '♾️ ' . e((string) $product['name']) . "\n"
                . '👥 تعداد کاربر: <b>' . fa_digits($unlimitedUsers) . "</b>\n"
                . '💵 ' . fa_digits($unlimitedUsers) . ' × '
                    . money(unlimited_user_price($product)) . ' = <b>' . money($price) . "</b>\n"
                . '👤 نام کاربری پنل: <code>' . e($username) . '</code>'),
            invoice_kb($order, $uid));
        return;
    }
    [$price, $setupFee, $prepaid] = panel_order_price($product);
    /* تخفیف اختصاصی کاربر (اگر مدیر برایش فعال کرده باشد) */
    $personal = user_discount_amount($uid, $price);
    $order = create_order($uid, 'panel', $price, $productId,
        ['product' => $productId, 'panel_username' => $username,
         'setup_fee' => $setupFee, 'prepaid' => $prepaid,
         'prepaid_hours' => prepaid_hours()], $personal);
    Tg::show($chat, $messageId,
        invoice_text($order, 'خرید پنل', panel_order_breakdown($product, $setupFee, $prepaid)
            . '📦 ' . e((string) $product['name'])
            . "\n" . '👤 نام کاربری پنل: <code>' . e($username) . '</code>'),
        invoice_kb($order, $uid));
}

/** شروع سفارش پنل تست با نام کاربری انتخاب‌شده */
function start_trial_order(int $uid, int|string $chat, ?int $messageId, int $productId,
                           string $username): void
{
    $product = product_get($productId);
    if (!trial_product_allowed($product)) {
        Tg::show($chat, $messageId, 'این محصول برای سرویس تست در دسترس نیست.',
            [[btn('« تست پنل', 'menu:trial', null, 'danger')]]);
        return;
    }
    $price = admin_trial_unlimited($uid) ? 0 : set_int('trial_price', 0);
    $order = create_order($uid, 'trial', $price, $productId, [
        'trial_gb' => set_int('trial_gb', 1),
        'trial_hours' => set_int('trial_hours', 24),
        'panel_username' => $username,
    ]);
    if ($price > 0) {
        Tg::show($chat, $messageId,
            invoice_text($order, 'پنل تست', $product['emoji'] . ' ' . e((string) $product['name'])
                . "\n" . '👤 نام کاربری پنل: <code>' . e($username) . '</code>'),
            invoice_kb($order, $uid));
        return;
    }
    try {
        [$settled, $note] = settle_payment($order, 'trial');
    } catch (Throwable $ex) {
        log_line('error', 'trial settle failed: ' . $ex->getMessage());
        [$settled, $note] = [false, 'ساخت پنل تست انجام نشد: ' . $ex->getMessage()];
    }
    if (!$settled) {
        Tg::show($chat, $messageId,
            "❌ <b>پنل تست ساخته نشد</b>\n\n" . e($note) . "\n\n"
            . '<i>مبلغی از کیف پولتان کم نشد.</i>',
            [[btn('🔁 تلاش دوباره', 'trial:go:' . $productId, null, 'success')],
             [btn('☎️ پشتیبانی', 'menu:support')],
             [btn('« بازگشت', 'menu:trial', null, 'danger')]]);
        return;
    }
    Tg::show($chat, $messageId, $note,
        [[btn('📦 پنل‌های من', 'menu:panels', null, 'success')],
         [btn('🛒 خرید پنل', 'menu:buy')],
         [btn('« بازگشت', 'home', null, 'danger')]]);
}

/** صفحهٔ انتخاب درگاه برای یک سفارش */
function screen_gateways(int|string $chat, ?int $messageId, array $order, ?string $notice = null): void
{
    $uid = (int) ($order['tg_id'] ?? 0);
    $gateways = gw_available($uid);
    $text = '🌐 <b>انتخاب درگاه پرداخت</b>' . "\n\n"
        . 'مبلغ: <b>' . money((int) $order['payable']) . '</b>' . "\n"
        . 'کد سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n\n"
        . ($notice !== null ? e($notice) . "\n\n" : '')
        . 'یکی را انتخاب کنید:';
    /* ترتیب دکمه‌ها: کارت، ارز دیجیتال، کریپتو، استارز، بقیه */
    $mirzaOrder = ['card' => 0, 'plisio' => 1, 'nowpayment' => 2, 'stars' => 3];
    uksort($gateways, static function ($a, $b) use ($mirzaOrder) {
        $pa = $mirzaOrder[$a] ?? 50;
        $pb = $mirzaOrder[$b] ?? 50;
        return $pa === $pb ? 0 : ($pa < $pb ? -1 : 1);
    });
    $rows = [];
    $line = [];
    foreach ($gateways as $id => $meta) {
        $label = $meta['emoji'] . ' ' . $meta['label'];
        $line[] = btn($label, 'inv:pay:' . $order['id'] . ':' . $id);
        if (count($line) === 2) {
            $rows[] = $line;
            $line = [];
        }
    }
    if ($line) {
        $rows[] = $line;
    }
    /* «بستن لیست» آخرین دکمه است */
    $rows[] = [btn('✖️ بستن لیست', 'inv:back:' . $order['id'], null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/**
 * کارت «کیف پول»: اطلاعات حساب، موجودی، تعداد پنل/فاکتور/زیرمجموعه و
 * گروه کاربری. کسرهای کوچک مصرف ساعتی این‌جا لیست نمی‌شوند.
 */
function wallet_account_text(int $uid): string
{
    $user = user_get($uid) ?? [];
    $balance = balance_of($uid);
    $panels = (int) DB::val("SELECT COUNT(*) FROM panels WHERE tg_id=? AND status<>'deleted'",
        [$uid], 0);
    $invoices = (int) DB::val("SELECT COUNT(*) FROM payments WHERE order_id IN "
        . "(SELECT id FROM orders WHERE tg_id=?) AND status='paid'", [$uid], 0);
    $invited = (int) DB::val('SELECT COUNT(*) FROM users WHERE referred_by=?', [$uid], 0);
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        $name = 'بدون نام';
    }
    $group = is_owner($uid) ? 'مالک' : (is_staff($uid) ? 'ادمین' : 'عادی');
    $registered = (int) ($user['created_at'] ?? 0);
    $code = (string) $uid;   // همان کدی که داخل لینک دعوت می‌رود
    /* معرف فقط وقتی واقعاً وجود دارد نمایش داده می‌شود؛ هیچ‌وقت ۰ یا None */
    $refBy = (int) ($user['referred_by'] ?? 0);
    $refLabel = '';
    if ($refBy > 0) {
        $refUser = user_get($refBy);
        $refName = $refUser !== null ? trim((string) ($refUser['name'] ?? '')) : '';
        $refLabel = ($refName !== '' ? $refName . ' — ' : '') . $refBy;
    }

    return "💰 <b>کیف پول</b>\n\n"
        . "🗂 <b>اطلاعات حساب کاربری شما :</b>\n\n"
        . '🪪 شناسه کاربری:' . "\n<code>" . $uid . "</code>\n\n"
        . '👤 نام:' . "\n<code>" . e($name) . "</code>\n\n"
        . '👥 کد معرف شما :' . "\n<code>" . e($code) . "</code>\n\n"
        . ($refBy > 0
            ? '🎁 معرف شما : ' . e($refLabel) . "\n\n"
            : '')
        . '⌚️ زمان ثبت نام : ' . ($registered > 0 ? fa_ts($registered) : '—') . "\n"
        . '💰 موجودی: <b>' . money($balance) . "</b>\n"
        . '📦 تعداد پنل‌های خریداری شده : ' . $panels . ' عدد' . "\n"
        . '📑 تعداد فاکتورهای پرداخت شده : ' . $invoices . ' عدد' . "\n"
        . '🤝 تعداد زیرمجموعه‌های شما : ' . $invited . ' نفر' . "\n"
        . '🔖 گروه کاربری : ' . $group;
}

/**
 * پشتیبانی: هیچ پیامی داخل ربات گرفته نمی‌شود؛ دکمه مستقیم چت خصوصی مالک را
 * باز می‌کند. آدرس فقط از تنظیمات ربات یا PANEL_SUPPORT_USERNAME خوانده می‌شود.
 */
/**
 * آیدی پشتیبانی — هیچ‌وقت داخل سورس نیست.
 * مقدار تنظیمات می‌تواند `@username`، `username` یا لینک کامل
 * `https://t.me/username` باشد؛ هر سه یکسان خوانده می‌شوند.
 */
function support_username(): string
{
    $link = trim(set_get('support_link', ''));
    if ($link !== '') {
        if (preg_match('~t\.me/([A-Za-z0-9_]{3,})~i', $link, $m)) {
            return $m[1];
        }
        if (preg_match('~^@?([A-Za-z0-9_]{3,})$~', $link, $m)) {
            return $m[1];
        }
    }
    return trim(ltrim(cfg('PANEL_SUPPORT_USERNAME', ''), '@'));
}

/** لینک کامل پشتیبانی برای دکمهٔ شیشه‌ای (خالی = تنظیم نشده) */
function support_url(): string
{
    $handle = support_username();
    return $handle !== '' ? 'https://t.me/' . $handle : '';
}

/**
 * کانال رسمی پروژه: از تنظیمات خوانده می‌شود و اگر خالی بود پیش‌فرض است.
 * لینک فقط برای دکمهٔ شیشه‌ای استفاده می‌شود.
 */
function channel_url(): string
{
    $link = trim(set_get('channel_link', ''));
    if ($link === '') {
        $link = trim(cfg('PANEL_CHANNEL_URL'));
    }
    if ($link === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $link)) {
        $link = 'https://t.me/' . ltrim($link, '@');
    }
    return $link;
}

function show_support(int|string $chat, ?int $messageId, int $uid): void
{
    $handle = support_username();
    if ($handle === '') {
        /* تا وقتی مدیر هندل پشتیبانی را تنظیم نکرده، هیچ لینکی جعل نمی‌شود */
        Tg::show($chat, $messageId,
            "☎️ <b>پشتیبانی</b>\n\n"
            . 'هنوز آدرس پشتیبانی تنظیم نشده است. لطفاً بعداً تلاش کنید.',
            [[btn('« بازگشت', 'nav:back', null, 'danger')]]);
        return;
    }
    $user = '@' . $handle;
    $channel = channel_url();
    /* دکمه با لینک کامل ساخته می‌شود؛ @ و لینک t.me هر دو پشتیبانی می‌شوند */
    $rows = [[btn('💬 پیام به ' . $user, null, 'https://t.me/' . $handle, 'success')]];
    if ($channel !== '') {
        $rows[] = [btn('📢 کانال رسمی', null, $channel, 'primary')];
    }
    $rows[] = [btn('« بازگشت', 'nav:back', null, 'danger')];
    Tg::show($chat, $messageId,
        "☎️ <b>پشتیبانی</b>\n\n"
        . 'برای ارتباط مستقیم با پشتیبانی روی دکمهٔ زیر بزنید؛ چت خصوصی باز می‌شود.' . "\n"
        . 'کاربر: <b>' . e($user) . "</b>"
        . ($channel !== '' ? "\n\n📢 خبر و بروزرسانی: «کانال رسمی»" : ''),
        $rows);
}

/**
 * فاکتور ارز دیجیتال: هشدار زمان، صرافی‌ها، شماره/مبلغ فاکتور، نرخ لحظه‌ای
 *   هشدار زمان پرداخت، فهرست صرافی‌های داخلی، شماره فاکتور، مبلغ فاکتور،
 *   قیمت لحظه‌ای دلار، یادداشت «حداکثر ۱۵ دقیقه» و دکمهٔ پرداخت در انتهای پیام.
 */
function crypto_links_list(): array
{
    $raw = set_get('crypto_links', '');
    $items = array_filter(array_map('trim', explode('|', $raw !== '' ? $raw : 'swapwallet.app')));
    return array_values($items);
}

function crypto_invoice_text(array $order, string $label, string $invoiceNo, float $paidUsd = 0.0): string
{
    $minutes = max(1, set_int('pay_expire_minutes', 30));
    $links = '';
    $mark = ['🔹', '🔸'];
    foreach (crypto_links_list() as $i => $host) {
        $links .= $mark[$i % 2] . ' ' . e($host) . "\n";
    }
    $lines = '💲 <b>جهت افزایش اعتبار کیف پول خود از طریق ارز دیجیتال روی دکمه پرداخت '
        . 'در انتهای پیام کلیک کنید</b>' . "\n\n"
        . '⚠️ توجه: زمان پرداخت ' . fa_digits($minutes) . ' دقیقه می باشد پس از '
        . fa_digits($minutes) . ' دقیقه تراکنش لغو خواهد شد' . "\n\n"
        . '🌐 برخی از سایت های داخلی جهت خرید ارز دیجیتال' . "\n\n"
        . $links . "\n"
        . '🧾 شماره فاکتور : <code>' . e($invoiceNo) . '</code>' . "\n"
        . '💰 مبلغ فاکتور : <b>' . money((int) $order['payable']) . '</b>' . "\n"
        . '📊 قیمت دلار: در این لحظه ' . money(usd_toman_rate()) . "\n\n"
        . '> ⚠️ پس از پرداخت، در صورتی که مبلغ تراکنش به‌درستی واریز شده باشد، '
        . 'کیف پول شما حداکثر تا ۱۵ دقیقه آینده به‌صورت خودکار شارژ خواهد شد.' . "\n\n"
        . 'جهت پرداخت از دکمه زیر استفاده کنید 👇🏻';
    if ($paidUsd > 0) {
        $lines .= "\n\n" . 'مبلغ دقیق این فاکتور: <b>'
            . fa_digits(number_format($paidUsd, 6)) . ' دلار</b>';
    }
    return $lines;
}

/** سرصفحهٔ بالای فاکتور کریپتو */
function crypto_invoice_header(array $order, string $label): string
{
    return '🌐 <b>پرداخت ' . e($label) . "</b>\n\n"
        . 'مبلغ: <b>' . money((int) $order['payable']) . "</b>\n"
        . 'کد سفارش: <code>' . e((string) $order['ref']) . "</code>\n\n"
        . 'روی دکمهٔ زیر بزنید و پرداخت را کامل کنید. '
        . 'بعد از پرداخت خودکار به همین‌جا برمی‌گردی.';
}

function screen_wallet(int|string $chat, ?int $messageId, int $uid): void
{
    /* فقط یک خط: آخرین تراکنش موفق و تأییدشده (مصرف ریز و موارد لغوشده نه) */
    $tx = DB::one("SELECT * FROM wallet_tx WHERE tg_id=? AND kind<>'usage' "
        . "AND amount<>0 ORDER BY id DESC LIMIT 1", [$uid]);
    $body = '';
    if ($tx !== null) {
        $amount = (int) $tx['amount'];
        $sign = $amount >= 0 ? '+' : '-';
        $body = "\n<b>آخرین تراکنش:</b>\n" . '· ' . fa_ts((int) $tx['created_at'])
            . ' | ' . $sign . money(abs($amount)) . ' | '
            . e((string) ($tx['note'] ?: tx_kind_label((string) $tx['kind']))) . "\n";
    }
    $min = set_int('min_topup', 50000);
    $text = wallet_account_text($uid) . $body . "\n"
        . '📆 ' . fa_date_only(now_ts()) . ' → ⏰ ' . fa_time_only(now_ts());
    $p = [$min, $min * 2, $min * 5, $min * 10];
    Tg::show($chat, $messageId, $text, [
        [btn('+ ' . money($p[0]), 'wallet:amt:' . $p[0], null, 'success'),
         btn('+ ' . money($p[1]), 'wallet:amt:' . $p[1], null, 'success')],
        [btn('+ ' . money($p[2]), 'wallet:amt:' . $p[2], null, 'success'),
         btn('+ ' . money($p[3]), 'wallet:amt:' . $p[3], null, 'success')],
        [btn('✍️ مبلغ دلخواه', 'wallet:charge', null, 'success')],
        [btn('🪪 احراز هویت و کارت‌ها', 'kyc:home', null, 'primary')],
        [btn('🎁 دعوت دوستان', 'menu:ref', null, 'primary')],
        [btn('« بازگشت', 'home', null, 'danger')],
    ]);
}

function order_kind_label(string $kind): string
{
    return ['panel' => 'خرید پنل', 'topup' => 'شارژ کیف پول', 'slot' => 'خرید اسلات',
            'trial' => 'پنل تست', 'users' => 'افزایش سقف کاربر'][$kind] ?? $kind;
}

function order_status_label(string $status): string
{
    return ['pending' => 'در انتظار پرداخت', 'review' => 'در حال بررسی', 'delivered' => 'تحویل شد',
            'refunded' => 'برگشت وجه', 'canceled' => 'لغو شد', 'failed' => 'ناموفق',
            'rejected' => 'رد شد'][$status] ?? $status;
}

function panel_status_label(string $status): string
{
    return ['active' => '🟢 فعال', 'suspended' => '🟡 معلق', 'expired' => '🔴 منقضی',
            'deleted' => '⚫️ حذف‌شده'][$status] ?? $status;
}

function tx_kind_label(string $kind): string
{
    return [
        'topup'  => 'شارژ کیف پول',
        'order'  => 'خرید',
        'usage'  => 'مصرف',
        'refund' => 'بازگشت وجه',
        'admin'  => 'تغییر دستی',
        'ref'    => 'پورسانت',
        'slot'   => 'اسلات',
    ][$kind] ?? $kind;
}

function screen_slots(int|string $chat, ?int $messageId, int $uid): void
{
    [$free, $bought, $used] = slots_total($uid);
    $price = set_int('slot_price', 150000);
    $cap = set_int('max_slots', 20);
    $days = set_int('slot_days', 30);
    $text = "🧩 <b>اسلات‌های من</b>\n\n"
        . '🎁 رایگان: <b>' . fa_digits($free) . "</b>\n"
        . '🛍 پنل‌های خریداری‌شده: <b>' . fa_digits($bought) . "</b>\n"
        . '📦 مصرف‌شده: <b>' . fa_digits($used) . "</b>\n"
        . '✅ باقی‌مانده: <b>' . fa_digits(max(0, $free + $bought - $used)) . '</b> از '
        . fa_digits($free + $bought) . "\n\n"
        . 'سقف کل: ' . fa_digits($cap) . " اسلات\n"
        . 'قیمت هر اسلات: <b>' . money($price) . "</b>\n"
        . 'اعتبار اسلات: ' . ($days ? fa_digits($days) . ' روز' : 'همیشگی') . "\n\n"
        . '🧩 هر اسلات یعنی یک پنل می‌توانید بسازید.';
    $rows = [];
    if ($free + $bought < $cap) {
        $rows[] = [btn('🛍 خرید اسلات', 'slot:buy', null, 'success')];
    }
    $rows[] = [btn('🛒 خرید پنل', 'menu:buy'), btn('« بازگشت', 'home', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/**
 * مالک و ادمین‌ها سهمیهٔ نامحدود تست دارند (هر چند تا بخواهند)،
 * ولی حجم و مدتِ هر تست همان چیزی است که در تنظیمات تعیین شده.
 */
function admin_trial_unlimited(int $uid): bool
{
    return is_staff($uid) && set_bool('admin_trial_unlimited', true);
}

/** نام کاربری رندومِ واقعی برای پنل (بدون پیشوند ثابت) */
function random_panel_username(int $length = 8): string
{
    /* پاسارگارد نامی را که با رقم شروع شود قبول نمی‌کند (HTTP 422)؛
       پس حرف اول همیشه حرف است و بقیه حرف/رقم */
    $letters = 'abcdefghijkmnpqrstuvwxyz';
    $alphabet = $letters . '23456789';
    $length = max(6, min(16, $length));
    for ($try = 0; $try < 12; $try++) {
        $name = $letters[random_int(0, strlen($letters) - 1)];
        for ($i = 1; $i < $length; $i++) {
            $name .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $taken = DB::one("SELECT id FROM panels WHERE panel_username=? AND status<>'deleted'",
            [$name]);
        if ($taken === null) {
            return $name;
        }
    }
    return 'u' . bin2hex(random_bytes(4));
}

/** نام کاربری معتبر است؟ خروجی: [ok, دلیل] */
function panel_username_check(string $name): array
{
    $name = trim($name);
    if (mb_strlen($name) < 3 || mb_strlen($name) > 32) {
        return [false, 'نام کاربری باید بین ۳ تا ۳۲ حرف باشد.'];
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        return [false, 'فقط حروف انگلیسی، عدد و _ مجاز است.'];
    }
    $taken = DB::one("SELECT id FROM panels WHERE panel_username=? AND status<>'deleted'", [$name]);
    if ($taken !== null) {
        return [false, 'این نام کاربری تکراری بود؛ یک نام دیگر بفرستید.'];
    }
    return [true, ''];
}

function trial_used(int $uid): int
{
    // فقط تست‌هایی که واقعاً تحویل شده‌اند سهمیه را مصرف می‌کنند
    return (int) DB::val("SELECT COUNT(*) FROM orders WHERE tg_id=? AND kind='trial' "
        . "AND status='delivered'", [$uid], 0);
}

/** فهرست سرویس تست: فقط محصولات متری؛ پنل نامحدود هرگز گزینهٔ تست نیست. */
function trial_products_list(): array
{
    $trialOnly = DB::all('SELECT * FROM products WHERE active=1 AND trial=1 ORDER BY id LIMIT 12');
    $trialOnly = array_values(array_filter($trialOnly, 'trial_product_allowed'));
    if ($trialOnly) {
        return $trialOnly;
    }
    return array_values(array_filter(products_list(true), 'trial_product_allowed'));
}

function screen_trial(int|string $chat, ?int $messageId, int $uid): void
{
    if (!set_bool('trial_on', true)) {
        Tg::show($chat, $messageId, '🧪 تست پنل فعلاً بسته است.',
            [[btn('« بازگشت', 'home', null, 'danger')]]);
        return;
    }
    $isManager = admin_trial_unlimited($uid);
    $limit = max(1, set_int('trial_per_user', 1));
    $used = trial_used($uid);
    $gb = (float) set_int('trial_gb', 1);
    $hours = set_int('trial_hours', 24);
    $price = $isManager ? 0 : set_int('trial_price', 0);
    $text = "🧪 <b>تست پنل</b>\n\n"
        . '📦 حجم: <b>' . fa_digits($gb) . " گیگ</b>\n"
        . '⏳ مدت: <b>' . fa_digits($hours) . " ساعت</b>\n"
        . '👥 تعداد کاربر: <b>۱</b>' . "\n"
        . '💵 هزینه: ' . ($price > 0 ? money($price) : '<b>رایگان</b>') . "\n"
        . '🎟 سهمیهٔ شما: ' . ($isManager
            ? '<b>نامحدود</b>'
            : fa_digits($used) . ' از ' . fa_digits($limit)) . "\n\n";
    if (!$isManager && $used >= $limit) {
        $text .= 'سهمیهٔ تستت تمام شده. برای پنل کامل «🛒 خرید پنل» را بزنید.';
        Tg::show($chat, $messageId, $text,
            [[btn('🛒 خرید پنل', 'menu:buy', null, 'success')],
             [btn('« بازگشت', 'home', null, 'danger')]]);
        return;
    }
    if (DB::one('SELECT id FROM pg_panels WHERE active=1 LIMIT 1') === null) {
        Tg::show($chat, $messageId,
            "🧪 <b>تست پنل</b>\n\n"
            . "اکنون هیچ پنل پاسارگاردی به ربات وصل نیست، پس پنل تست هم ساخته نمی‌شود.\n\n"
            . '<i>مدیر ربات باید اول از «🔌 پنل‌ها» پنل را با کلید API وصل کند.</i>',
            [[btn('🛒 خرید پنل', 'menu:buy', null, 'success')],
             [btn('☎️ پشتیبانی', 'menu:support')],
             [btn('« بازگشت', 'home', null, 'danger')]]);
        return;
    }
    $text .= 'یک محصول حجمی را انتخاب کنید تا پنل تست روی همان ساخته شود:';
    $rows = [];
    foreach (trial_products_list() as $product) {
        $rows[] = [btn((string) $product['emoji'] . ' ' . (string) $product['name'],
            'trial:p:' . $product['id'], null, 'success')];
    }
    if (!$rows) {
        $text .= "\n\n<i>اکنون محصول فعالی برای تست نیست.</i>";
    }
    $rows[] = [btn('« بازگشت', 'home', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** کارت کوچک پنل تست با دکمهٔ «دریافت تست» */
function screen_trial_product(int|string $chat, ?int $messageId, int $uid, int $productId): void
{
    $product = product_get($productId);
    if (!trial_product_allowed($product)) {
        Tg::show($chat, $messageId, 'این محصول برای سرویس تست در دسترس نیست.',
            [[btn('« تست پنل', 'menu:trial', null, 'danger')]]);
        return;
    }
    $gb = (float) set_int('trial_gb', 1);
    $hours = set_int('trial_hours', 24);
    $price = admin_trial_unlimited($uid) ? 0 : set_int('trial_price', 0);
    $text = '🧪 <b>پنل تست</b>' . "\n\n"
        . $product['emoji'] . ' <b>' . e((string) $product['name']) . "</b>\n"
        . '📦 حجم تست: <b>' . fa_digits($gb) . " گیگ</b>\n"
        . '⏳ مدت تست: <b>' . fa_digits($hours) . " ساعت</b>\n"
        . '👥 تعداد کاربر: <b>۱</b>' . "\n"
        . '💵 هزینه: ' . ($price > 0 ? money($price) : '<b>رایگان</b>') . "\n\n"
        . '<i>بعد از پایان ' . fa_digits($hours) . ' ساعت، پنل تست خودکار پاک می‌شود.</i>';
    Tg::show($chat, $messageId, $text, [
        [btn('🎁 دریافت تست', 'trial:go:' . $productId, null, 'success')],
        [btn('« بازگشت', 'menu:trial', null, 'danger')],
    ]);
}

/** انتخاب نوع نام کاربری — برای خرید، خرید نامحدود و تست */
function screen_username_choice(int|string $chat, ?int $messageId, int $productId,
                                string $mode, int $unlimitedUsers = 0): void
{
    $product = product_get($productId);
    if ($mode === 'trial' && !trial_product_allowed($product)) {
        Tg::show($chat, $messageId, 'این محصول برای سرویس تست در دسترس نیست.',
            [[btn('« تست پنل', 'menu:trial', null, 'danger')]]);
        return;
    }
    $title = $mode === 'trial' ? '🧪 پنل تست' : '🛒 خرید پنل';
    $countLine = $mode === 'buyu'
        ? '👥 تعداد کاربر: <b>' . fa_digits($unlimitedUsers) . "</b>\n\n" : '';
    $suffix = $mode === 'buyu' ? ':' . $unlimitedUsers : '';
    $back = $mode === 'trial' ? 'menu:trial'
        : ($mode === 'buyu' ? 'buy:users:' . $productId : 'buy:p:' . $productId);
    $text = '<b>' . $title . '</b>' . "\n\n"
        . ($product !== null ? $product['emoji'] . ' ' . e((string) $product['name']) . "\n" : '')
        . $countLine
        . 'نام کاربری پنل را چطور انتخاب می‌کنید؟' . "\n"
        . '🎲 <b>رندوم</b>: ربات یک نام تصادفی می‌سازد.' . "\n"
        . '✍️ <b>دلخواه</b>: نام کاربری را خودتان می‌نویسید.';
    Tg::show($chat, $messageId, $text, [
        [btn('🎲 نام کاربر رندوم', 'uname:' . $mode . ':' . $productId . ':rand' . $suffix,
            null, 'success')],
        [btn('✍️ نام کاربر دلخواه', 'uname:' . $mode . ':' . $productId . ':custom' . $suffix)],
        [btn('« بازگشت', $back, null, 'danger')],
    ]);
}

/** سفارش‌های من — یک‌جا، مرتب */
/** یک سفارش با کد پیگیری (یا شمارهٔ عددی) */
function order_by_code(string $code, ?int $uid = null): ?array
{
    $code = trim($code);
    $code = ltrim($code, '#');
    if ($code === '') {
        return null;
    }
    $scope = $uid !== null ? ' AND tg_id=' . (int) $uid : '';
    $row = DB::one('SELECT * FROM orders WHERE UPPER(ref)=? ' . $scope
        . ' ORDER BY id DESC LIMIT 1', [mb_strtoupper($code)]);
    if ($row !== null) {
        return $row;
    }
    if (ctype_digit($code)) {
        $row = DB::one('SELECT * FROM orders WHERE id=?' . $scope, [(int) $code]);
        if ($row !== null) {
            return $row;
        }
    }
    return null;
}

/** کارت یک سفارش برای خودِ کاربر */
function order_track_card(int|string $chat, ?int $messageId, array $order): void
{
    $icons = ['paid' => '✅', 'pending' => '⏳', 'review' => '🔎', 'failed' => '⚠️',
              'rejected' => '❌', 'canceled' => '🚫', 'delivered' => '✅'];
    $text = '🧾 <b>وضعیت سفارش</b>' . "\n" . str_repeat('─', 14) . "\n"
        . 'کد پیگیری: <code>' . e((string) $order['ref']) . '</code>' . "\n"
        . 'بابت: ' . receipt_kind_label($order) . "\n"
        . 'مبلغ: <b>' . money((int) $order['payable']) . '</b>' . "\n"
        . 'وضعیت: ' . ($icons[(string) $order['status']] ?? '•') . ' '
        . order_status_label((string) $order['status']) . "\n"
        . 'ثبت: ' . fa_ts((int) $order['created_at']) . "\n";
    if ((string) $order['status'] === 'pending') {
        $text .= "\n" . order_expiry_line($order);
    }
    if ((string) $order['status'] === 'review') {
        $text .= "\n" . '📷 رسیدت رسیده و در انتظار تأیید مدیر است.' . "\n";
    }
    Tg::show($chat, $messageId, $text,
        [[btn('🧾 سفارش‌های من', 'menu:orders', null, 'success')],
         [btn('« خانه', 'home', null, 'danger')]]);
}

/** کارت یک رسید برای ادمین (پیدا شده با کد) */
function receipt_find_card(int|string $chat, ?int $messageId, string $code, int $adminId): void
{
    $receipt = null;
    $order = order_by_code($code);
    if ($order !== null) {
        $receipt = DB::one('SELECT * FROM receipts WHERE order_id=? ORDER BY id DESC LIMIT 1',
            [(int) $order['id']]);
    }
    if ($receipt === null && ctype_digit(trim($code, '#'))) {
        $receipt = DB::one('SELECT * FROM receipts WHERE id=?', [(int) trim($code, '#')]);
        $order = $receipt !== null ? order_get((int) $receipt['order_id']) : null;
    }
    if ($receipt === null || $order === null) {
        Tg::show($chat, $messageId,
            '🔎 رسیدی با کد <code>' . e($code) . "</code> پیدا نشد.\n"
            . '<i>همان کد پیگیری سفارش را بفرستید.</i>',
            [[btn('🔎 جستجوی دیگر', 'ad:rsearch', null, 'success')],
             [btn('« رسیدها', 'ad:receipts', null, 'danger')]]);
        return;
    }
    $state = match ((string) $receipt['status']) {
        'approved' => 'approved', 'rejected' => 'rejected', default => 'pending',
    };
    $kb = $state === 'pending' ? receipt_card_kb((int) $receipt['id']) : [];
    $kb[] = [btn('🔎 جستجوی دیگر', 'ad:rsearch', null, 'success')];
    $kb[] = [btn('« رسیدها', 'ad:receipts', null, 'danger')];
    Tg::show($chat, $messageId,
        receipt_card_text($receipt, $order, $state, '👤 دیدن کارت: <code>' . $adminId . '</code>'),
        $kb);
}

/** کارت یک پنل، پیدا شده با نام کاربری — برای بخش مدیریت */
function panel_find_card(int|string $chat, ?int $messageId, string $username): void
{
    $canFull = admin_can(console_actor_id(), 'full');
    $username = ltrim(trim($username), '@');
    $panel = DB::one("SELECT * FROM panels WHERE panel_username=? AND status<>'deleted' "
        . 'ORDER BY id DESC LIMIT 1', [$username]);
    if ($panel === null) {
        Tg::show($chat, $messageId,
            '🔎 پنلی با نام کاربری <code>' . e($username) . "</code> پیدا نشد.",
            [[btn('🔎 جستجوی دیگر', 'ad:psearch', null, 'success')],
             [btn('« پنل‌ها', 'ad:panels', null, 'danger')]]);
        return;
    }
    $owner = user_get((int) $panel['tg_id']);
    $text = '🏢 <b>پنل ' . e((string) $panel['panel_username']) . "</b>\n\n"
        . 'کاربر: <b>' . e((string) ($owner['name'] ?? '—')) . '</b> <code>'
        . (int) $panel['tg_id'] . "</code>\n"
        . 'وضعیت: ' . e(panel_status_label((string) $panel['status'])) . "\n"
        . 'نوع: ' . ((int) $panel['is_trial'] === 1 ? '🧪 تست' : '🛒 خریداری‌شده') . "\n"
        . 'سقف کاربر: ' . ((int) $panel['max_users'] > 0
            ? (string) (int) $panel['max_users'] : 'نامحدود') . "\n"
        . 'ساخته‌شده: ' . fa_ts((int) $panel['created_at']);
    if ($canFull) {
        $text .= "\n" . 'مصرف کل: ' . money((int) $panel['spent_total']);
    } else {
        $text .= "\n" . '<i>مبالغ مصرفی در سطح عملیات نمایش داده نمی‌شود.</i>';
    }
    Tg::show($chat, $messageId, $text,
        [[btn('👤 کارت کاربر', 'ad:u:' . (int) $panel['tg_id'], null, 'success')],
         [btn('🔎 جستجوی دیگر', 'ad:psearch', null, 'primary')],
         [btn('« پنل‌ها', 'ad:panels', null, 'danger')]]);
}

function screen_my_orders(int|string $chat, ?int $messageId, int $uid): void
{
    $orders = DB::all('SELECT * FROM orders WHERE tg_id=? ORDER BY id DESC LIMIT 10', [$uid]);
    if (!$orders) {
        Tg::show($chat, $messageId, "🧾 هنوز سفارشی ندارید.

از «🛒 خرید پنل» شروع کنید.",
            [[btn('🛒 خرید پنل', 'menu:buy', null, 'primary')],
             [btn('« خانه', 'home', null, 'danger')]]);
        return;
    }
    $icons = ['paid' => '✅', 'pending' => '⏳', 'review' => '🔎', 'failed' => '⚠️',
              'rejected' => '❌', 'canceled' => '🚫'];
    $text = "🧾 <b>سفارش‌های من</b>
" . str_repeat('─', 14) . "
";
    foreach ($orders as $order) {
        $text .= ($icons[(string) $order['status']] ?? '•') . ' <code>'
            . e((string) $order['ref']) . '</code> · ' . receipt_kind_label($order) . "
"
            . '     ' . money((int) $order['payable']) . ' · '
            . order_status_label((string) $order['status']) . ' · '
            . fa_ts((int) $order['created_at']) . "
";
    }
    Tg::show($chat, $messageId, $text,
        [[btn('🛒 خرید پنل', 'menu:buy', null, 'primary')],
         [btn('« خانه', 'home', null, 'danger')]]);
}

/**
 * خواندن تازهٔ مصرف یک پنل هنگام باز کردن «پنل‌های من».
 * ⚠️ این تابع هیچ پولی کم نمی‌کند — فقط عدد را از پنل می‌خواند و همان‌جا
 * می‌نویسد تا نمایش درست باشد. کسر پول فقط در حلقهٔ خودکار panels_meter()
 * انجام می‌شود، پس باز و بسته کردن صفحه هیچ‌وقت دوباره پول کم نمی‌کند.
 */
function panel_sync_usage(int $panelId): int
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return 0;
    }
    $panelRow = $panel['pg_panel_id']
        ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]) : null;
    if ($panelRow === null) {
        return 0;
    }
    $usage = panel_usage_bytes($panelRow, (int) $panel['panel_admin_id'],
        (string) $panel['panel_username']);
    if ($usage === null) {
        return 0;
    }
    /* فقط عدد نمایشی تازه می‌شود؛ billed_bytes دست نمی‌خورد */
    DB::exec('UPDATE panels SET last_usage=?, last_synced_at=? WHERE id=?',
        [(int) $usage['used'], now_ts(), $panelId]);
    return (int) $usage['used'];
}

function screen_panels(int|string $chat, ?int $messageId, int $uid): void
{
    /* پنل حذف‌شده هیچ‌وقت در فهرست نمی‌آید؛ پنل تست هم مثل بقیه این‌جاست */
    $panels = DB::all('SELECT * FROM panels WHERE tg_id=? AND status IN '
        . "('active','suspended','expired') ORDER BY is_trial, id DESC LIMIT 12", [$uid]);
    /* حجم همین حالا حساب می‌شود (نه هر بار که متن رفرش شود) و روی ردیف نوشته می‌شود */
    foreach ($panels as $idx => $row) {
        if ((string) $row['status'] === 'active' && (int) ($row['is_trial'] ?? 0) !== 1
            && !panel_is_unlimited($row)) {
            panel_sync_usage((int) $row['id']);
            $panels[$idx] = DB::one('SELECT * FROM panels WHERE id=?', [(int) $row['id']]) ?? $row;
        }
    }
    if (!$panels) {
        Tg::show($chat, $messageId,
            "📦 هنوز پنلی ندارید.\n\nاز «🛒 خرید پنل» یکی بساز.",
            [[btn('🛒 خرید پنل', 'menu:buy', null, 'success')],
             [btn('➕ خرید اسلات', 'slot:buy')],
             [btn('« بازگشت', 'home', null, 'danger')]]);
        return;
    }
    $icons = ['active' => '🟢', 'suspended' => '🟡', 'expired' => '🔴', 'deleted' => '⚫️'];
    $rows = [];
    [$freeSlots, $boughtSlots, $usedSlots] = slots_total($uid);
    $slotPrice = set_int('slot_price', 150000);
    $slotCap = set_int('max_slots', 20);
    $text = "📦 <b>پنل‌های من</b>\n\n"
        . '🧩 اسلات: <b>' . fa_digits($freeSlots + $boughtSlots) . '</b>'
        . ' (رایگان ' . fa_digits($freeSlots) . ' + خریداری ' . fa_digits($boughtSlots)
        . ' · مصرف‌شده ' . fa_digits($usedSlots) . ' · آزاد '
        . fa_digits(slots_available($uid)) . ")\n\n";
    foreach ($panels as $panel) {
        $isTrial = (int) ($panel['is_trial'] ?? 0) === 1;
        $isUnlimited = panel_is_unlimited($panel);
        $text .= (($icons[$panel['status']] ?? '⚪️')) . ($isTrial ? '🧪 ' : ($isUnlimited ? '♾️ ' : ''))
            . '<code>' . e($panel['panel_username']) . '</code> — ';
        if ($isUnlimited) {
            $text .= '♾️ طبق نقش پاسارگارد · 👥 سقف ' . fa_digits((int) ($panel['user_cap'] ?? 0));
        } else {
            $text .= 'تا ' . fa_ts((int) ($panel['expires_at'] ?? 0));
            if ($isTrial) {
                $left = max(0, (int) ($panel['expires_at'] ?? 0) - now_ts());
                $text .= ' · ' . fa_digits((int) ceil($left / 3600)) . ' ساعت مانده';
            }
            $text .= ' · 📊 ' . gb_step_text((int) $panel['last_usage']) . ' گیگ';
        }
        $text .= "\n";
        $rows[] = [btn(($icons[$panel['status']] ?? '⚪️') . ($isTrial ? '🧪 ' : ($isUnlimited ? '♾️ ' : '')) . ' '
            . $panel['panel_username'], 'pn:' . $panel['id'])];
    }
    if ($freeSlots + $boughtSlots < $slotCap) {
        $rows[] = [btn('➕ خرید اسلات · ' . money($slotPrice), 'slot:buy', null, 'success')];
    }
    $rows[] = [btn('« بازگشت', 'home', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/* ── پنل فروخته‌شده: اطلاعات زنده از خود PasarGuard ──────────────────────── */

/** ردیف پنل پاسارگارد مربوط به یک پنل فروخته‌شده */
function panel_pg_row(array $panel): ?array
{
    if (empty($panel['pg_panel_id'])) {
        return null;
    }
    return DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]);
}

/**
 * خواندن وضعیت زندهٔ یک پنل فروخته‌شده از API.
 * خروجی: ok, used, limit, users, devices, hwid_limit, status, violation, error
 */
function panel_live_info(array $panel, bool $withDevices = true): array
{
    /* پنل نامحدود هرگز users_of/HWID scan ربات را اجرا نمی‌کند؛ نقش پاسارگارد مرجع است. */
    if (panel_is_unlimited($panel)) {
        $withDevices = false;
    }
    $out = ['ok' => false, 'used' => (int) ($panel['last_usage'] ?? 0), 'limit' => 0,
            'users' => (int) ($panel['last_users'] ?? 0), 'devices' => 0, 'hwid_limit' => 0,
            'status' => '', 'violation' => false, 'error' => ''];
    $pgRow = panel_pg_row($panel);
    if ($pgRow === null) {
        $out['error'] = 'پنل پاسارگارد این پنل حذف شده است.';
        return $out;
    }
    try {
        $client = new PgPanel($pgRow);
        $info = $client->admin_info((string) $panel['panel_username']);
    } catch (Throwable $ex) {
        $out['error'] = mb_substr($ex->getMessage(), 0, 160);
        return $out;
    }
    $out['ok'] = true;
    $out['used'] = (int) ($info['used_traffic'] ?? 0);
    $out['limit'] = (int) ($info['data_limit'] ?? 0);
    $out['users'] = (int) ($info['total_users'] ?? 0);
    $out['status'] = (string) ($info['status'] ?? '');
    if ($withDevices && set_bool('device_guard_on', true)) {
        try {
            $users = $client->users_of((string) $panel['panel_username'], 200);
            $devices = 0;
            $busiest = 0;
            foreach ($users as $userRow) {
                $count = (int) ($userRow['hwid_count'] ?? 0);
                $devices += $count;
                if ($count > $busiest) {
                    $busiest = $count;
                }
            }
            $out['devices'] = $devices;
            /* ملاک، سقفِ عددی خودِ ربات است (نه سقف روی پنل) */
            $maxDevices = max(1, set_int('device_max_devices', 10));
            $out['hwid_limit'] = $maxDevices;
            if ($busiest > $maxDevices) {
                $out['violation'] = true;
            }
            $out['devices'] = $devices;
        } catch (Throwable $ex) {
            log_line('warning', 'hwid scan failed: ' . $ex->getMessage());
        }
    }
    return $out;
}

/** ذخیرهٔ نتیجهٔ خواندن پنل روی ردیف محلی */
function panel_store_live(int $panelId, array $info): void
{
    DB::exec('UPDATE panels SET last_usage=?, last_users=?, last_devices=?, last_hwid_limit=?, '
        . 'last_synced_at=? WHERE id=?',
        [$info['used'], $info['users'], $info['devices'], $info['hwid_limit'], now_ts(), $panelId]);
}

function panel_sync_now(array $panel): array
{
    $info = panel_live_info($panel, !panel_is_unlimited($panel));
    if ($info['ok']) {
        panel_store_live((int) $panel['id'], $info);
    }
    return $info;
}

function screen_panel_detail(int|string $chat, ?int $messageId, int $panelId): void
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        Tg::show($chat, $messageId, 'پنل پیدا نشد.',
            [[btn('« پنل‌های من', 'menu:panels', null, 'danger')]]);
        return;
    }
    if ((string) $panel['status'] === 'active' && (int) ($panel['is_trial'] ?? 0) !== 1
        && !panel_is_unlimited($panel)) {
        panel_sync_usage($panelId);
        $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]) ?? $panel;
    }
    $live = ((int) ($panel['last_synced_at'] ?? 0) + 90) > now_ts()
        ? ['ok' => true, 'used' => (int) $panel['last_usage'], 'limit' => 0,
           'users' => (int) $panel['last_users'], 'devices' => (int) $panel['last_devices'],
           'hwid_limit' => (int) $panel['last_hwid_limit'], 'status' => '', 'violation' => false,
           'error' => '']
        : panel_sync_now($panel);
    if ($live['ok']) {
        panel_store_live($panelId, $live);
        $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]) ?? $panel;
    }
    $product = $panel['product_id'] ? product_get((int) $panel['product_id']) : null;
    $isTrial = (int) ($panel['is_trial'] ?? 0) === 1;
    $isUnlimited = panel_is_unlimited($panel);
    $statusMap = ['active' => '🟢 فعال', 'suspended' => '🟡 غیرفعال', 'expired' => '🔴 منقضی'];
    $status = $statusMap[$panel['status']] ?? e((string) $panel['status']);
    $cap = (int) ($panel['user_cap'] ?? 0) ?: (int) $panel['max_users'];
    $gb = static fn(int $bytes): string => gb_step_text($bytes);
    $pgRow = panel_pg_row($panel);
    $panelUrl = $pgRow !== null ? panel_dashboard_url(normalize_panel_url((string) $pgRow['url'])) : '';

    $text = '🖥 <b>' . e((string) $panel['panel_username']) . '</b>'
        . ($isTrial ? ' 🧪' : ($isUnlimited ? ' ♾️' : '')) . "\n\n"
        . '👤 نام کاربری: <code>' . e((string) $panel['panel_username']) . "</code>\n"
        . '🔑 رمز ورود: <code>' . e((string) ($panel['panel_password'] ?? '—')) . "</code>\n";
    if ($panelUrl !== '') {
        $text .= '🌐 آدرس پنل: ' . e($panelUrl) . "\n";
    }
    $text .= "\n" . 'وضعیت: ' . $status . "\n";
    if ($isUnlimited) {
        $text .= '♾️ نوع پنل: <b>نامحدود</b>' . "\n"
            . '⏳ اعتبار: طبق تنظیمات نقش در پاسارگارد' . "\n"
            . '📱 محدودیت دستگاه: طبق تنظیمات نقش در پاسارگارد' . "\n"
            . '👥 کاربر: ' . fa_digits((int) $panel['last_users']) . ' از '
                . fa_digits($cap) . "\n"
            . '💵 قیمت هر کاربر برای شارژ: ' . money(panel_unlimited_user_price($panel)) . "\n"
            . '🔻 حداقل شارژ: ' . fa_digits(panel_unlimited_min_users($panel)) . " کاربر\n"
            . '<i>ربات کنترل دستگاه، حجم و اعتبار این پنل را اعمال نمی‌کند.</i>' . "\n";
    } else {
        $text .= 'اعتبار تا: ' . fa_ts((int) ($panel['expires_at'] ?? 0)) . "\n"
            . '👥 کاربر: ' . fa_digits((int) $panel['last_users']) . ' از ' . fa_digits($cap) . "\n"
            . '📊 مصرف: <b>' . $gb((int) $panel['last_usage']) . ' گیگ</b>'
            . ((int) $panel['data_cap'] > 0 ? ' از ' . $gb((int) $panel['data_cap']) . ' گیگ' : '') . "\n";
        if (!$isTrial) {
            $text .= '💵 هر گیگ: ' . money((int) $panel['per_gb_price'])
                . ' · ⏱ هر ساعت: ' . money((int) $panel['hourly_price']) . "\n";
        }
        if ($isTrial) {
            $leftH = max(0, (int) ceil(((int) ($panel['expires_at'] ?? 0) - now_ts()) / 3600));
            $text .= "\n🧪 <i>پنل تست: رایگان است، هزینه‌ای از کیف پول کم نمی‌شود و با "
                . 'موجودی صفر هم قطع یا حذف نمی‌شود.</i>' . "\n"
                . '⏳ باقی‌مانده: <b>' . fa_digits($leftH) . " ساعت</b>\n"
                . '<i>در پایان همین ۲۴ ساعت خودکار پاک می‌شود.</i>' . "\n";
        }
        if (!$isTrial && (int) $panel['hourly_price'] > 0) {
            $text .= '📈 روزانه ' . money((int) $panel['hourly_price'] * 24)
                . ' · ماهانه ' . money((int) $panel['hourly_price'] * 24 * 30) . "\n";
        }
        if (!$isTrial) {
            $leftPrepaid = panel_prepaid_remaining($panel);
            if ($leftPrepaid > 0) {
                $text .= '🎁 <b>' . fa_digits((int) ceil($leftPrepaid / 3600))
                    . ' ساعت پیش‌پرداخت‌شده</b> باقی مانده — تا آن زمان کسر ساعتی شروع نمی‌شود' . "\n"
                    . '   شروع کسر ساعتی: ' . fa_ts((int) $panel['billing_start_at']) . "\n";
            } elseif ((int) ($panel['prepaid_amount'] ?? 0) > 0) {
                $text .= '⏱ کسر ساعتی از ' . fa_ts((int) $panel['billing_start_at'])
                    . ' فعال شده است' . "\n";
            }
        }
    }
    if (!$isUnlimited && (int) $panel['last_devices'] > 0) {
        $text .= '📱 دستگاه متصل: ' . fa_digits((int) $panel['last_devices']) . "\n";
    }
    if (!$isUnlimited && (int) ($panel['violation_until'] ?? 0) > now_ts()) {
        $text .= '⏳ قطع به‌خاطر تخلف تا: ' . fa_ts((int) $panel['violation_until']) . "\n";
    }
    $discountLine = ($isTrial || $isUnlimited) ? '' : panel_discount_text($panel);
    if ($discountLine !== '') {
        $text .= $discountLine . "\n";
    }
    if (!$live['ok'] && $live['error'] !== '') {
        $text .= "\n⚠️ خواندن از پنل نشد: <code>" . e($live['error']) . "</code>\n";
    }
    if (!$isTrial && !$isUnlimited) {
        $text .= "\n👛 موجودی کیف پول: <b>" . money(balance_of((int) $panel['tg_id'])) . '</b>';
    }

    /* دکمه‌ها: فقط کارهای واقعی و ضروری */
    $rows = [];
    if ($panelUrl !== '') {
        $rows[] = [btn('🌐 ورود به پنل', null, $panelUrl, 'primary')];
    }
    if ($isUnlimited) {
        $rows[] = [btn('🔑 تغییر رمز', 'pn:' . $panelId . ':pass', null, 'primary')];
        $rows[] = [btn('➕ شارژ / افزودن کاربر', 'pn:' . $panelId . ':users', null, 'success')];
    } else {
        $rows[] = [btn('🔑 تغییر رمز', 'pn:' . $panelId . ':pass', null, 'primary'),
                   btn('📱 دستگاه‌ها', 'pn:' . $panelId . ':sec', null, 'primary')];
        $blockPrice = $isTrial ? 0 : (int) ($product['extra_block_price'] ?? 0);
        $blockSize = (int) ($product['extra_block_size'] ?? 1000) ?: 1000;
        if ($blockPrice > 0) {
            $rows[] = [btn('➕ ' . fa_digits($blockSize) . ' کاربر · ' . money($blockPrice),
                'pn:' . $panelId . ':extra', null, 'primary')];
        }
    }
    if (!$isTrial && !$isUnlimited && $discountLine === '') {
        $rows[] = [btn('🏷 کد تخفیف', 'pn:' . $panelId . ':disc', null, 'primary')];
    }
    if (!$isTrial && !$isUnlimited && $panel['status'] !== 'active') {
        $rows[] = [btn('💳 شارژ کیف پول', 'menu:wallet', null, 'primary')];
    }
    if (!$isTrial) {
        $rows[] = [btn('🗑 حذف پنل', 'pn:' . $panelId . ':del', null, 'danger')];
    }
    $rows[] = [btn('« بازگشت', 'menu:panels', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/**
 * اجارهٔ ۲۴ ساعت اول پنل، همان لحظهٔ ساخت، از کیف پول کم می‌شود.
 * خروجی: مبلغی که واقعاً کم شد.
 */
/**
 * ثبت پیش‌پرداخت ساعت‌های اول در دفتر مصرف پنل.
 *
 * پول هنگام پرداخت فاکتور از کیف پول رفته است؛ اینجا فقط ثبت دفتری می‌شود تا
 * «مصرف» و «هزینهٔ پنل» در گزارش‌ها درست دیده شود.
 * ⚠️ این مبلغ غیرقابل استرداد است و با حذف پنل هم برنمی‌گردد.
 */
function panel_record_prepaid(int $panelId, int $tgId, int $amount, ?int $hours = null): int
{
    if ($amount <= 0) {
        return 0;
    }
    $hours = $hours ?? prepaid_hours();
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return 0;
    }
    $billingStart = (int) ($panel['billing_start_at'] ?? 0);
    if ($billingStart <= 0) {
        $billingStart = now_ts() + $hours * 3600;
    }
    DB::exec('INSERT INTO usage_charges(panel_id, tg_id, bytes, hours, amount, kind, created_at) '
        . 'VALUES(?,?,?,?,?,?,?)', [$panelId, $tgId, 0, $hours, $amount, 'prepaid', now_ts()]);
    DB::exec('UPDATE panels SET prepaid_amount=?, billing_start_at=?, last_meter_at=?, '
        . 'spent_total = spent_total + ? WHERE id=?',
        [$amount, $billingStart, $billingStart, $amount, $panelId]);
    return $amount;
}

/**
 * اگر قیمت‌های روی پنل صفر مانده باشند، از محصول خوانده و ذخیره می‌شوند
 * (تا گیگی و ساعتی همیشه حساب شود). خروجی: ردیف به‌روزشدهٔ پنل.
 */
function panel_sync_prices(array $panel): array
{
    if ((int) ($panel['is_trial'] ?? 0) === 1 || panel_is_unlimited($panel)
        || empty($panel['product_id'])) {
        return $panel;
    }
    $prodRow = product_get((int) $panel['product_id']);
    if ($prodRow === null) {
        return $panel;
    }
    $fix = [];
    if ((int) $panel['per_gb_price'] <= 0 && (int) $prodRow['per_gb_price'] > 0) {
        $fix['per_gb_price'] = (int) $prodRow['per_gb_price'];
    }
    if ((int) $panel['hourly_price'] <= 0 && (int) $prodRow['hourly_price'] > 0) {
        $fix['hourly_price'] = (int) $prodRow['hourly_price'];
    }
    if ((int) ($panel['user_cap'] ?? 0) <= 0 && (int) $prodRow['max_users'] > 0) {
        $fix['user_cap'] = (int) $prodRow['max_users'];
    }
    foreach ($fix as $key => $value) {
        DB::exec("UPDATE panels SET $key=? WHERE id=?", [$value, (int) $panel['id']]);
        $panel[$key] = $value;
    }
    return $panel;
}

/** حذف پنل توسط خود کاربر (روی پنل هم حذف می‌شود) */
function panel_delete_own(array $panel): array
{
    if ((int) ($panel['is_trial'] ?? 0) === 1) {
        delete_trial_panel($panel, 'حذف توسط کاربر');
        return [true, '🧪 پنل تست حذف شد.'];
    }
    $panelRow = panel_pg_row($panel);
    $apiOk = true;
    $note = '';
    $usersDeleted = 0;
    if ($panelRow !== null) {
        try {
            $purge = (new PgPanel($panelRow))->delete_admin_with_users(
                (string) $panel['panel_username']);
            $usersDeleted = (int) ($purge['users'] ?? 0);
        } catch (Throwable $ex) {
            $message = $ex->getMessage();
            $apiOk = str_contains($message, '404');
            if (!$apiOk) {
                $note = mb_substr($message, 0, 140);
            }
        }
    }
    /* ردیف محلی کامل پاک می‌شود: نه در «پنل‌های من»، نه در بخش مدیریت */
    $panelId = (int) $panel['id'];
    DB::exec('DELETE FROM panels WHERE id=?', [$panelId]);
    DB::exec('DELETE FROM tg_state WHERE data LIKE ?', ['%"panel_id":' . $panelId . '%']);
    post_report('🗑 <b>پنل توسط کاربر حذف شد</b>' . "\n"
        . '👤 کاربر <code>' . (int) $panel['tg_id'] . "</code>\n"
        . '🆔 پنل <code>' . e((string) $panel['panel_username']) . "</code>\n"
        . '♻️ کاربران ساخته‌شده روی پنل: ' . $usersDeleted
        . ($apiOk ? '' : "\n⚠️ حذف روی پنل انجام نشد: " . e($note)), 'panel');
    return [true, '🗑 پنل <code>' . e((string) $panel['panel_username']) . '</code> حذف شد.'
        . ($usersDeleted > 0
            ? "\n♻️ " . fa_digits($usersDeleted) . ' کاربر/کانفیگ ساخته‌شده روی آن هم پاک شد.'
            : '')
        . ($apiOk ? '' : "\n⚠️ روی سرور پنل حذف نشد: " . e($note))];
}

/** تغییر رمز ورود پنل — رمز تصادفی یا دلخواه */
function panel_change_password(int $panelId, string $password): array
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return [false, 'پنل پیدا نشد.'];
    }
    if (trim($password) === '') {
        $password = random_password(set_int('panel_pass_len', 12));
    } elseif (mb_strlen($password) < 8) {
        return [false, 'رمز باید حداقل ۸ کاراکتر باشد.'];
    }
    $pgRow = panel_pg_row($panel);
    if ($pgRow === null) {
        return [false, 'پنل پاسارگارد این پنل حذف شده است.'];
    }
    try {
        (new PgPanel($pgRow))->set_password((string) $panel['panel_username'], $password);
    } catch (Throwable $ex) {
        return [false, 'تغییر رمز روی پنل انجام نشد: ' . mb_substr($ex->getMessage(), 0, 160)
            . "\n<i>مطمئن شو کلید API اجازهٔ admins.update دارد.</i>"];
    }
    DB::exec('UPDATE panels SET panel_password=? WHERE id=?', [$password, $panelId]);
    post_report('🔑 <b>تغییر رمز پنل</b>' . "\n" . '👤 کاربر <code>' . (int) $panel['tg_id']
        . "</code>\n" . '📦 پنل <code>' . e((string) $panel['panel_username']) . '</code>', 'panel');
    return [true, "✅ رمز پنل عوض شد.\n\n👤 نام کاربری: <code>"
        . e((string) $panel['panel_username']) . "</code>\n🔑 رمز تازه: <code>"
        . e($password) . "</code>\n\n<i>رمز را جایی امن ذخیره کنید.</i>"];
}

function screen_panel_password(int|string $chat, ?int $messageId, int $panelId): void
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return;
    }
    Tg::show($chat, $messageId,
        "🔑 <b>تغییر رمز پنل</b>\n\n"
        . 'کاربری: <code>' . e((string) $panel['panel_username']) . "</code>\n\n"
        . 'رمز جدید چطور ساخته شود؟' . "\n"
        . '<i>با رمز تصادفی، ربات خودش رمز امن می‌سازد و نشانت می‌دهد.</i>',
        [[btn('🎲 رمز خودکار', 'pn:' . $panelId . ':passnew', null, 'success')],
         [btn('✍️ رمز دلخواه', 'pn:' . $panelId . ':passset')],
         [btn('« بازگشت', 'pn:' . $panelId, null, 'danger')]]);
}

function screen_panel_security(int|string $chat, ?int $messageId, int $panelId): void
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return;
    }
    if (panel_is_unlimited($panel)) {
        Tg::show($chat, $messageId,
            "♾️ <b>مدیریت دستگاه</b>\n\n"
            . 'برای پنل نامحدود، محدودیت دستگاه و اعتبار فقط از نقش پاسارگارد اعمال می‌شود. '
            . 'ربات بررسی یا محدودسازی دستگاه انجام نمی‌دهد.',
            [[btn('« پنل', 'pn:' . $panelId, null, 'danger')]]);
        return;
    }
    $info = panel_live_info($panel);
    if ($info['ok']) {
        panel_store_live($panelId, $info);
    }
    $minutes = max(1, set_int('device_cut_minutes', 10));
    $maxDevices = max(1, set_int('device_max_devices', 10));
    $guard = set_bool('device_guard_on', true);
    $limited = device_restricted_rows($panelId);
    /* متن دقیقاً می‌گوید مجازات فقط برای همان کاربر است، نه کل کانفیگ‌های پنل */
    $text = '📱 <b>دستگاه‌های متصل</b>' . "\n\n"
        . '👤 کاربری: <code>' . e((string) $panel['panel_username']) . '</code>' . "\n\n"
        . '🔢 کاربران این کانفیگ: ' . $info['users'] . "\n\n"
        . '📱 دستگاه متصل اکنون: ' . $info['devices'] . "\n\n"
        . '⚙️ کنترل خودکار: ' . ($guard ? '🟢 روشن' : '🔴 خاموش') . "\n\n";
    $text .= $guard
        ? '⏱ اگر روی این کانفیگ بیشتر از سقف مجاز (' . $maxDevices . ' دستگاه) هم‌زمان وصل شوند، '
            . 'فقط همین کاربر به مدت ' . $minutes . ' دقیقه غیرفعال (Disabled) شده، '
            . 'اتصالات آن ریست می‌شود و پس از ' . $minutes
            . ' دقیقه مجدداً به صورت خودکار فعال خواهد شد.'
        : 'با خاموش بودن این گزینه ربات فقط گزارش می‌دهد و هیچ کاربری را غیرفعال نمی‌کند.';
    $text .= "\n";
    if ($limited !== []) {
        $text .= "\n⏳ <b>در حال جریمه</b> (فقط همین کاربران):" . "\n";
        foreach ($limited as $row) {
            $leftMin = (int) max(0, ceil(((int) $row['unban_at'] - now_ts()) / 60));
            $text .= '· <code>' . e((string) $row['username']) . '</code> — ' . $leftMin
                . ' دقیقه مانده (فعال‌سازی ' . device_clock((int) $row['unban_at']) . ')' . "\n";
        }
    }
    if ($info['violation']) {
        $text .= "\n⛔️ یکی از کانفیگ‌های این پنل از سقف دستگاه رد شده است "
            . '(بقیهٔ کانفیگ‌ها دست‌نخورده‌اند).' . "\n";
    }
    if ((int) ($panel['violation_count'] ?? 0) > 0) {
        $text .= '📌 تعداد تخلف‌های ثبت‌شده: ' . (int) $panel['violation_count'] . "\n";
    }
    if (!$info['ok'] && $info['error'] !== '') {
        $text .= "\n⚠️ <code>" . e($info['error']) . "</code>\n";
    }
    $rows = [];
    if ($info['devices'] > 0) {
        $rows[] = [btn('🚪 خروج همهٔ دستگاه‌ها', 'pn:' . $panelId . ':hwreset', null, 'danger')];
    }
    $rows[] = [btn('🔄 بررسی دوباره', 'pn:' . $panelId . ':sec'),
               btn('« پنل', 'pn:' . $panelId, null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** خروج همهٔ دستگاه‌های کاربران این پنل (reset HWID) */
function panel_reset_devices(int $panelId): array
{
    $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        return [false, 'پنل پیدا نشد.'];
    }
    if (panel_is_unlimited($panel)) {
        return [false, 'برای پنل نامحدود، مدیریت دستگاه با نقش پاسارگارد است.'];
    }
    $pgRow = panel_pg_row($panel);
    if ($pgRow === null) {
        return [false, 'پنل پاسارگارد این پنل حذف شده است.'];
    }
    try {
        $client = new PgPanel($pgRow);
        $users = $client->users_of((string) $panel['panel_username'], 200);
    } catch (Throwable $ex) {
        return [false, 'خواندن کاربران پنل انجام نشد: ' . mb_substr($ex->getMessage(), 0, 160)];
    }
    $done = 0;
    $failed = 0;
    foreach ($users as $userRow) {
        if ((int) ($userRow['hwid_count'] ?? 0) <= 0) {
            continue;
        }
        if ($done >= 50) {
            break;
        }
        try {
            $client->reset_hwids((int) ($userRow['id'] ?? 0));
            $done++;
        } catch (Throwable $ex) {
            $failed++;
        }
    }
    DB::exec('UPDATE panels SET last_devices=0 WHERE id=?', [$panelId]);
    if ($done === 0 && $failed === 0) {
        return [true, 'هیچ دستگاهی برای خروج ثبت نشده بود.'];
    }
    post_report('🚪 <b>خروج دستگاه‌ها</b>' . "\n" . '📦 پنل <code>'
        . e((string) $panel['panel_username']) . "</code>\n"
        . '✅ ' . $done . ' کاربر · ❌ ' . $failed . ' خطا', 'panel');
    return [$failed === 0, '🚪 دستگاه‌های ' . fa_digits($done) . ' کاربر خارج شد'
        . ($failed > 0 ? ' · ' . fa_digits($failed) . ' مورد خطا داد' : '') . '.'];
}

