<?php
declare(strict_types=1);

/*
   21) PAYMENTS — درگاه‌های پرداخت: کارت‌به‌کارت · کیف پول · زرین‌پال ·
       آقای‌پرداخت · ایران‌پی ۱/۲/۴ · نواپی‌منت · پلیسیو · واریزا · ترون ·
       استارز تلگرام.
*/

/** هر درگاه: عنوان، ایموجی، نوع، و کلید تنظیماتش */
const PAY_GATEWAYS = [
    'wallet'        => ['label' => 'کیف پول', 'emoji' => '👛', 'type' => 'instant',
                        'hint' => 'پرداخت از موجودی کیف پول؛ بدون کارمزد.'],
    'card'          => ['label' => 'کارت به کارت', 'emoji' => '💳', 'type' => 'manual',
                        'hint' => 'کاربر رسید می‌فرستد و ادمین تأیید می‌کند.'],
    'zarinpal'      => ['label' => 'زرین‌پال', 'emoji' => '🟡', 'type' => 'online',
                        'hint' => 'merchant_id از پنل زرین‌پال (۳۶ کاراکتری).'],
    'aqayepardakht' => ['label' => 'آقای پرداخت', 'emoji' => '🔵', 'type' => 'online',
                        'hint' => 'pin درگاه از پنل aqayepardakht.ir.'],
    'iranpay1'      => ['label' => 'FloyPay', 'emoji' => '🟣', 'type' => 'online',
                        'hint' => 'ApiKey از پنل FloyPay (tetra98.com).'],
    'iranpay2'      => ['label' => 'CubePay', 'emoji' => '🟢', 'type' => 'online',
                        'hint' => 'توکن Bearer از cubevps.ir (همان کلید، امضای کال‌بک را می‌سازد).'],
    'iranpay4'      => ['label' => 'AbanPay', 'emoji' => '🟠', 'type' => 'online',
                        'hint' => 'API key + آدرس سرویس درگاه (https).'],
    'tetrapay'      => ['label' => 'تتراپی', 'emoji' => '🔷', 'type' => 'online',
                        'hint' => 'فقط کلید API تتراپی؛ مبلغ‌ها خودکار از تومان به ریال تبدیل می‌شوند.'],
    'nowpayment'    => ['label' => 'NowPayments', 'emoji' => '₿', 'type' => 'crypto',
                        'hint' => 'x-api-key از nowpayments.io + IPN secret.'],
    'plisio'        => ['label' => 'Plisio', 'emoji' => '🪙', 'type' => 'crypto',
                        'hint' => 'Secret key از Plisio (همان کلید، امضای کال‌بک را می‌سازد).'],
    'variza'        => ['label' => 'Variza', 'emoji' => '🏦', 'type' => 'online',
                        'hint' => 'توکن API + secret وبهوک Variza.'],
    'trx'           => ['label' => 'TRX (Tron)', 'emoji' => '💎', 'type' => 'crypto',
                        'hint' => 'توکن درگاه TRX + آدرس کیف پول.'],
    'stars'         => ['label' => 'Telegram Stars', 'emoji' => '⭐️', 'type' => 'telegram',
                        'hint' => 'پرداخت با Stars؛ نیازی به کلید ندارد.'],
];

/** تنظیمات پیش‌فرض هر درگاه (on/off، کلید، سقف، کش‌بک) */
function pay_setting_defaults(): array
{
    $defaults = ['pay_channels' => 'wallet,card'];
    foreach (PAY_GATEWAYS as $id => $meta) {
        if ($meta['type'] === 'instant') {
            continue;
        }
        $defaults["pay_{$id}_on"] = ($id === 'card') ? '1' : '0';
        $defaults["pay_{$id}_key"] = '';
        /* TetraPay new installs require only its API key. An old key2 setting,
           if already present, is read solely to validate a legacy webhook. */
        if ($id !== 'tetrapay') {
            $defaults["pay_{$id}_key2"] = '';
        }
        /* اعداد پیش‌فرض درگاه‌ها */
        $minMax = [
            'card'       => ['20000', '10000000'],
            'tetrapay'   => ['20000', '1000000'],
            'nowpayment' => ['250000', '1000000'],
            'plisio'     => ['250000', '100000000'],
            'stars'      => ['20000', '1000000'],
        ][$id] ?? ['20000', '1000000'];
        $defaults["pay_{$id}_min"] = $minMax[0];
        $defaults["pay_{$id}_max"] = $minMax[1];
        $defaults["pay_{$id}_cashback"] = ($id === 'nowpayment') ? '1' : '0';
        /* 0 means no daily ceiling. Only settled transactions count. */
        $defaults["pay_{$id}_daily_limit"] = '0';
        $defaults["pay_{$id}_help"] = '';
        if (in_array($id, ['plisio', 'nowpayment'], true)) {
            $defaults["pay_{$id}_min_fiat"] = '0';    // ۰ = حداقل را از خود درگاه می‌خوانیم
        }
    }
    /* مسیر جداگانهٔ AbanPay؛ در نسخه‌های قدیمی گاهی اشتباهاً در key2 ذخیره می‌شد. */
    $defaults['pay_iranpay4_endpoint'] = '';
    $defaults['swap_api_key'] = '';
    $defaults['usd_toman_rate'] = '0';                // فقط نرخ پشتیبان، وقتی سواپ‌ولت در دسترس نیست
    return $defaults;
}

function pay_settings_bootstrap(): void
{
    foreach (pay_setting_defaults() as $key => $value) {
        if (DB::one('SELECT key FROM settings WHERE key=?', [$key]) === null) {
            set_put($key, $value);
        }
    }
    /* One-time non-destructive migration: an old UI put the AbanPay endpoint
       in key2. Move only a valid HTTPS URL, never a secret. */
    if (DB::one("SELECT key FROM settings WHERE key='pay_iranpay4_endpoint_migrated'") === null) {
        $old = trim(set_get('pay_iranpay4_key2', ''));
        if (set_get('pay_iranpay4_endpoint', '') === ''
            && gw_https_url($old)) {
            set_put('pay_iranpay4_endpoint', rtrim($old, '/'));
        }
        set_put('pay_iranpay4_endpoint_migrated', '1');
    }
}

function gw_meta(string $gw): ?array
{
    return PAY_GATEWAYS[$gw] ?? null;
}

function gw_on(string $gw): bool
{
    if ($gw === 'wallet') {
        return true;
    }
    return set_get("pay_{$gw}_on", '0') === '1';
}

function gw_key(string $gw): string
{
    return trim(set_get("pay_{$gw}_key", ''));
}

function gw_key2(string $gw): string
{
    return trim(set_get("pay_{$gw}_key2", ''));
}

/** Strict safe URL validator for provider endpoints and public callbacks. */
function gw_https_url(string $url): bool
{
    if ($url === '' || strlen($url) > 2048 || trim($url) !== $url
        || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || trim((string) ($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = (string) $parts['host'];
    if (strlen($host) > 253 || preg_match('/[\x00-\x20\x7F@]/', $host) === 1) {
        return false;
    }
    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        return false;
    }
    return true;
}

function gw_min(string $gw): int
{
    return max(1000, set_int("pay_{$gw}_min", set_int('min_topup', 50000)));
}

function gw_max(string $gw): int
{
    return max(gw_min($gw), set_int("pay_{$gw}_max", set_int('max_topup', 20000000)));
}

function gw_cashback(string $gw): int
{
    return max(0, min(50, set_int("pay_{$gw}_cashback", 0)));
}

/** 0 means unlimited; a daily ceiling is calculated only from settled rows. */
function gw_daily_limit(string $gw): int
{
    return max(0, set_int("pay_{$gw}_daily_limit", 0));
}

function gw_help(string $gw): string
{
    return mb_substr(trim(set_get("pay_{$gw}_help", '')), 0, 1800);
}

/** Customer help is administrator-authored plain text, never trusted HTML. */
function pay_gateway_help_notice(string $gw): string
{
    $help = gw_help($gw);
    return $help === '' ? '' : "\n\n📘 <b>راهنمای پرداخت</b>\n" . e($help);
}

/** Start of the current Tehran business day, independent of server timezone. */
function gw_day_start(): int
{
    $shift = (int) IRAN_OFFSET;
    return intdiv(now_ts() + $shift, DAY) * DAY - $shift;
}

function gw_daily_paid_total(string $gw): int
{
    $start = gw_day_start();
    /* Card-to-card has no provider payment row. Its authoritative settlement
       event is the finance review timestamp of an approved receipt. */
    if ($gw === 'card') {
        return (int) DB::val("SELECT COALESCE(SUM(amount),0) FROM receipts WHERE status='approved' "
            . 'AND reviewed_at>=? AND reviewed_at<?', [$start, $start + DAY], 0);
    }
    return (int) DB::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE method=? "
        . "AND status='paid' AND paid_at>=? AND paid_at<?", [$gw, $start, $start + DAY], 0);
}

function gw_daily_allowed(string $gw, int $amount): bool
{
    $limit = gw_daily_limit($gw);
    return $limit === 0 || $amount <= 0 || gw_daily_paid_total($gw) + $amount <= $limit;
}

/** Must be called inside the same DB::tx() that claims the payment/receipt. */
function gw_daily_capacity_available(string $gw, int $amount): bool
{
    $limit = gw_daily_limit($gw);
    if ($limit <= 0 || $amount <= 0) {
        return true;
    }
    $start = gw_day_start();
    if ($gw === 'card') {
        $paid = (int) DB::val("SELECT COALESCE(SUM(amount),0) FROM receipts WHERE status='approved' "
            . 'AND reviewed_at>=? AND reviewed_at<?', [$start, $start + DAY], 0);
    } else {
        $paid = (int) DB::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE method=? "
            . "AND status='paid' AND paid_at>=? AND paid_at<?", [$gw, $start, $start + DAY], 0);
    }
    return $paid + $amount <= $limit;
}

/** A visible provider must be able to receive a signed/authoritative callback. */
function gw_readiness(string $gw): array
{
    $meta = gw_meta($gw);
    if ($meta === null) {
        return [false, ['درگاه ناشناخته است']];
    }
    if ($gw === 'wallet') {
        return [true, []];
    }
    if ($gw === 'card') {
        return [set_get('card_number', '') !== '', ['شماره کارت ثبت نشده']];
    }
    $missing = [];
    if (!gw_on($gw)) {
        $missing[] = 'درگاه خاموش است';
    }
    if ($gw !== 'stars' && gw_key($gw) === '') {
        $missing[] = 'کلید اصلی ثبت نشده';
    }
    if ($gw === 'iranpay4' && pay_abanpay_endpoint() === '') {
        $missing[] = 'آدرس HTTPS ابان‌پی ثبت نشده';
    }
    if ($gw === 'variza' && gw_key2($gw) === '') {
        $missing[] = 'کلید امضای وب‌هوک واریزا ثبت نشده';
    }
    if ($gw === 'nowpayment' && gw_key2($gw) === '') {
        $missing[] = 'IPN secret نواپی‌منت ثبت نشده';
    }
    if ($gw === 'trx' && gw_key2($gw) === '') {
        $missing[] = 'آدرس کیف پول TRX ثبت نشده';
    }
    if (in_array((string) $meta['type'], ['online', 'crypto'], true)) {
        $callback = $gw === 'tetrapay' ? tetrapay_callback_url() : pay_callback_url($gw);
        if (!gw_https_url($callback)) {
            $missing[] = $gw === 'tetrapay'
                ? 'سامانهٔ بازگشت پرداخت تتراپی آماده نیست'
                : 'آدرس HTTPS کال‌بک ثبت نشده';
        }
    }
    return [$missing === [], $missing];
}

/** درگاه‌هایی که کاربر همین حالا می‌تواند انتخاب کند. */
function gw_available(?int $uid = null): array
{
    $list = [];
    $uid = $uid ?? (int) ($GLOBALS['panel_act_uid'] ?? 0);
    foreach (PAY_GATEWAYS as $id => $meta) {
        if ($id === 'wallet') {
            continue;
        }
        [$ready] = gw_readiness($id);
        if ($ready) {
            $list[$id] = $meta;
        }
    }
    return $list;
}


function web_base(): string
{
    $url = trim(set_get('web_url', ''));
    if ($url === '') {
        $url = trim(cfg('PANEL_WEB_URL', ''));
    }
    if ($url === '') {
        $domain = trim(set_get('web_domain', ''));
        $url = $domain !== '' ? 'https://' . $domain : '';
    }
    return rtrim($url, '/');
}

function pay_callback_url(string $gw): string
{
    $url = rtrim(web_base(), '/') . '/pay/' . rawurlencode($gw);
    return gw_https_url($url) ? $url : '';
}

/* ── ثبت و مدیریت پرداخت‌ها ─────────────────────────────────────────────── */

function pay_new(array $order, string $method, int $amount): array
{
    $id = DB::exec('INSERT INTO payments(order_id, tg_id, method, amount, status, created_at) '
        . 'VALUES(?,?,?,?,?,?)',
        [(int) $order['id'], (int) $order['tg_id'], $method, $amount, 'unpaid', now_ts()]);
    return DB::one('SELECT * FROM payments WHERE id=?', [$id]) ?? [];
}

function pay_get(int $id): ?array
{
    return DB::one('SELECT * FROM payments WHERE id=?', [$id]);
}

