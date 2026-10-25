<?php
declare(strict_types=1);

/* 18) Usage meter — اجارهٔ ساعتی + مصرف هر گیگ */

/** خواندن مصرف یک ادمین از پنل (بایت مصرف‌شده) */
function panel_usage_bytes(array $panelRow, int $adminId, string $username = ''): ?array
{
    if ($adminId <= 0 && trim($username) === '') {
        return null;
    }
    try {
        $client = new PgPanel($panelRow);
        $data = $client->usage($adminId, $username);
    } catch (Throwable $ex) {
        log_line('warning', 'usage fetch failed: ' . $ex->getMessage());
        return null;
    }
    $used = $data['used_traffic'] ?? null;
    if ($used === null) {
        return null;
    }
    return [
        'used'   => (int) $used,
        'limit'  => (int) ($data['data_limit'] ?? 0),
        'status' => (string) ($data['status'] ?? ''),
        'users'  => (int) ($data['total_users'] ?? 0),
    ];
}

/**
 * یک بار متر کردن همهٔ پنل‌های فروخته‌شده.
 * خروجی: [charges, suspended, resumed, errors]
 */
/** هر بلوک مصرف حجم: ۱۰۰ مگابایت = ۰.۱ گیگ */
const USAGE_BLOCK_BYTES = 104857600;      // نمایش: هر ۱۰۰ مگ یک پله (۰.۱ گیگ)
const USAGE_BILL_BYTES  = 1073741824;     // کسر پول: فقط هر یک گیگ کامل

/** حجم را فقط با پله‌های ۰.۱ گیگ نشان می‌دهد (۰.۱ · ۰.۲ · ۰.۳ …) */
function gb_step_text(int $bytes): string
{
    $steps = intdiv(max(0, $bytes), USAGE_BLOCK_BYTES);
    return $steps <= 0 ? '0' : number_format($steps / 10, 1, '.', '');
}

/** شمارهٔ پلهٔ مصرف (هر پله ۱۰۰ مگ) */
function usage_blocks(int $bytes): int
{
    return intdiv(max(0, $bytes), USAGE_BLOCK_BYTES);
}

/** تعداد گیگ‌های کاملِ حساب‌شده */
function billed_gigs(int $bytes): int
{
    return intdiv(max(0, $bytes), USAGE_BILL_BYTES);
}

/**
 * مبلغ مصرف یک پنل: هر گیگ کاملِ مصرف‌شده × قیمت هر گیگ همان پنل.
 * مبنا ستون billed_bytes خودِ همان پنل است؛ پس نه دو بار کسر می‌شود و
 * نه پنل‌های مختلف روی هم می‌افتند.
 * خروجی: blocks(نمایش) · gigs(کسر) · bytes · gb_cost · hours · hours_cost
 */
function panel_meter_amounts(array $panel, int $usedBytes, bool $hourlyEnabled,
                             bool $perGbEnabled): array
{
    $billed = (int) ($panel['billed_bytes'] ?? 0);
    if ($billed > $usedBytes) {
        $billed = 0;   // حجم از سمت پنل ریست شده
    }
    $delta = max(0, $usedBytes - $billed);
    $gigs = billed_gigs($delta);
    $gigCost = 0;
    if ($perGbEnabled && $gigs > 0 && (int) $panel['per_gb_price'] > 0) {
        $gigCost = $gigs * (int) $panel['per_gb_price'];
        $discountPct = 0;
        if ((int) ($panel['discount_until'] ?? 0) > now_ts()
            && (int) ($panel['discount_percent'] ?? 0) > 0) {
            $discountPct = min(100, (int) $panel['discount_percent']);
            $gigCost = (int) round($gigCost * (100 - $discountPct) / 100);
        }
    }
    $meterAt = (int) $panel['last_meter_at'] ?: (int) $panel['created_at'];
    /* ⏱ ساعت‌های پیش‌پرداخت‌شده (پیش‌فرض ۲۴ ساعت اول) حساب نمی‌شوند:
       مبنای کسر ساعتی فقط بعد از پایان آن‌ها جلو می‌رود */
    $billingStart = (int) ($panel['billing_start_at'] ?? 0);
    if ($billingStart > 0 && $billingStart > $meterAt) {
        $meterAt = $billingStart;
    }
    $hours = 0;
    $meterUntil = now_ts();
    if ($hourlyEnabled && (int) $panel['hourly_price'] > 0
        && (int) ($panel['hourly_paused'] ?? 0) !== 1) {
        $hours = (int) floor(max(0, now_ts() - $meterAt) / 3600);
        $meterUntil = $meterAt + ($hours * 3600);
    }
    return [
        'blocks'      => usage_blocks($delta),          // فقط برای نمایش ۰.۱ گیگی
        'gigs'        => $gigs,                          // گیگ‌های کاملِ حساب‌شده
        'bytes'       => $gigs * USAGE_BILL_BYTES,
        'gb_cost'     => $gigCost,
        'hours'       => $hours,
        'hours_cost'  => $hours * (int) $panel['hourly_price'],
        'meter_until' => $meterUntil,
        'billed'      => $billed + ($gigs * USAGE_BILL_BYTES),
    ];
}

