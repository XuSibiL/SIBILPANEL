<?php
declare(strict_types=1);

/* 19) CLI — --check / --poll / --meter / --report / --version / --migrate */


/**
 * اجرای همهٔ صفحه‌های ادمین بدون تلگرام.
 * اگر یک ثابت یا تابع دیرتر از نقطهٔ اجرا تعریف شده باشد، همین‌جا لو می‌رود.
 */
function cli_selftest(): int
{
    Tg::$dry = true;
    $admin = (int) (admin_ids()[0] ?? 1);
    user_ensure($admin, 'self test', '');
    $catId = cat_create('تست', '🧪');
    DB::exec('INSERT INTO products(name, emoji, category, category_id, role_id, group_ids, '
        . 'max_users, device_limit, days, data_cap_gb, per_gb_price, hourly_price, min_balance, '
        . 'renew_price, slots_needed, panel_prefix, trial, active, created_at) '
        . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['محصول تست', '🧪', 'v2ray', $catId, 1, '[]', 5, 1, 30, 0, 1000, 100, 0, 0, 1,
         'T', 0, 1, now_ts()]);
    $productId = (int) DB::val('SELECT id FROM products ORDER BY id DESC LIMIT 1', [], 0);
    $screens = [
        'ad:home', 'ad:stats', 'ad:receipts', 'ad:finance', 'ad:users', 'ad:cats', 'ad:panel',
        'ad:ref', 'ad:tools', 'ad:settings', 'ad:setg:home', 'ad:setg:trial', 'ad:setg:billing',
        'ad:setg:paneltest', 'ad:setg:report', 'ad:setg:card', 'ad:slots', 'ad:products',
        'ad:help', 'ad:fin:disc', 'ad:fin:disc:new', 'ad:fin:card', 'ad:fin:sales:today',
        'ad:fin:tx:1', 'ad:fin:wallets:all', 'ad:pay', 'ad:payweb', 'ad:cat:' . $catId,
        'ad:cat:new', 'ad:prod:' . $productId, 'ad:role:0:page:1:' . $productId,
        'ad:set:slot_price', 'ad:set:trial_gb', 'ad:set:card_number',
        'ad:pf:' . $productId . ':hourly', 'ad:pg:new', 'ad:pg:newapi',
        'ad:pg:testall', 'ad:u:' . $admin, 'ad:broadcast', 'ad:backup',
        'ad:backup:settings', 'ad:report', 'ad:report:setup', 'ad:setg:backup',
        'ad:setg:kyc', 'kyc:home', 'kyc:cards', 'kyc:pending',
        'ad:rolep:' . $productId, 'ad:prod:' . $productId . ':sp', 'ad:fin:day',
        'ad:fin:tx:1:topup', 'ad:users', 'ad:products', 'ad:settings',
    ];
    $failed = 0;
    foreach ($screens as $data) {
        State::clear($admin);
        Tg::$outbox = [];
        try {
            handle_callback(['id' => 'self', 'from' => ['id' => $admin],
                'message' => ['chat' => ['id' => $admin], 'message_id' => 1], 'data' => $data]);
        } catch (Throwable $ex) {
            $failed++;
            echo "FAIL  $data — " . $ex->getMessage() . "\n";
            continue;
        }
        $bad = false;
        foreach (Tg::$outbox as $out) {
            $text = (string) ($out[1]['text'] ?? '');
            if (str_contains($text, 'خطای داخلی') || str_contains($text, 'دیگر معتبر نیست')) {
                $bad = true;
                echo "FAIL  $data — " . mb_substr($text, 0, 120) . "\n";
            }
        }
        if ($bad) {
            $failed++;
        } else {
            echo "ok    $data\n";
        }
    }
    State::clear($admin);
    echo $failed === 0 ? "SELFTEST: OK\n" : "SELFTEST: $failed failed\n";
    return $failed === 0 ? 0 : 1;
}