function pay_set_authority(int $paymentId, string $authority, array $extra = []): void
{
    $row = DB::one('SELECT payload FROM payments WHERE id=?', [$paymentId]);
    $meta = json_decode((string) ($row['payload'] ?? ''), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    unset($extra['_status']);
    $meta['create'] = $extra;   // پاسخ خام درگاه، برای پیگیری
    DB::exec('UPDATE payments SET authority=?, payload=? WHERE id=?',
        [$authority, json_encode($meta, JSON_UNESCAPED_UNICODE), $paymentId]);
}

function pay_find_by_authority(string $authority): ?array
{
    /* Expired rows remain discoverable solely so an authoritative late provider
       signal can be escalated to finance; pay_settle() still refuses auto-credit. */
    return DB::one("SELECT * FROM payments WHERE authority=? AND status IN ('unpaid','expire') "
        . 'ORDER BY id DESC LIMIT 1', [$authority]);
}

/** عبارت مهلت پرداخت برای پیام مشتری. */
function pay_expiry_human(): string
{
    $minutes = max(1, set_int('pay_expire_minutes', 1440));
    if ($minutes === 1440) {
        return 'یک روز';
    }
    if ($minutes % 60 === 0) {
        return fa_digits(intdiv($minutes, 60)) . ' ساعت';
    }
    return fa_digits($minutes) . ' دقیقه';
}

/** Variza uses compact documented durations; the SIBIL one-day policy is 1d. */
function pay_variza_expiry(): string
{
    $minutes = max(1, set_int('pay_expire_minutes', 1440));
    if ($minutes % 1440 === 0) {
        return intdiv($minutes, 1440) . 'd';
    }
    return max(1, (int) ceil($minutes / 60)) . 'h';
}

/** پیام استاندارد بعد از ساخت لینک؛ تراکنش فقط ایجاد شده و هنوز اعتبار ندارد. */
function pay_created_notice(array $order, array $payment): string
{
    $gateway = PAY_GATEWAYS[(string) ($payment['method'] ?? '')]['label']
        ?? (string) ($payment['method'] ?? '—');
    $tracking = trim((string) ($payment['authority'] ?? ''));
    if ($tracking === '') {
        $tracking = (string) ($order['ref'] ?? '—');
    }
    $amount = number_format((int) ($payment['amount'] ?? $order['payable'] ?? 0));
    $support = support_username();
    $supportLine = $support !== ''
        ? "\n✅ در صورت مشکل می‌توانید با پشتیبانی @" . e($support) . ' در ارتباط باشید.'
        : "\n✅ در صورت مشکل می‌توانید با پشتیبانی در ارتباط باشید.";

    return '<b>' . e(bot_name()) . "</b>\n\n"
        . '✅ <b>تراکنش شما ایجاد شد</b>' . "\n\n"
        . '🛒 کد پیگیری:' . "\n<code>" . e($tracking) . "</code>\n\n"
        . '💲 مبلغ تراکنش به تومان:' . "\n<code>" . e($amount) . "</code> تومان\n\n"
        . '💳 درگاه: <b>' . e($gateway) . "</b>\n\n"
        . '💢 لطفاً پیش از پرداخت به این نکات توجه کنید 👇' . "\n\n"
        . '❌ این تراکنش تا <b>' . pay_expiry_human() . '</b> اعتبار دارد؛ پس از آن امکان پرداخت ندارد.'
        . $supportLine . "\n\n"
        . '⚠️ <b>تا قبل از Verify/Webhook معتبر، کیف پول یا سفارش شما هیچ تغییری نمی‌کند.</b>'
        . pay_gateway_help_notice((string) ($payment['method'] ?? ''));
}

/** پیام تأیید نهایی فقط پس از پاسخ معتبر Verify/Webhook و تحویل موفق سفارش. */
function pay_verified_notice(array $order, array $payment, string $deliveryText): string
{
    $gateway = PAY_GATEWAYS[(string) ($payment['method'] ?? '')]['label']
        ?? (string) ($payment['method'] ?? '—');
    $ref = trim((string) ($payment['ref'] ?? ''));
    if ($ref === '') {
        $ref = trim((string) ($payment['authority'] ?? ''));
    }
    $amount = number_format((int) ($payment['amount'] ?? 0));
    $tracking = $ref !== '' ? $ref : (string) ($order['ref'] ?? '—');
    $body = '<b>' . e(bot_name()) . "</b>\n\n"
        . '✅ <b>پرداخت شما با موفقیت و به‌صورت قطعی تأیید شد</b>' . "\n\n"
        . '🛒 کد پیگیری: <code>' . e($tracking) . "</code>\n"
        . '💲 مبلغ تأییدشده: <code>' . e($amount) . "</code> تومان\n"
        . '💳 درگاه: <b>' . e($gateway) . "</b>\n\n";
    if ((string) ($order['kind'] ?? '') === 'topup') {
        $body .= '💲 کاربر گرامی مبلغ <b>' . e($amount) . '</b> تومان به کیف پول شما واریز گردید.'
            . "\n\nبا تشکر از پرداخت شما. 🙏\n"
            . '🛒 کد پیگیری شما: <code>' . e($tracking) . "</code>\n\n";
    }
    return $body . $deliveryText;
}

/**
 * تسویهٔ نهایی یک پرداخت (idempotent).
 * خروجی: ['ok' => bool, 'state' => success|already|notfound|failed, 'text' => پیام]
 */
function pay_apply_cashback_once(array $payment): int
{
    return DB::tx(static function () use ($payment): int {
        $fresh = pay_get((int) $payment['id']);
        if ($fresh === null || (string) $fresh['status'] !== 'paid') {
            return 0;
        }
        $meta = pay_meta($fresh);
        if (!empty($meta['cashback_applied_at'])) {
            return 0;
        }
        $percent = gw_cashback((string) $fresh['method']);
        $reward = $percent > 0 ? intdiv((int) $fresh['amount'] * $percent, 100) : 0;
        /* Marking and the wallet transaction share one BEGIN IMMEDIATE block,
           so a retry cannot grant the same cashback twice. */
        $meta['cashback_applied_at'] = now_ts();
        $meta['cashback_amount'] = $reward;
        $changed = DB::update('UPDATE payments SET payload=? WHERE id=? AND status=\'paid\'',
            [json_encode($meta, JSON_UNESCAPED_UNICODE), (int) $fresh['id']]);
        if ($changed !== 1 || $reward <= 0) {
            return 0;
        }
        add_balance((int) $fresh['tg_id'], $reward, 'ref', (string) $fresh['id'],
            'کش‌بک ' . $percent . '٪ پرداخت');
        return $reward;
    });
}

/**
 * تسویهٔ نهایی یک پرداخت (idempotent).
 * The payment claim, invoice TTL and daily capacity test are deliberately in
 * one BEGIN IMMEDIATE transaction. A second callback can therefore observe
 * either the settled row or the still-unpaid row, never an over-limit credit.
 */
function pay_settle(int $paymentId, string $ref = '', array $extra = [], bool $force = false): array
{
    $claim = DB::tx(static function () use ($paymentId, $ref, $extra, $force): array {
        $payment = pay_get($paymentId);
        if ($payment === null) {
            return ['state' => 'notfound'];
        }
        $order = order_get((int) $payment['order_id']);
        if ($order === null) {
            return ['state' => 'notfound'];
        }
        if ((string) $payment['status'] === 'paid') {
            return ['state' => 'already', 'payment' => $payment, 'order' => $order];
        }
        if ((string) $payment['status'] === 'expire' && !$force) {
            return ['state' => 'expired', 'payment' => $payment, 'order' => $order];
        }
        if (!$force && order_payment_expired($order)) {
            DB::update("UPDATE payments SET status='expire' WHERE id=? AND status='unpaid'", [$paymentId]);
            DB::update("UPDATE orders SET status='canceled', paid_at=NULL WHERE id=? AND status='pending'",
                [(int) $order['id']]);
            $payment = pay_get($paymentId) ?? $payment;
            $order = order_get((int) $order['id']) ?? $order;
            return ['state' => 'expired', 'payment' => $payment, 'order' => $order];
        }
        if ((string) $order['status'] !== 'pending'
            && !($force && (string) $order['status'] === 'canceled')) {
            return ['state' => 'closed', 'payment' => $payment, 'order' => $order];
        }
        if (!gw_daily_capacity_available((string) $payment['method'], (int) $payment['amount'])) {
            return ['state' => 'limit', 'payment' => $payment, 'order' => $order];
        }
        $meta = pay_meta($payment);
        $meta['verify'] = $extra;
        $where = $force ? "status IN ('unpaid','expire')" : "status='unpaid'";
        $changed = DB::update("UPDATE payments SET status='paid', ref=?, paid_at=?, payload=? WHERE id=? AND $where",
            [$ref, now_ts(), json_encode($meta, JSON_UNESCAPED_UNICODE), $paymentId]);
        if ($changed !== 1) {
            $fresh = pay_get($paymentId);
            return ['state' => $fresh !== null && (string) $fresh['status'] === 'paid' ? 'already' : 'closed',
                'payment' => $fresh ?? $payment, 'order' => $order];
        }
        return ['state' => 'claimed', 'payment' => pay_get($paymentId) ?? $payment, 'order' => $order];
    });

    $state = (string) ($claim['state'] ?? 'notfound');
    $payment = $claim['payment'] ?? null;
    $order = $claim['order'] ?? null;
    if ($state === 'notfound') {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'پرداخت یا سفارش پیدا نشد'];
    }
    if ($state === 'expired') {
        if (is_array($payment)) {
            pay_late_notice($payment);
        }
        return ['ok' => false, 'state' => 'expired',
            'text' => 'مهلت این فاکتور گذشته؛ برای بررسی به پشتیبانی اطلاع دادیم.'];
    }
    if ($state === 'limit') {
        log_payment('gateway daily limit blocked settlement', ['payment' => $paymentId,
            'method' => (string) ($payment['method'] ?? ''), 'amount' => (int) ($payment['amount'] ?? 0)]);
        return ['ok' => false, 'state' => 'daily_limit',
            'text' => 'سقف تراکنشِ تأییدشدهٔ امروز برای این درگاه پر شده است؛ با پشتیبانی تماس بگیرید.'];
    }
    if ($state === 'closed') {
        return ['ok' => false, 'state' => 'failed', 'text' => 'این سفارش دیگر برای پرداخت باز نیست.'];
    }
    if (!is_array($payment) || !is_array($order)) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'پرداخت یا سفارش پیدا نشد'];
    }
    if ($state === 'already') {
        /* A prior process may have completed delivery but stopped before its
           cashback marker; safely repair that tiny post-settlement window. */
        $freshOrder = order_get((int) $order['id']) ?? $order;
        if ((string) $freshOrder['status'] === 'delivered') {
            pay_apply_cashback_once($payment);
        }
        return ['ok' => true, 'state' => 'already', 'text' => 'قبلاً تأیید شده است'];
    }

    log_payment('gateway paid', [
        'payment' => $paymentId, 'method' => $payment['method'],
        'order' => (int) $payment['order_id'], 'tg' => (int) $payment['tg_id'],
        'ref' => $ref, 'amount' => (int) $order['payable'],
    ]);
    [$ok, $text] = settle_payment($order, (string) $payment['method']);
    $tgId = (int) $payment['tg_id'];
    if (!$ok) {
        Tg::send($tgId, '⚠️ <b>پرداخت تأیید شد، اما انجام سفارش نیاز به بررسی دارد</b>' . "\n\n"
            . 'کد سفارش: <code>' . e((string) $order['ref']) . "</code>\n"
            . 'هنوز پیام موفقیت یا شارژ کیف پول صادر نشده است. لطفاً با پشتیبانی هماهنگ کنید.');
        post_report('⚠️ <b>پرداخت تأیید شد ولی تحویل ناموفق بود</b>' . "\n"
            . 'کاربر <code>' . $tgId . '</code> · سفارش <code>' . e((string) $order['ref']) . '</code>', 'payment');
        return ['ok' => false, 'state' => 'waiting', 'text' => $text];
    }

    $reward = pay_apply_cashback_once($payment);
    if ($reward > 0) {
        Tg::send($tgId, '🎁 ' . money($reward) . ' کش‌بک پرداخت به کیف پولتان اضافه شد.');
    }
    $paidRow = pay_get($paymentId) ?? $payment;
    Tg::send($tgId, pay_verified_notice($order, $paidRow, $text), home_kb($tgId));
    $gwLabel = PAY_GATEWAYS[(string) $payment['method']]['label'] ?? $payment['method'];
    post_report('💰 <b>پرداخت موفق</b>' . "\n" . 'درگاه: ' . e($gwLabel) . "\n"
        . 'کاربر <code>' . $tgId . '</code> · ' . money((int) $payment['amount']) . "\n"
        . 'سفارش <code>' . e((string) $order['ref']) . '</code>');
    return ['ok' => true, 'state' => 'success', 'text' => $text];
}

/** صفحهٔ نتیجهٔ پرداخت — ساده و بدون فونت بیرونی */
function pay_result_page(string $state, string $orderRef = '', int $amount = 0): string
{
    $map = [
        'success'  => ['✔', '#2ecc71', 'پرداخت موفق', 'سفارش شما فعال شد. به ربات برگرد.'],
        'already'  => ['✔', '#2ecc71', 'قبلاً تأیید شده', 'این پرداخت پیش‌تر ثبت شده است.'],
        'failed'   => ['✖', '#e74c3c', 'پرداخت ناموفق', 'اگر مبلغ کسر شده، با پشتیبانی تماس بگیرید.'],
        'expired'  => ['⏱', '#e67e22', 'پرداخت منقضی شده', 'یک سفارش تازه بساز.'],
        'notfound' => ['؟', '#e67e22', 'تراکنش پیدا نشد', 'اطلاعات این پرداخت معتبر نیست.'],
        'waiting'  => ['⏳', '#3498db', 'در انتظار تأیید', 'واریز دریافت شد؛ تأیید خودکار در چند دقیقه.'],
    ];
    [$icon, $color, $title, $detail] = $map[$state] ?? $map['failed'];
    $botHandle = ltrim(cfg('PANEL_BOT_USERNAME', set_get('bot_username', '')), '@');
    $botLink = $botHandle !== ''
        ? '<a class="btn" href="https://t.me/' . e($botHandle) . '">بازگشت به ربات</a>' : '';
    $rows = '';
    if ($orderRef !== '') {
        $rows .= '<div class="row"><span>کد سفارش</span><b>' . e($orderRef) . '</b></div>';
    }
    if ($amount > 0) {
        $rows .= '<div class="row"><span>مبلغ</span><b>' . money($amount) . '</b></div>';
    }
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><style>'
        . 'body{font-family:Tahoma,sans-serif;background:#f2f2f2;margin:0;padding:20px;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh}'
        . '.box{background:#fff;border-radius:14px;box-shadow:0 2px 14px rgba(0,0,0,.08);'
        . 'padding:32px 24px;max-width:360px;width:100%;text-align:center}'
        . '.icon{font-size:44px;color:' . $color . '}h2{color:#333;margin:12px 0;font-size:20px}'
        . 'p{color:#777;font-size:14px;line-height:1.8;margin:8px 0}'
        . '.row{display:flex;justify-content:space-between;border-top:1px dashed #eee;'
        . 'padding:10px 0;font-size:14px;color:#555}'
        . '.btn{display:inline-block;margin-top:18px;background:' . $color . ';color:#fff;'
        . 'text-decoration:none;padding:10px 22px;border-radius:10px;font-size:14px}'
        . '</style></head><body><div class="box">'
        . '<div class="icon">' . $icon . '</div><h2>' . e($title) . '</h2>'
        . '<p>' . e($detail) . '</p>' . $rows . $botLink
        . '</div></body></html>';
}

/* ── درخواست پرداخت از هر درگاه ─────────────────────────────────────────── */

/**
 * شروع پرداخت. خروجی:
 * ['ok'=>bool, 'url'=>لینک پرداخت, 'text'=>پیام برای کاربر, 'payment'=>id]
 */