/**
 * کسر مصرف یک پنل — با قفل اتمیِ تک‌پنلی.
 *
 * ⚠️ قاعدهٔ اصلی: هیچ گیگی دو بار حساب نمی‌شود.
 * قبل از هر کسری، «مبنای حساب‌شدهٔ قبلی» (billed_bytes) و «ساعت حساب‌شدهٔ قبلی»
 * (last_meter_at) با همان مقادیری که خوانده‌ایم مقایسه می‌شوند؛ اگر اجرای
 * دیگری (حلقه، تیک همزمان، دو بار باز کردن صفحه) زودتر آن‌ها را جلو برده باشد،
 * این اجرا هیچ پولی کم نمی‌کند و فقط برمی‌گردد.
 *
 * هر پنل مبنای خودش را دارد، پس ده‌ها پنل روی هم نمی‌افتند.
 * خروجی: [charged, hours, gigs, skipped, suspended]
 */
function panel_charge_once(array $panel, int $usedBytes, bool $hourlyEnabled,
                           bool $perGbEnabled, int $grace = 0): array
{
    $out = ['charged' => 0, 'hours' => 0, 'gigs' => 0, 'skipped' => false,
            'suspended' => false];
    $panelId = (int) $panel['id'];
    $tgId = (int) $panel['tg_id'];
    if ((int) ($panel['is_trial'] ?? 0) === 1) {
        return $out;   // پنل تست هیچ‌وقت حساب نمی‌شود
    }
    $panel = panel_sync_prices($panel);
    $amounts = panel_meter_amounts($panel, $usedBytes, $hourlyEnabled, $perGbEnabled);
    $charge = $amounts['gb_cost'] + $amounts['hours_cost'];
    $note = [];
    if ($amounts['gb_cost'] > 0) {
        $note[] = 'حجم ' . $amounts['gigs'] . ' گیگ';
    }
    if ($amounts['hours_cost'] > 0) {
        $note[] = $amounts['hours'] . ' ساعت';
    }

    /* قفل اتمی: فقط اگر مبنای قبلی همان باشد که ما دیدیم جلو می‌رویم */
    $oldBilled = (int) ($panel['billed_bytes'] ?? 0);
    $oldMeter = (int) ($panel['last_meter_at'] ?? 0);
    $locked = DB::update('UPDATE panels SET billed_bytes=?, last_meter_at=?, last_usage=? '
        . 'WHERE id=? AND billed_bytes=? AND last_meter_at=?',
        [$amounts['billed'], max(0, (int) $amounts['meter_until']), $usedBytes,
         $panelId, $oldBilled, $oldMeter]);
    if ($locked === 0) {
        $out['skipped'] = true;   // کسی دیگر حسابش را انجام داده
        return $out;
    }
    $out['hours'] = (int) $amounts['hours'];
    $out['gigs'] = (int) $amounts['gigs'];
    if ($charge <= 0) {
        return $out;
    }

    $balance = balance_of($tgId);
    $paid = min(max(0, $balance), $charge);
    if ($paid > 0) {
        add_balance($tgId, -$paid, 'usage', (string) $panelId, implode(' + ', $note));
        DB::exec('INSERT INTO usage_charges(panel_id, tg_id, bytes, hours, amount, kind, '
            . 'created_at) VALUES(?,?,?,?,?,?,?)',
            [$panelId, $tgId, $amounts['bytes'], (int) $amounts['hours'], $paid,
             $paid < $charge ? 'usage-partial' : 'usage', now_ts()]);
        DB::exec('UPDATE panels SET spent_total = spent_total + ? WHERE id=?',
            [$paid, $panelId]);
        post_report('⚡️ <b>صورتحساب مصرف</b>' . "\n" . '👤 کاربر <code>' . $tgId . "</code>\n"
            . '📦 پنل <code>' . e((string) $panel['panel_username']) . "</code>\n"
            . '📊 ' . e(implode(' + ', $note)) . "\n" . '💵 کسر شد: ' . money($paid)
            . "\n" . '👛 موجودی: ' . money($balance - $paid), 'usage');
        $out['charged'] = $paid;
    }
    if ($paid < $charge) {
        /* کمبود موجودی: گیگ‌ها همین حالا ثبت شدند، پس دوباره کسر نمی‌شوند */
        $graceUntil = (int) ($panel['suspended_at'] ?? 0) + $grace;
        if ($grace === 0 || ($panel['suspended_at'] && now_ts() >= $graceUntil)) {
            if (suspend_panel($panel, 'کمبود موجودی')) {
                $out['suspended'] = true;
            }
        } elseif (!$panel['suspended_at']) {
            DB::exec('UPDATE panels SET suspended_at=? WHERE id=?', [now_ts(), $panelId]);
            Tg::send($tgId, "⚠️ موجودی کیف پولتان کافی نیست.\n"
                . 'پنل <code>' . e((string) $panel['panel_username']) . '</code> تا '
                . $grace . " ثانیه بعد تعلیق می‌شود.");
        }
    }
    return $out;
}

