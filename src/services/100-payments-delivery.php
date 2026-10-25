<?php
declare(strict_types=1);

/* 13) Payments and delivery */

/** نام کاربری پنل: پیشوند محصول + پسوند تصادفی (سازگار با قوانین پاسارگارد) */
function random_username(string $prefix): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    $clean = mb_substr(trim($clean, '_'), 0, 14);
    $suffix = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= chr(random_int(97, 122));
    }
    /* بدون پیشوند هم نام کاربری کاملاً تصادفی ساخته می‌شود */
    return $clean === '' ? $suffix : $clean . '_' . $suffix;
}

/**
 * رمز تصادفی با ساختار امن — حرف بزرگ و کوچک، رقم و نماد.
 * نمونه: 7P20g8Z!ot%k
 */
function random_password(int $length = 12): string
{
    $length = max(10, min(32, $length));
    $lower  = 'abcdefghijkmnpqrstuvwxyz';
    $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digit  = '23456789';
    $symbol = '!@#$%^&*-_+?';
    $all    = $lower . $upper . $digit . $symbol;
    /* قوانین خود پنل پاسارگارد: حداقل ۱۲ کاراکتر، ۲ رقم، ۲ حرف بزرگ،
       ۲ حرف کوچک و ۱ کاراکتر ویژه — دقیقاً همین تعداد را تضمین می‌کنیم */
    $length = max(12, min(64, $length));
    $chars = [
        $digit[random_int(0, strlen($digit) - 1)],
        $digit[random_int(0, strlen($digit) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $symbol[random_int(0, strlen($symbol) - 1)],
    ];
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);
    $password = implode('', $chars);
    return str_contains($password, '"') ? str_replace('"', '*', $password) : $password;
}

/** رمز مناسب پنل که نام کاربری داخلش نباشد (پنل این را هم رد می‌کند) */
function panel_password_ok(string $password, string $username = ''): bool
{
    return strlen($password) >= 12
        && preg_match_all('/\d/', $password) >= 2
        && preg_match_all('/[A-Z]/', $password) >= 2
        && preg_match_all('/[a-z]/', $password) >= 2
        && preg_match('/[!@#$%^&*()\-_=+\[\]{}|;:,.<>?\/~`]/', $password) === 1
        && ($username === '' || !str_contains(mb_strtolower($password), mb_strtolower($username)));
}

/** رمز پنل: تا وقتی قوانین پنل را رعایت نکند دوباره ساخته می‌شود */
function panel_password(int $length, string $username): string
{
    for ($try = 0; $try < 20; $try++) {
        $candidate = random_password($length);
        if (panel_password_ok($candidate, $username)) {
            return $candidate;
        }
    }
    /* پشتیبان قطعی: ساخت دستی طبق قانون */
    return bin2hex(random_bytes(6)) . '!' . random_int(100, 999);
}

/** تحویل سفارش: ساخت ادمین روی پنل پاسارگارد و ثبت ردیف پنل. */
/** خطای پنل یعنی «این نام قبلاً هست»؟ */
function panel_error_is_duplicate(Throwable $ex): bool
{
    $status = $ex instanceof PanelError ? (int) $ex->status : 0;
    $msg = mb_strtolower($ex->getMessage());
    if (str_contains($msg, 'exist') || str_contains($msg, 'duplicate')
        || str_contains($msg, 'already') || str_contains($msg, 'تکراری')) {
        return true;
    }
    return in_array($status, [400, 409, 422], true)
        && (str_contains($msg, 'username') || str_contains($msg, 'admin'));
}

/** نام آزاد روی خود پنل (نه فقط دیتابیس ربات) */
function panel_free_username(PgPanel $client, int $length = 8): string
{
    for ($try = 0; $try < 6; $try++) {
        $candidate = random_panel_username($length);
        try {
            if ($client->admin_by_username($candidate) === null) {
                return $candidate;
            }
        } catch (Throwable $ex) {
            return $candidate;   // پنل جواب نداد؛ همان نام را امتحان می‌کنیم
        }
    }
    return random_panel_username($length + 2);
}

function deliver_panel_order(array $order): array
{
    $product = order_product($order);
    if ($product === null) {
        return [false, 'محصول این سفارش پیدا نشد.'];
    }
    $isTrial = (string) $order['kind'] === 'trial';
    $panelRow = null;
    if (!empty($product['panel_id'])) {
        $panelRow = DB::one('SELECT * FROM pg_panels WHERE id=? AND active=1', [(int) $product['panel_id']]);
    }
    if ($panelRow === null) {
        $panelRow = DB::one('SELECT * FROM pg_panels WHERE active=1 ORDER BY is_default DESC, id LIMIT 1');
    }
    if ($panelRow === null) {
        return [false, "هیچ پنل پاسارگارد فعالی وصل نیست.\n"
            . 'در «🔌 پنل‌ها → ➕ افزودن پنل» پنل را با کلید API وصل کن، '
            . 'بعد دوباره همین دکمه را بزنید.'];
    }
    if (trim((string) ($panelRow['api_key'] ?? '')) === '') {
        return [false, "کلید API این پنل ثبت نشده است.\n"
            . 'در «🔌 پنل‌ها» روی پنل بزنید و «🔑 تغییر کلید API» را انجام بدهید.'];
    }
    $payloadOrder = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
    $unlimitedTrial = $isTrial && !empty($payloadOrder['trial_unlimited']);
    $dataCapGb = $unlimitedTrial ? 0.0
        : ($isTrial ? (float) ($payloadOrder['trial_gb'] ?? set_int('trial_gb', 1))
            : (float) $product['data_cap_gb']);
    $expireAt = $unlimitedTrial ? 0
        : ($isTrial
            ? time() + max(1, (int) ($payloadOrder['trial_hours'] ?? set_int('trial_hours', 24))) * 3600
            : time() + (int) $product['days'] * DAY);
    $userCap = $unlimitedTrial ? 0 : ($isTrial ? 1 : (int) $product['max_users']);
    $roleId = (int) $product['role_id'];
    if ($roleId <= 0) {
        $roleId = auto_role_id($panelRow);
        if ($roleId > 0) {
            DB::exec('UPDATE products SET role_id=? WHERE id=?', [$roleId, (int) $product['id']]);
        }
    }
    if ($roleId <= 0) {
        return [false, 'این محصول هنوز نقش پنل ندارد.' . "\n"
            . 'در بخش محصول دکمهٔ «🎭 نقش پنل» را بزنید و یکی از نقش‌های خود پنل را انتخاب کنید.'];
    }
    $base = normalize_panel_url((string) $panelRow['url']);
    $client = new PgPanel($panelRow);
    $username = '';
    $created = [];
    $lastError = '';
    $wantedName = trim((string) ($payloadOrder['panel_username'] ?? ''));
    $finalName = '';
    $tries = $wantedName !== '' ? 5 : 4;
    $password = '';
    for ($attempt = 1; $attempt <= $tries; $attempt++) {
        if ($attempt === 1 && $wantedName !== '') {
            $username = $wantedName;
        } else {
            /* نام رندوم را قبل از ساخت روی خود پنل چک می‌کنیم */
            $username = panel_free_username($client, $attempt >= 3 ? 10 : 8);
        }
        /* رمز باید قوانین خود پنل را رعایت کند و نام کاربری داخلش نباشد */
        $password = panel_password(set_int('panel_pass_len', 12), $username);
        try {
            $created = $client->create_admin($username, $password, $roleId, [
                'users_limit' => $userCap,
                'data_limit'  => (int) round($dataCapGb * 1024 ** 3),
                'expire'      => $expireAt,
                'note'        => (string) $product['name'],
            ]);
            $lastError = '';
            $finalName = $username;
            break;
        } catch (Throwable $ex) {
            $lastError = $ex->getMessage();
            log_line('warning', 'create_admin failed (' . $username . '): ' . $lastError);
            /* 422 یعنی داده پذیرفته نشد (نام یا رمز). یک‌بار با نام و رمز تازه
               دوباره تلاش می‌کنیم؛ 401/403/500 دوباره‌تلاش بی‌فایده است. */
            $status = $ex instanceof PanelError ? (int) $ex->status : 0;
            $retryable = panel_error_is_duplicate($ex) || $status === 422;
            if (!$retryable) {
                break;
            }
        }
    }
    if ($lastError !== '') {
        $note = $lastError;
        if (str_contains($note, '401')) {
            $note .= "\nکلید API معتبر نیست یا باطل شده؛ کلید تازه بساز و از «🔑 تغییر کلید API» ثبت کنید.";
        }
        return [false, 'ساخت پنل روی سرور انجام نشد: ' . $note];
    }
    $panelId = DB::exec('INSERT INTO panels(tg_id, product_id, panel_admin_id, panel_username, '
        . 'panel_password, label, max_users, device_limit, data_cap, expires_at, per_gb_price, '
        . 'hourly_price, min_balance, status, created_at, pg_panel_id, order_id, user_cap, is_trial) '
        . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$order['tg_id'], $product['id'], (int) ($created['id'] ?? 0), $username, $password,
         $product['name'] . ($isTrial ? ' (تست)' : ''), $userCap, (int) $product['device_limit'],
         (int) round($dataCapGb * 1024 ** 3),
         $expireAt, $isTrial ? 0 : (int) $product['per_gb_price'],
         $isTrial ? 0 : (int) $product['hourly_price'],
         (int) $product['min_balance'], 'active',
         now_ts(), (int) $panelRow['id'], (int) $order['id'], $userCap, $isTrial ? 1 : 0]);
    if (!$isTrial) {
        /* این‌ها نباید تحویل پنل را خراب کنند؛ پنل ساخته شده و باید به کاربر برسد */
        try {
            grant_order_discount($order, $panelId);
        } catch (Throwable $ex) {
            log_line('warning', 'grant_order_discount failed: ' . $ex->getMessage());
        }
        try {
            panel_charge_first_day($panelId, (int) $order['tg_id'], (int) $product['hourly_price']);
        } catch (Throwable $ex) {
            log_line('warning', 'first day charge failed: ' . $ex->getMessage());
        }
    }
    $user = user_get((int) $order['tg_id']);
    $who = e(mb_substr((string) ($user['name'] ?? $order['tg_id']), 0, 22));
    try {
        post_report(($isTrial ? '🧪 <b>پنل تست ساخته شد</b>' : '🛒 <b>پنل فروخته شد</b>') . "\n"
        . '👤 کاربر: ' . $who . ' <code>' . (int) $order['tg_id'] . "</code>\n"
        . '🆔 نام کاربری: <code>' . e($username) . "</code>\n"
        . '📦 محصول: ' . e((string) $product['name']) . "\n"
        . '🔌 پنل: ' . e((string) $panelRow['name']) . "\n"
        . '📊 حجم: ' . fa_digits($dataCapGb) . ' گیگ'
        . ($isTrial ? ' · ⏳ ' . fa_digits((int) (($expireAt - time()) / 3600)) . ' ساعت'
            : ' · ⏳ ' . fa_digits((int) $product['days']) . ' روز')
        . "\n" . '💵 مبلغ: ' . money((int) $order['payable']),
        $isTrial ? 'panel' : 'sales');
    } catch (Throwable $ex) {
        log_line('warning', 'sale report failed: ' . $ex->getMessage());
    }
    $dashboard = panel_dashboard_url($base);
    $nameNote = ($wantedName !== '' && $wantedName !== $username)
        ? '⚠️ نام دلخواه <code>' . e($wantedName) . "</code> روی پنل موجود بود؛ "
            . 'نام تازه صادر شد.' . "\n\n"
        : '';
    if ($isTrial) {
        return [true, "🧪 <b>پنل تست شما آماده است</b>\n\n" . $nameNote
            . '🌐 آدرس پنل: ' . e($dashboard) . "\n"
            . '👤 نام کاربری: <code>' . e($username) . "</code>\n"
            . '🔑 رمز: <code>' . e($password) . "</code>\n"
            . '📦 حجم: ' . fa_digits($dataCapGb) . " گیگ\n"
            . '⏳ مدت: ' . fa_digits((int) (($expireAt - time()) / 3600)) . " ساعت\n\n"
            . 'همین حالا در «📦 پنل‌های من» هم می‌بینی‌اش. برای پنل اصلی «🛒 خرید پنل» را بزنید.'];
    }
    return [true, "✅ <b>پنل شما آماده است</b>\n\n" . $nameNote
        . '🌐 آدرس پنل: ' . e(panel_dashboard_url($base)) . "\n"
        . '👤 نام کاربری: <code>' . e($username) . "</code>\n"
        . '🔑 رمز: <code>' . e($password) . "</code>\n"
        . '⏳ اعتبار: ' . fa_digits((int) $product['days']) . " روز\n"
        . '👥 سقف کاربر: ' . fa_digits($userCap) . "\n\n"
        . '🎓 از «📦 پنل‌های من» یا «🎓 راهنما» ادامه بدهید.'];
}

