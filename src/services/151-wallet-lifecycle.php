<?php
declare(strict_types=1);

/** Durable local wakes: no Telegram/provider request in a wallet transaction. */
function wallet_lifecycle_bootstrap(): void
{
    DB::pdo()->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS wallet_panels_owner_state ON panels(tg_id,status,suspended_reason);
CREATE TRIGGER IF NOT EXISTS wallet_balance_changed_v31810 AFTER UPDATE OF balance ON users
WHEN NEW.balance<>OLD.balance BEGIN
 INSERT INTO wallet_balance_events(tg_id,old_balance,new_balance,created_at)
 VALUES(NEW.tg_id,OLD.balance,NEW.balance,CAST(strftime('%s','now') AS INTEGER));
 INSERT INTO wallet_panel_work(panel_id,generation,due_at,attempts)
 SELECT id,1,CAST(strftime('%s','now') AS INTEGER),0 FROM panels
 WHERE tg_id=NEW.tg_id AND COALESCE(is_trial,0)=0
 AND ((status='suspended' AND suspended_reason IN ('balance','online_balance','hourly_balance'))
 OR (NEW.balance<=0 AND status='active' AND (COALESCE(is_unlimited,0)=0 OR unlimited_billing_mode='hourly')))
 ON CONFLICT(panel_id) DO UPDATE SET generation=generation+1,due_at=excluded.due_at,attempts=0;
END;
CREATE TRIGGER IF NOT EXISTS wallet_hold_changed_v31810 AFTER UPDATE OF status,suspended_reason ON panels
WHEN NEW.status='suspended' AND NEW.suspended_reason IN ('balance','online_balance','hourly_balance')
AND (OLD.status<>NEW.status OR COALESCE(OLD.suspended_reason,'')<>NEW.suspended_reason) BEGIN
 INSERT INTO wallet_panel_work(panel_id,generation,due_at,attempts)
 VALUES(NEW.id,1,CAST(strftime('%s','now') AS INTEGER),0)
 ON CONFLICT(panel_id) DO UPDATE SET generation=generation+1,due_at=excluded.due_at,attempts=0;
END;
SQL);
    if (!DB::val("SELECT value FROM settings WHERE key='wallet_lifecycle_seed_v31810'", [], '')) {
        DB::tx(static function (): void {
            DB::exec("INSERT OR IGNORE INTO wallet_panel_work(panel_id,due_at) SELECT id,? FROM panels "
                . "WHERE status='suspended' AND suspended_reason IN ('balance','online_balance','hourly_balance')", [now_ts()]);
            DB::exec("INSERT OR IGNORE INTO settings(key,value) VALUES('wallet_lifecycle_seed_v31810','1')");
        });
    }
    static $registered = false;
    if (!$registered && !in_array('--wallet-worker', $GLOBALS['argv'] ?? [], true)) {
        $registered = true;
        register_shutdown_function('wallet_worker_kick');
    }
}

function wallet_panel_schedule(int $panelId, int $delay = 0): void
{
    DB::exec('INSERT INTO wallet_panel_work(panel_id,due_at) VALUES(?,?) '
        . 'ON CONFLICT(panel_id) DO UPDATE SET generation=generation+1,due_at=excluded.due_at',
        [$panelId, now_ts() + max(0, $delay)]);
}

/** A per-panel process lock serializes manual commands, meter and recovery. */
function wallet_panel_guard(int $id, callable $body, mixed $busy = false): mixed
{
    static $held = [];
    if (isset($held[$id])) return $body(); // nested charge -> suspend on this same process
    $file = @fopen(panel_home() . '/.panel-lifecycle-' . $id . '.lock', 'c');
    if (!$file) return $busy;
    if (!flock($file, LOCK_EX | LOCK_NB)) { fclose($file); return $busy; }
    $held[$id] = true;
    try { return $body(); }
    finally { unset($held[$id]); flock($file, LOCK_UN); fclose($file); }
}
function suspend_panel(array $panel, string $reason, int $adminId = 0): bool
{
    return (bool) wallet_panel_guard((int)$panel['id'], static fn(): bool => suspend_panel_inner($panel, $reason, $adminId));
}
function resume_panel(array $panel, int $adminId = 0): bool
{
    return (bool) wallet_panel_guard((int)$panel['id'], static fn(): bool => resume_panel_inner($panel, $adminId));
}