function pay_start(array $order, string $gw): array
{
    $meta = gw_meta($gw);
    if ($meta === null) {
        return ['ok' => false, 'url' => '', 'text' => 'درگاه نامعتبر است'];
    }
    $fresh = order_get((int) ($order['id'] ?? 0));
    if ($fresh === null) {
        return ['ok' => false, 'url' => '', 'text' => 'سفارش پیدا نشد'];
    }
    $order = $fresh;
    if (order_cancel_if_payment_expired($order)) {
        return ['ok' => false, 'url' => '', 'state' => 'expired',
            'text' => 'مهلت این فاکتور تمام شده است؛ یک سفارش تازه بسازید.'];
    }
    if ((string) $order['status'] !== 'pending') {
        return ['ok' => false, 'url' => '', 'text' => 'این سفارش دیگر برای پرداخت باز نیست.'];
    }
    $amount = (int) $order['payable'];
    if ($gw !== 'wallet') {
        [$ready, $missing] = gw_readiness($gw);
        if (!$ready) {
            return ['ok' => false, 'url' => '', 'text' => 'این درگاه آمادهٔ پرداخت نیست: '
                . implode('، ', $missing) . '.'];
        }
    }
    if ($amount < gw_min($gw) || $amount > gw_max($gw)) {
        return ['ok' => false, 'url' => '',
                'text' => 'مبلغ این سفارش خارج از محدودهٔ این درگاه است (' . money(gw_min($gw))
                    . ' تا ' . money(gw_max($gw)) . ').'];
    }
    if ($gw !== 'wallet' && !gw_daily_allowed($gw, $amount)) {
        return ['ok' => false, 'url' => '', 'text' => 'سقف تراکنشِ تأییدشدهٔ امروز برای این درگاه پر شده است؛ '
            . 'یک درگاه دیگر را انتخاب کنید یا با پشتیبانی تماس بگیرید.'];
    }
    /* درگاه‌های ارزی حداقل خودشان را به دلار دارند؛ قبل از ساخت فاکتور بگو */
    if (gw_is_fiat($gw) && gw_min_fiat($gw) > 0 && gw_fiat_amount($gw, $amount) < gw_min_fiat($gw)) {
        return ['ok' => false, 'url' => '', 'text' => gw_fiat_too_small_text($gw, $amount)];
    }
    if ($gw === 'wallet') {
        [$ok, $text] = pay_from_wallet($order);
        return ['ok' => $ok, 'url' => '', 'text' => $text];
    }
    if ($gw === 'card') {
        return ['ok' => true, 'url' => '', 'text' => 'کارت‌به‌کارت'];
    }
    if ($gw === 'stars') {
        return pay_stars($order);
    }
    /* لمس دوبارهٔ دکمه نباید برای یک سفارش چند فاکتور درگاه بسازد. */
    $existing = DB::one("SELECT * FROM payments WHERE order_id=? AND method=? AND status='unpaid' "
        . "AND authority<>'' ORDER BY id DESC LIMIT 1", [(int) $order['id'], $gw]);
    if ($existing !== null) {
        $existingMeta = pay_meta($existing);
        $existingUrl = trim((string) ($existingMeta['checkout_url'] ?? ''));
        if (gw_https_url($existingUrl)) {
            return ['ok' => true, 'url' => $existingUrl,
                    'alt_url' => (string) ($existingMeta['telegram_checkout_url'] ?? ''),
                    'text' => PAY_GATEWAYS[$gw]['label'] ?? $gw,
                    'payment' => (int) $existing['id'], 'reused' => true];
        }
    }
    $payment = pay_new($order, $gw, $amount);
    if (!$payment) {
        return ['ok' => false, 'url' => '', 'text' => 'ثبت پرداخت ناموفق بود'];
    }
    pay_prepare($payment, $order);
    $payment = pay_get((int) $payment['id']) ?? $payment;   // متادیتای تازه‌نوشته‌شده
    $created = match ($gw) {
        'zarinpal'      => pay_zarinpal_create($payment, $order),
        'aqayepardakht' => pay_aqaye_create($payment, $order),
        'iranpay1'      => pay_floypay_create($payment, $order),
        'iranpay2'      => pay_cubepay_create($payment, $order),
        'iranpay4'      => pay_abanpay_create($payment, $order),
        'tetrapay'      => pay_tetrapay_create($payment, $order),
        'nowpayment'    => pay_nowpay_create($payment, $order),
        'plisio'        => pay_plisio_create($payment, $order),
        'variza'        => pay_variza_create($payment, $order),
        'trx'           => pay_trx_create($payment, $order),
        default         => ['ok' => false, 'url' => '', 'text' => 'این درگاه پشتیبانی نمی‌شود'],
    };
    /* اگر خود درگاه فاکتور نساخت و حرف از «حداقل مبلغ» بود، همان عدد خودش را
       فارسی و روشن نشان می‌دهیم؛ پیام خام انگلیسی به کاربر نمی‌رسد. */
    if (($created['ok'] ?? false) !== true && gw_is_fiat($gw)) {
        $raw = (string) ($created['text'] ?? '');
        if ($raw !== '' && preg_match('/min|low|small|کم/i', $raw) === 1) {
            $created['text'] = gw_min_from_provider_text($gw, $amount, $raw);
        }
    }
    if (($created['ok'] ?? false) === true && ($created['url'] ?? '') !== '') {
        /* URL را محلی نگه می‌داریم تا لمس دوبارهٔ دکمه همان فاکتور باز را باز کند. */
        $fresh = pay_get((int) $payment['id']) ?? $payment;
        $meta = pay_meta($fresh);
        $meta['checkout_url'] = (string) $created['url'];
        if (!empty($created['alt_url']) && preg_match('~^https://~i', (string) $created['alt_url']) === 1) {
            $meta['telegram_checkout_url'] = (string) $created['alt_url'];
        }
        pay_save_meta((int) $payment['id'], $meta);
        $created['payment'] = (int) $payment['id'];
    }
    return $created;
}

function pay_json_post(string $url, array $body, array $headers = [], int $timeout = 25): array
{
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Accept: application/json';
    [$raw, $error, $status] = http_request('POST', $url, $headers,
        json_encode($body, JSON_UNESCAPED_UNICODE), true, $timeout);
    if ($raw === null) {
        return ['_error' => $error, '_status' => 0];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = ['_raw' => mb_substr((string) $raw, 0, 300)];
    }
    $decoded['_status'] = $status;
    return $decoded;
}

/*
   نرخ‌های لحظه‌ای سواپ‌ولت (SwapWallet).

   همهٔ نرخ‌ها از API خود سواپ‌ولت خوانده می‌شوند؛ هیچ نرخی دستی وارد نمی‌شود.
     · دلار (تتر) → USDT/IRT
     · ترون       → TRX/IRT
     · استارز     → قیمت بستهٔ ۱۰۰ استارز فروشگاه ÷ ۱۰۰
   واحد IRT در این API همان تومان است.
*/

/* مسیرهای رسمی سواپ‌ولت (مطابق docs.swapwallet.app):
   GET  https://swapwallet.app/api/v1/market/prices   → همهٔ جفت‌نرخ‌ها (X/USDT و X/IRT)
   GET  https://swapwallet.app/api/v1/shop/prices     → قیمت محصولات فروشگاه، از جمله TG-STARS-100
   GET  https://swapwallet.app/api/v1/utils/authentication/check → تست کلید
   واحد IRT در این API همان «تومان» است. */
const SWAP_API = 'https://swapwallet.app/api';
const SWAP_DOCS = 'https://docs.swapwallet.app/';
const SWAP_PATH_PRICES = '/v1/market/prices';
const SWAP_PATH_SHOP = '/v1/shop/prices';
const SWAP_PATH_CHECK = '/v1/utils/authentication/check';

function swap_key(): string
{
    return trim(set_get('swap_api_key', ''));
}

/** یک GET به سواپ‌ولت؛ خروجی آرایهٔ رمزگشایی‌شده با کلیدهای _status/_error */
function swap_get(string $path, int $timeout = 12): array
{
    $headers = ['Accept: application/json'];
    if (swap_key() !== '') {
        $headers[] = 'Authorization: Bearer ' . swap_key();
    }
    [$raw, $error, $status] = http_request('GET', SWAP_API . $path, $headers, null, true, $timeout);
    if ($raw === null) {
        return ['_error' => (string) $error, '_status' => 0];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return ['_error' => 'invalid json', '_status' => (int) $status];
    }
    $data['_status'] = (int) $status;
    return $data;
}

/** پاسخ موفق سواپ‌ولت؟ */
function swap_ok(array $res): bool
{
    return strtoupper((string) ($res['status'] ?? '')) === 'OK';
}

/**
 * نرخ همهٔ توکن‌ها به تومان. حداکثر ۲ دقیقه کش می‌شود تا هر کلیک یک درخواست نزند.
 * خروجی: ['USDT' => 271799, 'TRX' => 89709, ...]
 */
function swap_rates(bool $force = false): array
{
    $cache = json_decode((string) set_get('swap_rates_cache', ''), true);
    $cache = is_array($cache) ? $cache : [];
    $age = now_ts() - set_int('swap_rates_at', 0);
    if (!$force && $cache !== [] && $age < 30) {
        return $cache;
    }
    $res = swap_get(SWAP_PATH_PRICES);
    $rates = [];
    if (swap_ok($res) && is_array($res['result'] ?? null)) {
        foreach ($res['result'] as $pair => $value) {
            if (!str_contains((string) $pair, '/IRT')) {
                continue;
            }
            $token = strtoupper((string) strstr((string) $pair, '/', true));
            $rate = (int) round((float) $value);
            if ($token !== '' && $rate > 0) {
                $rates[$token] = $rate;
            }
        }
    }
    if ($rates !== []) {
        set_put('swap_rates_cache', json_encode($rates));
        set_put('swap_rates_at', (string) now_ts());
        set_put('swap_last_error', '');
        set_put('swap_last_http', (string) ($res['_status'] ?? 0));
        set_put('swap_last_body', mb_substr((string) json_encode(
            ['USDT/IRT' => $rates['USDT'] ?? null, 'TRX/IRT' => $rates['TRX'] ?? null],
            JSON_UNESCAPED_UNICODE), 0, 300));
        return $rates;
    }
    log_line('warning', 'swapwallet prices failed', ['status' => $res['_status'] ?? 0,
        'error' => $res['_error'] ?? ($res['message'] ?? '')]);
    set_put('swap_last_error', (string) ($res['_error'] ?? ($res['message'] ?? 'no answer')));
    return $cache;   // آخرین نرخ سالم، بهتر از هیچ
}

/** قیمت هر استارز به دلار (از قیمت بستهٔ ۱۰۰ استارز در فروشگاه سواپ‌ولت) */
function swap_star_usdt(bool $force = false): float
{
    $cached = (float) set_get('swap_star_cache', '0');
    $age = now_ts() - set_int('swap_star_at', 0);
    if (!$force && $cached > 0 && $age < 600) {
        return $cached;
    }
    $res = swap_get(SWAP_PATH_SHOP);
    $price = 0.0;
    if (swap_ok($res) && is_array($res['result'] ?? null)) {
        foreach ($res['result'] as $item) {
            if ((string) ($item['code'] ?? '') === 'TG-STARS-100') {
                $price = (float) ($item['price']['number'] ?? 0) / 100;
                break;
            }
        }
    }
    if ($price <= 0) {
        $price = $cached > 0 ? $cached : 0.0165;   // آخرین نرخ سالم / پیش‌فرض
    } else {
        set_put('swap_star_cache', (string) $price);
        set_put('swap_star_at', (string) now_ts());
    }
    return $price;
}

/** ۱ دلار چند تومان است — لحظه‌ای از سواپ‌ولت */
function usd_toman_rate(): int
{
    $rates = swap_rates();
    $rate = (int) ($rates['USDT'] ?? 0);
    if ($rate <= 0) {
        $rate = set_int('usd_toman_rate', 0);   // نرخ پشتیبان (اختیاری، از تنظیمات)
    }
    if ($rate <= 0) {
        $rate = 100000;
    }
    return $rate;
}

/** ۱ ترون چند تومان است */
function trx_toman_rate(): int
{
    $rates = swap_rates();
    $rate = (int) ($rates['TRX'] ?? 0);
    if ($rate <= 0) {
        $rate = (int) round(usd_toman_rate() * 0.33);
    }
    return $rate;
}

/** ۱ استارز چند تومان است */
function star_toman_rate(): int
{
    $rate = (int) round(swap_star_usdt() * usd_toman_rate());
    if ($rate <= 0) {
        $rate = max(100, set_int('stars_rate', 1000));
    }
    return $rate;
}

/**
 * تست زندهٔ سواپ‌ولت: کد HTTP، زمان پاسخ و بدنهٔ خام.
 * برای این‌که خودتان ببینید نرخ واقعاً از API می‌آید و از کش نیست.
 */
function swap_selftest(): array
{
    $started = microtime(true);
    $prices = swap_get(SWAP_PATH_PRICES, 20);
    $ms = (int) round((microtime(true) - $started) * 1000);
    $ok = swap_ok($prices) && is_array($prices['result'] ?? null);
    return [
        'ok'      => $ok,
        'http'    => (int) ($prices['_status'] ?? 0),
        'ms'      => $ms,
        'url'     => SWAP_API . SWAP_PATH_PRICES,
        'body'    => mb_substr((string) json_encode($prices, JSON_UNESCAPED_UNICODE), 0, 400),
        'error'   => (string) ($prices['_error'] ?? ''),
        'usdt'    => (int) round((float) ($prices['result']['USDT/IRT'] ?? 0)),
        'trx'     => (int) round((float) ($prices['result']['TRX/IRT'] ?? 0)),
    ];
}

/** وضعیت کوتاه برای نمایش در صفحهٔ ادمین */
function swap_state(): array
{
    $rates = swap_rates();
    return [
        'ok'       => $rates !== [] && set_get('swap_last_error', '') === '',
        'usdt'     => usd_toman_rate(),
        'trx'      => trx_toman_rate(),
        'star'     => star_toman_rate(),
        'star_usd' => swap_star_usdt(),
        'at'       => set_int('swap_rates_at', 0),
        'key'      => swap_key() !== '',
        'error'    => set_get('swap_last_error', ''),
        'http'     => set_int('swap_last_http', 0),
    ];
}

/* ── ارز و نرخ تبدیل درگاه‌های ارزی (Plisio / NowPayments) ─────────────── */

function gw_is_fiat(string $gw): bool
{
    return in_array($gw, ['plisio', 'nowpayment'], true);
}

/**
 * نرخ تبدیل تومان→دلار. از سواپ‌ولت خوانده می‌شود؛ نرخ دستی فقط پشتیبان است.
 */
function gw_fiat_rate(string $gw = ''): float
{
    return (float) usd_toman_rate();
}

function gw_fiat_amount(string $gw, int $toman): float
{
    $rate = gw_fiat_rate($gw);
    return $rate > 0 ? round($toman / $rate, 2) : 0.0;
}

function gw_fiat_currency(string $gw = ''): string
{
    return 'usd';
}

/**
 * حداقل مبلغ به دلار برای درگاه‌های ارزی.
 *
 * درس گرفته‌شده: قبلاً همین‌جا برای NowPayments عددِ /v1/min-amount ارز
 * «usdttrc20» (حدود ۱۱ دلار) و برای Plisio عدد ۵ دلار سخت شده بود؛ نتیجه این بود
 * که سفارش‌های معمولی (۵۰۰٬۰۰۰ تومان ≈ ۱.۸ دلار) هیچ‌وقت به فاکتور نمی‌رسیدند،
 * در حالی که خودِ درگاه با همان ۱.۸۴ دلار فاکتور می‌سازد (فاکتور BTC/​USDT کاربر).
 *
 * پس: هیچ حداقلی از طرف ما تحمیل نمی‌شود. فاکتور ساخته می‌شود و اگر خود درگاه
 * مبلغ را کم بداند، پیام دقیق خودش (با همان عدد) به کاربر نشان داده می‌شود.
 * ادمین می‌تواند با pay_<gw>_min_fiat عدد دلخواه بگذارد.
 */