function order_product(array $order): ?array
{
    if (empty($order['product_id'])) {
        return null;
    }
    return product_get((int) $order['product_id']);
}

/**
 * تسویهٔ سفارش. خروجی: [موفق, متن برای کاربر]
 * انواع: panel | topup | slot
 */
function settle_payment(array $order, string $source = 'wallet', int $adminId = 0): array
{
    $kind = (string) $order['kind'];
    $tgId = (int) $order['tg_id'];
    /* یک سفارش فقط یک‌بار انجام می‌شود؛ دوبار زدن دکمه یعنی دوبار پنل ساختن */
    if ((string) $order['status'] === 'delivered') {
        return [true, '✅ این سفارش قبلاً انجام شده است.' . "\n"
            . 'کد سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n"
            . 'برای دیدن پنل‌ها «📦 پنل‌های من» را بزنید.'];
    }
    if ($kind === 'topup') {
        $payload = json_decode((string) $order['payload'], true) ?: [];
        $amount = (int) ($payload['topup'] ?? $order['payable']);
        $newBalance = add_balance($tgId, $amount, 'topup', (string) $order['id'],
            'شارژ کیف پول (' . pay_source_label($source) . ')');
        order_mark((int) $order['id'], 'delivered');
        /* شمارش واریزی + پورسانت معرف (فقط واریزی اول و دوم) */
        try {
            $depositNo = deposit_count_up($tgId);
            $commission = referral_deposit_payout($tgId, $amount);
            if ($commission > 0) {
                log_payment('referral commission', ['user' => $tgId, 'deposit' => $depositNo,
                    'amount' => $amount, 'reward' => $commission]);
            }
        } catch (Throwable $ex) {
            log_line('warning', 'referral deposit payout failed: ' . $ex->getMessage());
        }
        $user = user_get($tgId);
        post_report('💰 <b>شارژ کیف پول</b>' . "\n"
            . '👤 ' . e(mb_substr((string) ($user['name'] ?? $tgId), 0, 22))
            . ' <code>' . $tgId . "</code>\n"
            . '💵 مبلغ: <b>' . money($amount) . "</b>\n"
            . '🔀 روش: ' . e(pay_source_label($source)) . "\n"
            . '👛 موجودی جدید: ' . money($newBalance), 'topup');
        return [true, '✅ کیف پول شارژ شد: ' . money($amount) . "\nموجودی جدید: "
            . money($newBalance)];
    }
    if ($kind === 'users') {
        $payload = json_decode((string) $order['payload'], true) ?: [];
        $panelId = (int) ($payload['panel_id'] ?? 0);
        $block = max(1, (int) ($payload['block'] ?? 1000));
        $panel = DB::one('SELECT * FROM panels WHERE id=? AND tg_id=?', [$panelId, $tgId]);
        if ($panel === null) {
            order_mark((int) $order['id'], 'failed');
            return [false, 'این پنل پیدا نشد.'];
        }
        $cap = ((int) ($panel['user_cap'] ?? 0) ?: (int) $panel['max_users']) + $block;
        $panelRow = $panel['pg_panel_id']
            ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]) : null;
        if ($panelRow !== null) {
            try {
                (new PgPanel($panelRow))->update_admin((string) $panel['panel_username'],
                    ['users_limit' => $cap]);
            } catch (Throwable $ex) {
                order_mark((int) $order['id'], 'failed');
                return [false, 'افزایش سقف روی پنل انجام نشد: ' . $ex->getMessage()
                    . "\n(پرداخت انجام شده؛ با پشتیبانی تماس بگیرید)"];
            }
        }
        DB::exec('UPDATE panels SET user_cap=?, max_users=? WHERE id=?', [$cap, $cap, $panelId]);
        order_mark((int) $order['id'], 'delivered');
        post_report('➕ <b>افزایش سقف کاربر</b>' . "\n"
            . '👤 کاربر <code>' . $tgId . "</code>\n"
            . '📦 پنل <code>' . e((string) $panel['panel_username']) . "</code>\n"
            . '👥 سقف جدید: ' . fa_digits($cap) . "\n"
            . '💵 مبلغ: ' . money((int) $order['payable']), 'sales');
        return [true, '✅ سقف کاربر پنل <code>' . e((string) $panel['panel_username'])
            . '</code> شد <b>' . fa_digits($cap) . "</b> کاربر."];
    }
    if ($kind === 'slot') {
        DB::exec('INSERT INTO slots(tg_id, count, expires_at, created_at) VALUES(?,?,?,?)',
            [$tgId, 1, null, now_ts()]);
        order_mark((int) $order['id'], 'delivered');
        post_report('🧩 <b>خرید اسلات</b>' . "\n"
            . '👤 کاربر <code>' . $tgId . "</code>\n"
            . '💵 مبلغ: ' . money((int) $order['payable']), 'sales');
        return [true, '✅ یک اسلات اضافه شد.'];
    }
    try {
        [$ok, $text] = deliver_panel_order($order);
    } catch (Throwable $ex) {
        log_line('error', 'deliver failed: ' . $ex->getMessage());
        [$ok, $text] = [false, 'ساخت پنل با خطای غیرمنتظره متوقف شد: ' . $ex->getMessage()];
    }
    if ($ok) {
        order_mark((int) $order['id'], 'delivered');
        try {
            referral_payout($order);
        } catch (Throwable $ex) {
            log_line('warning', 'referral payout failed: ' . $ex->getMessage());
        }
    } else {
        order_mark((int) $order['id'], 'failed');
    }
    return [$ok, $text];
}