function panels_meter(bool $verbose = false): array
{
    $grace = set_int('meter_grace', 0);
    $hourlyEnabled = set_bool('billing_hourly', true);
    $perGbEnabled = set_bool('billing_per_gb', true);
    $stats = ['charges' => 0, 'suspended' => 0, 'resumed' => 0, 'errors' => 0, 'charged' => 0];
    $balancePass = panels_balance_enforce($verbose);
    $stats['suspended'] += $balancePass['suspended'];
    $stats['resumed'] += $balancePass['resumed'];
    /* پنل‌های معلق: صورتحساب متوقف است و فقط شرط بازگشت بررسی می‌شود */
    foreach (DB::all("SELECT * FROM panels WHERE status='suspended'") as $held) {
        if ((int) ($held['is_trial'] ?? 0) === 1) {
            continue;
        }
        DB::exec('UPDATE panels SET last_meter_at=? WHERE id=?', [now_ts(), (int) $held['id']]);
        $heldBalance = balance_of((int) $held['tg_id']);
        $heldExpired = !empty($held['expires_at']) && (int) $held['expires_at'] <= now_ts();
        if ($heldBalance > 0 && $heldBalance >= (int) $held['min_balance'] && !$heldExpired) {
            if (resume_panel($held)) {
                $stats['resumed']++;
            }
        }
    }
    $rows = DB::all("SELECT * FROM panels WHERE status='active'");
    foreach ($rows as $panel) {
        $panelRow = $panel['pg_panel_id']
            ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]) : null;
        if ($panelRow === null) {
            continue;
        }

        /* پنل تست: هیچ صورتحساب و تعلیقی ندارد؛ فقط بعد از پایان زمانش پاک می‌شود
           (تموم شدن حجم یا صفر بودن موجودی هیچ‌وقت باعث حذف زودتر نمی‌شود) */
        if ((int) ($panel['is_trial'] ?? 0) === 1) {
            if (!empty($panel['expires_at']) && (int) $panel['expires_at'] <= now_ts()) {
                delete_trial_panel($panel, 'پایان زمان تست');
                $stats['trial_deleted'] = ($stats['trial_deleted'] ?? 0) + 1;
            }
            continue;
        }
        $panel = panel_sync_prices($panel);
        $usage = panel_usage_bytes($panelRow, (int) $panel['panel_admin_id'],
            (string) $panel['panel_username']);
        if ($usage === null) {
            $stats['errors']++;
            continue;
        }
        $tgId = (int) $panel['tg_id'];
        $panelId = (int) $panel['id'];

        $result = panel_charge_once($panel, (int) $usage['used'], $hourlyEnabled,
            $perGbEnabled, $grace);
        if ($result['charged'] > 0) {
            $stats['charges']++;
            $stats['charged'] += $result['charged'];
        }
        if ($result['suspended']) {
            $stats['suspended']++;
        }
        if ($result['skipped']) {
            continue;   // رشتهٔ دیگری زودتر همین مصرف را حساب کرده است
        }

        /* پایان اعتبار زمانی */
        if (!empty($panel['expires_at']) && (int) $panel['expires_at'] < now_ts()
            && $panel['status'] === 'active') {
            if (suspend_panel($panel, 'پایان اعتبار')) {
                $stats['suspended']++;
            }
        }

        if ($verbose) {
            echo '· ' . $panel['panel_username'] . ' — used=' . $usage['used']
                . ' gigs=' . $result['gigs'] . ' hours=' . $result['hours']
                . ' charge=' . money($result['charged']) . "\n";
        }
    }
    /* پایان تخفیف‌های مصرف (نرخ هر گیگ به قیمت پایه برمی‌گردد) */
    try {
        $endedDiscounts = panel_discount_expire($verbose);
        if ($endedDiscounts > 0) {
            $stats['discount_ended'] = $endedDiscounts;
        }
    } catch (Throwable $ex) {
        log_line('warning', 'discount expire failed: ' . $ex->getMessage());
    }
    $guard = panels_device_guard($verbose);
    $stats['device_cut'] = $guard['cut'];
    $stats['device_back'] = $guard['back'];
    $stats['device_alerts'] = $guard['alerts'];
    return $stats;
}

/**
 * حذف پنل تست پس از پایان زمان تست:
 * روی خود پنل حذف می‌شود (پنل تست موقتی است) و ردیف محلی هم پاک می‌شود.
 */
function delete_trial_panel(array $panel, string $reason = 'پایان زمان تست'): bool
{
    $panelId = (int) $panel['id'];
    $username = (string) $panel['panel_username'];
    $tgId = (int) $panel['tg_id'];
    $apiOk = true;
    $apiNote = '';
    $panelRow = $panel['pg_panel_id']
        ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]) : null;
    if ($panelRow !== null) {
        try {
            (new PgPanel($panelRow))->delete_admin_with_users($username);
        } catch (Throwable $ex) {
            $message = $ex->getMessage();
            // 404 یعنی از قبل نیست — برای ما یعنی کار تمام است
            $apiOk = str_contains($message, '404');
            if (!$apiOk) {
                $apiNote = mb_substr($message, 0, 140);
            }
        }
    }
    DB::exec('DELETE FROM panels WHERE id=?', [$panelId]);
    DB::exec('DELETE FROM tg_state WHERE data LIKE ?', ['%"panel_id":' . $panelId . '%']);
    $hours = max(1, set_int('trial_hours', 24));
    Tg::send($tgId, '🧪 <b>زمان پنل تست تمام شد</b>' . "\n\n"
        . 'پنل تست <code>' . e($username) . '</code> بعد از ' . fa_digits($hours)
        . " ساعت پاک شد و کانفیگ‌هایش دیگر کار نمی‌کنند.\n"
        . 'اگر خواستی ادامه بدهی، یک پنل اصلی بگیرید 👇',
        [[btn('🛒 خرید پنل', 'menu:buy', null, 'primary')],
         [btn('🧪 تست بعدی', 'menu:trial')]]);
    post_report('🧪 <b>پنل تست حذف شد</b>' . "\n"
        . '👤 کاربر <code>' . $tgId . "</code>\n"
        . '🆔 پنل <code>' . e($username) . "</code>\n"
        . '📝 دلیل: ' . e($reason)
        . ($apiOk ? '' : "\n⚠️ حذف روی پنل انجام نشد: " . e($apiNote)), 'panel');
    log_line('info', 'trial panel deleted', ['panel' => $username, 'api' => $apiOk]);
    return $apiOk;
}