/** Never charge a stale active snapshot while recovery resets a paused clock. */
function panel_charge_once(array $panel, int $usedBytes, bool $hourlyEnabled, bool $perGbEnabled, int $grace = 0): array
{
    $skip=['charged'=>0,'hours'=>0,'gigs'=>0,'skipped'=>true,'suspended'=>false];
    return wallet_panel_guard((int)$panel['id'], static function () use ($panel,$usedBytes,$hourlyEnabled,$perGbEnabled,$grace,$skip): array {
        $fresh=DB::one('SELECT * FROM panels WHERE id=?',[(int)$panel['id']]);
        if (!$fresh || $fresh['status']!=='active') return $skip;
        return panel_charge_once_inner(array_merge($panel,$fresh),$usedBytes,$hourlyEnabled,$perGbEnabled,$grace);
    },$skip);
}

/** Background process, not a provider loop in the Telegram poller. */
function wallet_worker_kick(bool $fromWorker = false): void
{
    if (Tg::$dry || !function_exists('shell_exec') || (!$fromWorker && in_array('--wallet-worker', $GLOBALS['argv'] ?? [], true))) return;
    static $last = 0;
    if (time() === $last) return;
    $last = time();
    try {
        $due = DB::val('SELECT 1 FROM wallet_panel_work WHERE due_at<=? LIMIT 1', [now_ts()]);
        $events = DB::val('SELECT 1 FROM wallet_balance_events LIMIT 1');
        $notices = DB::val("SELECT 1 FROM wallet_low_notices WHERE state='pending' LIMIT 1");
        if (!$due && !$events && !$notices) return;
        // A cheap gate prevents spawning duplicate processes while a worker is busy.
        $gate = @fopen(panel_home() . '/.wallet-worker.lock', 'c');
        if (!$gate) return;
        $free = flock($gate, LOCK_EX | LOCK_NB);
        if ($free) flock($gate, LOCK_UN);
        fclose($gate);
        if (!$free) return;
        $php = PHP_BINARY;
        $script = realpath(__FILE__);
        if (!$script || !is_file($php)) return;
        $cmd = 'PANEL_HOME=' . escapeshellarg(panel_home()) . ' PANEL_ENV=' . escapeshellarg(env_file_path())
            . ' nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --wallet-worker >/dev/null 2>&1 &';
        @shell_exec($cmd);
    } catch (Throwable $ex) { log_line('warning', 'wallet wake failed: ' . $ex->getMessage()); }
}

/** Uses actual usage charges, never top-ups, orders, refunds or admin withdrawals. */
function wallet_consumption_estimate(int $uid, ?int $at = null): array
{
    $at ??= now_ts();
    $first = (int)DB::val('SELECT MIN(created_at) FROM panels WHERE tg_id=? AND COALESCE(is_trial,0)=0', [$uid], 0);
    $start = max($at - 23 * 3600, $first > 0 ? $first : $at);
    $seconds = max(0, $at - $start);
    $spent = (int)DB::val('SELECT COALESCE(SUM(amount),0) FROM usage_charges WHERE tg_id=? AND created_at>? AND created_at<=?',
        [$uid, $start, $at], 0);
    $rate = $seconds >= 3600 && $spent > 0 ? $spent * 3600 / $seconds : 0.0;
    $balance = max(0, balance_of($uid));
    return ['hours' => $seconds / 3600, 'spent' => $spent, 'rate' => $rate,
        'remaining' => $rate > 0 ? $balance / $rate : null, 'balance' => $balance];
}
function wallet_low_text(int $uid, int $threshold): string
{
    $e = wallet_consumption_estimate($uid);
    $text = "⏳ <b>موجودی کیف پولت داره تموم می‌شه</b>\n\n";
    if ($e['remaining'] !== null) {
        $hours = number_format(max(0.1, (float)$e['remaining']), 1, '.', '');
        $text .= 'با میانگین هزینهٔ مصرف ثبت‌شده در ' . number_format($e['hours'], 1, '.', '')
            . ' ساعت گذشته، موجودیت حدود <b>' . $hours . " ساعت</b> دیگه کافیه.\n";
    } else {
        $text .= "هنوز سابقهٔ کافی برای تخمین معتبر زمان باقی‌مانده نداریم.\n";
    }
    $text .= 'موجودی فعلی: <b>' . number_format($e['balance']) . "</b> تومان\n";
    if ($e['rate'] > 0) $text .= 'میانگین مصرف: <b>' . number_format($e['rate']) . "</b> تومان در ساعت\n";
    $text .= "\nقبل از اینکه تموم شه شارژ کن تا پنل‌هات قطع نشن.";
    if ($e['rate'] > 0) $text .= "\nاین زمان تخمینی است و با تغییر مصرف و نرخ پنل‌ها تغییر می‌کند.";
    return $text;
}
function wallet_events_collect(): void
{
    DB::tx(static function (): void {
        foreach (DB::all('SELECT * FROM wallet_balance_events ORDER BY id LIMIT 100') as $event) {
            $old = (int)$event['old_balance']; $new = (int)$event['new_balance'];
            // On a large jump send only the most urgent crossed threshold, not a burst of three stale warnings.
            $level = 0;
            foreach ([90000, 50000, 10000] as $threshold) {
                if ($old > $threshold && $new <= $threshold) $level = $threshold;
            }
            if ($level > 0 && $new > 0) {
                DB::exec('INSERT OR IGNORE INTO wallet_low_notices(event_id,tg_id,threshold,created_at) VALUES(?,?,?,?)',
                    [(int)$event['id'], (int)$event['tg_id'], $level, (int)$event['created_at']]);
            }
            DB::exec('DELETE FROM wallet_balance_events WHERE id=?', [(int)$event['id']]);
        }
    });
}

