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

/**
 * آیا پنل از پنجرهٔ پیش‌پرداخت گذشته است؟
 * billing_start_at=0 برای پنل‌های قدیمی/بدون پیش‌پرداخت است و فوراً قابل
 * بررسی موجودی است. در لحظهٔ دقیق پایان هم هنوز تعلیق صفر-موجودی مجاز نیست؛
 * شرط صریح مطابق قرارداد: time() > billing_start_at.
 */
function panel_balance_suspension_allowed(array $panel, ?int $at = null): bool
{
    if (panel_is_unlimited($panel)) {
        return false;
    }
    $billingStart = (int) ($panel['billing_start_at'] ?? 0);
    $at = $at ?? time();
    return $billingStart <= 0 || $at > $billingStart;
}

/** تنها تعلیق مجازِ پنجرهٔ پیش‌پرداخت: تمام‌شدن سقف ترافیک واقعی. */
function panel_traffic_limit_reached(array $panel, array $usage): bool
{
    if (panel_is_unlimited($panel)) {
        return false;
    }
    $limit = (int) ($usage['limit'] ?? 0);
    if ($limit <= 0) {
        $limit = (int) ($panel['data_cap'] ?? 0);
    }
    return $limit > 0 && (int) ($usage['used'] ?? 0) >= $limit;
}