/**
 * موجودی صفر ⇒ کانفیگ‌های پنل غیرفعال شود (بدون حذف).
 * مستقل از API کار می‌کند تا اگر پنل جواب نداد هم اعمال شود.
 */
function panels_balance_enforce(bool $verbose = false): array
{
    $out = ['suspended' => 0, 'resumed' => 0];
    if (!set_bool('suspend_on_zero', true)) {
        return $out;
    }
    foreach (DB::all("SELECT * FROM panels WHERE status IN ('active','suspended')") as $panel) {
        if ((int) ($panel['is_trial'] ?? 0) === 1) {
            continue; // پنل تست رایگان است و به موجودی کاری ندارد
        }
        $balance = balance_of((int) $panel['tg_id']);
        if ($panel['status'] === 'active' && $balance <= 0) {
            suspend_panel($panel, 'موجودی کیف پول صفر است');
            DB::exec('UPDATE panels SET suspended_reason=? WHERE id=?',
                ['balance', (int) $panel['id']]);
            $out['suspended']++;
            if ($verbose) {
                echo '· balance zero: ' . $panel['panel_username'] . "\n";
            }
            continue;
        }
        if ($panel['status'] === 'suspended' && ($panel['suspended_reason'] ?? '') === 'balance'
            && $balance > 0) {
            DB::exec('UPDATE panels SET suspended_reason=NULL WHERE id=?', [(int) $panel['id']]);
            if (resume_panel($panel)) {
                $out['resumed']++;
            }
        }
    }
    return $out;
}

/**
 * گزارش تخلف دستگاه — دقیقاً همان قالبی که خواسته شده.
 * Username = نام کاربری کانفیگ · Belongs To = نام کاربری پنل
 */
function device_limit_report(string $configUser, int $devices, int $maxDevices,
                             string $panelUser, int $cutMinutes): string
{
    return '📵 <b>#DeviceLimit</b>' . "\n"
        . '➖➖➖➖➖➖➖➖➖' . "\n"
        . 'Username : <code>' . e($configUser) . '</code>' . "\n"
        . 'Devices : <b>' . $devices . '</b> / ' . $maxDevices . "\n"
        . '➖➖➖➖➖➖➖➖➖' . "\n"
        . 'Belongs To : <code>' . e($panelUser) . '</code>' . "\n\n"
        . '<i>این کاربر بیشتر از حد مجاز هم‌زمان وصل شده بود؛ دستگاه‌های اضافه قطع شدند '
        . 'و بعد از ' . $cutMinutes . ' دقیقه خودکار برمی‌گردد.</i>';
}

/** ساعت محلی ایران با رقم لاتین — برای «رأس ساعت … فعال می‌شود» */
function device_clock(int $ts): string
{
    return gmdate('H:i', $ts + (int) IRAN_OFFSET);
}

/**
 * ⚠️ قاعدهٔ اصلی این بخش:
 *   مجازات تخلف دستگاه همیشه و فقط روی «همان username» اعمال می‌شود.
 *   هیچ اینباند، هیچ گره، هیچ ادمین/پنل و هیچ کانفیگ دیگری لمس نمی‌شود.
 *   تنها دو تماس مجاز است:
 *     PUT  /api/user/{username}       با {"status":"disabled"|"active"}
 *     POST /api/user/{id}/hwids/reset (فقط برای ریست اتصالات همان کاربر)
 */

/** متن اخطار به صاحب همان کانفیگ (۱۰ دقیقه، فقط همین کاربر) */
function device_restrict_text(string $configUser, int $devices, int $maxDevices,
                              int $minutes, int $unbanAt = 0): string
{
    $at = $unbanAt > 0 ? $unbanAt : now_ts() + $minutes * 60;
    return '⚠️ <b>اخطار عبور از سقف اتصال</b>' . "\n\n"
        . '👤 کانفیگ: <code>' . e($configUser) . '</code>' . "\n"
        . '📱 دستگاه هم‌زمان: ' . $devices . ' (سقف مجاز ' . $maxDevices . ')' . "\n\n"
        . 'کانفیگ شما به دلیل اتصال بیش از ' . $maxDevices . ' دستگاه هم‌زمان، به مدت '
        . $minutes . ' دقیقه موقتاً مسدود شد و اتصالات قبلی ریست گردید. '
        . 'اتصال شما رأس ساعت <b>' . device_clock($at) . '</b> مجدداً فعال می‌شود.' . "\n\n"
        . '<i>این محدودیت فقط روی همین کانفیگ است؛ بقیهٔ کانفیگ‌های پنلت سالماند.</i>';
}