function cli_check(): int
{
    echo 'panel-service ' . PANEL_VERSION . ' (PHP ' . PHP_VERSION . ")\n";
    echo 'config : ' . env_file_path() . (is_file(env_file_path()) ? "  [found]\n" : "  [MISSING]\n");
    echo 'db     : ' . db_path() . (is_file(db_path()) ? "  [found]\n" : "  [new]\n");
    $ok = true;
    if (bot_token() === '') {
        echo "warn   : PANEL_BOT_TOKEN is empty\n";
        $ok = false;
    } else {
        echo 'token  : ' . substr(bot_token(), 0, 8) . "…\n";
    }
    echo 'admins : ' . (admin_ids() ? implode(', ', admin_ids()) : 'none') . "\n";
    echo 'php    : curl=' . (function_exists('curl_init') ? 'ext' : (curl_binary() ?: 'missing'))
        . ' pdo_sqlite=' . (extension_loaded('pdo_sqlite') ? 'yes' : 'no') . "\n";
    $counts = [
        'users'     => (int) DB::val('SELECT COUNT(*) FROM users', [], 0),
        'categories' => (int) DB::val('SELECT COUNT(*) FROM categories', [], 0),
        'products'  => (int) DB::val('SELECT COUNT(*) FROM products', [], 0),
        'panels'    => (int) DB::val('SELECT COUNT(*) FROM panels', [], 0),
        'pg_panels' => (int) DB::val('SELECT COUNT(*) FROM pg_panels', [], 0),
        'receipts'  => (int) DB::val("SELECT COUNT(*) FROM receipts WHERE status='pending'", [], 0),
    ];
    foreach ($counts as $label => $value) {
        echo str_pad($label, 11) . ': ' . $value . "\n";
    }
    foreach (DB::all('SELECT * FROM pg_panels') as $row) {
        [$panelOk, $note] = panel_test($row);
        echo 'panel  : ' . $row['name'] . ' → ' . ($panelOk ? 'OK' : 'FAIL') . ' — ' . $note . "\n";
    }
    return $ok ? 0 : 1;
}

/**
 * منوی دستورات ربات (همان لیستی که با زدن «/» در تلگرام باز می‌شود).
 * اولین گزینهٔ منو «/start — راه‌اندازی مجدد بات» است.
 */
function bot_commands_sync(bool $force = false): bool
{
    $stamp = set_int('bot_commands_at', 0);
    if (!$force && $stamp > 0 && (now_ts() - $stamp) < 86400
        && set_get('bot_commands_v', '') === PANEL_VERSION) {
        return true;
    }
    $commands = [
        ['command' => 'start', 'description' => 'راه‌اندازی مجدد بات'],
    ];
    $res = Tg::call('setMyCommands', [
        'commands'     => $commands,
        'scope'        => ['type' => 'default'],
        'language_code' => 'fa',
    ]);
    $ok = is_array($res) && ($res['ok'] ?? false) === true;
    if ($ok) {
        /* برای کسانی که زبان تلگرامشان انگلیسی است هم همین منو ثبت شود */
        Tg::call('setMyCommands', ['commands' => $commands]);
        set_put('bot_commands_at', (string) now_ts());
        set_put('bot_commands_v', PANEL_VERSION);
    }
    return $ok;
}

/**
 * دکمهٔ آبی Mini App از سطح پیش‌فرض بات پاک می‌شود؛ نوع commands همان منوی
 * دستور استاندارد تلگرام است. مهر در DB مانع درخواست تکراری در هر ری‌استارت است.
 */
function bot_menu_button_sync(bool $force = false): bool
{
    /* این درخواست ذاتاً idempotent است. عمداً در هر آغاز poll اجرا می‌شود تا اگر
       Mini App از BotFather یا اجرای قدیمی مانده باشد، همان لحظه حذف شود؛ مهر DB
       فقط برای گزارش/عیب‌یابی است، نه دلیلی برای رد کردن درخواست رسمی. */
    $res = Tg::call('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
    $ok = is_array($res) && (($res['ok'] ?? false) === true);
    if ($ok) {
        set_put('bot_menu_button_mode', 'commands');
        set_put('bot_menu_button_at', (string) now_ts());
        log_line('info', 'telegram chat menu button reset to commands');
    } else {
        log_line('warning', 'telegram setChatMenuButton failed; will retry on next startup');
    }
    return $ok;
}

/**
 * قفل سطح‌فایل برای long polling. حتی اگر یک runner قدیمی کنار systemd روشن
 * مانده باشد، فقط یکی اجازهٔ getUpdates دارد و /start دوبار اجرا نمی‌شود.
 */
function polling_lock_acquire(): bool
{
    $path = panel_home() . '/.poll.lock';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        log_line('error', 'could not open polling lock: ' . $path);
        return false;
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        log_line('warning', 'second polling worker refused: lock is held');
        return false;
    }
    @ftruncate($handle, 0);
    @fwrite($handle, (string) getmypid() . "\n");
    @fflush($handle);
    $GLOBALS['panel_poll_lock'] = $handle;
    return true;
}