/** هیچ تعلیقِ مانده از موجودی نباید سقف ترافیک/تاریخ انقضا را دور بزند. */
function panel_balance_hold_can_resume(array $panel): bool
{
    if (panel_is_unlimited($panel)) {
        return true;
    }
    if (!empty($panel['expires_at']) && (int) $panel['expires_at'] <= now_ts()) {
        return false;
    }
    return !panel_traffic_limit_reached($panel, [
        'used' => (int) ($panel['last_usage'] ?? 0),
        'limit' => (int) ($panel['data_cap'] ?? 0),
    ]);
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
    if ((int) ($panel['is_trial'] ?? 0) === 1 || panel_is_unlimited($panel)) {
        return $out;   // پنل تست و پنل نامحدود هیچ صورتحساب محلی ندارند
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
    if ($paid < $charge && panel_balance_suspension_allowed($panel)) {
        /* کمبود موجودی بعد از پایان پیش‌پرداخت: گیگ‌ها همین حالا ثبت شدند، پس دوباره کسر نمی‌شوند */
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
    } elseif ($paid < $charge) {
        /* در ۲۴ ساعت تسویه‌شده، صفر بودن کیف پول هرگز پنل را معلق نمی‌کند. */
        log_line('info', 'prepaid window: balance suspension skipped', ['panel' => $panelId,
            'billing_start_at' => (int) ($panel['billing_start_at'] ?? 0)]);
    }
    return $out;
}

/**
 * v3.18.7 — live per-client billing for unlimited panels.
 *
 * A minute is written only when PasarGuard's node online-IP endpoint reports at
 * least one current connection for that exact user. We intentionally do not
 * fall back to used traffic, last-seen, or users?online=true: those are not a
 * live socket signal and would make offline time billable.
 */
const UNLIMITED_ONLINE_MINUTE_SECONDS = 60;
const UNLIMITED_ONLINE_HOUR_SECONDS = 3600;

function unlimited_online_minute_start(?int $at = null): int
{
    $at = max(0, $at ?? now_ts());
    return intdiv($at, UNLIMITED_ONLINE_MINUTE_SECONDS) * UNLIMITED_ONLINE_MINUTE_SECONDS;
}

function unlimited_online_hour_start(?int $at = null): int
{
    $at = max(0, $at ?? now_ts());
    return intdiv($at, UNLIMITED_ONLINE_HOUR_SECONDS) * UNLIMITED_ONLINE_HOUR_SECONDS;
}

/** Exact ownership guard when the API returns an admin object with the user. */
function unlimited_online_user_owner(array $user): string
{
    $admin = $user['admin'] ?? null;
    if (is_array($admin)) {
        return trim((string) ($admin['username'] ?? ''));
    }
    return '';
}

/**
 * Persists supplied observations. This small pure-storage seam also lets the
 * regression suite exercise accounting without pretending an HTTP response is
 * live telemetry. An observation with connections<=0 intentionally writes no
 * row: offline time is zero cost, while the unique index makes a repeated tick
 * in the same minute harmless.
 *
 * @param list<array{id:int,username?:string,connections:int,sampled_at?:int}> $observations
 * @return array{recorded:int,active_clients:int,active_minutes:int}
 */
function unlimited_online_record_observations(array $panel, array $observations, ?int $at = null): array
{
    $out = ['recorded' => 0, 'active_clients' => 0, 'active_minutes' => 0];
    if (!panel_is_hourly_unlimited($panel)) {
        return $out;
    }
    $panelId = (int) ($panel['id'] ?? 0);
    if ($panelId <= 0) {
        throw new InvalidArgumentException('invalid unlimited panel id');
    }
    $unique = [];
    foreach ($observations as $row) {
        $clientId = (int) ($row['id'] ?? 0);
        $connections = max(0, (int) ($row['connections'] ?? 0));
        if ($clientId <= 0 || $connections <= 0) {
            continue;
        }
        $sampledAt = max(0, (int) ($row['sampled_at'] ?? $at ?? now_ts()));
        $minuteAt = unlimited_online_minute_start($sampledAt);
        $username = mb_substr(trim((string) ($row['username'] ?? '')), 0, 191);
        $inserted = DB::q('INSERT OR IGNORE INTO unlimited_online_minutes(panel_id, client_id, client_username, '
            . 'minute_at, connections, sampled_at) VALUES(?,?,?,?,?,?)',
            [$panelId, $clientId, $username, $minuteAt, $connections, $sampledAt])->rowCount() === 1;
        if ($inserted) {
            $out['recorded']++;
            $out['active_minutes']++;
            $unique[$clientId] = true;
        }
    }
    $out['active_clients'] = count($unique);
    return $out;
}

/**
 * Reads every client belonging to a sold panel, then verifies each one through
 * /api/node/online_stats/{id}/ip. A single unavailable/ambiguous node response
 * aborts this panel's sample rather than converting uncertainty into an online
 * or offline minute.
 */
function unlimited_online_sample_panel(array $panel, ?int $at = null): array
{
    $panelId = (int) ($panel['id'] ?? 0);
    $adminUsername = trim((string) ($panel['panel_username'] ?? ''));
    $pgRow = panel_pg_row($panel);
    if ($panelId <= 0 || $adminUsername === '' || $pgRow === null) {
        throw new PanelError('live billing panel mapping is incomplete');
    }
    $client = new PgPanel($pgRow);
    $users = $client->users_of_all($adminUsername);
    $observations = [];
    foreach ($users as $user) {
        $clientId = (int) ($user['id'] ?? 0);
        $username = trim((string) ($user['username'] ?? ''));
        if ($clientId <= 0) {
            throw new PanelError('users response has no stable user id');
        }
        $owner = unlimited_online_user_owner($user);
        if ($owner !== '' && mb_strtolower($owner) !== mb_strtolower($adminUsername)) {
            throw new PanelError('users response crossed sold-panel ownership');
        }
        $live = $client->user_online_connections($clientId);
        if (!$live['available']) {
            throw new PanelError('live node stats are unavailable; minute was not recorded');
        }
        $observations[] = [
            'id' => $clientId,
            'username' => $username,
            'connections' => (int) $live['connections'],
            /* The bucket belongs to the moment the direct node response was read. */
            'sampled_at' => now_ts(),
        ];
    }
    $out = unlimited_online_record_observations($panel, $observations, $at);
    $out['users'] = count($users);
    return $out;
}

/**
 * Settles one completed hour under a SQLite write lock. The denominator stays
 * 60 for the carry, so 750 toman/hour applied to a single active minute becomes
 * 12 toman + 30/60 carried forward, never 13 repeatedly.
 */
function unlimited_online_settle_hour(int $panelId, int $hourAt, ?int $at = null): array
{
    $at = $at ?? now_ts();
    return db_tx(static function () use ($panelId, $hourAt, $at): array {
        $empty = ['state' => 'skip', 'new' => false, 'settled' => false, 'pending' => false,
            'panel_id' => $panelId, 'hour_at' => $hourAt, 'amount' => 0, 'active_minutes' => 0,
            'active_clients' => 0, 'remainder_before' => 0, 'remainder_after' => 0];
        $panel = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
        if ($panel === null || !panel_is_hourly_unlimited($panel)) {
            return $empty;
        }
        $existing = DB::one('SELECT * FROM unlimited_online_settlements WHERE panel_id=? AND hour_at=?',
            [$panelId, $hourAt]);
        if ($existing !== null && (string) $existing['status'] === 'settled') {
            return array_merge($empty, [
                'state' => 'already', 'settled' => true, 'settlement_id' => (int) $existing['id'],
                'amount' => (int) $existing['amount'],
                'active_minutes' => (int) $existing['active_minutes'],
                'active_clients' => (int) $existing['active_clients'],
                'remainder_before' => (int) $existing['remainder_before'],
                'remainder_after' => (int) $existing['remainder_after'],
            ]);
        }
        DB::exec('INSERT OR IGNORE INTO unlimited_online_billing_state(panel_id, remainder_numerator, updated_at) '
            . 'VALUES(?,?,?)', [$panelId, 0, $at]);
        $state = DB::one('SELECT * FROM unlimited_online_billing_state WHERE panel_id=?', [$panelId])
            ?? ['remainder_numerator' => 0];
        $currentCarry = max(0, (int) $state['remainder_numerator']) % 60;

        if ($existing === null) {
            $usage = DB::one('SELECT COUNT(*) AS active_minutes, COUNT(DISTINCT client_id) AS active_clients '
                . 'FROM unlimited_online_minutes WHERE panel_id=? AND minute_at>=? AND minute_at<?',
                [$panelId, $hourAt, $hourAt + UNLIMITED_ONLINE_HOUR_SECONDS])
                ?? ['active_minutes' => 0, 'active_clients' => 0];
            $activeMinutes = max(0, (int) $usage['active_minutes']);
            $activeClients = max(0, (int) $usage['active_clients']);
            if ($activeMinutes === 0) {
                return $empty;
            }
            $rate = max(0, unlimited_online_hourly_rate($panel));
            if ($rate > 0 && $activeMinutes > intdiv(PHP_INT_MAX - $currentCarry, $rate)) {
                throw new RuntimeException('unlimited live-minute amount overflow');
            }
            $numerator = $currentCarry + ($activeMinutes * $rate);
            $amount = intdiv($numerator, 60);
            $carryAfter = $numerator % 60;
            $settlementId = DB::exec('INSERT INTO unlimited_online_settlements(panel_id, hour_at, hourly_price, '
                . 'active_minutes, active_clients, amount, remainder_before, remainder_after, status, '
                . 'created_at, settled_at, reported_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                [$panelId, $hourAt, $rate, $activeMinutes, $activeClients, $amount, $currentCarry,
                 $carryAfter, 'pending', $at, 0, 0]);
            $existing = DB::one('SELECT * FROM unlimited_online_settlements WHERE id=?', [$settlementId]);
        }
        if ($existing === null) {
            throw new RuntimeException('unlimited live-minute settlement was not stored');
        }
        $amount = max(0, (int) $existing['amount']);
        $balance = balance_of((int) $panel['tg_id']);
        $event = [
            'state' => 'pending', 'new' => false, 'settled' => false, 'pending' => true,
            'settlement_id' => (int) $existing['id'], 'panel_id' => $panelId, 'hour_at' => $hourAt,
            'tg_id' => (int) $panel['tg_id'], 'username' => (string) $panel['panel_username'],
            'hourly_price' => (int) $existing['hourly_price'],
            'active_minutes' => (int) $existing['active_minutes'],
            'active_clients' => (int) $existing['active_clients'], 'amount' => $amount,
            'remainder_before' => (int) $existing['remainder_before'],
            'remainder_after' => (int) $existing['remainder_after'], 'balance' => $balance,
        ];
        if ($balance < $amount) {
            return $event;
        }
        if ($amount > 0) {
            $changed = DB::update('UPDATE users SET balance=balance-? WHERE tg_id=? AND balance>=?',
                [$amount, (int) $panel['tg_id'], $amount]);
            if ($changed !== 1) {
                throw new RuntimeException('live-minute wallet changed during settlement');
            }
        }
        $changed = DB::update("UPDATE unlimited_online_settlements SET status='settled', settled_at=? "
            . "WHERE id=? AND status='pending'", [$at, (int) $existing['id']]);
        if ($changed !== 1) {
            throw new RuntimeException('live-minute settlement changed during settlement');
        }
        DB::exec('UPDATE unlimited_online_billing_state SET remainder_numerator=?, updated_at=? WHERE panel_id=?',
            [(int) $existing['remainder_after'], $at, $panelId]);
        if ($amount > 0) {
            $newBalance = $balance - $amount;
            DB::exec('INSERT INTO wallet_tx(tg_id, amount, kind, ref, note, balance, created_at) '
                . 'VALUES(?,?,?,?,?,?,?)', [(int) $panel['tg_id'], -$amount, 'unlimited-online',
                (string) $existing['id'], 'صورتحساب زمان آنلاین پنل نامحدود', $newBalance, $at]);
            DB::exec('INSERT INTO usage_charges(panel_id, tg_id, bytes, hours, amount, kind, created_at) '
                . 'VALUES(?,?,?,?,?,?,?)', [$panelId, (int) $panel['tg_id'], 0,
                ((int) $existing['active_minutes']) / 60, $amount, 'unlimited-online', $at]);
            DB::exec('UPDATE panels SET spent_total=spent_total+?, last_meter_at=? WHERE id=?',
                [$amount, $at, $panelId]);
            $event['balance'] = $newBalance;
        }
        $event['state'] = 'settled';
        $event['new'] = true;
        $event['settled'] = true;
        $event['pending'] = false;
        return $event;
    });
}