/** متن فعال‌سازی مجدد پس از پایان جریمه */
function device_unban_text(string $configUser = ''): string
{
    return '✅ کانفیگ' . ($configUser !== '' ? ' <code>' . e($configUser) . '</code>' : '')
        . ' شما پس از اتمام زمان جریمه مجدداً فعال شد. '
        . 'لطفاً تعداد دستگاه‌های متصل را در محدودهٔ مجاز نگه دارید.';
}

/** سازگاری با نسخه‌های قبل: همان متن اخطار، با زمان پایان چرخهٔ فعلی */
function device_limit_owner_text(string $configUser, int $devices, int $maxDevices,
                                 int $cutMinutes): string
{
    return device_restrict_text($configUser, $devices, $maxDevices, $cutMinutes);
}

/**
 * دامنهٔ اثر (Scope Guard):
 *   فقط کاربرانی پایش می‌شوند که ادمین سازنده‌شان یک پنلِ ثبت‌شده در همین ربات باشد.
 *   مستثنا: کاربران پنل مالک/پنل اصلی، و کاربران ادمین‌هایی که در ربات ثبت نیستند.
 */
function device_scope_panels(bool $refresh = false): array
{
    /* { username => panelRow } — فقط پنل‌های ثبت‌شده در ربات */
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    foreach (DB::all('SELECT * FROM panels') as $panelRow) {
        $username = (string) ($panelRow['panel_username'] ?? '');
        if ($username !== '') {
            $cache[$username] = $panelRow;
        }
    }
    return $cache;
}

/** نام‌های ادمین ریشهٔ پاسارگارد (همان‌هایی که ربات با آن‌ها لاگین می‌کند) */
function device_scope_root_admins(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    foreach (DB::all('SELECT username FROM pg_panels') as $row) {
        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') {
            $cache[$username] = true;
        }
    }
    return $cache;
}

/**
 * آیا این پنل/کانفیگ متعلق به مالک یا پنل اصلی است؟
 * (کاربرانی که مستقیم روی پنل اصلی ساخته شده‌اند هرگز جریمه نمی‌شوند)
 */
function device_scope_is_owner_panel(array $panelRow): bool
{
    $owner = owner_id();
    $tgId = (int) ($panelRow['tg_id'] ?? 0);
    if ($owner > 0 && $tgId === $owner) {
        return true;
    }
    $panelUsername = trim((string) ($panelRow['panel_username'] ?? ''));
    if ($panelUsername !== '' && isset(device_scope_root_admins()[$panelUsername])) {
        return true;
    }
    return false;
}

/**
 * تصمیم نهایی برای یک کانفیگ مشخص.
 * خروجی: [allowed, reason] — reason یکی از owner|external-admin|not-registered|foreign
 */
function device_scope_check(array $panelRow, array $userRow): array
{
    $creator = trim((string) ($userRow['admin'] ?? ''));
    $panelUsername = (string) ($panelRow['panel_username'] ?? '');

    /* ۱) کاربر باید واقعاً زیر همین پنل ثبت‌شده باشد */
    if ($creator !== '' && $creator !== $panelUsername) {
        return [false, 'foreign'];
    }
    /* ۲) کاربران پنل مالک/پنل اصلی هرگز جریمه نمی‌شوند */
    if (device_scope_is_owner_panel($panelRow)) {
        return [false, 'owner'];
    }
    /* ۳) نام ادمین ریشهٔ پاسارگارد (پنل اصلی) هم مستثناست */
    if ($creator !== '' && isset(device_scope_root_admins(true)[$creator])) {
        return [false, 'owner'];
    }
    /* ۴) ادمین سازنده باید در ربات ثبت شده باشد (پنل فروخته‌شده) */
    $registered = device_scope_panels(true);
    if (!isset($registered[$panelUsername])) {
        return [false, 'not-registered'];
    }
    if ($creator !== '' && !isset($registered[$creator])) {
        return [false, 'external-admin'];
    }
    return [true, ''];
}

/** گزارش رسمی تخلف برای صاحب همان پنل (مدیر ثبت‌شده در ربات) */
function device_owner_report_text(string $configUser, int $devices, int $maxDevices,
                                  int $minutes, int $unbanAt): string
{
    return '⚠️ <b>گزارش تخلف اتصال هم‌زمان دستگاه‌ها</b>' . "\n\n"
        . '👤 نام کاربری کلاینت: <code>' . e($configUser) . '</code>' . "\n"
        . '📱 تعداد دستگاه‌های متصل: <b>' . $devices . '</b> '
        . '(بیشتر از سقف مجاز: ' . $maxDevices . ' دستگاه)' . "\n"
        . '⏱ وضعیت: این کانفیگ به مدت ' . $minutes
        . ' دقیقه غیرفعال (Disabled) شد و اتصالات آن ریست گردید.' . "\n"
        . '🕒 زمان فعال‌سازی مجدد خودکار: رأس ساعت <b>' . device_clock($unbanAt) . '</b>' . "\n\n"
        . '<i>این محدودیت فقط روی همین کانفیگ اعمال شد؛ سایر کانفیگ‌های پنل شما دست‌نخورده‌اند.</i>';
}