function gw_min_fiat(string $gw): float
{
    return (float) set_get('pay_' . $gw . '_min_fiat', '0');
}

/** حداقل تراکنش NowPayments به دلار — از API خودشان، با کش ۶ ساعته */
function nowpay_min_usd(bool $force = false): float
{
    $cached = (float) set_get('pay_nowpayment_min_cache', '0');
    $age = now_ts() - set_int('pay_nowpayment_min_at', 0);
    if (!$force && $cached > 0 && $age < 21600) {
        return $cached;
    }
    $res = pay_json_get(gw_endpoint('nowpayment', gw_endpoint_default('nowpayment'))
        . '/v1/min-amount?currency_from=usdttrc20&currency_to=usdttrc20&fiat_equivalent=usd',
        ['x-api-key: ' . gw_key('nowpayment')]);
    $min = 0.0;
    foreach (['fiat_equivalent', 'min_amount'] as $key) {
        $value = $res[$key] ?? null;
        $value = is_array($value) ? ($value['number'] ?? 0) : $value;
        $value = (float) $value;
        if ($value > 0 && $value < 500) {
            $min = $value;
            break;
        }
    }
    if ($min <= 0) {
        $min = $cached > 0 ? $cached : 2.0;
    } else {
        set_put('pay_nowpayment_min_cache', (string) $min);
        set_put('pay_nowpayment_min_at', (string) now_ts());
    }
    return $min;
}

/**
 * اگر درگاه خودش گفت «مبلغ کم است»، همان عدد را از پیام او درمی‌آوریم و
 * به فارسی روشن نشان می‌دهیم. اگر عددی پیدا نشد، فقط پیام خودش می‌آید.
 */
function gw_min_error_usd(string $providerText): float
{
    /* ۱) عددی که کنار دلار نوشته شده: «minimum amount is 5.00 USD» */
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:USD|usd|\$)/', $providerText, $m) === 1) {
        return (float) $m[1];
    }
    /* عددهای بسیار کوچک معمولاً مقدار ارز دیجیتال است، نه دلار */
    /* ۲) عددی که قبلش حرف «min» هست: «min 5 » */
    if (preg_match('/min(?:imum|imal)?[^0-9]{0,40}?([0-9]+(?:\.[0-9]+)?)/i', $providerText, $m) === 1) {
        $value = (float) $m[1];
        if ($value >= 0.5) {
            return $value;
        }
    }
    /* ۳) هر عدد کوچک منطقی داخل پیامِ خطای حداقل */
    if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\b/', $providerText, $m) === 1) {
        $value = (float) $m[1];
        return $value >= 0.5 && $value < 1000 ? $value : 0.0;
    }
    return 0.0;
}

function gw_min_from_provider_text(string $gw, int $toman, string $providerText): string
{
    $minUsd = gw_min_error_usd($providerText);
    if ($minUsd > 0) {
        return gw_fiat_too_small_text($gw, $toman, $minUsd);
    }
    $label = PAY_GATEWAYS[$gw]['label'] ?? $gw;
    return '❌ <b>' . e($label) . ' فاکتور نساخت</b>' . "\n\n"
        . 'پاسخ درگاه: <code>' . e(mb_substr($providerText, 0, 200)) . '</code>' . "\n\n"
        . 'درگاه دیگری انتخاب کنید یا چند دقیقه بعد دوباره امتحان کنید.';
}

/**
 * پیام روشن وقتی مبلغ برای درگاه ارزی کم است.
 * حداقل مبلغ درگاه، به دلار و شفاف.
 */
function gw_fiat_too_small_text(string $gw, int $toman, ?float $minUsdOverride = null): string
{
    $label = PAY_GATEWAYS[$gw]['label'] ?? $gw;
    $rate = usd_toman_rate();
    $usd = gw_fiat_amount($gw, $toman);
    $minUsd = $minUsdOverride ?? gw_min_fiat($gw);
    $minToman = (int) ceil($minUsd * $rate);
    return '❌ <b>مبلغ برای ' . e($label) . ' کم است</b>' . "\n\n"
        . 'مبلغ سفارش: ' . money($toman) . ' ≈ <b>' . fa_digits(number_format($usd, 2))
        . '</b> دلار' . "\n"
        . 'حداقل تراکنش ' . e($label) . ': <b>' . fa_digits(number_format($minUsd, 2))
        . '</b> دلار' . "\n"
        . 'یعنی کمترین مبلغ قابل پرداخت با این درگاه <b>' . money($minToman) . '</b> است.' . "\n\n"
        . 'یا مبلغ را بالاتر ببر، یا درگاه دیگری انتخاب کنید.';
}

/**
 * آدرس سرویس (endpoint) هر درگاه.
 * پیش‌فرض‌ها همان آدرس‌های رسمی خود سرویس‌دهنده است؛ ادمین می‌تواند از پنل عوض کند
 * (مثلاً وقتی سرویس‌دهنده دامنه‌اش را تغییر می‌دهد، بدون آپدیت ربات).
 */
function gw_endpoint(string $gw, string $default = ''): string
{
    /* Only Aban Gateway has a provider-specific service address configured by
       an administrator. All other supported endpoints are official constants. */
    if ($gw === 'iranpay4') {
        $custom = trim(set_get('pay_iranpay4_endpoint', ''));
        if ($custom !== '' && gw_https_url($custom)) {
            return rtrim($custom, '/');
        }
    }
    return gw_https_url($default) ? rtrim($default, '/') : '';
}

/** آدرس پیش‌فرض رسمی هر درگاه (بدون تنظیم دستی) */
function gw_endpoint_default(string $gw): string
{
    return [
        'zarinpal'      => 'https://payment.zarinpal.com',
        'aqayepardakht' => 'https://panel.aqayepardakht.ir',
        'iranpay1'      => 'https://tetra98.com',            // FloyPay
        'iranpay2'      => 'https://cubevps.ir',             // CubePay
        'iranpay4'      => '',                               // AbanPay: سرویس خودتان
        'tetrapay'      => 'https://api.tetra98.com/api/v1',
        'nowpayment'    => 'https://api.nowpayments.io',
        'plisio'        => 'https://api.plisio.net',
        'variza'        => 'https://variza.ir',
        'trx'           => 'https://pay.melorinabeauty.com',
    ][$gw] ?? '';
}

/** متادیتای ذخیره‌شده روی یک پرداخت */
function pay_meta(array $payment): array
{
    $meta = json_decode((string) ($payment['payload'] ?? ''), true);
    return is_array($meta) ? $meta : [];
}

function pay_save_meta(int $paymentId, array $meta): void
{
    DB::exec('UPDATE payments SET payload=? WHERE id=?',
        [json_encode($meta, JSON_UNESCAPED_UNICODE), $paymentId]);
}

/**
 * بعضی درگاه‌های ایرانی مبلغ را «ریال» برمی‌گردانند و بعضی «تومان».
 * این تابع مبلغ دریافتی را به تومان نرمال می‌کند تا تطبیق مبلغ اشتباه نشود:
 * اگر عدد حدود ۱۰ برابر مبلغ سفارش باشد، ریالی است.
 */
function gw_amount_toman(float $value, int $expectedToman): float
{
    if ($value <= 0) {
        return 0.0;
    }
    if ($expectedToman > 0 && $value >= $expectedToman * 4) {
        return round($value / 10, 2);
    }
    return $value;
}

/** مبلغ مورد انتظار این پرداخت (تومان برای درگاه‌های ریالی، ارز برای بقیه) */
function pay_expected(array $payment): float
{
    $meta = pay_meta($payment);
    return (float) ($meta['fiat_amount'] ?? (int) $payment['amount']);
}

/** ثبت مبلغ/ارز در همان لحظهٔ ساخت پرداخت */
function pay_prepare(array $payment, array $order): array
{
    $gw = (string) $payment['method'];
    $meta = pay_meta($payment);
    $meta['order_ref'] = (string) $order['ref'];
    if (gw_is_fiat($gw)) {
        $meta['fiat_currency'] = gw_fiat_currency($gw);
        $meta['rate'] = gw_fiat_rate($gw);
        $meta['fiat_amount'] = gw_fiat_amount($gw, (int) $payment['amount']);
    } else {
        $meta['fiat_currency'] = 'toman';
        $meta['fiat_amount'] = (int) $payment['amount'];
    }
    pay_save_meta((int) $payment['id'], $meta);
    return $meta;
}

/**
 * تنها راه «موفق» شدن یک پرداخت.
 * فقط وقتی صدا زده می‌شود که درگاه سمت سرور پرداخت را تأیید کرده باشد.
 */
function pay_accept(array $payment, float $paid, string $ref, array $raw = []): array
{
    $expected = pay_expected($payment);
    /* A provider may overpay, never underpay.  There is deliberately no
       percentage tolerance: a completed status belongs to this exact invoice. */
    if ($expected > 0 && ($paid <= 0 || $paid + 0.000001 < $expected)) {
        log_line('warning', 'payment amount mismatch', ['payment' => (int) $payment['id'],
            'expected' => $expected, 'paid' => $paid, 'method' => (string) $payment['method']]);
        return ['ok' => false, 'state' => 'failed',
                'text' => 'مبلغ تأییدشده کمتر از مبلغ سفارش است؛ با پشتیبانی تماس بگیرید.'];
    }
    return pay_settle((int) $payment['id'], $ref, $raw);
}

function pay_json_get(string $url, array $headers = [], int $timeout = 25): array
{
    $headers[] = 'Accept: application/json';
    [$raw, $error, $status] = http_request('GET', $url, $headers, null, true, $timeout);
    if ($raw === null) {
        return ['_error' => $error, '_status' => 0];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = ['_raw' => mb_substr((string) $raw, 0, 300)];
    }
    $decoded['_status'] = $status;
    return $decoded;
}

/* زرین‌پال ─────────────────────────────────────────────────────────────── */

const ZARINPAL_ERRORS = [
    '-9'  => 'اطلاعات ارسالی درست نیست.',
    '-10' => 'مرچنت کد یا آدرس آی‌پی معتبر نیست.',
    '-11' => 'درخواست مورد نظر پیدا نشد.',
    '-12' => 'امکان ویرایش درخواست وجود ندارد.',
    '-15' => 'درگاه پرداخت غیرفعال است.',
    '-16' => 'سطح تأیید مرچنت پایین است.',
    '-17' => 'محدودیت پرداخت روزانه اعمال شده است.',
    '-30' => 'درگاه با این ارز پرداخت نمی‌کند.',
    '-31' => 'حساب بانکی مرچنت اشکال دارد.',
    '-32' => 'مبلغ تراکنش از سقف مجاز بیشتر است.',
    '-33' => 'مبلغ تراکنش از حداقل مجاز کمتر است.',
    '-34' => 'سقف تقسیم تراکنش پر شده است.',
    '-40' => 'پارامتر نامعتبر است.',
    '-50' => 'مبلغ پرداخت‌شده با مبلغ درخواستی هم‌خوانی ندارد.',
    '-51' => 'پرداخت ناموفق بود.',
    '-52' => 'خطای غیرمنتظره در درگاه رخ داد.',
    '-53' => 'این تراکنش متعلق به این مرچنت نیست.',
    '-54' => 'شناسهٔ authority نامعتبر است.',
    '-55' => 'این تراکنش قبلاً تأیید شده است.',
];

function zarinpal_error(array $res): string
{
    $code = (string) ($res['errors']['code'] ?? ($res['data']['code'] ?? ''));
    if ($code !== '' && isset(ZARINPAL_ERRORS[$code])) {
        return ZARINPAL_ERRORS[$code] . ' (کد ' . $code . ')';
    }
    $message = (string) ($res['errors']['message'] ?? ($res['data']['message'] ?? ''));
    if ($message !== '') {
        return $message;
    }
    return pay_error_text($res, 'خطای نامشخص از زرین‌پال');
}

function pay_zarinpal_create(array $payment, array $order): array
{
    $merchant = gw_key('zarinpal');
    if ($merchant === '') {
        return ['ok' => false, 'url' => '', 'text' => 'مرچنت زرین‌پال ثبت نشده است.'];
    }
    $res = pay_json_post(gw_endpoint('zarinpal', gw_endpoint_default('zarinpal'))
        . '/pg/v4/payment/request.json', [
        'merchant_id'  => $merchant,
        'currency'     => 'IRT',
        'amount'       => (int) $payment['amount'],
        'callback_url' => pay_callback_url('zarinpal'),
        'description'  => 'سفارش ' . $order['ref'],
        'metadata'     => ['order_id' => (string) $order['ref']],
    ]);
    $code = (int) ($res['data']['code'] ?? 0);
    $authority = (string) ($res['data']['authority'] ?? '');
    if ($code !== 100 || $authority === '') {
        return ['ok' => false, 'url' => '', 'text' => 'زرین‌پال: ' . zarinpal_error($res)];
    }
    pay_set_authority((int) $payment['id'], $authority, $res);
    return ['ok' => true,
            'url' => gw_endpoint('zarinpal', gw_endpoint_default('zarinpal'))
                . '/pg/StartPay/' . $authority,
            'text' => 'زرین‌پال'];
}

function pay_zarinpal_verify(array $payment, array $query): array
{
    $authority = (string) ($query['Authority'] ?? $query['authority'] ?? '');
    $status = strtoupper((string) ($query['Status'] ?? $query['status'] ?? ''));
    if ($authority === '' || !hash_equals((string) $payment['authority'], $authority)) {
        return ['ok' => false, 'state' => 'notfound',
                'text' => 'این پرداخت به هیچ سفارشی در ربات وصل نیست.'];
    }
    if ($status !== 'OK') {
        return ['ok' => false, 'state' => 'failed', 'text' => 'پرداخت لغو شد یا انجام نشد.'];
    }
    $res = pay_json_post(gw_endpoint('zarinpal', gw_endpoint_default('zarinpal'))
        . '/pg/v4/payment/verify.json', [
        'merchant_id' => gw_key('zarinpal'),
        'amount'      => (int) $payment['amount'],
        'authority'   => $authority,
    ]);
    $code = (int) ($res['data']['code'] ?? 0);
    if (in_array($code, [100, 101], true)) {
        return pay_accept($payment, (float) $payment['amount'],
            (string) ($res['data']['ref_id'] ?? $authority), $res);
    }
    return ['ok' => false, 'state' => 'failed', 'text' => 'زرین‌پال: ' . zarinpal_error($res)];
}

/* آقای پرداخت ──────────────────────────────────────────────────────────── */