/** Settles the oldest due live-time hours first; an unpaid hour blocks later ones. */
function unlimited_online_settle_due(array $panel, ?int $at = null): array
{
    $at = $at ?? now_ts();
    $panelId = (int) ($panel['id'] ?? 0);
    $out = ['events' => [], 'pending' => false];
    if ($panelId <= 0 || !panel_is_hourly_unlimited($panel)) {
        return $out;
    }
    $cutoff = unlimited_online_hour_start($at);
    $hours = [];
    foreach (DB::all("SELECT hour_at FROM unlimited_online_settlements WHERE panel_id=? AND status='pending' "
            . 'AND hour_at<? ORDER BY hour_at', [$panelId, $cutoff]) as $row) {
        $hours[(int) $row['hour_at']] = true;
    }
    foreach (DB::all('SELECT DISTINCT minute_at - (minute_at % 3600) AS hour_at '
            . 'FROM unlimited_online_minutes WHERE panel_id=? AND minute_at<? ORDER BY hour_at',
            [$panelId, $cutoff]) as $row) {
        $hours[(int) $row['hour_at']] = true;
    }
    ksort($hours, SORT_NUMERIC);
    foreach (array_keys($hours) as $hourAt) {
        $event = unlimited_online_settle_hour($panelId, (int) $hourAt, $at);
        if (($event['state'] ?? '') === 'pending') {
            $out['pending'] = true;
            $out['events'][] = $event;
            break;
        }
        if (!empty($event['new'])) {
            $out['events'][] = $event;
        }
    }
    return $out;
}