/** بررسی دوبارهٔ یک کانفیگ از API پیش از هر اکشن (جلوگیری از مسدودی کاذب) */
function device_recheck(PgPanel $client, string $username, int $maxDevices): array
{
    try {
        $fresh = $client->user_info($username);
    } catch (Throwable $ex) {
        return ['ok' => false, 'devices' => 0, 'error' => $ex->getMessage()];
    }
    if (!is_array($fresh) || $fresh === []) {
        return ['ok' => false, 'devices' => 0, 'error' => 'empty answer'];
    }
    $devices = (int) ($fresh['hwid_count'] ?? (is_array($fresh['hwids'] ?? null)
        ? count($fresh['hwids']) : 0));
    $status = (string) ($fresh['status'] ?? 'active');
    if ($status === 'disabled') {
        return ['ok' => false, 'devices' => $devices, 'error' => 'already disabled'];
    }
    if ($devices <= $maxDevices) {
        return ['ok' => false, 'devices' => $devices, 'error' => 'under limit now'];
    }
    return ['ok' => true, 'devices' => $devices, 'error' => ''];
}

/** ردیف‌های محدودشدهٔ یک پنل (برای نمایش در صفحهٔ دستگاه‌ها) */
function device_restricted_rows(int $panelId): array
{
    return DB::all('SELECT * FROM temp_restricted_users WHERE panel_id=? ORDER BY unban_at ASC',
        [$panelId]);
}

/**
 * مرحله ۳ — رفع مسدودی خودکار.
 * هر دقیقه از حلقهٔ ربات صدا زده می‌شود؛ هر ردیفی که unban_at آن گذشته باشد
 * با PUT /api/user/{username} {"status":"active"} دوباره فعال و ردیفش پاک می‌شود.
 * خروجی: تعداد کاربرانی که برگشتند.
 */
function device_guard_unban(bool $verbose = false): int
{
    $back = 0;
    foreach (DB::all('SELECT * FROM temp_restricted_users') as $row) {
        $unbanAt = (int) $row['unban_at'];
        if ($unbanAt <= 0) {
            /* ردیف کهنه — بی‌معنا */
            DB::exec('DELETE FROM temp_restricted_users WHERE id=?', [(int) $row['id']]);
            continue;
        }
        if ($unbanAt > now_ts()) {
            continue;
        }
        $panel = DB::one('SELECT * FROM panels WHERE id=?', [(int) $row['panel_id']]);
        if ($panel !== null) {
            $pgRow = panel_pg_row($panel);
            if ($pgRow !== null) {
                try {
                    /* فقط همین username روی پنل فعال می‌شود */
                    (new PgPanel($pgRow))->set_user_status((string) $row['username'], 'active');
                } catch (Throwable $ex) {
                    /* پنل در دسترس نیست: ردیف می‌ماند و دقیقهٔ بعد دوباره تلاش می‌شود */
                    log_line('warning', 'device unban failed: ' . $ex->getMessage());
                    continue;
                }
            }
        }
        DB::exec('DELETE FROM temp_restricted_users WHERE id=?', [(int) $row['id']]);
        /* اگر کاربر دیگری از همین پنل محدود نیست، علامت تخلف پنل هم برداشته می‌شود */
        $left = (int) DB::val('SELECT COUNT(*) FROM temp_restricted_users WHERE panel_id=?',
            [(int) $row['panel_id']], 0);
        if ($left === 0) {
            DB::exec('UPDATE panels SET violation_until=0 WHERE id=?', [(int) $row['panel_id']]);
        }
        log_device_guard('unbanned', ['panel' => (string) ($panel['panel_username'] ?? ''),
            'username' => (string) $row['username'], 'restricted_at' => (int) $row['restricted_at'],
            'devices' => (int) $row['devices']]);
        $tgId = (int) $row['tg_id'];
        if ($tgId > 0 && !is_staff($tgId)) {
            Tg::send($tgId, device_unban_text((string) $row['username']));
        }
        post_report('♻️ <b>#DeviceLimit</b>' . "\n"
            . '➖➖➖➖➖➖➖➖➖' . "\n"
            . 'Username : <code>' . e((string) $row['username']) . '</code>' . "\n"
            . 'Status : <b>active</b> (بعد از پایان جریمه)' . "\n"
            . '➖➖➖➖➖➖➖➖➖' . "\n"
            . 'Belongs To : <code>' . e((string) ($panel['panel_username'] ?? '')) . '</code>',
            'panel');
        if ($verbose) {
            echo '· device unban: ' . $row['username'] . " (only that user)\n";
        }
        $back++;
    }
    return $back;
}

/**
 * مرحله ۱ و ۲ — تشخیص و محدودسازی موقت، فقط برای همان کاربر متخلف.
 * خروجی: cut, alerts, reset
 */