function pay_from_wallet(array $order): array
{
    $tgId = (int) $order['tg_id'];
    // کدی که تا لحظهٔ پرداخت منقضی/حذف شده، تخفیفش برداشته می‌شود
    $payload = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
    $code = (string) ($payload['discount_code'] ?? '');
    if ($code !== '' && (int) $order['discount'] > 0) {
        [$stillOk] = discount_validate($code, $tgId);
        if (!$stillOk) {
            $payload['discount_code'] = '';
            DB::exec('UPDATE orders SET discount=0, payable=price, payload=? WHERE id=?',
                [json_encode($payload, JSON_UNESCAPED_UNICODE), (int) $order['id']]);
            $order = order_get((int) $order['id']) ?? $order;
            Tg::send($tgId, '⚠️ کد تخفیف این سفارش منقضی شده بود و حذف شد؛ '
                . 'مبلغ به قیمت اصلی برگشت.');
        }
    }
    if ((string) $order['status'] === 'delivered') {
        return settle_payment($order, 'wallet');
    }
    $payable = (int) $order['payable'];
    if (balance_of($tgId) < $payable) {
        return [false, 'موجودی کیف پول کافی نیست.'];
    }
    if ($payable > 0) {
        add_balance($tgId, -$payable, 'order', (string) $order['id'], 'پرداخت سفارش');
    }
    try {
        return settle_payment($order, 'wallet');
    } catch (Throwable $ex) {
        log_line('error', 'settle crashed: ' . $ex->getMessage());
        if ($payable > 0) {
            add_balance($tgId, $payable, 'refund', (string) $order['id'],
                'برگشت مبلغ به‌خاطر خطا در انجام سفارش');
        }
        return [false, 'انجام سفارش نیمه‌کاره ماند و مبلغ به کیف پولتان برگشت.' . "\n"
            . 'کد سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n"
            . '<i>دوباره تلاش کن؛ اگر تکرار شد با پشتیبانی تماس بگیرید.</i>'];
    }
}