const AQAYE_ERRORS = [
    '-1'  => 'مبلغ نمی‌تواند خالی باشد.',
    '-2'  => 'کد پین درگاه نمی‌تواند خالی باشد.',
    '-3'  => 'کال‌بک نمی‌تواند خالی باشد.',
    '-4'  => 'مبلغ باید عددی باشد.',
    '-5'  => 'مبلغ باید بین ۱٬۰۰۰ تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد.',
    '-6'  => 'کد پین درگاه اشتباه است.',
    '-7'  => 'شناسهٔ تراکنش نمی‌تواند خالی باشد.',
    '-8'  => 'تراکنش مورد نظر وجود ندارد.',
    '-9'  => 'کد پین با درگاه تراکنش مطابقت ندارد.',
    '-10' => 'مبلغ با مبلغ تراکنش مطابقت ندارد.',
    '-11' => 'درگاه در انتظار تأیید یا غیرفعال است.',
    '-12' => 'امکان ارسال درخواست برای این پذیرنده وجود ندارد.',
    '-14' => 'این درگاه روی سایت دیگری در حال استفاده است.',
];

function aqaye_error(array $res): string
{
    $code = (string) ($res['code'] ?? '');
    if ($code !== '' && isset(AQAYE_ERRORS[$code])) {
        return AQAYE_ERRORS[$code] . ' (کد ' . $code . ')';
    }
    return pay_error_text($res, 'خطای نامشخص از آقای پرداخت');
}

function pay_aqaye_create(array $payment, array $order): array
{
    $res = pay_json_post(gw_endpoint('aqayepardakht', gw_endpoint_default('aqayepardakht'))
        . '/api/v2/create', [
        'pin'        => gw_key('aqayepardakht'),
        'amount'     => (int) $payment['amount'],
        'callback'   => pay_callback_url('aqayepardakht'),
        'invoice_id' => (string) $payment['id'],
    ]);
    $transid = (string) ($res['transid'] ?? '');
    if ((string) ($res['status'] ?? '') !== 'success' || $transid === '') {
        return ['ok' => false, 'url' => '', 'text' => 'آقای پرداخت: ' . aqaye_error($res)];
    }
    pay_set_authority((int) $payment['id'], $transid, $res);
    return ['ok' => true,
            'url' => gw_endpoint('aqayepardakht', gw_endpoint_default('aqayepardakht'))
                . '/startpay/' . $transid,
            'text' => 'آقای پرداخت'];
}

function pay_aqaye_verify(array $payment, array $params): array
{
    $transid = (string) ($params['transid'] ?? '');
    $invoice = (string) ($params['invoice_id'] ?? '');
    if ($invoice !== '' && $invoice !== (string) $payment['id']
        && !hash_equals((string) $payment['authority'], $transid)) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'این پرداخت به سفارش دیگری تعلق دارد.'];
    }
    if ($transid === '' || !hash_equals((string) $payment['authority'], $transid)) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'شمارهٔ تراکنش این پرداخت مطابقت ندارد.'];
    }
    $res = pay_json_post(gw_endpoint('aqayepardakht', gw_endpoint_default('aqayepardakht'))
        . '/api/v2/verify', [
        'pin'     => gw_key('aqayepardakht'),
        'amount'  => (int) $payment['amount'],
        'transid' => $transid,
    ]);
    $code = (string) ($res['code'] ?? '');
    if ($code === '1' || $code === '2') {
        return pay_accept($payment, (float) $payment['amount'], $transid, $res);
    }
    return ['ok' => false, 'state' => 'failed', 'text' => 'آقای پرداخت: ' . aqaye_error($res)];
}

/* FloyPay (ایران‌پی ۱) ─────────────────────────────────────────────────── */

function pay_floypay_create(array $payment, array $order): array
{
    $res = pay_json_post(gw_endpoint('iranpay1', gw_endpoint_default('iranpay1'))
        . '/api/create_order', [
        'ApiKey'      => gw_key('iranpay1'),
        'Hash_id'     => (string) $order['ref'],
        'Amount'      => (string) ((int) $payment['amount'] * 10),   // ریال
        'CallbackURL' => pay_callback_url('iranpay1'),
    ], ['accept: application/json']);
    $url = (string) ($res['payment_url'] ?? $res['url'] ?? $res['link']
        ?? ($res['data']['payment_url'] ?? ($res['data']['url'] ?? '')));
    $hashid = (string) ($res['hash_id'] ?? ($res['data']['hash_id'] ?? $order['ref']));
    if ($url === '') {
        return ['ok' => false, 'url' => '', 'text' => 'FloyPay: '
            . pay_error_text($res, 'لینک پرداخت برنگشت')];
    }
    pay_set_authority((int) $payment['id'], $hashid, $res);
    return ['ok' => true, 'url' => $url, 'text' => 'FloyPay'];
}

function pay_floypay_verify(array $payment, array $params): array
{
    $status = (string) ($params['status'] ?? '');
    $authority = (string) ($params['authority'] ?? '');
    $hashid = (string) ($params['hashid'] ?? ($params['Hash_id'] ?? ''));
    if ($hashid !== '' && !hash_equals((string) $payment['authority'], $hashid)
        && $hashid !== pay_meta($payment)['order_ref']) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'این پرداخت به سفارش دیگری تعلق دارد.'];
    }
    if ($status !== '' && $status !== '100') {
        return ['ok' => false, 'state' => 'failed', 'text' => 'FloyPay: پرداخت تأیید نشد.'];
    }
    if ($authority === '') {
        return ['ok' => false, 'state' => 'waiting', 'text' => 'کد تأیید نرسیده است.'];
    }
    $res = pay_json_post(gw_endpoint('iranpay1', gw_endpoint_default('iranpay1'))
        . '/api/verify', [
        'ApiKey'    => gw_key('iranpay1'),
        'authority' => $authority,
        'hashid'    => (string) $payment['authority'],
    ]);
    if ((string) ($res['status'] ?? '') === '100') {
        return pay_accept($payment, (float) $payment['amount'], $authority, $res);
    }
    return ['ok' => false, 'state' => 'failed', 'text' => 'FloyPay: '
        . pay_error_text($res, 'تأیید پرداخت انجام نشد')];
}

/* CubePay (ایران‌پی ۲) ─────────────────────────────────────────────────── */

function pay_cubepay_create(array $payment, array $order): array
{
    $res = pay_json_post(gw_endpoint('iranpay2', gw_endpoint_default('iranpay2'))
        . '/pay/create-order.php', [
        'price_amount'           => (int) $payment['amount'],
        'order_id'               => (string) $order['ref'],
        'callback_url'           => pay_callback_url('iranpay2'),
        'redirect_after_payment' => false,
        'customer_user_id'       => (string) $order['tg_id'],
    ], ['Authorization: Bearer ' . gw_key('iranpay2')]);
    $url = (string) ($res['payment_link'] ?? $res['pay_page_url'] ?? '');
    if ($url === '') {
        return ['ok' => false, 'url' => '', 'text' => 'CubePay: '
            . pay_error_text($res, 'لینک پرداخت برنگشت')];
    }
    pay_set_authority((int) $payment['id'],
        (string) ($res['payment_id'] ?? $order['ref']), $res);
    return ['ok' => true, 'url' => $url, 'text' => 'CubePay'];
}

function pay_cubepay_verify(array $payment, array $params): array
{
    $orderRef = (string) ($params['order_id'] ?? '');
    if ($orderRef === '' || !hash_equals((string) (pay_meta($payment)['order_ref'] ?? ''),
        $orderRef)) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'این پرداخت به سفارش دیگری تعلق دارد.'];
    }
    $status = (string) ($params['status'] ?? '');
    $amount = (float) ($params['amount'] ?? $params['amount_toman'] ?? 0);
    $sig = (string) ($params['sig'] ?? '');
    $token = gw_key('iranpay2');
    if ($sig !== '') {
        $expected = hash_hmac('sha256', $orderRef . '|' . $status . '|'
            . (string) ($params['amount'] ?? $params['amount_toman'] ?? ''), $token);
        if (!hash_equals($expected, $sig)) {
            return ['ok' => false, 'state' => 'failed', 'text' => 'امضای کال‌بک CubePay معتبر نیست.'];
        }
        $amount = gw_amount_toman($amount, (int) $payment['amount']);
        if ($status !== 'paid' || ($amount > 0 && $amount < (int) $payment['amount'])) {
            return ['ok' => false, 'state' => 'failed', 'text' => 'CubePay: پرداخت تأیید نشد.'];
        }
        return pay_accept($payment, $amount > 0 ? $amount : (float) $payment['amount'],
            $orderRef, $params);
    }
    $authority = (string) ($params['authority'] ?? '');
    if ($authority === '') {
        return ['ok' => false, 'state' => 'waiting', 'text' => 'در انتظار تأیید CubePay.'];
    }
    $res = pay_json_post(gw_endpoint('iranpay2', gw_endpoint_default('iranpay2'))
        . '/smspay/api/verify-payment.php',
        ['authority' => $authority], ['Authorization: Bearer ' . $token]);
    /* مبلغ این API ریالی است؛ نرمال‌سازی خودکار تومان/ریال */
    $paid = gw_amount_toman((float) ($res['amount'] ?? 0), (int) $payment['amount']);
    if ((string) ($res['order_id'] ?? '') !== $orderRef) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'پاسخ CubePay برای سفارش دیگری بود.'];
    }
    if (empty($res['success']) && (int) ($res['_status'] ?? 0) !== 409) {
        return ['ok' => false, 'state' => 'failed', 'text' => 'CubePay: تأیید پرداخت انجام نشد.'];
    }
    return pay_accept($payment, $paid, (string) ($res['authority'] ?? $authority), $res);
}

/* AbanPay (AbanGateway / ایران‌پی ۴) ───────────────────────────────────── */

/** Safely extract a scalar from provider response paths such as data.token. */
function pay_response_value(array $response, array $paths): string
{
    foreach ($paths as $path) {
        $value = $response;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    return '';
}

function pay_abanpay_endpoint(): string
{
    $endpoint = trim(set_get('pay_iranpay4_endpoint', ''));
    /* Compatibility only for databases not yet through the safe migration. */
    if ($endpoint === '') {
        $endpoint = trim(gw_key2('iranpay4'));
    }
    return gw_https_url($endpoint) ? rtrim($endpoint, '/') : '';
}

/** Mirrors Mirza 0.9's AbanGateway contract: POST /create, Bearer API key,
    amount/order_id/callback_url, and its canonical payment_link response. */
function pay_abanpay_create(array $payment, array $order): array
{
    $endpoint = pay_abanpay_endpoint();
    $key = gw_key('iranpay4');
    if ($key === '') {
        return ['ok' => false, 'url' => '', 'text' => 'کلید API ابان‌پی ثبت نشده است.'];
    }
    if ($endpoint === '') {
        return ['ok' => false, 'url' => '', 'text' => 'آدرس HTTPS سرویس AbanPay ثبت نشده است.'];
    }
    $res = pay_json_post($endpoint . '/create', [
        'amount'       => (int) $payment['amount'],
        'order_id'     => (string) $order['ref'],
        'callback_url' => pay_callback_url('iranpay4'),
    ], ['Authorization: Bearer ' . $key]);
    $url = pay_response_value($res, ['payment_link', 'payment_url', 'pay_url', 'url',
        'data.payment_link', 'data.payment_url', 'data.pay_url', 'data.url']);
    if ($url === '' || preg_match('~^https://~i', $url) !== 1) {
        return ['ok' => false, 'url' => '', 'text' => 'AbanPay: '
            . pay_error_text($res, 'لینک پرداخت معتبر برنگشت')];
    }
    $authority = pay_response_value($res, ['authority', 'token', 'transaction_id',
        'data.authority', 'data.token', 'data.transaction_id']);
    /* The reference Aban contract identifies the return by order_id; authority
       may legitimately be absent, so the immutable order reference is retained. */
    pay_set_authority((int) $payment['id'], $authority !== '' ? $authority : (string) $order['ref'], $res);
    return ['ok' => true, 'url' => $url, 'text' => 'AbanPay'];
}

function pay_abanpay_verify(array $payment, array $params): array
{
    $endpoint = pay_abanpay_endpoint();
    $expectedOrder = (string) (pay_meta($payment)['order_ref'] ?? '');
    $orderRef = trim((string) ($params['order_id'] ?? $params['order'] ?? $params['invoice_id'] ?? ''));
    if ($endpoint === '' || $expectedOrder === '' || $orderRef === ''
        || !hash_equals($expectedOrder, $orderRef)) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'این پرداخت به سفارش دیگری تعلق دارد.'];
    }
    $authority = trim((string) ($params['authority'] ?? $params['token'] ?? $payment['authority'] ?? ''));
    $res = pay_json_post($endpoint . '/verify', [
        'order_id'  => $orderRef,
        'authority' => $authority,
        'amount'    => (int) $payment['amount'],
    ], ['Authorization: Bearer ' . gw_key('iranpay4')]);
    $providerOrder = pay_response_value($res, ['order_id', 'data.order_id']);
    $providerAmount = pay_response_value($res, ['amount', 'data.amount']);
    $paid = gw_amount_toman((float) $providerAmount, (int) $payment['amount']);
    $providerState = strtolower(pay_response_value($res, ['status', 'data.status', 'message', 'data.message']));
    $success = !empty($res['success']) || in_array($providerState, ['paid', 'verified', 'success', 'completed'], true);
    if ((int) ($res['_status'] ?? 0) !== 200 || !$success
        || !hash_equals($orderRef, $providerOrder)
        || $paid + 0.000001 < (int) $payment['amount']) {
        return ['ok' => false, 'state' => 'failed',
            'text' => 'AbanPay: مبلغ، وضعیت یا کد سفارش تأیید نشد.'];
    }
    $reference = pay_response_value($res, ['ref_id', 'reference', 'transaction_id',
        'authority', 'data.ref_id', 'data.reference', 'data.transaction_id']);
    return pay_accept($payment, $paid, $reference !== '' ? $reference : $authority, $res);
}

/* تتراپی v1 ───────────────────────────────────────────────────────────── */

/**
 * TetraPay v1 takes Rial, while SIBIL stores Toman. New checkout creation and
 * verification always use the provider's fixed production v1 API.
 */
function tetrapay_base_url(): string
{
    return 'https://api.tetra98.com/api/v1';
}

/**
 * Tetra v1 accepts only a genuinely public HTTPS return URL. A syntactically
 * valid localhost URL is still unreachable by the provider, so do not let it
 * pass merely because it has an HTTPS scheme.
 */
function tetrapay_public_https_url(string $url): bool
{
    if (!gw_https_url($url)) {
        return false;
    }
    $parts = parse_url($url);
    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    $host = trim($host, '[]');
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false
        || !str_contains($host, '.')
        || preg_match('~(^|\.)(?:localhost|local)$~i', $host) === 1) {
        return false;
    }
    return true;
}

/** Build the documented callback path without carrying an unsafe query/fragment. */
function tetrapay_callback_from_base(string $base): string
{
    $base = trim($base);
    if ($base === '' || str_contains($base, '?') || str_contains($base, '#')) {
        return '';
    }
    $url = rtrim($base, '/') . '/pay/tetrapay';
    return tetrapay_public_https_url($url) ? $url : '';
}

/**
 * Tetra validates the callback against the installed public website. Prefer
 * the installer/runtime URL, then PANEL_DOMAIN, before the legacy public-web
 * setting used by older SIBIL deployments.
 */