/** One Persian aggregate report, never one report per minute/client. */
function unlimited_online_report_text(array $event): string
{
    $hourAt = (int) ($event['hour_at'] ?? 0);
    $activeMinutes = max(0, (int) ($event['active_minutes'] ?? 0));
    $activeClients = max(0, (int) ($event['active_clients'] ?? 0));
    $carry = max(0, (int) ($event['remainder_after'] ?? 0)) % 60;
    $text = '♾️ <b>صورتحساب زمان آنلاین پنل نامحدود</b>' . "\n"
        . '👤 مشتری <code>' . (int) ($event['tg_id'] ?? 0) . "</code>\n"
        . '📦 پنل <code>' . e((string) ($event['username'] ?? '')) . "</code>\n"
        . '🕒 بازه: ' . fa_ts($hourAt) . ' تا ' . fa_ts($hourAt + UNLIMITED_ONLINE_HOUR_SECONDS) . "\n"
        . '👥 کاربرِ فعال: ' . fa_digits($activeClients) . "\n"
        . '⏱ مجموع زمان فعال: ' . fa_digits($activeMinutes) . ' دقیقهٔ کاربر' . "\n"
        . '💵 نرخ: ' . money((int) ($event['hourly_price'] ?? 0)) . ' / کاربر / ساعت' . "\n"
        . '➖ کسر شد: <b>' . money((int) ($event['amount'] ?? 0)) . "</b>\n"
        . '👛 موجودی: ' . money((int) ($event['balance'] ?? 0));
    if ($carry > 0) {
        $text .= "\n" . '🔢 باقیماندهٔ کسری برای ساعت بعد: ' . fa_digits($carry) . '/۶۰ تومان';
    }
    return $text;
}