function device_guard_detect(bool $verbose = false): array
{
    $out = ['cut' => 0, 'alerts' => 0, 'reset' => 0, 'skipped' => 0];
    $cutMinutes = max(1, set_int('device_cut_minutes', 10));
    $cutSeconds = $cutMinutes * 60;
    $maxDevices = max(1, set_int('device_max_devices', 10));
    $registered = device_scope_panels(true);

    foreach (DB::all("SELECT * FROM panels WHERE status='active'") as $panel) {
        $panelId = (int) $panel['id'];
        $panelUsername = (string) $panel['panel_username'];
        /* فقط پنل‌های ثبت‌شده در ربات پایش می‌شوند */
        if ($panelUsername === '' || !isset($registered[$panelUsername])) {
            continue;
        }
        /* کاربران پنل مالک/پنل اصلی هرگز جریمه نمی‌شوند */
        if (device_scope_is_owner_panel($panel)) {
            log_device_guard('scope-skip', ['panel' => $panelUsername, 'reason' => 'owner']);
            continue;
        }
        $pgRow = panel_pg_row($panel);
        if ($pgRow === null) {
            continue;
        }
        $client = new PgPanel($pgRow);
        try {
            /* GET /api/users?admin=… — فقط خواندن */
            $users = $client->users_of($panelUsername, 500);
        } catch (Throwable $ex) {
            continue;
        }
        $peak = 0;
        foreach ($users as $userRow) {
            $count = (int) ($userRow['hwid_count'] ?? 0);
            if ($count > $peak) {
                $peak = $count;
            }
            if ($count <= $maxDevices) {
                continue;
            }
            $uname = (string) ($userRow['username'] ?? '');
            if ($uname === '') {
                continue;
            }
            /* ── دامنهٔ اثر: مالک / ادمین ثبت‌نشده / کاربر پنل دیگر → رد ── */
            [$allowed, $reason] = device_scope_check($panel, $userRow);
            if (!$allowed) {
                $out['skipped']++;
                log_device_guard('scope-skip', ['panel' => $panelUsername,
                    'username' => $uname, 'reason' => $reason, 'devices' => $count]);
                if ($verbose) {
                    echo '· device scope skip (' . $reason . '): ' . $uname . "\n";
                }
                continue;
            }
            /* ── بررسی عدم وجود جریمهٔ فعال (بدون دستور تکراری) ── */
            if (DB::one('SELECT id FROM temp_restricted_users WHERE panel_id=? AND username=?',
                    [$panelId, $uname]) !== null) {
                continue;
            }
            /* ── بررسی دو مرحله‌ای: خواندن دوبارهٔ کاربر از پنل ── */
            $again = device_recheck($client, $uname, $maxDevices);
            if (!$again['ok']) {
                log_device_guard('recheck-skip', ['panel' => $panelUsername, 'username' => $uname,
                    'reason' => $again['error'], 'devices' => $again['devices']]);
                continue;
            }
            $count = $again['devices'];
            $userId = (int) ($userRow['id'] ?? 0);
            try {
                /* فقط همان کاربر غیرفعال می‌شود — نه پنل، نه اینباند، نه کسی دیگر */
                $client->set_user_status($uname, 'disabled');
            } catch (Throwable $ex) {
                log_device_guard('restrict-failed', ['panel' => $panelUsername,
                    'username' => $uname, 'error' => $ex->getMessage()]);
                log_line('warning', 'device restrict failed: ' . $ex->getMessage());
                continue;
            }
            /* ریست اتصالات فعال همان کاربر تا سوکت‌ها همین حالا قطع شوند */
            $devicesReset = 0;
            if ($userId > 0) {
                try {
                    $client->reset_hwids($userId);
                    $devicesReset = $count;
                    $out['reset']++;
                } catch (Throwable $ex) {
                    log_line('warning', 'device hwid reset failed: ' . $ex->getMessage());
                }
            }
            $now = now_ts();
            $unbanAt = $now + $cutSeconds;
            DB::exec('INSERT OR REPLACE INTO temp_restricted_users'
                . '(panel_id, tg_id, username, restricted_at, unban_at, devices, max_allowed, reason)'
                . ' VALUES(?,?,?,?,?,?,?,?)',
                [$panelId, (int) $panel['tg_id'], $uname, $now, $unbanAt,
                 $count, $maxDevices, 'device_limit']);
            DB::exec('UPDATE panels SET violation_until=?, violation_count=violation_count+1, '
                . 'last_devices_max=? WHERE id=?',
                [$unbanAt, $count, $panelId]);

            log_device_guard('restricted', ['panel' => $panelUsername, 'username' => $uname,
                'devices' => $count, 'max' => $maxDevices, 'minutes' => $cutMinutes,
                'unban_at' => date('Y-m-d H:i:s', $unbanAt + (int) IRAN_OFFSET)]);

            post_report(device_limit_report($uname, $count, $maxDevices,
                $panelUsername, $cutMinutes), 'panel');
            /* گزارش رسمی به پی‌وی صاحب همان پنل (مدیر ثبت‌شده در ربات) */
            $tgId = (int) $panel['tg_id'];
            if ($tgId > 0 && !is_staff($tgId)) {
                Tg::send($tgId, device_owner_report_text($uname, $count, $maxDevices,
                        $cutMinutes, $unbanAt),
                    [[btn('📱 دستگاه‌ها', 'pn:' . $panelId . ':sec', null, 'danger')]]);
                log_device_guard('owner-notified', ['panel' => $panelUsername, 'tg_id' => $tgId]);
            }
            $out['cut']++;
            $out['alerts']++;
            log_line('info', 'device limit: only user ' . $uname . ' restricted for '
                . $cutMinutes . ' min (' . $count . ' > ' . $maxDevices . ')');
            if ($verbose) {
                echo '· device limit: ' . $panelUsername . ' / ' . $uname
                    . ' (' . $count . ' > ' . $maxDevices . ') devices reset: '
                    . $devicesReset . " — only that user\n";
            }
        }
        if ($peak > 0) {
            DB::exec('UPDATE panels SET last_devices_max=? WHERE id=? AND last_devices_max<?',
                [$peak, $panelId, $peak]);
        }
    }
    return $out;
}