function tetrapay_callback_url(): string
{
    $candidates = [];
    $web = trim((string) cfg('PANEL_WEB_URL', ''));
    if ($web !== '') {
        $candidates[] = $web;
    }
    $domain = trim((string) cfg('PANEL_DOMAIN', ''));
    if ($domain !== '') {
        $candidates[] = str_contains($domain, '://') ? $domain : 'https://' . $domain;
    }
    $legacy = trim(web_base());
    if ($legacy !== '') {
        $candidates[] = $legacy;
    }
    foreach ($candidates as $base) {
        $url = tetrapay_callback_from_base($base);
        if ($url !== '') {
            return $url;
        }
    }
    return '';
}

function tetrapay_webhook_url(): string
{
    if (gw_key2('tetrapay') === '') {
        return '';
    }
    $callback = tetrapay_callback_url();
    return $callback === '' ? '' : $callback . '/webhook';
}

function tetrapay_external_order_id(array $order, array $payment): string
{
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($order['ref'] ?? '')) ?: 'order';
    return mb_substr('sibil-' . $ref . '-' . (int) ($payment['id'] ?? 0), 0, 80);
}

function tetrapay_error(array $res, string $fallback = 'ساخت لینک تتراپی انجام نشد.'): string
{
    $error = is_array($res['error'] ?? null) ? $res['error'] : [];
    $code = strtoupper(trim((string) ($error['code'] ?? '')));
    $retry = max(0, (int) ($error['retryAfter'] ?? 0));
    return match ($code) {
        'UNAUTHORIZED' => 'کلید API تتراپی پذیرفته نشد یا باطل شده است.',
        'GATEWAY_SUSPENDED' => 'درگاه تتراپی در پنل پذیرنده غیرفعال یا تعلیق شده است.',
        'NO_METHOD_AVAILABLE' => 'هیچ روش پرداخت فعالی در پنل تتراپی روشن نیست.',
        'INVALID_CALLBACK' => 'تتراپی آدرس بازگشت را نپذیرفت. دامنهٔ عمومی HTTPS ربات و مسیر /pay/tetrapay را در SIBIL بررسی کنید.',
        'INVALID_WEBHOOK', 'WEBHOOK_NOT_CONFIGURED' => 'وب‌هوک اختیاری تتراپی پذیرفته نشد؛ بررسی بازگشت پرداخت ادامه دارد.',
        'AMOUNT_OUT_OF_RANGE' => 'مبلغ خارج از بازهٔ مجاز تتراپی است.',
        'RATE_LIMITED' => $retry > 0
            ? 'تعداد درخواست‌های تتراپی زیاد است؛ ' . $retry . ' ثانیه دیگر دوباره تلاش کنید.'
            : 'تعداد درخواست‌های تتراپی موقتاً زیاد است؛ کمی بعد دوباره تلاش کنید.',
        'DAILY_LIMIT_REACHED' => 'سقف روزانهٔ درگاه تتراپی پر شده است.',
        default => ($code !== '' ? 'تتراپی: ' . $code : pay_error_text($res, $fallback)),
    };
}

function tetrapay_data(array $res): array
{
    return is_array($res['data'] ?? null) ? $res['data'] : $res;
}

function tetrapay_rial(int $toman): int
{
    return $toman > 0 && $toman <= intdiv(PHP_INT_MAX, 10) ? $toman * 10 : 0;
}

function tetrapay_same_amount(mixed $actual, int $expectedRial): bool
{
    $actual = trim((string) $actual);
    if ($expectedRial <= 0 || preg_match('/^\d+$/', $actual) !== 1) {
        return false;
    }
    return ltrim($actual, '0') === (string) $expectedRial
        || ($expectedRial === 0 && $actual === '0');
}

function pay_tetrapay_create(array $payment, array $order): array
{
    if (gw_key('tetrapay') === '') {
        return ['ok' => false, 'url' => '', 'text' => 'کلید API تتراپی ثبت نشده است.'];
    }
    $callback = tetrapay_callback_url();
    if ($callback === '') {
        return ['ok' => false, 'url' => '', 'text' => 'سامانهٔ بازگشت امن تتراپی در این نصب آماده نیست؛ با پشتیبانی تماس بگیرید.'];
    }
    $amountRial = tetrapay_rial((int) ($payment['amount'] ?? 0));
    if ($amountRial <= 0) {
        return ['ok' => false, 'url' => '', 'text' => 'مبلغ پرداخت تتراپی معتبر نیست.'];
    }
    $base = tetrapay_base_url();
    $externalOrder = tetrapay_external_order_id($order, $payment);
    $meta = pay_meta($payment);
    $meta['tetrapay_order_id'] = $externalOrder;
    $meta['tetrapay_amount_rial'] = (string) $amountRial;
    $meta['tetrapay_endpoint'] = $base;
    $meta['tetrapay_callback'] = $callback;
    pay_save_meta((int) $payment['id'], $meta);

    /* Tetra v1 documents these exact create fields. Do not send an inherited
       optional webhook/key2 field: current SIBIL setup is one API key plus the
       provider-validated HTTPS return URL; settlement remains server-side verify. */
    $body = [
        'amount'       => (string) $amountRial,
        'order_id'     => $externalOrder,
        'callback_url' => $callback,
    ];
    $res = pay_json_post($base . '/payments', $body,
        ['Authorization: Bearer ' . gw_key('tetrapay')]);
    $data = tetrapay_data($res);
    $token = trim((string) ($data['token'] ?? ''));
    $url = trim((string) ($data['payment_url'] ?? ''));
    $botUrl = trim((string) ($data['bot_payment_url'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{6,191}$/', $token) !== 1
        || preg_match('~^https://~i', $url) !== 1) {
        return ['ok' => false, 'url' => '', 'text' => tetrapay_error($res)];
    }
    pay_set_authority((int) $payment['id'], $token, $res);
    $fresh = pay_get((int) $payment['id']) ?? $payment;
    $meta = pay_meta($fresh);
    $meta['checkout_url'] = $url;
    if (preg_match('~^https://~i', $botUrl) === 1) {
        $meta['telegram_checkout_url'] = $botUrl;
    }
    pay_save_meta((int) $payment['id'], $meta);
    return ['ok' => true, 'url' => $url, 'alt_url' => $meta['telegram_checkout_url'] ?? '',
            'text' => 'تتراپی', 'payment' => (int) $payment['id']];
}

function pay_tetrapay_verify(array $payment, array $params = []): array
{
    $token = trim((string) ($params['token'] ?? $params['Token'] ?? $payment['authority'] ?? ''));
    if ($token === '' || !hash_equals((string) ($payment['authority'] ?? ''), $token)) {
        return ['ok' => false, 'state' => 'notfound', 'text' => 'توکن تتراپی با پرداخت ربات مطابقت ندارد.'];
    }
    $meta = pay_meta($payment);
    /* Tetra is intentionally fixed to its official production v1 API. */
    $base = tetrapay_base_url();
    $res = pay_json_post(rtrim($base, '/') . '/payments/' . rawurlencode($token) . '/verify', [],
        ['Authorization: Bearer ' . gw_key('tetrapay')]);
    $data = tetrapay_data($res);
    $state = strtolower(trim((string) ($data['status'] ?? '')));
    if ($state === 'expired') {
        $fresh = pay_get((int) $payment['id']) ?? $payment;
        if ((string) $fresh['status'] === 'unpaid') {
            pay_expire_one($fresh);
        }
        return ['ok' => false, 'state' => 'expired', 'text' => 'لینک پرداخت تتراپی منقضی شده است.'];
    }
    if ($state !== 'paid') {
        if ((int) ($res['_status'] ?? 0) >= 400) {
            return ['ok' => false, 'state' => 'waiting', 'text' => tetrapay_error($res, 'استعلام تتراپی انجام نشد.')];
        }
        return ['ok' => false, 'state' => 'waiting', 'text' => 'پرداخت تتراپی هنوز تأیید نشده است.'];
    }
    $expectedOrder = (string) ($meta['tetrapay_order_id'] ?? '');
    $expectedRial = (int) ($meta['tetrapay_amount_rial'] ?? tetrapay_rial((int) $payment['amount']));
    if ($expectedOrder === '' || !hash_equals($expectedOrder, (string) ($data['order_id'] ?? ''))
        || !tetrapay_same_amount($data['amount'] ?? '', $expectedRial)) {
        log_payment('tetrapay verify mismatch', ['payment' => (int) $payment['id']]);
        return ['ok' => false, 'state' => 'failed', 'text' => 'مبلغ یا شمارهٔ سفارش تتراپی با فاکتور مطابقت ندارد.'];
    }
    if (isset($data['received_amount']) && $data['received_amount'] !== null
        && (string) $data['received_amount'] !== ''
        && (!tetrapay_same_amount($data['received_amount'], $expectedRial))) {
        return ['ok' => false, 'state' => 'failed', 'text' => 'پرداخت تتراپی ناقص است و شارژ نشد.'];
    }
    return pay_accept($payment, (float) ((int) $expectedRial / 10),
        (string) ($data['transaction_id'] ?? $data['reference'] ?? $token), $res);
}

/** امضای وب‌هوک v1: X-TetraPay-Signature: t=unix,v1=HMAC_SHA256(t.raw_body). */
function tetrapay_webhook_signature_ok(string $raw, string $signature, string $secret): bool
{
    if ($raw === '' || $signature === '' || $secret === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $signature) as $piece) {
        [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
        if ($key !== '' && $value !== '') {
            $parts[trim($key)] = trim($value);
        }
    }
    $timestamp = (string) ($parts['t'] ?? '');
    $given = strtolower((string) ($parts['v1'] ?? ''));
    if (preg_match('/^\d+$/', $timestamp) !== 1 || preg_match('/^[a-f0-9]{64}$/', $given) !== 1
        || abs(now_ts() - (int) $timestamp) > 300) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $given);
}

function pay_tetrapay_webhook(array $body, string $raw, string $signature): array
{
    $secret = gw_key2('tetrapay');
    if ($secret === '') {
        return ['ok' => false, 'code' => 500, 'text' => 'webhook not configured'];
    }
    if (!tetrapay_webhook_signature_ok($raw, $signature, $secret)) {
        return ['ok' => false, 'code' => 403, 'text' => 'invalid signature'];
    }
    $event = is_array($body['data'] ?? null) ? ($body['data'] + $body) : $body;
    $type = strtolower((string) ($event['event'] ?? $event['type'] ?? ''));
    $state = strtolower((string) ($event['status'] ?? ''));
    if ($type !== 'payment.paid' && $state !== 'paid') {
        return ['ok' => true, 'code' => 200, 'text' => 'ignored'];
    }
    $token = trim((string) ($event['token'] ?? ''));
    $payment = $token !== '' ? DB::one('SELECT * FROM payments WHERE authority=? AND method=? '
        . 'ORDER BY id DESC LIMIT 1', [$token, 'tetrapay']) : null;
    if ($payment === null) {
        return ['ok' => false, 'code' => 404, 'text' => 'payment not found'];
    }
    if ((string) $payment['status'] === 'paid') {
        return ['ok' => true, 'code' => 200, 'text' => 'already'];
    }
    if (!in_array((string) $payment['status'], ['unpaid', 'expire'], true)) {
        return ['ok' => false, 'code' => 409, 'text' => 'payment closed'];
    }
    $meta = pay_meta($payment);
    $expectedOrder = (string) ($meta['tetrapay_order_id'] ?? '');
    $expectedRial = (int) ($meta['tetrapay_amount_rial'] ?? tetrapay_rial((int) $payment['amount']));
    if (($event['order_id'] ?? '') !== $expectedOrder
        || !tetrapay_same_amount($event['amount'] ?? '', $expectedRial)) {
        return ['ok' => false, 'code' => 400, 'text' => 'payment mismatch'];
    }
    /* وب‌هوک امضاشده سریع است، ولی نتیجه را یک بار دیگر از verify می‌خوانیم. */
    $result = pay_tetrapay_verify($payment, ['token' => $token]);
    $state = (string) ($result['state'] ?? 'waiting');
    return ['ok' => (bool) ($result['ok'] ?? false),
            'code' => ($result['ok'] ?? false) ? 200 : ($state === 'waiting' ? 500 : 400),
            'text' => $state];
}


/* NowPayments ──────────────────────────────────────────────────────────── */

function pay_nowpay_create(array $payment, array $order): array
{
    $key = gw_key('nowpayment');
    if ($key === '') {
        return ['ok' => false, 'url' => '', 'text' => 'کلید NowPayments ثبت نشده است.'];
    }
    $meta = pay_meta($payment);
    $res = pay_json_post(gw_endpoint('nowpayment', gw_endpoint_default('nowpayment'))
        . '/v1/invoice', [
        'price_amount'      => (float) ($meta['fiat_amount'] ?? 0),
        'price_currency'    => strtoupper((string) ($meta['fiat_currency'] ?? 'usd')),
        'order_id'          => (string) $order['ref'],
        'order_description' => 'Order ' . $order['ref'],
        'ipn_callback_url'  => pay_callback_url('nowpayment'),
        'success_url'       => web_base() . '/pay/nowpayment?return=1',
        'cancel_url'        => web_base() . '/pay/nowpayment?cancel=1',
    ], ['x-api-key: ' . $key]);
    $url = (string) ($res['invoice_url'] ?? '');
    $invoiceId = (string) ($res['id'] ?? '');
    if ($url === '' || $invoiceId === '') {
        return ['ok' => false, 'url' => '', 'text' => 'NowPayments: '
            . pay_error_text($res, 'لینک پرداخت ساخته نشد')];
    }
    pay_set_authority((int) $payment['id'], $invoiceId, $res);
    return ['ok' => true, 'url' => $url, 'text' => 'NowPayments'];
}

function pay_nowpay_status(array $payment): array
{
    $invoiceId = (string) $payment['authority'];
    if ($invoiceId === '') {
        return ['ok' => false, 'state' => 'waiting', 'text' => 'شناسهٔ فاکتور ذخیره نشده است.'];
    }
    $res = pay_json_get(gw_endpoint('nowpayment', gw_endpoint_default('nowpayment'))
        . '/v1/invoice/' . urlencode($invoiceId),
        ['x-api-key: ' . gw_key('nowpayment')]);
    $status = strtolower((string) ($res['payment_status'] ?? $res['status'] ?? ''));
    $paid = (float) ($res['price_amount'] ?? ($res['amount'] ?? 0));
    if (in_array($status, ['finished', 'confirmed'], true)) {
        return pay_accept($payment, $paid, $invoiceId, $res);
    }
    if (in_array($status, ['failed', 'refunded', 'expired'], true)) {
        $fresh = pay_get((int) $payment['id']) ?? $payment;
        if ((string) $fresh['status'] === 'unpaid') {
            pay_expire_one($fresh);
        }
        return ['ok' => false, 'state' => 'expired', 'text' => 'NowPayments: ' . $status];
    }
    return ['ok' => false, 'state' => 'waiting',
            'text' => 'NowPayments: وضعیت ' . ($status !== '' ? $status : 'نامشخص')];
}

/** IPN نواپی‌منت — امضا با ipn_secret بررسی می‌شود، بعد وضعیت از خود API خوانده می‌شود */
function pay_nowpay_webhook(array $body, string $rawBody, string $signature): array
{
    $secret = gw_key2('nowpayment');
    if ($secret !== '' && $signature !== '') {
        $sorted = $body;
        ksort($sorted);
        $expected = hash_hmac('sha512', json_encode($sorted, JSON_UNESCAPED_SLASHES), $secret);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'code' => 403, 'text' => 'invalid signature'];
        }
    } elseif ($secret !== '') {
        return ['ok' => false, 'code' => 403, 'text' => 'missing signature'];
    }
    $invoiceId = (string) ($body['invoice_id'] ?? '');
    $orderRef = (string) ($body['order_id'] ?? '');
    $payment = $invoiceId !== '' ? pay_find_by_authority($invoiceId) : null;
    if ($payment === null && $orderRef !== '') {
        $order = DB::one('SELECT * FROM orders WHERE ref=?', [$orderRef]);
        if ($order !== null) {
            $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='nowpayment' "
                . "AND status IN ('unpaid','expire') ORDER BY id DESC LIMIT 1", [(int) $order['id']]);
        }
    }
    if ($payment === null) {
        return ['ok' => false, 'code' => 404, 'text' => 'order not found'];
    }
    /* هرگز به بدنهٔ IPN اعتماد نمی‌کنیم؛ وضعیت را از API می‌خوانیم */
    $result = pay_nowpay_status($payment);
    return ['ok' => (bool) ($result['ok'] ?? false), 'code' => 200,
            'text' => (string) ($result['state'] ?? 'waiting')];
}