/** Claim before Telegram I/O: concurrent ticks can produce at most one hourly report. */
function unlimited_online_post_report(array $event): bool
{
    $id = (int) ($event['settlement_id'] ?? 0);
    if ($id <= 0) {
        return false;
    }
    $claimed = DB::update('UPDATE unlimited_online_settlements SET reported_at=? '
        . 'WHERE id=? AND reported_at=0', [now_ts(), $id]);
    if ($claimed !== 1) {
        return false;
    }
    post_report(unlimited_online_report_text($event), 'usage');
    return true;
}

/**
 * Called once per minute by the poller (and by --meter). It measures only active
 * sold panels; a balance-suspended panel retries its oldest hourly invoice and
 * resumes only after that exact debt has been settled.
 */
function panels_unlimited_online_tick(bool $verbose = false, ?int $at = null): array
{
    $at = $at ?? now_ts();
    $out = ['panels' => 0, 'users' => 0, 'active_minutes' => 0, 'settlements' => 0,
        'charged' => 0, 'suspended' => 0, 'resumed' => 0, 'errors' => 0];
    $rows = DB::all("SELECT * FROM panels WHERE is_unlimited=1 AND unlimited_billing_mode='hourly' "
        . "AND (status='active' OR (status='suspended' AND suspended_reason IN ('online_balance','hourly_balance'))) ");
    foreach ($rows as $panel) {
        $out['panels']++;
        $panelId = (int) $panel['id'];
        if ((string) $panel['status'] === 'active') {
            try {
                $sample = unlimited_online_sample_panel($panel, $at);
                $out['users'] += (int) ($sample['users'] ?? 0);
                $out['active_minutes'] += (int) ($sample['active_minutes'] ?? 0);
                if ($verbose) {
                    echo '· unlimited live ' . $panel['panel_username'] . ' — users='
                        . (int) ($sample['users'] ?? 0) . ' active-minutes='
                        . (int) ($sample['active_minutes'] ?? 0) . "\n";
                }
            } catch (Throwable $ex) {
                $out['errors']++;
                log_line('warning', 'unlimited live-minute sample failed: ' . $ex->getMessage(),
                    ['panel' => $panelId]);
            }
        }
        try {
            $settlement = unlimited_online_settle_due($panel, $at);
            $hasPending = (bool) ($settlement['pending'] ?? false);
            foreach ($settlement['events'] as $event) {
                if (($event['state'] ?? '') === 'pending') {
                    continue;
                }
                if (!empty($event['new'])) {
                    $out['settlements']++;
                    $out['charged'] += (int) $event['amount'];
                    unlimited_online_post_report($event);
                }
            }
            $fresh = DB::one('SELECT * FROM panels WHERE id=?', [$panelId]);
            if ($fresh !== null && $hasPending) {
                if ((string) $fresh['status'] === 'active'
                    && suspend_panel($fresh, 'کمبود موجودی برای زمان آنلاین پنل نامحدود')) {
                    DB::exec('UPDATE panels SET suspended_reason=? WHERE id=?', ['online_balance', $panelId]);
                    $out['suspended']++;
                }
                continue;
            }
            if ($fresh !== null && (string) $fresh['status'] === 'suspended'
                && in_array((string) ($fresh['suspended_reason'] ?? ''), ['online_balance', 'hourly_balance'], true)) {
                if (resume_panel($fresh)) {
                    DB::exec('UPDATE panels SET suspended_reason=NULL WHERE id=?', [$panelId]);
                    $out['resumed']++;
                }
            }
        } catch (Throwable $ex) {
            $out['errors']++;
            log_line('warning', 'unlimited live-minute settlement failed: ' . $ex->getMessage(),
                ['panel' => $panelId]);
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
        if (panel_is_unlimited($held)) {
            if (panel_is_hourly_unlimited($held)) {
                /* Live-minute debt/recovery belongs to panels_unlimited_online_tick(),
                   which runs every minute and never invokes the old capacity meter. */
                continue;
            }
            /* اگر نسخهٔ قبلی ربات به‌علت موجودی این پنل را بسته باشد، واگذار کردن
               اختیار به Role مستلزم برداشتن همان محدودیت محلی است. */
            if (($held['suspended_reason'] ?? '') === 'balance') {
                DB::exec('UPDATE panels SET suspended_reason=NULL WHERE id=?', [(int) $held['id']]);
                if (resume_panel($held)) {
                    $stats['resumed']++;
                }
            }
            continue;
        }
        if ((int) ($held['is_trial'] ?? 0) === 1) {
            continue;
        }
        DB::exec('UPDATE panels SET last_meter_at=? WHERE id=?', [now_ts(), (int) $held['id']]);
        $heldBalance = balance_of((int) $held['tg_id']);
        $heldExpired = !empty($held['expires_at']) && (int) $held['expires_at'] <= now_ts();
        if ($heldBalance > 0 && $heldBalance >= (int) $held['min_balance'] && !$heldExpired
            && panel_balance_hold_can_resume($held)) {
            if (resume_panel($held)) {
                $stats['resumed']++;
            }
        }
    }
    $rows = DB::all("SELECT * FROM panels WHERE status='active'");
    foreach ($rows as $panel) {
        if (panel_is_unlimited($panel)) {
            /* No volume/expiry meter applies to unlimited panels. Their separate
               live-minute worker samples direct node connection state each minute. */
            continue;
        }
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

        /* سقف ترافیک یک محدودیت واقعی سرویس است و حتی در ۲۴ ساعت پیش‌پرداخت
           هم مجاز است پنل را متوقف کند؛ صفر بودن کیف پول چنین مجوزی ندارد. */
        if (panel_traffic_limit_reached($panel, $usage)) {
            DB::exec('UPDATE panels SET last_usage=? WHERE id=?', [(int) $usage['used'], $panelId]);
            if (suspend_panel($panel, 'پایان حجم ترافیک')) {
                $stats['suspended']++;
            }
            continue;
        }

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
        if (panel_is_unlimited($panel)) {
            if (panel_is_hourly_unlimited($panel)) {
                /* Its own live-minute meter decides when this service may resume. */
                continue;
            }
            if ($panel['status'] === 'suspended' && ($panel['suspended_reason'] ?? '') === 'balance') {
                DB::exec('UPDATE panels SET suspended_reason=NULL WHERE id=?', [(int) $panel['id']]);
                if (resume_panel($panel)) {
                    $out['resumed']++;
                }
            }
            continue; // legacy unlimited: اعتبار/دستگاه فقط با Role است
        }
        $balance = balance_of((int) $panel['tg_id']);
        /* پول ۲۴ ساعت اول در فاکتور اولیه تسویه شده است؛ تا عبور صریح زمان
           billing_start_at هیچ تعلیق یا هشدارِ «موجودی صفر» مجاز نیست. */
        if (!panel_balance_suspension_allowed($panel)) {
            if ($panel['status'] === 'suspended' && ($panel['suspended_reason'] ?? '') === 'balance'
                && panel_balance_hold_can_resume($panel)) {
                DB::exec('UPDATE panels SET suspended_reason=NULL WHERE id=?', [(int) $panel['id']]);
                if (resume_panel($panel)) {
                    $out['resumed']++;
                }
            }
            if ($verbose) {
                echo '· prepaid window: balance ignored for ' . $panel['panel_username'] . "\n";
            }
            continue;
        }
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
            && $balance > 0 && panel_balance_hold_can_resume($panel)) {
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
        $panelForRestriction = DB::one('SELECT * FROM panels WHERE id=?', [(int) $row['panel_id']]);
        if ($panelForRestriction !== null && panel_is_unlimited($panelForRestriction)) {
            /* جریمه‌ای که پیش از تغییر نوع/نسخه ثبت شده، نباید روی نامحدود بماند. */
            $pgRow = panel_pg_row($panelForRestriction);
            if ($pgRow !== null) {
                try {
                    (new PgPanel($pgRow))->set_user_status((string) $row['username'], 'active');
                } catch (Throwable $ex) {
                    log_line('warning', 'unlimited device release failed: ' . $ex->getMessage());
                    continue;
                }
            }
            DB::exec('DELETE FROM temp_restricted_users WHERE id=?', [(int) $row['id']]);
            DB::exec('UPDATE panels SET violation_until=0 WHERE id=?', [(int) $row['panel_id']]);
            continue;
        }
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
        if (panel_is_unlimited($panel)) {
            /* فقط Role پاسارگارد دستگاه را کنترل می‌کند؛ حتی users_of هم صدا زده نمی‌شود. */
            $out['skipped']++;
            continue;
        }
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

function suspend_panel(array $panel, string $reason, int $adminId = 0): bool
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
    $notice = '⛔️ پنل <code>' . e((string) $panel['panel_username'])
        . "</code> تعلیق شد.\n" . 'دلیل: ' . e($reason) . "\n"
        . 'با شارژ کیف پول، پنل خودکار فعال می‌شود.';
    $noticeKb = [[btn('💳 شارژ کیف پول', 'menu:wallet', null, 'success')]];
    if ($adminId > 0) {
        support_send_admin_message($adminId, (int) $panel['tg_id'], $notice, $noticeKb);
    } else {
        Tg::send((int) $panel['tg_id'], $notice, $noticeKb);
    }
    return true;
}

function resume_panel(array $panel, int $adminId = 0): bool
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
    $notice = '✅ پنل <code>' . e((string) $panel['panel_username'])
        . '</code> دوباره فعال شد.';
    $noticeKb = [[btn('📦 پنل‌های من', 'menu:panels', null, 'primary')]];
    if ($adminId > 0) {
        support_send_admin_message($adminId, (int) $panel['tg_id'], $notice, $noticeKb);
    } else {
        Tg::send((int) $panel['tg_id'], $notice, $noticeKb);
    }
    return true;
}