function polling_lock_release(): void
{
    $handle = $GLOBALS['panel_poll_lock'] ?? null;
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
    unset($GLOBALS['panel_poll_lock']);
}

function cli_poll(int $seconds = 0): void
{
    if (!polling_lock_acquire()) {
        return;
    }
    try {
    /* این سرویس فقط long polling دارد: وب‌هوک و آپدیت‌های معوق پاک می‌شوند تا
       نه وب‌هوک قدیمی و نه worker دوم /start را دوبار تحویل ندهند. */
    try {
        $webhook = Tg::call('deleteWebhook', ['drop_pending_updates' => true]);
        if (!is_array($webhook) || (($webhook['ok'] ?? false) !== true)) {
            log_line('warning', 'deleteWebhook(drop_pending_updates=true) did not confirm');
        } else {
            log_line('info', 'telegram webhook cleared for single-worker polling');
        }
    } catch (Throwable $ex) {
        log_line('warning', 'deleteWebhook on polling startup failed: ' . $ex->getMessage());
    }
    /* اگر مالک از پنل درخواست بازگردانی بکاپ داده باشد، همین‌جا اعمال می‌شود */
    try {
        backup_restore_apply_pending();
    } catch (Throwable $ex) {
        log_line('error', 'restore on startup failed: ' . $ex->getMessage());
    }
    /* استارتاپ: یوزرنیم ربات یک‌بار خودکار از getMe خوانده و ذخیره می‌شود
       (لینک دعوت هیچ‌وقت به تنظیم دستی وابسته نیست) */
    try {
        $handle = bot_username();
        if ($handle === '') {
            log_line('warning', 'bot username is still unknown; /start ref links fall back '
                . 'to the id form until Telegram answers getMe');
        } else {
            log_line('info', 'bot username synced: @' . $handle);
        }
    } catch (Throwable $ex) {
        log_line('warning', 'bot username sync failed: ' . $ex->getMessage());
    }
    bot_commands_sync();
    bot_menu_button_sync();
    /* نصب‌های قدیمی ممکن است @username ذخیره کرده باشند؛ فقط پس از همان
       اعتبارسنجی کامل Telegram به شناسهٔ canonical مهاجرت می‌شوند. */
    try {
        force_channel_upgrade_legacy();
    } catch (Throwable $ex) {
        log_line('warning', 'force-join legacy migration failed: ' . $ex->getMessage());
    }
    /* استارتاپ: تاپیک‌های احراز هویت ساخته/نام‌گذاری شوند */
    try {
        kyc_topics_startup();
    } catch (Throwable $ex) {
        log_line('warning', 'kyc topics startup failed: ' . $ex->getMessage());
    }
    $deadline = $seconds > 0 ? time() + $seconds : PHP_INT_MAX;
    $lastMeter = 0;
    $lastUnban = 0;
    $lastReport = (int) set_get('last_report_at', '0');
    while (time() < $deadline) {
        /* آپدیت از دکمهٔ ربات یا CLI: خروج تمیز، سرویس خودش بالا می‌آید */
        $restartFlag = panel_home() . '/.restart-request';
        if (is_file($restartFlag)) {
            @unlink($restartFlag);
            log_line('info', 'restart requested after update');
            return;
        }
        $startedAt = microtime(true);
        try {
            $updates = Tg::getUpdates(25);
        } catch (Throwable $ex) {
            log_line('error', 'getUpdates failed: ' . $ex->getMessage());
            sleep(3);
            continue;
        }
        // اگر تلگرام خطا داد و بلافاصله برگشت، حلقه را نسوزان
        if (!$updates && (microtime(true) - $startedAt) < 1.0) {
            sleep(3);
        }
        foreach ($updates as $update) {
            Tg::setOffset((int) $update['update_id'] + 1);
            handle_update($update);
        }
        /* رفع مسدودی موقت دستگاه‌ها هر ۶۰ ثانیه — مستقل از متر ۵ دقیقه‌ای */
        if (time() - $lastUnban >= 60) {
            $lastUnban = time();
            try {
                $backCount = device_guard_unban();
                if ($backCount > 0) {
                    log_line('info', 'device unban tick', ['back' => $backCount]);
                }
            } catch (Throwable $ex) {
                log_line('warning', 'device unban tick failed: ' . $ex->getMessage());
            }
        }
        /* متر مصرف هر ۵ دقیقه */
        if (time() - $lastMeter >= 300) {
            $lastMeter = time();
            try {
                $stats = panels_meter();
                if ($stats['charges'] || $stats['suspended'] || $stats['resumed']
                    || ($stats['device_cut'] ?? 0) || ($stats['device_back'] ?? 0)) {
                    log_line('info', 'meter', $stats);
                }
            } catch (Throwable $ex) {
                log_line('error', 'meter failed: ' . $ex->getMessage());
            }
            try {
                payments_poll();
                payments_expire(set_int('pay_expire_minutes', 1440));
            } catch (Throwable $ex) {
                log_line('warning', 'payment sweep failed: ' . $ex->getMessage());
            }
            try {
                maybe_backup();
            } catch (Throwable $ex) {
                log_line('warning', 'backup failed: ' . $ex->getMessage());
            }
        }
        /* گزارش روزانه */
        $reportHour = set_int('report_hour', 23);
        $localHour = (int) floor(((time() + (int) IRAN_OFFSET) % DAY) / 3600);
        $today = (int) floor((time() + (int) IRAN_OFFSET) / DAY);
        if ($localHour === $reportHour && (int) set_get('last_report_day', '0') !== $today) {
            set_put('last_report_day', (string) $today);
            set_put('last_report_at', (string) now_ts());
            if (report_ready()) {
                post_report(daily_report_text(), 'daily');
            } else {
                $primary = primary_owner_id();
                if ($primary > 0) {
                    Tg::send($primary, daily_report_text());
                }
            }
        }
    }
    } finally {
        polling_lock_release();
    }
}