/* Plisio ───────────────────────────────────────────────────────────────── */

function pay_plisio_create(array $payment, array $order): array
{
    $key = gw_key('plisio');
    if ($key === '') {
        return ['ok' => false, 'url' => '', 'text' => 'کلید Plisio ثبت نشده است.'];
    }
    $meta = pay_meta($payment);
    $amount = (float) ($meta['fiat_amount'] ?? 0);
    if ($amount <= 0) {
        return ['ok' => false, 'url' => '', 'text' => 'نرخ تبدیل Plisio تنظیم نشده است.'];
    }
    $query = http_build_query([
        'api_key'         => $key,
        'source_currency' => strtoupper((string) ($meta['fiat_currency'] ?? 'USD')),
        'source_amount'   => $amount,
        'order_number'    => (string) $order['ref'],
        'order_name'      => 'Order ' . $order['ref'],
        'language'        => 'fa',
        'callback_url'    => pay_callback_url('plisio') . '?json=true',
        'success_callback_url' => web_base() . '/pay/plisio?return=1',
        'fail_callback_url'    => web_base() . '/pay/plisio?fail=1',
        'expire_min'      => 60,
        'plugin'          => 'panel-service',
        'version'         => PANEL_VERSION,
    ]);
    $res = pay_json_get(gw_endpoint('plisio', gw_endpoint_default('plisio'))
        . '/api/v1/invoices/new?' . $query);
    $url = (string) ($res['data']['invoice_url'] ?? '');
    $txnId = (string) ($res['data']['txn_id'] ?? '');
    if (strtolower((string) ($res['status'] ?? '')) !== 'success' || $url === '' || $txnId === '') {
        return ['ok' => false, 'url' => '', 'text' => 'Plisio: ' . plisio_error($res)];
    }
    pay_set_authority((int) $payment['id'], $txnId, $res);
    return ['ok' => true, 'url' => $url, 'text' => 'Plisio'];
}

function plisio_error(array $res): string
{
    $message = $res['data']['message'] ?? ($res['message'] ?? '');
    if (is_array($message)) {
        $message = implode(' · ', array_map('strval', $message));
    }
    $message = trim((string) $message);
    if ($message === '') {
        $message = pay_error_text($res, 'خطای نامشخص از Plisio');
    }
    $map = [
        'api key' => 'کلید API پذیرفته نشد.',
        'min sum' => 'مبلغ از حداقل مجاز این درگاه کمتر است.',
        'currency' => 'ارز انتخاب‌شده برای این درگاه پشتیبانی نمی‌شود.',
    ];
    foreach ($map as $needle => $fa) {
        if (stripos($message, $needle) !== false) {
            return $fa . ' (' . mb_substr($message, 0, 90) . ')';
        }
    }
    return mb_substr($message, 0, 140);
}

function pay_plisio_status(array $payment): array
{
    $txnId = (string) $payment['authority'];
    if ($txnId === '') {
        return ['ok' => false, 'state' => 'waiting', 'text' => 'شناسهٔ فاکتور Plisio ذخیره نشده است.'];
    }
    $res = pay_json_get(gw_endpoint('plisio', gw_endpoint_default('plisio'))
        . '/api/v1/operations?' . http_build_query([
        'api_key' => gw_key('plisio'),
        'search'  => $txnId,
        'limit'   => 20,
    ]));
    $operations = $res['data']['operations'] ?? ($res['data'] ?? []);
    if (!is_array($operations)) {
        $operations = [];
    }
    $match = null;
    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            continue;
        }
        /* فقط همان فاکتور خودمان، نه هر عملیات دیگری */
        if ((string) ($operation['txn_id'] ?? '') !== $txnId) {
            continue;
        }
        $orderNumber = (string) ($operation['order_number'] ?? ($operation['params']['order_number'] ?? ''));
        $expectedRef = (string) (pay_meta($payment)['order_ref'] ?? '');
        if ($orderNumber !== '' && $expectedRef !== '' && $orderNumber !== $expectedRef) {
            continue;
        }
        $match = $operation;
        break;
    }
    if ($match === null) {
        return ['ok' => false, 'state' => 'waiting', 'text' => 'Plisio: تراکنش هنوز ثبت نشده.'];
    }
    $status = strtolower((string) ($match['status'] ?? ''));
    $paid = (float) ($match['source_amount'] ?? ($match['invoice_total_sum'] ?? 0));
    if ($status === 'completed') {
        return pay_accept($payment, $paid > 0 ? $paid : pay_expected($payment), $txnId, $match);
    }
    if (in_array($status, ['cancelled', 'expired', 'error'], true)) {
        $fresh = pay_get((int) $payment['id']) ?? $payment;
        if ((string) $fresh['status'] === 'unpaid') {
            pay_expire_one($fresh);
        }
        return ['ok' => false, 'state' => 'expired', 'text' => 'Plisio: ' . $status];
    }
    return ['ok' => false, 'state' => 'waiting', 'text' => 'Plisio: وضعیت ' . ($status !== '' ? $status : 'نامشخص')];
}

/** IPN پلیسیو — verify_hash با کلید API بررسی می‌شود */
function pay_plisio_webhook(array $fields, string $rawBody, string $secret): array
{
    if ($secret === '') {
        return ['ok' => false, 'code' => 500, 'text' => 'not configured'];
    }
    $given = (string) ($fields['verify_hash'] ?? '');
    if ($given === '') {
        return ['ok' => false, 'code' => 400, 'text' => 'missing verify_hash'];
    }
    $clean = $fields;
    unset($clean['verify_hash']);
    ksort($clean);
    if (isset($clean['expire_utc'])) {
        $clean['expire_utc'] = (string) $clean['expire_utc'];
    }
    if (isset($clean['tx_urls'])) {
        $clean['tx_urls'] = html_entity_decode((string) $clean['tx_urls']);
    }
    $variants = [
        serialize($clean),                                   // تمپلیت رسمی PHP
        json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        trim($rawBody),
    ];
    $valid = false;
    foreach ($variants as $payload) {
        foreach (['sha1', 'sha256'] as $algo) {
            if (hash_equals(hash_hmac($algo, $payload, $secret), $given)) {
                $valid = true;
                break 2;
            }
        }
    }
    if (!$valid) {
        return ['ok' => false, 'code' => 403, 'text' => 'invalid verify_hash'];
    }
    $orderRef = (string) ($fields['order_number'] ?? '');
    $txnId = (string) ($fields['txn_id'] ?? '');
    $payment = $txnId !== '' ? pay_find_by_authority($txnId) : null;
    if ($payment === null && $orderRef !== '') {
        $order = DB::one('SELECT * FROM orders WHERE ref=?', [$orderRef]);
        if ($order !== null) {
            $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='plisio' "
                . "AND status IN ('unpaid','expire') ORDER BY id DESC LIMIT 1", [(int) $order['id']]);
        }
    }
    if ($payment === null) {
        return ['ok' => false, 'code' => 404, 'text' => 'order not found'];
    }
    /* باز هم وضعیت را از API می‌خوانیم؛ بدنه فقط «تلنگر» است */
    $result = pay_plisio_status($payment);
    return ['ok' => (bool) ($result['ok'] ?? false), 'code' => 200,
            'text' => (string) ($result['state'] ?? 'waiting')];
}

/* واریزا ──────────────────────────────────────────────────────────────── */

/** Mirrors the Mirza 0.9 Variza contract.  Variza settles only from its signed
    payment.paid webhook; the browser return is deliberately informational. */
function pay_variza_create(array $payment, array $order): array
{
    $token = gw_key('variza');
    if ($token === '') {
        return ['ok' => false, 'url' => '', 'text' => 'توکن API واریزا ثبت نشده است.'];
    }
    if (gw_key2('variza') === '') {
        return ['ok' => false, 'url' => '', 'text' => 'کلید امضای وب‌هوک واریزا ثبت نشده است.'];
    }
    $callback = pay_callback_url('variza');
    if (!gw_https_url($callback)) {
        return ['ok' => false, 'url' => '', 'text' => 'برای واریزا ابتدا آدرس HTTPS کال‌بک را ثبت کنید.'];
    }
    $res = pay_json_post(gw_endpoint('variza', gw_endpoint_default('variza')) . '/api/v1/pay', [
        'amount'     => (int) $payment['amount'],
        'return_url' => $callback . '?order=' . urlencode((string) $order['ref']),
        'title'      => bot_name() . ' order ' . (string) $order['ref'],
        'expires_in' => pay_variza_expiry(),
    ], ['Authorization: Bearer ' . $token]);
    $url = pay_response_value($res, ['pay_url', 'payment_url', 'url',
        'data.pay_url', 'data.payment_url', 'data.url']);
    $slug = pay_response_value($res, ['slug', 'payment_id', 'data.slug', 'data.payment_id']);
    if ($url === '' || preg_match('~^https://~i', $url) !== 1 || $slug === '') {
        return ['ok' => false, 'url' => '', 'text' => 'واریزا: '
            . pay_error_text($res, 'لینک یا شناسهٔ پرداخت معتبر برنگشت')];
    }
    pay_set_authority((int) $payment['id'], $slug, $res);
    return ['ok' => true, 'url' => $url, 'text' => 'Variza'];
}

function pay_variza_webhook(array $body, string $raw, string $signature): array
{
    $secret = gw_key2('variza');
    if ($secret === '') {
        return ['ok' => false, 'code' => 500, 'text' => 'not configured'];
    }
    $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
    if ($provided === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $provided)) {
        return ['ok' => false, 'code' => 403, 'text' => 'invalid signature'];
    }
    if ((string) ($body['event'] ?? '') !== 'payment.paid') {
        return ['ok' => true, 'code' => 200, 'text' => 'ignored'];
    }
    $slug = trim((string) ($body['slug'] ?? ''));
    if ($slug === '') {
        return ['ok' => false, 'code' => 400, 'text' => 'missing slug'];
    }
    /* Do not use pay_find_by_authority here: retries after a successful payment
       must return "already" rather than pretending the order disappeared. */
    $payment = DB::one('SELECT * FROM payments WHERE authority=? AND method=? ORDER BY id DESC LIMIT 1',
        [$slug, 'variza']);
    if ($payment === null) {
        return ['ok' => false, 'code' => 404, 'text' => 'order not found'];
    }
    if ((string) $payment['status'] === 'paid') {
        return ['ok' => true, 'code' => 200, 'text' => 'already'];
    }
    if (!in_array((string) $payment['status'], ['unpaid', 'expire'], true)) {
        return ['ok' => false, 'code' => 409, 'text' => 'payment is not open'];
    }
    $amount = (float) ($body['amount'] ?? 0);
    if ($amount + 0.000001 < (int) $payment['amount']) {
        log_line('warning', 'variza amount mismatch', ['payment' => (int) $payment['id'],
            'expected' => (int) $payment['amount'], 'paid' => $amount]);
        return ['ok' => false, 'code' => 400, 'text' => 'amount mismatch'];
    }
    $result = pay_accept($payment, $amount, $slug, $body);
    return $result + ['code' => 200];
}

/* TRX ─────────────────────────────────────────────────────────────────── */

function pay_trx_create(array $payment, array $order): array
{
    $res = pay_json_post(gw_endpoint('trx', gw_endpoint_default('trx'))
        . '/api/factor/create', [
        'amount'  => (int) $payment['amount'],
        'address' => gw_key2('trx'),
        'base'    => 'trx',
    ], ['Authorization: Token ' . gw_key('trx')]);
    $url = (string) ($res['payment_url'] ?? ($res['url'] ?? ($res['link'] ?? '')));
    $ref = (string) ($res['id'] ?? ($res['factor_id'] ?? ''));
    if ($url === '' || $ref === '') {
        return ['ok' => false, 'url' => '', 'text' => 'درگاه TRX: '
            . pay_error_text($res, 'فاکتور ساخته نشد')];
    }
    pay_set_authority((int) $payment['id'], $ref, $res);
    return ['ok' => true, 'url' => $url, 'text' => 'TRX'];
}

function pay_trx_verify(array $payment): array
{
    $id = (string) $payment['authority'];
    if ($id === '') {
        return ['ok' => false, 'state' => 'waiting', 'text' => 'شناسهٔ فاکتور TRX ذخیره نشده است.'];
    }
    $res = pay_json_get(gw_endpoint('trx', gw_endpoint_default('trx'))
        . '/api/factor/status?id=' . urlencode($id),
        ['Authorization: Token ' . gw_key('trx')]);
    $status = (string) ($res['data']['status'] ?? ($res['status'] ?? ''));
    if ($status === 'approved') {
        return pay_accept($payment, (float) $payment['amount'], $id, $res);
    }
    return ['ok' => false, 'state' => 'waiting', 'text' => 'TRX: ' . ($status !== '' ? $status : 'نامشخص')];
}

/**
 * بررسی امن پیکربندی درگاه.
 *
 * نسخه‌های پیشین برای «تست زنده» یک فاکتور واقعی می‌ساختند؛ این رفتار هم
 * ناخواسته بود و هم می‌توانست سهمیه/تراکنش واقعی بسازد. این بررسی فقط
 * تنظیمات محلی، URL و آدرس callback را می‌سنجد و هیچ درخواست پرداختی ارسال
 * نمی‌کند. verify واقعی فقط از callback/webhook یک پرداختی که خود کاربر آغاز
 * کرده اجرا می‌شود.
 */