/**
 * کنترل تخلف دستگاه (تفکیک‌شده برای تک‌کاربر متخلف):
 *   • ابتدا هر محدودیت منقضی‌شده آزاد می‌شود.
 *   • سپس هر کانفیگی که از سقف دستگاه رد شده باشد، فقط خودش غیرفعال می‌شود،
 *     اتصالاتش ریست می‌شود و بعد از device_cut_minutes خودکار برمی‌گردد.
 *   • هیچ تماس پنل‌محور (اینباند/گره/ریست سرویس) در این مسیر وجود ندارد.
 * خروجی: [cut, back, alerts, reset]
 */
function panels_device_guard(bool $verbose = false): array
{
    $out = ['cut' => 0, 'back' => 0, 'alerts' => 0, 'reset' => 0, 'skipped' => 0];
    $out['back'] = device_guard_unban($verbose);
    if (!set_bool('device_guard_on', true)) {
        return $out;
    }
    $det = device_guard_detect($verbose);
    $out['cut'] = $det['cut'];
    $out['alerts'] = $det['alerts'];
    $out['reset'] = $det['reset'];
    $out['skipped'] = $det['skipped'];
    return $out;
}

function panel_api_status(array $panelRow, int $adminId, string $status, string $username = ''): void
{
    if ($adminId <= 0 && $username === '') {
        return;
    }
    try {
        $client = new PgPanel($panelRow);
        $client->set_status($username !== '' ? $username : $adminId, $status);
    } catch (Throwable $ex) {
        log_line('warning', 'set_status failed: ' . $ex->getMessage());
    }
}

function suspend_panel(array $panel, string $reason): bool
{
    $fresh = DB::one('SELECT * FROM panels WHERE id=?', [(int) $panel['id']]);
    if ($fresh === null) {
        return false;
    }
    if (($fresh['status'] ?? '') === 'suspended') {
        return false; // همین حالا معلق است؛ پیام تکراری لازم نیست
    }
    $panel = array_merge($panel, $fresh);
    $panelRow = $panel['pg_panel_id']
        ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]) : null;
    if ($panelRow !== null) {
        panel_api_status($panelRow, (int) $panel['panel_admin_id'], 'disabled',
            (string) $panel['panel_username']);
    }
    DB::exec("UPDATE panels SET status='suspended', suspended_at=? WHERE id=?",
        [now_ts(), (int) $panel['id']]);
    post_report('⛔️ <b>پنل تعلیق شد</b>' . "\n"
        . '👤 کاربر <code>' . (int) $panel['tg_id'] . "</code>\n"
        . '📦 پنل <code>' . e((string) $panel['panel_username']) . "</code>\n"
        . '📝 دلیل: ' . e($reason), 'panel');
    if ((int) ($panel['is_trial'] ?? 0) === 1) {
        delete_trial_panel($panel, $reason);
        return true;
    }
    Tg::send((int) $panel['tg_id'], '⛔️ پنل <code>' . e((string) $panel['panel_username'])
        . "</code> تعلیق شد.\n" . 'دلیل: ' . e($reason) . "\n"
        . 'با شارژ کیف پول، پنل خودکار فعال می‌شود.',
        [[btn('💳 شارژ کیف پول', 'menu:wallet', null, 'success')]]);
    return true;
}

function resume_panel(array $panel): bool
{
    $fresh = DB::one('SELECT * FROM panels WHERE id=?', [(int) $panel['id']]);
    if ($fresh === null) {
        return false;
    }
    if (($fresh['status'] ?? '') === 'active') {
        return false; // از قبل فعال است؛ پیام تکراری لازم نیست
    }
    $panel = array_merge($panel, $fresh);
    $panelRow = $panel['pg_panel_id']
        ? DB::one('SELECT * FROM pg_panels WHERE id=?', [(int) $panel['pg_panel_id']]) : null;
    if ($panelRow !== null) {
        panel_api_status($panelRow, (int) $panel['panel_admin_id'], 'active',
            (string) $panel['panel_username']);
    }
    DB::exec("UPDATE panels SET status='active', suspended_at=NULL WHERE id=?",
        [(int) $panel['id']]);
    post_report('✅ <b>پنل دوباره فعال شد</b>' . "\n"
        . '👤 کاربر <code>' . (int) $panel['tg_id'] . "</code>\n"
        . '📦 پنل <code>' . e((string) $panel['panel_username']) . '</code>', 'panel');
    Tg::send((int) $panel['tg_id'], '✅ پنل <code>' . e((string) $panel['panel_username'])
        . '</code> دوباره فعال شد.',
        [[btn('📦 پنل‌های من', 'menu:panels', null, 'primary')]]);
    return true;
}

