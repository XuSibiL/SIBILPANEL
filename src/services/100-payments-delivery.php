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
    $payloadOrder = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
    $isTrial = (string) $order['kind'] === 'trial';
    /* سد نهایی: حتی سفارش قدیمی/دستیِ تست هم نباید پنل نامحدود بسازد. */
    if ($isTrial && product_is_unlimited($product)) {
        return [false, 'پنل نامحدود در سرویس تست قابل ساخت نیست.'];
    }
    $isUnlimited = !$isTrial && ((string) ($payloadOrder['sale_type'] ?? '') === PRODUCT_SALE_UNLIMITED_USERS
        || !empty($payloadOrder['unlimited_users']));
    $unlimitedUsers = (int) ($payloadOrder['user_count'] ?? 0);
    $unlimitedUnit = (int) ($payloadOrder['per_user_price'] ?? 0);
    $unlimitedMin = max(1, (int) ($payloadOrder['min_users'] ?? 1));
    /* The order payload is the immutable economics snapshot.  Old orders have
       no mode, so they remain the legacy up-front product by design. */
    $unlimitedHourly = $isUnlimited
        && (string) ($payloadOrder['unlimited_billing_mode'] ?? '') === UNLIMITED_BILLING_HOURLY;
    $unlimitedFixedHourly = $unlimitedHourly
        ? max(0, (int) ($payloadOrder['panel_hourly_price'] ?? 0)) : 0;
    $unlimitedUsersTotal = 0;
    if ($isUnlimited && $unlimitedUnit > 0 && $unlimitedUsers <= intdiv(PHP_INT_MAX, $unlimitedUnit)) {
        $unlimitedUsersTotal = $unlimitedUsers * $unlimitedUnit;
    }
    $unlimitedExpected = $unlimitedUsersTotal;
    if ($unlimitedHourly && $unlimitedExpected <= PHP_INT_MAX - $unlimitedFixedHourly) {
        $unlimitedExpected += $unlimitedFixedHourly;
    } elseif ($unlimitedHourly) {
        $unlimitedExpected = -1;
    }
    /* قیمت و تعداد در payload سفارش snapshot هستند تا تغییر محصول پس از پرداخت
       به مبلغ/سقف فروخته‌شده اثر نگذارد. ساعت اول در فاکتور است، نه کسر کیف پول. */
    if ($isUnlimited && ($unlimitedUsers < $unlimitedMin || $unlimitedUsers > UNLIMITED_USERS_MAX
        || $unlimitedUnit <= 0 || $unlimitedUsers > intdiv(PHP_INT_MAX, $unlimitedUnit)
        || $unlimitedExpected !== (int) $order['price'])) {
        return [false, 'اطلاعات تعداد کاربر یا نرخ ساعتی این سفارش نامعتبر است؛ با پشتیبانی تماس بگیرید.'];
    }

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

    $unlimitedTrial = $isTrial && !empty($payloadOrder['trial_unlimited']);
    $dataCapGb = ($isUnlimited || $unlimitedTrial) ? 0.0
        : ($isTrial ? (float) ($payloadOrder['trial_gb'] ?? set_int('trial_gb', 1))
            : (float) $product['data_cap_gb']);
    $expireAt = ($isUnlimited || $unlimitedTrial) ? 0
        : ($isTrial
            ? time() + max(1, (int) ($payloadOrder['trial_hours'] ?? set_int('trial_hours', 24))) * 3600
            : time() + (int) $product['days'] * DAY);
    /* برای پنل نامحدود فقط سقف تعداد کاربر override می‌شود؛ نقش، اعتبار و دستگاه را تعیین می‌کند. */
    $userCap = $isUnlimited ? $unlimitedUsers
        : ($unlimitedTrial ? 0
            : ($isTrial ? max(1, set_int('trial_user_limit', 10)) : (int) $product['max_users']));
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
            $username = panel_free_username($client, $attempt >= 3 ? 10 : 8);
        }
        $password = panel_password(set_int('panel_pass_len', 12), $username);
        try {
            $created = $client->create_admin($username, $password, $roleId, [
                'users_limit' => $userCap,
                /* مقدار صفر عمداً ارسال نمی‌شود؛ نقش پاسارگارد limit/expire را مالک است. */
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
            $status = $ex instanceof PanelError ? (int) $ex->status : 0;
            if (!(panel_error_is_duplicate($ex) || $status === 422)) {
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

    /* Legacy unlimited panels stay completely outside local billing.  The new
       model records first-hour paid-through clocks per component. */
    $now = now_ts();
    $prepaidHours = ($isTrial || $isUnlimited) ? 0 : prepaid_hours();
    $billingStartAt = $unlimitedHourly ? $now + 3600
        : ($prepaidHours > 0 ? $now + $prepaidHours * 3600 : 0);
    $prepaidAmount = ($isTrial || $isUnlimited) ? 0
        : max(0, (int) ($payloadOrder['prepaid'] ?? panel_prepaid_amount($product)));
    $labelSuffix = $isTrial ? ' (تست)' : ($isUnlimited ? ' (نامحدود)' : '');
    $panelId = DB::exec('INSERT INTO panels(tg_id, product_id, panel_admin_id, panel_username, '
        . 'panel_password, label, max_users, device_limit, data_cap, expires_at, per_gb_price, '
        . 'hourly_price, min_balance, status, created_at, pg_panel_id, order_id, user_cap, is_trial, '
        . 'is_unlimited, unlimited_user_price, unlimited_min_users, unlimited_billing_mode, '
        . 'billing_start_at, prepaid_amount, last_meter_at) '
        . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$order['tg_id'], $product['id'], (int) ($created['id'] ?? 0), $username, $password,
         $product['name'] . $labelSuffix, $userCap,
         $isUnlimited ? max(0, (int) ($product['device_limit'] ?? 0)) : (int) $product['device_limit'],
         (int) round($dataCapGb * 1024 ** 3), $expireAt,
         ($isTrial || $isUnlimited) ? 0 : (int) $product['per_gb_price'],
         $unlimitedHourly ? $unlimitedFixedHourly : (($isTrial || $isUnlimited) ? 0 : (int) $product['hourly_price']),
         $isUnlimited ? 0 : (int) $product['min_balance'], 'active',
         $now, (int) $panelRow['id'], (int) $order['id'], $userCap, $isTrial ? 1 : 0,
         $isUnlimited ? 1 : 0, $isUnlimited ? $unlimitedUnit : 0,
         $isUnlimited ? $unlimitedMin : 1,
         $unlimitedHourly ? UNLIMITED_BILLING_HOURLY : UNLIMITED_BILLING_LEGACY,
         $billingStartAt, $prepaidAmount, $billingStartAt > 0 ? $billingStartAt : $now]);
    if ($unlimitedHourly) {
        /* The base and user-cap lines are separate so an added cap never incurs
           a charge for hours before it was purchased.  INSERT OR IGNORE makes
           an accidental re-delivery of the same order harmless. */
        db_tx(static function () use ($panelId, $order, $unlimitedFixedHourly, $unlimitedUsers,
                                      $unlimitedUnit, $billingStartAt): void {
            unlimited_billing_unit_add($panelId, (int) $order['id'], 'base', 1,
                $unlimitedFixedHourly, $billingStartAt);
            unlimited_billing_unit_add($panelId, (int) $order['id'], 'users', $unlimitedUsers,
                $unlimitedUnit, $billingStartAt);
        });
    }
    if (!$isTrial && !$isUnlimited) {
        try {
            grant_order_discount($order, $panelId);
        } catch (Throwable $ex) {
            log_line('warning', 'grant_order_discount failed: ' . $ex->getMessage());
        }
        if ($prepaidAmount > 0) {
            try {
                panel_record_prepaid($panelId, (int) $order['tg_id'], $prepaidAmount, $prepaidHours);
            } catch (Throwable $ex) {
                log_line('warning', 'prepaid ledger failed: ' . $ex->getMessage());
            }
        }
    }

    $user = user_get((int) $order['tg_id']);
    $who = e(mb_substr((string) ($user['name'] ?? $order['tg_id']), 0, 22));
    try {
        $kindTitle = $isTrial ? '🧪 <b>پنل تست ساخته شد</b>'
            : ($isUnlimited ? '♾️ <b>پنل نامحدود فروخته شد</b>' : '🛒 <b>پنل فروخته شد</b>');
        $spec = $isUnlimited
            ? ('👥 سقف کاربر: ' . fa_digits($userCap)
                . ($unlimitedHourly
                    ? (' · 💵 هر کاربر/ساعت: ' . money($unlimitedUnit)
                        . ' · 🏷 ثابت/ساعت: ' . money($unlimitedFixedHourly))
                    : (' · 💵 هر کاربر: ' . money($unlimitedUnit))))
            : ('📊 حجم: ' . fa_digits($dataCapGb) . ' گیگ'
                . ($isTrial ? ' · ⏳ ' . fa_digits((int) (($expireAt - time()) / 3600)) . ' ساعت'
                    : ' · ⏳ ' . fa_digits((int) $product['days']) . ' روز'));
        post_report($kindTitle . "\n"
            . '👤 کاربر: ' . $who . ' <code>' . (int) $order['tg_id'] . "</code>\n"
            . '🆔 نام کاربری: <code>' . e($username) . "</code>\n"
            . '📦 محصول: ' . e((string) $product['name']) . "\n"
            . '🔌 پنل: ' . e((string) $panelRow['name']) . "\n"
            . $spec . "\n" . '💵 مبلغ: ' . money((int) $order['payable']),
            $isTrial ? 'panel' : 'sales');
    } catch (Throwable $ex) {
        log_line('warning', 'sale report failed: ' . $ex->getMessage());
    }
    $dashboard = panel_dashboard_url($base);
    $nameNote = ($wantedName !== '' && $wantedName !== $username)
        ? '⚠️ نام دلخواه <code>' . e($wantedName) . "</code> روی پنل موجود بود؛ "
            . 'نام تازه صادر شد.' . "\n\n" : '';
    if ($isTrial) {
        return [true, "🧪 <b>پنل تست شما آماده است</b>\n\n" . $nameNote
            . '🌐 آدرس پنل: ' . e($dashboard) . "\n"
            . '👤 نام کاربری: <code>' . e($username) . "</code>\n"
            . '🔑 رمز: <code>' . e($password) . "</code>\n"
            . '📦 حجم: ' . fa_digits($dataCapGb) . " گیگ\n"
            . '⏳ مدت: ' . fa_digits((int) (($expireAt - time()) / 3600)) . " ساعت\n\n"
            . 'همین حالا در «📦 پنل‌های من» هم می‌بینی‌اش. برای پنل اصلی «🛒 خرید پنل» را بزنید.'];
    }
    if ($isUnlimited) {
        $hourlyNote = $unlimitedHourly
            ? ('💵 ساعت اول پرداخت شده است. از ' . fa_ts($billingStartAt)
                . '، هر ساعت <b>' . money($unlimitedUsersTotal + $unlimitedFixedHourly)
                . '</b> از کیف پول کسر می‌شود.\n')
            : '';
        return [true, "✅ <b>پنل نامحدود شما آماده است</b>\n\n" . $nameNote
            . '🌐 آدرس پنل: ' . e($dashboard) . "\n"
            . '👤 نام کاربری: <code>' . e($username) . "</code>\n"
            . '🔑 رمز: <code>' . e($password) . "</code>\n"
            . '👥 سقف کاربر: <b>' . fa_digits($userCap) . "</b>\n"
            . $hourlyNote
            . '♾️ اعتبار و محدودیت دستگاه‌ها طبق نقش پاسارگارد اعمال می‌شود.' . "\n\n"
            . 'برای افزودن کاربر از «📦 پنل‌های من» استفاده کنید.'];
    }
    return [true, "✅ <b>پنل شما آماده است</b>\n\n" . $nameNote
        . '🌐 آدرس پنل: ' . e($dashboard) . "\n"
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
 * Credit one wallet top-up and mark its order delivered as one durable local
 * action. Provider verification has already happened before this helper is
 * called; this function only makes a retry safe if a process stopped between
 * the provider payment claim and customer delivery.
 *
 * The legacy wallet_tx table has no uniqueness constraint.  The BEGIN
 * IMMEDIATE transaction plus the immutable topup/order id reference therefore
 * form the durable exactly-once delivery claim: a later retry sees its own
 * wallet entry and only repairs the unfinished order state.
 *
 * @return array{ok:bool,text:string,credited:bool}
 */
function settle_topup_once(array $order, string $source): array
{
    $orderId = (int) ($order['id'] ?? 0);
    $tgId = (int) ($order['tg_id'] ?? 0);
    $payload = json_decode((string) ($order['payload'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $amount = (int) ($payload['topup'] ?? ($order['payable'] ?? 0));
    if ($orderId <= 0 || $tgId <= 0 || $amount <= 0) {
        return ['ok' => false, 'text' => 'اطلاعات شارژ کیف پول معتبر نیست.', 'credited' => false];
    }

    try {
        $claim = DB::tx(static function () use ($orderId, $tgId, $amount, $source): array {
            $fresh = order_get($orderId);
            if ($fresh === null) {
                return ['state' => 'notfound'];
            }
            if ((string) $fresh['status'] === 'delivered') {
                return ['state' => 'already', 'balance' => balance_of($tgId)];
            }
            /* A finance-approved manual receipt intentionally moves its
               order to review before the same idempotent credit path runs. */
            if (!in_array((string) $fresh['status'], ['pending', 'review'], true)) {
                return ['state' => 'closed'];
            }
            if (user_get($tgId) === null) {
                return ['state' => 'user_missing'];
            }

            /* A pre-existing entry can only be this exact immutable order;
               do not append a second ledger row while repairing delivery. */
            $existing = DB::one("SELECT id FROM wallet_tx WHERE tg_id=? AND kind='topup' AND ref=? "
                . 'ORDER BY id DESC LIMIT 1', [$tgId, (string) $orderId]);
            $credited = $existing === null;
            if ($credited) {
                $changed = DB::update('UPDATE users SET balance = balance + ? WHERE tg_id=?',
                    [$amount, $tgId]);
                if ($changed !== 1) {
                    return ['state' => 'user_missing'];
                }
                $balance = balance_of($tgId);
                DB::exec('INSERT INTO wallet_tx(tg_id, amount, kind, ref, note, balance, created_at) '
                    . 'VALUES(?,?,?,?,?,?,?)',
                    [$tgId, $amount, 'topup', (string) $orderId,
                        'شارژ کیف پول (' . pay_source_label($source) . ')', $balance, now_ts()]);
            } else {
                $balance = balance_of($tgId);
            }

            $marked = DB::update("UPDATE orders SET status='delivered', paid_at=? "
                . "WHERE id=? AND status IN ('pending','review')", [now_ts(), $orderId]);
            if ($marked !== 1) {
                /* Rolling back a just-added wallet entry is safer than ever
                   allowing an ambiguous second credit. */
                throw new RuntimeException('topup delivery claim changed concurrently');
            }
            return ['state' => 'delivered', 'balance' => $balance, 'credited' => $credited];
        });
    } catch (Throwable $ex) {
        log_line('warning', 'topup delivery failed: ' . $ex->getMessage(), ['order' => $orderId]);
        return ['ok' => false, 'text' => 'شارژ کیف پول موقتاً انجام نشد.', 'credited' => false];
    }

    $state = (string) ($claim['state'] ?? 'notfound');
    if ($state === 'already') {
        return ['ok' => true, 'text' => '✅ این سفارش قبلاً انجام شده است.' . "\n"
            . 'کد سفارش: <code>' . e((string) ($order['ref'] ?? '')) . '</code>', 'credited' => false];
    }
    if ($state !== 'delivered') {
        return ['ok' => false, 'text' => $state === 'closed'
            ? 'این سفارش دیگر برای شارژ کیف پول باز نیست.' : 'کاربر یا سفارش شارژ پیدا نشد.',
            'credited' => false];
    }

    $balance = (int) ($claim['balance'] ?? balance_of($tgId));
    if (!empty($claim['credited'])) {
        /* Referral side effects intentionally run only for the one transaction
           that inserted the top-up ledger entry.  A delivery repair can never
           create a second deposit count, commission, or balance credit. */
        try {
            $depositNo = deposit_count_up($tgId);
            if (ref_mode() === 'cash' && ref_cash_when() === 'purchase') {
                referral_welcome_cash($tgId);
            }
            $commission = referral_deposit_payout($tgId, $amount);
            if ($commission > 0) {
                log_payment('referral commission', ['user' => $tgId, 'deposit' => $depositNo,
                    'amount' => $amount, 'reward' => $commission]);
            }
        } catch (Throwable $ex) {
            log_line('warning', 'referral deposit payout failed: ' . $ex->getMessage());
        }
        try {
            $user = user_get($tgId);
            post_report('💰 <b>شارژ کیف پول</b>' . "\n"
                . '👤 ' . e(mb_substr((string) ($user['name'] ?? $tgId), 0, 22))
                . ' <code>' . $tgId . "</code>\n"
                . '💵 مبلغ: <b>' . money($amount) . "</b>\n"
                . '🔀 روش: ' . e(pay_source_label($source)) . "\n"
                . '👛 موجودی جدید: ' . money($balance), 'topup');
        } catch (Throwable $ex) {
            log_line('warning', 'topup report failed: ' . $ex->getMessage(), ['order' => $orderId]);
        }
    }

    return ['ok' => true, 'text' => '✅ کیف پول شارژ شد: ' . money($amount) . "\nموجودی جدید: "
        . money($balance), 'credited' => !empty($claim['credited'])];
}

/**
 * تسویهٔ سفارش. خروجی: [موفق, متن برای کاربر]
 * انواع: panel | topup | slot
 */
function settle_payment(array $order, string $source = 'wallet', int $adminId = 0): array
{
    /* Direct paths (free orders and wallet) do not have a provider payment row
       to protect them, so they enforce the same invoice TTL here. A submitted
       manual receipt has status review and is intentionally left for finance. */
    if ((string) ($order['status'] ?? '') === 'pending' && order_cancel_if_payment_expired($order)) {
        return [false, 'مهلت این فاکتور تمام شده است؛ یک سفارش تازه بسازید.'];
    }
    $kind = (string) $order['kind'];
    $tgId = (int) $order['tg_id'];
    /* یک سفارش فقط یک‌بار انجام می‌شود؛ دوبار زدن دکمه یعنی دوبار پنل ساختن */
    if ((string) $order['status'] === 'delivered') {
        return [true, '✅ این سفارش قبلاً انجام شده است.' . "\n"
            . 'کد سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n"
            . 'برای دیدن پنل‌ها «📦 پنل‌های من» را بزنید.'];
    }
    if ($kind === 'topup') {
        $topup = settle_topup_once($order, $source);
        return [(bool) ($topup['ok'] ?? false), (string) ($topup['text'] ?? '')];
    }
    if ($kind === 'users') {
        $payload = json_decode((string) $order['payload'], true) ?: [];
        $panelId = (int) ($payload['panel_id'] ?? 0);
        $isUnlimitedRecharge = !empty($payload['unlimited_users'])
            || (string) ($payload['sale_type'] ?? '') === PRODUCT_SALE_UNLIMITED_USERS;
        $panel = DB::one('SELECT * FROM panels WHERE id=? AND tg_id=?', [$panelId, $tgId]);
        if ($panel === null) {
            order_mark((int) $order['id'], 'failed');
            return [false, 'این پنل پیدا نشد.'];
        }
        $hourlyUnlimitedRecharge = false;
        if ($isUnlimitedRecharge) {
            $block = (int) ($payload['user_count'] ?? 0);
            $unit = (int) ($payload['per_user_price'] ?? 0);
            $min = max(1, (int) ($payload['min_users'] ?? 1));
            $payloadHourly = (string) ($payload['unlimited_billing_mode'] ?? '') === UNLIMITED_BILLING_HOURLY;
            $hourlyUnlimitedRecharge = panel_is_hourly_unlimited($panel);
            /* Added capacity pays only its own first hour.  It must never be
               charged for historical hours of an existing panel. */
            if (!panel_is_unlimited($panel) || $payloadHourly !== $hourlyUnlimitedRecharge
                || $block < $min || $block > UNLIMITED_USERS_MAX
                || $unit <= 0 || $block > intdiv(PHP_INT_MAX, $unit)
                || $block * $unit !== (int) $order['price']
                || $unit !== panel_unlimited_user_price($panel)
                || $min !== panel_unlimited_min_users($panel)) {
                order_mark((int) $order['id'], 'failed');
                return [false, 'اطلاعات شارژ کاربر این پنل معتبر نیست؛ با پشتیبانی تماس بگیرید.'];
            }
        } else {
            /* مسیر قدیمیِ بلاک ثابت دقیقاً همان رفتار پیشین را حفظ می‌کند. */
            if (panel_is_unlimited($panel)) {
                order_mark((int) $order['id'], 'failed');
                return [false, 'برای پنل نامحدود باید تعداد کاربر دلخواه وارد شود.'];
            }
            $block = max(1, (int) ($payload['block'] ?? 1000));
        }
        $cap = ((int) ($panel['user_cap'] ?? 0) ?: (int) $panel['max_users']) + $block;
        $panelRow = $panel['pg_panel_id']
            ? DB::one('SELECT * FROM pg_panels WHERE id=? AND active=1', [(int) $panel['pg_panel_id']]) : null;
        if ($panelRow === null && $isUnlimitedRecharge) {
            order_mark((int) $order['id'], 'failed');
            return [false, 'پنل پاسارگارد این سرویس در دسترس نیست؛ سقف کاربر تغییر نکرد. '
                . 'با پشتیبانی تماس بگیرید.'];
        }
        if ($panelRow !== null) {
            try {
                (new PgPanel($panelRow))->set_admin_users_limit((string) $panel['panel_username'], $cap);
            } catch (Throwable $ex) {
                order_mark((int) $order['id'], 'failed');
                return [false, 'افزایش سقف روی پنل انجام نشد: ' . $ex->getMessage()
                    . "\n(پرداخت انجام شده؛ با پشتیبانی تماس بگیرید)"];
            }
        }
        /* فقط پس از پاسخ موفق PasarGuard مقدار محلی تغییر می‌کند. */
        DB::exec('UPDATE panels SET user_cap=?, max_users=? WHERE id=?', [$cap, $cap, $panelId]);
        $newUsersPaidUntil = 0;
        if ($hourlyUnlimitedRecharge) {
            $newUsersPaidUntil = now_ts() + 3600;
            db_tx(static function () use ($panelId, $order, $block, $unit, $newUsersPaidUntil): void {
                unlimited_billing_unit_add($panelId, (int) $order['id'], 'users', $block, $unit,
                    $newUsersPaidUntil);
            });
        }
        order_mark((int) $order['id'], 'delivered');
        post_report(($isUnlimitedRecharge ? '♾️ <b>افزودن کاربر پنل نامحدود</b>'
                : '➕ <b>افزایش سقف کاربر</b>') . "\n"
            . '👤 کاربر <code>' . $tgId . "</code>\n"
            . '📦 پنل <code>' . e((string) $panel['panel_username']) . "</code>\n"
            . '➕ کاربر افزوده‌شده: ' . fa_digits($block) . "\n"
            . '👥 سقف جدید: ' . fa_digits($cap) . "\n"
            . '💵 مبلغ: ' . money((int) $order['payable'])
            . ($hourlyUnlimitedRecharge ? "\n⏱ ساعت اول کاربران تازه تا " . fa_ts($newUsersPaidUntil)
                . ' پرداخت شد.' : ''), 'sales');
        return [true, '✅ سقف کاربر پنل <code>' . e((string) $panel['panel_username'])
            . '</code> شد <b>' . fa_digits($cap) . "</b> کاربر."
            . ($hourlyUnlimitedRecharge
                ? "\n⏱ ساعت اولِ کاربران تازه پرداخت شده؛ کسر ساعتی از "
                    . fa_ts($newUsersPaidUntil) . ' آغاز می‌شود.' : '')];
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
            /* پاداش نقدی‌ای که مدیر روی «اولین خرید» گذاشته، همین‌جا آزاد می‌شود */
            if (ref_mode() === 'cash' && ref_cash_when() === 'purchase') {
                referral_welcome_cash($tgId);
            } else {
                referral_payout($order);
            }
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
    $fresh = order_get((int) ($order['id'] ?? 0));
    if ($fresh === null) {
        return [false, 'سفارش پیدا نشد.'];
    }
    $order = $fresh;
    if (order_cancel_if_payment_expired($order)) {
        return [false, 'مهلت پرداخت تمام شد؛ یک سفارش تازه بسازید.'];
    }
    if (!in_array((string) $order['status'], ['pending', 'delivered'], true)) {
        return [false, 'این سفارش دیگر برای پرداخت باز نیست.'];
    }
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