function gw_live_test(string $gw): array
{
    if (!isset(PAY_GATEWAYS[$gw]) || in_array($gw, ['wallet', 'card', 'stars'], true)) {
        return ['ok' => false, 'title' => 'درگاه نامعتبر',
                'body' => 'برای این نوع پرداخت بررسی خودکار لازم نیست.'];
    }
    $issues = [];
    $notes = [];
    $endpoint = $gw === 'tetrapay' ? tetrapay_base_url()
        : gw_endpoint($gw, gw_endpoint_default($gw));
    if (gw_key($gw) === '') {
        $issues[] = 'کلید درگاه ثبت نشده است.';
    } else {
        $notes[] = 'کلید درگاه ثبت شده است.';
    }
    if (!gw_https_url($endpoint)) {
        $issues[] = 'آدرس سرویس باید HTTPS معتبر باشد.';
    } else {
        $notes[] = 'آدرس HTTPS سرویس معتبر است.';
    }
    $callback = $gw === 'tetrapay' ? tetrapay_callback_url() : pay_callback_url($gw);
    if (!gw_https_url($callback)) {
        $issues[] = $gw === 'tetrapay'
            ? 'آدرس عمومی HTTPS نصب برای بازگشت تتراپی آماده نیست.'
            : 'آدرس وب ربات باید یک دامنهٔ HTTPS عمومی باشد تا callback درگاه کار کند.';
    } else {
        $notes[] = 'callback HTTPS آماده است: ' . $callback;
    }
    if ($gw === 'tetrapay') {
        $notes[] = 'تتراپی با API رسمی production v1 و بازگشت HTTPS آماده است.';
        $notes[] = 'برای اطمینان از دسترس‌پذیری عمومی، سلامت /pay/tetrapay را از SIBIL Domain / SSL بررسی کنید.';
    }
    return [
        'ok'       => $issues === [],
        'title'    => $issues === [] ? 'پیکربندی برای استفاده آماده است' : 'پیکربندی کامل نیست',
        'body'     => implode("\n", array_merge($issues, $notes)),
        'endpoint' => $endpoint,
        'amount'   => 0,
    ];
}

/* استارز تلگرام ────────────────────────────────────────────────────────── */
function pay_stars(array $order): array
{
    $order = order_get((int) ($order['id'] ?? 0)) ?? $order;
    if ((string) ($order['status'] ?? '') !== 'pending' || order_cancel_if_payment_expired($order)) {
        return ['ok' => false, 'url' => '', 'state' => 'expired',
            'text' => 'مهلت این فاکتور تمام شده است؛ یک سفارش تازه بسازید.'];
    }
    $amount = (int) $order['payable'];
    /* Reuse the same real payment row across a retry. The invoice payload is
       bound to that row, so a successful Telegram update cannot settle a
       similarly numbered order or an unrelated Stars invoice. */
    $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='stars' AND status='unpaid' "
        . 'ORDER BY id DESC LIMIT 1', [(int) $order['id']]);
    $reused = $payment !== null;
    if ($payment === null) {
        $payment = pay_new($order, 'stars', $amount);
        if ($payment === []) {
            return ['ok' => false, 'url' => '', 'text' => 'ثبت فاکتور استارز ناموفق بود'];
        }
    }
    $meta = pay_meta($payment);
    $savedUrl = trim((string) ($meta['checkout_url'] ?? ''));
    if ($reused && gw_https_url($savedUrl)) {
        return ['ok' => true, 'url' => $savedUrl, 'text' => 'Telegram Stars',
            'payment' => (int) $payment['id'], 'reused' => true];
    }
    $rate = star_toman_rate();
    if ($rate < 100) {
        $rate = max(100, set_int('stars_rate', 1000));
    }
    $stars = max(1, (int) ceil($amount / $rate));
    $payload = 'order:' . (int) $order['id'] . ':payment:' . (int) $payment['id'];
    $invoice = [
        'title'        => (string) ($order['kind'] ?? '') === 'topup' ? 'شارژ کیف پول' : 'پرداخت سفارش',
        'description'  => 'سفارش ' . (string) $order['ref'],
        'payload'      => $payload,
        'currency'     => 'XTR',
        'prices'       => [['label' => 'سفارش ' . (string) $order['ref'], 'amount' => $stars]],
    ];
    $meta['stars_payload'] = $payload;
    $meta['stars_amount'] = $stars;
    $meta['stars_rate'] = $rate;
    /* Kept as an audit snapshot; it also makes recovery of an unfinished
       invoice deterministic without trusting any browser-side value. */
    $meta['create'] = ['invoice_payload' => $payload, 'currency' => 'XTR',
        'total_amount' => $stars];
    pay_save_meta((int) $payment['id'], $meta);

    $res = Tg::call('createInvoiceLink', $invoice);
    $rawUrl = $res['result'] ?? '';
    $url = is_string($rawUrl) ? trim($rawUrl) : '';
    if (!gw_https_url($url)) {
        return ['ok' => false, 'url' => '', 'text' => 'ساخت فاکتور استارز ناموفق بود'];
    }
    $fresh = pay_get((int) $payment['id']) ?? $payment;
    $freshMeta = pay_meta($fresh);
    $freshMeta['checkout_url'] = $url;
    pay_save_meta((int) $payment['id'], $freshMeta);
    return ['ok' => true, 'url' => $url, 'text' => 'Telegram Stars',
        'payment' => (int) $payment['id'], 'reused' => $reused];
}

/** Telegram's successful_payment update is the sole authoritative Stars settlement. */
function pay_stars_success(array $message): void
{
    $successful = is_array($message['successful_payment'] ?? null) ? $message['successful_payment'] : [];
    $payload = (string) ($successful['invoice_payload'] ?? '');
    if (preg_match('/^order:(\d+):payment:(\d+)$/', $payload, $matches) !== 1) {
        return;
    }
    $orderId = (int) $matches[1];
    $paymentId = (int) $matches[2];
    $payment = DB::one("SELECT * FROM payments WHERE id=? AND order_id=? AND method='stars'", [$paymentId, $orderId]);
    if ($payment === null) {
        return;
    }
    $meta = pay_meta($payment);
    if (!hash_equals((string) ($meta['stars_payload'] ?? ''), $payload)) {
        log_payment('stars payload mismatch', ['payment' => $paymentId, 'order' => $orderId]);
        return;
    }
    $expectedStars = (int) ($meta['stars_amount'] ?? 0);
    $receivedStars = (int) ($successful['total_amount'] ?? 0);
    if ((string) ($successful['currency'] ?? '') !== 'XTR' || $expectedStars <= 0
        || $receivedStars !== $expectedStars) {
        log_payment('stars amount mismatch', ['payment' => $paymentId, 'order' => $orderId,
            'expected' => $expectedStars, 'received' => $receivedStars]);
        return;
    }
    $chargeId = trim((string) ($successful['telegram_payment_charge_id'] ?? ''));
    if ($chargeId === '') {
        return;
    }
    pay_accept($payment, (float) $payment['amount'], $chargeId, $message);
}

function pay_error_text(array $res, string $fallback): string
{
    foreach (['message', 'error', 'detail', 'errors', '_raw'] as $key) {
        if (!empty($res[$key])) {
            $value = is_array($res[$key]) ? json_encode($res[$key], JSON_UNESCAPED_UNICODE) : $res[$key];
            return mb_substr((string) $value, 0, 140);
        }
    }
    return $fallback;
}

/** منقضی کردن پرداخت‌های رهاشده */
function payments_expire(int $minutes = 1440): int
{
    $minutes = max(1, $minutes);
    $limit = now_ts() - $minutes * 60;
    $closed = 0;
    $rows = DB::all("SELECT * FROM payments WHERE status='unpaid' AND created_at<?", [$limit]);
    foreach ($rows as $row) {
        $closed += pay_expire_one($row) ? 1 : 0;
    }
    /* A manual card invoice has no payments row before its receipt is sent.
       Close only pending orders here; review rows deliberately remain visible
       to finance so an already-submitted receipt is never silently discarded. */
    $closed += DB::update("UPDATE orders SET status='canceled', paid_at=NULL "
        . "WHERE status='pending' AND created_at<?", [$limit]);
    return $closed;
}

/**
 * بستن یک تراکنش: اول تلاش می‌کنیم خودِ درگاه تراکنش را لغو کند، بعد
 * ردیف را قفل می‌کنیم، پیام فاکتور را از چت کاربر پاک می‌کنیم و به او خبر
 * می‌دهیم. بعد از این لحظه حتی اگر درگاه «پرداخت شد» بفرستد، ربات خودش
 * پول را شارژ نمی‌کند و پرونده را برای تصمیم ادمین بالا می‌فرستد.
 */
function pay_expire_one(array $row): bool
{
    $changed = DB::update("UPDATE payments SET status='expire' WHERE id=? AND status='unpaid'",
        [(int) $row['id']]);
    if ($changed === 0) {
        return false;
    }
    $order = order_get((int) $row['order_id']);
    if ($order !== null && $order['status'] === 'pending') {
        order_mark((int) $order['id'], 'canceled');
    }
    /* ۱) لغو سمت درگاه — هر درگاهی که مسیر لغو دارد همین‌جا صدا زده می‌شود */
    $remote = pay_provider_cancel($row);
    log_line('info', 'payment expired', ['payment' => (int) $row['id'],
        'method' => (string) $row['method'], 'remote' => $remote]);
    /* ۲) لینک پرداخت از چت کاربر حذف می‌شود تا دیگر قابل استفاده نباشد */
    $msgId = (int) (pay_meta($row)['msg_id'] ?? 0);
    if ($msgId > 0) {
        Tg::delete((int) $row['tg_id'], $msgId);
    }
    /* ۳) اطلاع به کاربر */
    $minutes = max(1, set_int('pay_expire_minutes', 1440));
    Tg::send((int) $row['tg_id'],
        '⌛️ <b>مهلت پرداخت تمام شد</b>' . "\n\n"
        . 'فاکتور ' . fa_digits($minutes) . ' دقیقه‌ای این سفارش بسته شد و لینک پرداختش '
        . 'غیرفعال شد.' . "\n"
        . 'اگر پولی واریز کرده‌ای، همین‌جا به پشتیبانی بگو تا بررسی شود.',
        [[btn('📦 پنل‌های من', 'menu:panels'), btn('💰 کیف پول', 'menu:wallet')]]);
    return true;
}

/**
 * لغو تراکنش از سمت درگاه.
 * جدول زیر فقط مسیرهایی را دارد که خود ارائه‌دهنده اعلام کرده است؛ برای
 * درگاه‌هایی که API لغو ندارند (NowPayments، Plisio، زرین‌پال…) چیزی از خودمان
 * اختراع نمی‌کنیم و به‌جایش ردیف را قفل می‌کنیم تا هرگز تسویه نشود.
 */
function pay_provider_cancel(array $payment): string
{
    $gw = (string) $payment['method'];
    $authority = trim((string) $payment['authority']);
    if ($authority === '') {
        return 'no-authority';
    }
    $routes = pay_cancel_routes();
    if (!isset($routes[$gw])) {
        return 'provider-has-no-cancel-api';
    }
    $spec = $routes[$gw];
    $key = gw_key($gw);
    if ($key === '') {
        return 'no-key';
    }
    $url = str_replace(['{key}', '{authority}'], [rawurlencode($key), rawurlencode($authority)],
        (string) $spec['url']);
    $body = str_replace(['{key}', '{authority}'], [$key, $authority], (string) ($spec['body'] ?? ''));
    [$code, $raw] = http_request((string) $spec['method'], $url,
        (array) ($spec['headers'] ?? []), $body);
    log_line($code >= 200 && $code < 300 ? 'info' : 'warning',
        'gateway cancel call', ['method' => $gw, 'status' => $code,
            'body' => mb_substr((string) $raw, 0, 200)]);
    return $code >= 200 && $code < 300 ? 'cancelled' : 'cancel-failed:' . $code;
}

/** مسیرهای لغو تراکنش که خودِ درگاه‌ها مستند کرده‌اند (خالی = درگاه چنین API ندارد) */
function pay_cancel_routes(): array
{
    return [];
}

/**
 * بررسی دوره‌ای پرداخت‌های باز.
 * فقط درگاه‌هایی که «تأیید سمت سرور» دارند و فقط با تطبیق شناسهٔ همان پرداخت.
 */
/**
 * واریزی بعد از انقضا: سفارش قبلاً بسته شده، پس شارژ خودکار انجام نمی‌شود.
 * یک پیام با دو دکمه برای مالک می‌رود تا خودش تأیید یا رد کند.
 */
function pay_late_notice(array $payment): void
{
    $key = 'late_notice_' . (int) $payment['id'];
    if (set_get($key) !== '') {
        return;
    }
    set_put($key, (string) now_ts());
    $gw = PAY_GATEWAYS[(string) $payment['method']]['label'] ?? $payment['method'];
    $text = '⚠️ <b>واریزی بعد از انقضای فاکتور</b>' . "\n\n"
        . 'کاربر <code>' . (int) $payment['tg_id'] . '</code>' . "\n"
        . 'درگاه: ' . e((string) $gw) . "\n"
        . 'مبلغ: ' . money((int) $payment['amount']) . "\n"
        . 'شناسهٔ پرداخت: <code>' . (int) $payment['id'] . '</code>' . "\n\n"
        . 'تراکنش منقضی شده و خودکار شارژ نشد. اگر واریز واقعی بوده، خودتان تأیید کنید.';
    $kb = [[btn('✅ تأیید و شارژ', 'ad:late:' . (int) $payment['id'], null, 'success')],
           [btn('❌ رد کردن', 'ad:late:no:' . (int) $payment['id'], null, 'danger')]];
    foreach (array_unique(array_filter([report_chat_id(), (string) owner_id()])) as $target) {
        Tg::send($target, $text, $kb);
    }
}

/** شناسهٔ پیام فاکتور را روی ردیف پرداخت نگه می‌داریم تا هنگام انقضا پاکش کنیم */
function pay_note_message(int $orderId, string $method, int $messageId): void
{
    if ($orderId <= 0 || $messageId <= 0) {
        return;
    }
    $row = DB::one('SELECT * FROM payments WHERE order_id=? AND method=? '
        . "AND status='unpaid' ORDER BY id DESC LIMIT 1", [$orderId, $method]);
    if ($row === null) {
        return;
    }
    $meta = pay_meta($row);
    $meta['msg_id'] = $messageId;
    DB::exec('UPDATE payments SET payload=? WHERE id=?',
        [json_encode($meta, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
}

function payments_poll(): int
{
    $settled = 0;
    $rows = DB::all("SELECT * FROM payments WHERE status='unpaid' "
        . "AND method IN ('trx','plisio','nowpayment','tetrapay') AND authority<>'' "
        . 'ORDER BY id LIMIT 15');
    foreach ($rows as $row) {
        try {
            $result = match ((string) $row['method']) {
                'trx'        => pay_trx_verify($row),
                'plisio'     => pay_plisio_status($row),
                'nowpayment' => pay_nowpay_status($row),
                'tetrapay'   => pay_tetrapay_verify($row),
                default      => ['ok' => false, 'state' => 'waiting'],
            };
        } catch (Throwable $ex) {
            log_line('warning', 'payment poll failed: ' . $ex->getMessage());
            continue;
        }
        if (($result['ok'] ?? false) && ($result['state'] ?? '') === 'success') {
            $settled++;
        }
    }
    return $settled;
}