function cli_migrate(): int
{
    echo "migrating database…\n";
    db_bootstrap();
    settings_bootstrap();
    echo "done: " . db_path() . "\n";
    return 0;
}

/** راه‌اندازی وب‌سرور داخلی برای کال‌بک درگاه‌ها */
function cli_serve(array $argv): int
{
    $port = set_int('web_port', (int) cfg('PANEL_WEB_PORT', '8080'));
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, '--port=')) {
            $port = (int) substr((string) $arg, 7);
        }
    }
    $php = PHP_BINARY;
    $cmd = escapeshellcmd($php) . ' -S 0.0.0.0:' . (int) $port . ' ' . escapeshellarg(__FILE__);
    echo bot_name() . " web endpoint on 0.0.0.0:$port (callbacks: /pay/<gateway>)\n";
    passthru($cmd, $code);
    return (int) $code;
}

function cli_main(array $argv): int
{
    $command = $argv[1] ?? '';
    load_env();
    db_bootstrap();
    settings_bootstrap();
    switch ($command) {
        case '--version':
        case '-v':
            echo 'panel-service ' . PANEL_VERSION . "\n";
            return 0;
        case '--check':
            return cli_check();
        case '--migrate':
            return cli_migrate();
        case '--selftest':
            return cli_selftest();
        case '--serve':
            return cli_serve($argv);
        case '--sync-bot':
            /* یوزرنیم ربات خودکار از getMe خوانده و ذخیره می‌شود */
            bot_username_forget();
            $synced = bot_username();
            if ($synced === '') {
                echo "FAIL could not read the bot username from Telegram (token/network)\n";
                return 1;
            }
            echo 'OK   @' . $synced . "\n";
            return 0;
        case '--commands':
            $ok = bot_commands_sync(true);
            echo ($ok ? 'OK   ' : 'FAIL ') . 'bot commands synced (‎/start = راه‌اندازی مجدد بات)' . "\n";
            return $ok ? 0 : 1;
        case '--pay-poll':
            $settled = payments_poll();
            $expired = payments_expire(set_int('pay_expire_minutes', 1440));
            echo json_encode(['settled' => $settled, 'expired' => $expired]) . "\n";
            return 0;
        case '--meter':
            $stats = panels_meter(true);
            echo json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        case '--broadcast':
            /* کارگر پس‌زمینهٔ پیام همگانی: تا تمام شدن کار ادامه می‌دهد */
            if (!function_exists('bc_job_tick')) {
                echo "FAIL broadcast module is not loaded\n";
                return 1;
            }
            $guard = 0;
            while (!bc_job_tick(45) && $guard++ < 2000) {
                /* هر تیک ۴۵ ثانیه کار می‌کند؛ بین تیک‌ها نفس می‌کشد */
                usleep(200000);
            }
            echo "OK   broadcast job finished\n";
            return 0;
        case '--backup':
            [$backupOk, $backupNote] = backup_send('بکاپ دستی از خط فرمان', true);
            echo ($backupOk ? 'OK   ' : 'FAIL ') . $backupNote . "\n";
            return $backupOk ? 0 : 1;
        case '--topics':
            [$topicsOk, $topicsNote] = report_topics_build();
            echo ($topicsOk ? 'OK   ' : 'FAIL ') . $topicsNote . "\n";
            return $topicsOk ? 0 : 1;
        case '--report':
            $text = daily_report_text();
            echo strip_tags(str_replace('<br>', "\n", $text)) . "\n";
            if (in_array('--post', $argv, true)) {
                post_report($text, 'daily');
                echo 'posted to ' . report_chat_id() . "\n";
            }
            return 0;
        case '--poll':
            Tg::$dry = in_array('--dry', $argv, true);
            $seconds = 0;
            foreach ($argv as $arg) {
                if (ctype_digit((string) $arg)) {
                    $seconds = (int) $arg;
                }
            }
            cli_poll($seconds);
            return 0;
        case '--once':
            $updates = Tg::getUpdates(0);
            foreach ($updates as $update) {
                Tg::setOffset((int) $update['update_id'] + 1);
                handle_update($update);
            }
            return 0;
        case '--test-update':
            Tg::$dry = true;
            handle_update(['message' => ['from' => ['id' => (int) (admin_ids()[0] ?? 1),
                'first_name' => 'Test'], 'chat' => ['id' => (int) (admin_ids()[0] ?? 1)],
                'text' => '/start']]);
            echo json_encode(Tg::$outbox, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            return 0;
        case '':
        case '--dump':
            /* خروجی اتمی دیتابیس برای بکاپ CLI: sibil.php --dump /path/out.db */
            $target = (string) ($argv[2] ?? '');
            if ($target === '') {
                fwrite(STDERR, "usage: sibil.php --dump <path>\n");
                return 2;
            }
            db_bootstrap();
            try {
                DB::pdo()->exec('VACUUM INTO ' . DB::pdo()->quote($target));
            } catch (Throwable $ex) {
                if (!@copy(db_path(), $target)) {
                    fwrite(STDERR, 'dump failed: ' . $ex->getMessage() . "\n");
                    return 1;
                }
            }
            return is_file($target) ? 0 : 1;
        case '--stamp':
            /* مهر زمانی شمسی برای نام فایل بکاپ: 14050803-150451 */
            echo str_replace('/', '', fa_date_only(now_ts())) . '-'
                . str_replace(':', '', fa_time_only(now_ts())) . "\n";
            return 0;
        case '--help':
        case '-h':
            echo 'panel-service ' . PANEL_VERSION . "\n\n"
                . "usage: php sibil.php [option]\n\n"
                . "  --poll [sec]     run the bot (long polling); 0 = forever\n"
                . "  --serve          run the payment callback web server\n"
                . "  --pay-poll       check unpaid online payments once\n"
                . "  --once           process pending updates and exit\n"
                . "  --check          health check: config, db, panels\n"
                . "  --meter          run the usage/hourly billing pass once\n"
                . "  --report [--post] daily report text (optionally to the report group)\n"
                . "  --backup         send one backup to the backup group and delete it\n"
                . "  --sync-bot       read and store the bot username (getMe)\n"
                . "  --broadcast      run the background broadcast job until it ends\n"
                . "  --topics         create the missing report topics in the group\n"
                . "  --migrate        create/upgrade the database\n"
                . "  --test-update    dry-run one update (no telegram calls)\n"
                . "  --version        print version\n";
            return 0;
        default:
            fwrite(STDERR, "unknown option: $command\n");
            return 2;
    }
}


