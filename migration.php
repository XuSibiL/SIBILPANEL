<?php
/* ============================================================
 *  database migration (standalone)
 * ------------------------------------------------------------
 *  این فایل فقط دیتابیس را با نسخه‌ی فعلی کد هم‌تراز می‌کند.
 *  هیچ داده‌ای پاک نمی‌شود، هیچ ستونی حذف نمی‌شود و اجرای
 *  چندباره‌ی آن بی‌خطر است (idempotent).
 *
 *  استفاده:
 *    php migration.php                # اجرای مهاجرت
 *    php migration.php --status       # فقط گزارش وضعیت
 *    php migration.php --quiet        # بدون خروجی، فقط کد خروج
 *
 *  خروجی: 0 = موفق، 1 = خطا
 * ============================================================ */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migration.php is a CLI script.\n");
}

$migrateArgv = $_SERVER['argv'] ?? [];
$statusOnly  = in_array('--status', $migrateArgv, true);
$quiet       = in_array('--quiet', $migrateArgv, true);

/* --- مسیر نصب: از متغیر محیطی، وگرنه پوشه‌ی همین فایل --- */
$home = getenv('PANEL_HOME') ?: getenv('SIBIL_HOME');
if (!is_string($home) || $home === '') {
    $home = __DIR__;
}
putenv('PANEL_HOME=' . $home);

$app = $home . '/sibil.php';
if (!is_file($app)) {
    $app = __DIR__ . '/sibil.php';
}
if (!is_file($app)) {
    fwrite(STDERR, "sibil.php not found (looked in $home and " . __DIR__ . ")\n");
    exit(1);
}

require_once $app;

/* --- ابزار چاپ --- */
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $line . "\n");
    }
};

try {
    load_env();
    db_bootstrap();                                   /* ساخت جداول + ستون‌های تازه */
    $pdo = DB::pdo();
} catch (Throwable $ex) {
    fwrite(STDERR, 'migration failed: ' . $ex->getMessage() . "\n");
    exit(1);
}

/* --- گزارش وضعیت --- */
$tables = ['users', 'wallet_tx', 'panels', 'pg_panels', 'orders', 'receipts',
           'products', 'categories', 'discounts', 'kyc_requests', 'kyc_cards',
           'payments', 'usage_charges', 'temp_restricted_users', 'settings', 'slots'];

$say('panel-service — database migration');
$say('  database : ' . db_path());
$say('  version  : ' . (defined('PANEL_VERSION') ? PANEL_VERSION : '?'));

$missing  = [];
$rows     = [];
foreach ($tables as $table) {
    try {
        $count = (int) DB::val("SELECT COUNT(*) FROM $table", [], 0);
        $rows[$table] = $count;
    } catch (Throwable $ex) {
        $missing[] = $table;
    }
}

if ($missing) {
    /* اگر db_bootstrap جدولی نساخت یعنی چیزی جدی اشتباه است */
    fwrite(STDERR, 'migration incomplete — missing tables: ' . implode(', ', $missing) . "\n");
    exit(1);
}

if ($statusOnly) {
    foreach ($rows as $table => $count) {
        $say(sprintf('  %-12s %d row(s)', $table, $count));
    }
    $say('  status   : up to date');
    exit(0);
}

/* --- یک‌بار تنظیمات پایه (کلید/مقدار) هم ساخته می‌شود --- */
try {
    settings_bootstrap();
} catch (Throwable $ex) {
    $say('  settings : skipped (' . $ex->getMessage() . ')');
    /* نبودن تنظیمات مانع مهاجرت نیست */
}

/* --- خلاصه --- */
foreach ($rows as $table => $count) {
    $say(sprintf('  %-12s %d row(s)', $table, $count));
}

$integrity = (string) DB::val('PRAGMA integrity_check', [], '');
$say('  integrity: ' . ($integrity === '' ? 'ok' : $integrity));

log_line('info', 'migration.php finished · ' . json_encode($rows, JSON_UNESCAPED_UNICODE));
$say('  done     : schema is up to date');
exit(0);