/** At most eight provider transitions/run; a failed panel never blocks its siblings. */
function wallet_worker_run(int $limit = 8): array
{
    $out = ['processed'=>0,'resumed'=>0,'suspended'=>0,'errors'=>0,'notices'=>0];
    $lock = @fopen(panel_home() . '/.wallet-worker.lock', 'c');
    if (!$lock) return $out;
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $out; }
    $GLOBALS['wallet_worker_telegram_timeout'] = 4;
    try {
        wallet_events_collect();
        foreach (DB::all('SELECT * FROM wallet_panel_work WHERE due_at<=? ORDER BY due_at,panel_id LIMIT ?',
            [now_ts(), max(1, min(32,$limit))]) as $job) {
            $id=(int)$job['panel_id']; $retry=false; $out['processed']++;
            try {
                $p=DB::one('SELECT * FROM panels WHERE id=?',[$id]);
                if ($p && (int)($p['is_trial']??0)!==1) {
                    $balance=balance_of((int)$p['tg_id']);
                    if ($p['status']==='active' && $balance<=0 && set_bool('suspend_on_zero',true)
                        && panel_balance_suspension_allowed($p)) {
                        if (suspend_panel($p,'موجودی کیف پول صفر است')) $out['suspended']++;
                        else $retry=true;
                    } elseif ($p['status']==='suspended' && in_array((string)($p['suspended_reason']??''),
                        ['balance','online_balance','hourly_balance'],true) && $balance>0 && panel_balance_hold_can_resume($p)) {
                        $pending=false;
                        if (panel_is_hourly_unlimited($p)) {
                            $settled=unlimited_online_settle_due($p);
                            $pending=(bool)$settled['pending'];
                            foreach ($settled['events'] as $event) if(!empty($event['new'])) unlimited_online_post_report($event);
                        }
                        if (!$pending && balance_of((int)$p['tg_id'])>0) {
                            if (resume_panel($p)) $out['resumed']++;
                            else $retry=true;
                        }
                    }
                }
            } catch (Throwable $ex) { $retry=true; $out['errors']++; log_line('warning','wallet lifecycle retry',['panel'=>$id]); }
            $delay=min(300,15*(1+min(19,(int)$job['attempts'])));
            if ($retry) DB::exec('UPDATE wallet_panel_work SET due_at=?,attempts=attempts+1 WHERE panel_id=? AND generation=?',
                [now_ts()+$delay,$id,(int)$job['generation']]);
            else DB::exec('DELETE FROM wallet_panel_work WHERE panel_id=? AND generation=?',[$id,(int)$job['generation']]);
        }
        foreach (DB::all("SELECT * FROM wallet_low_notices WHERE state='pending' ORDER BY id LIMIT 6") as $n) {
            $uid=(int)$n['tg_id'];$balance=balance_of($uid);
            if ($balance<=0 || $balance>(int)$n['threshold']) {
                DB::exec("UPDATE wallet_low_notices SET state='obsolete' WHERE id=?",[(int)$n['id']]);continue;
            }
            $claim=DB::update("UPDATE wallet_low_notices SET state='sending' WHERE id=? AND state='pending'",[(int)$n['id']]);
            if ($claim!==1)continue;
            $r=Tg::send($uid,wallet_low_text($uid,(int)$n['threshold']),[[btn('💳 شارژ کیف پول','menu:wallet',null,'success')]]);
            DB::exec('UPDATE wallet_low_notices SET state=? WHERE id=?',[$r!==null?'sent':'uncertain',(int)$n['id']]);
            $out['notices']++;
        }
    } finally { unset($GLOBALS['wallet_worker_telegram_timeout']); flock($lock, LOCK_UN); fclose($lock); }
    return $out;
}
