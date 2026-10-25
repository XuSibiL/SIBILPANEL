<?php
declare(strict_types=1);

/*
   9c) BACKUP & RESTORE — فایل استاندارد تاریخ‌دار + بازگردانی تضمینی

   قانون‌ها:
   ۱) نام فایل استاندارد است: sibil-backup-YYYY-MM-DD.sqlite3
      (همان فایلی که با ابزار sqlite3 هم باز می‌شود)
   ۲) کنار دیتابیس، فایل تنظیمات .env هم فرستاده می‌شود (قابل خاموش‌کردن)
   ۳) بازگردانی هم از CLI («sibil restore <file>») و هم از خود ربات انجام می‌شود
   ۴) بازگردانی هرگز جدول‌ها را نصفه نمی‌گذارد: اعتبارسنجی → نسخهٔ ایمن از
      دادهٔ فعلی → جایگزینی اتمی → مایگریشن؛ اگر مایگریشن خطا بدهد، همان
      نسخهٔ ایمن برمی‌گردد و هیچ داده‌ای گم نمی‌شود.
*/

/** تاریخ فایل بکاپ (YYYY-MM-DD) به وقت ایران */
function backup_stamp(): string
{
    return date('Y-m-d', now_ts() + (int) IRAN_OFFSET);
}

/** مسیر موقت یک بکاپ تازه با نام استاندارد و تاریخ‌دار */
function backup_temp_path(): string
{
    return sys_get_temp_dir() . '/sibil-backup-' . backup_stamp() . '.sqlite3';
}

/** مسیر فایل .env کنار بکاپ */
function backup_env_temp_path(): string
{
    return sys_get_temp_dir() . '/sibil-env-' . backup_stamp() . '.txt';
}

/** آیا .env هم داخل بکاپ برود؟ (پیش‌فرض: بله) */
function backup_with_env(): bool
{
    return set_bool('backup_env', true);
}

/**
 * اعتبارسنجی یک فایل بکاپ، بدون دست‌زدن به دادهٔ فعلی.
 * فایل gzip قدیمی (.panelbak) همان‌جا باز می‌شود.
 * خروجی: [ok, متن, مسیر sqlite قابل استفاده]
 */
function backup_validate(string $path): array
{
    if (!is_file($path)) {
        return [false, 'فایل بکاپ پیدا نشد.', ''];
    }
    $head = (string) @file_get_contents($path, false, null, 0, 16);
    if (!str_starts_with($head, 'SQLite format 3')) {
        $raw = (string) @file_get_contents($path);
        if (str_starts_with($raw, "\x1f\x8b")) {
            $unpacked = @gzdecode($raw);
            if ($unpacked === false || !str_starts_with($unpacked, 'SQLite format 3')) {
                return [false, 'فایل بکاپ سالم نیست (gzip خراب است).', ''];
            }
            $tmp = sys_get_temp_dir() . '/sibil-restore-'
                . bin2hex(random_bytes(4)) . '.sqlite3';
            if (@file_put_contents($tmp, $unpacked) === false) {
                return [false, 'نوشتن فایل موقت انجام نشد.', ''];
            }
            $path = $tmp;
        } else {
            return [false, 'این فایل، دیتابیس این ربات نیست.', ''];
        }
    }
    try {
        $probe = new PDO('sqlite:' . $path, null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $integrity = (string) $probe->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            return [false, 'دیتابیس داخل بکاپ سالم نیست (integrity: ' . $integrity . ').', ''];
        }
        $users = (int) $probe->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $panels = (int) $probe->query('SELECT COUNT(*) FROM panels')->fetchColumn();
        $probe = null;
    } catch (Throwable $ex) {
        return [false, 'دیتابیس داخل بکاپ خوانده نشد: ' . $ex->getMessage(), ''];
    }
    return [true, '👥 ' . fa_digits($users) . ' کاربر · 📦 ' . fa_digits($panels)
        . ' پنل · 🩺 سلامت: ok', $path];
}

/**
 * بازگردانی واقعی: نسخهٔ ایمن از دادهٔ فعلی → جایگزینی اتمی → مایگریشن.
 * خروجی: [موفق, متن]
 */
function backup_restore_apply(string $sqlitePath): array
{
    [$okFile, $whyFile, $ready] = backup_validate($sqlitePath);
    if (!$okFile) {
        return [false, $whyFile];
    }
    $target = db_path();
    $safety = '';
    if (is_file($target)) {
        $safety = $target . '.before-restore-' . date('Ymd-His');
        if (!@copy($target, $safety)) {
            return [false, 'نوشتن نسخهٔ ایمن از دادهٔ فعلی انجام نشد؛ بازگردانی متوقف شد.'];
        }
    }
    /* هر سه فایل (اصلی + WAL + SHM) پاک می‌شوند تا دادهٔ قبلی برنگردد */
    DB::close();
    foreach (['', '-wal', '-shm'] as $suffix) {
        if ($suffix !== '' && is_file($target . $suffix)) {
            @unlink($target . $suffix);
        }
    }
    if (!@copy($ready, $target)) {
        DB::close();
        if ($safety !== '' && is_file($safety)) {
            @copy($safety, $target);
        }
        return [false, 'جایگزینی دیتابیس انجام نشد؛ دادهٔ قبلی بازگشت.'];
    }
    @chmod($target, 0640);
    DB::close();
    try {
        db_bootstrap();
        settings_bootstrap();
    } catch (Throwable $ex) {
        DB::close();
        if ($safety !== '' && is_file($safety)) {
            @copy($safety, $target);
            DB::close();
            try {
                db_bootstrap();
            } catch (Throwable $ignored) {
            }
        }
        return [false, 'مایگریشن بکاپ خطا داد؛ دادهٔ قبلی بازگشت: ' . $ex->getMessage()];
    }
    $health = backup_health();
    $users = (int) DB::val('SELECT COUNT(*) FROM users', [], 0);
    post_report('♻️ <b>بازگردانی بکاپ انجام شد</b>' . "\n" . SEP . "\n"
        . '👥 کاربر: ' . fa_digits($users) . "\n"
        . '🩺 سلامت دیتابیس: ' . ($health['ok'] ? 'سالم ✅' : 'هشدار ⚠️'), 'backup');
    return [true, '✅ بازگردانی انجام شد — ' . fa_digits($users) . ' کاربر و همهٔ '
        . 'موجودی‌ها، سفارش‌ها و پنل‌ها برگشتند.'];
}

/** مسیر فایل نشانهٔ «بازگردانی در انتظار» */
function backup_restore_flag(): string
{
    return panel_home() . '/.restore-request';
}

/** فایل بکاپِ ارسال‌شده به ربات را ذخیره و برای تأیید آماده می‌کند */
function backup_restore_stage(string $fileId, string $fileName = ''): array
{
    [$path, $error] = Tg::download($fileId);
    if ($path === '') {
        return [false, 'دریافت فایل انجام نشد: ' . $error];
    }
    [$okFile, $note, $ready] = backup_validate($path);
    if (!$okFile) {
        @unlink($path);
        return [false, $note];
    }
    $keep = panel_home() . '/uploads';
    if (!is_dir($keep)) {
        @mkdir($keep, 0755, true);
    }
    $dest = $keep . '/restore-' . date('Ymd-His') . '.sqlite3';
    if (!@copy($ready, $dest)) {
        @unlink($path);
        return [false, 'ذخیرهٔ فایل بکاپ روی سرور انجام نشد.'];
    }
    @unlink($path);
    if ($ready !== $path && str_contains($ready, 'sibil-restore-')) {
        @unlink($ready);
    }
    set_put('restore_pending', $dest);
    set_put('restore_pending_name', $fileName !== '' ? $fileName : basename($dest));
    return [true, '📦 فایل بکاپ دریافت و بررسی شد.' . "\n" . $note];
}

/**
 * درخواست بازگردانی: ربات خروج می‌کند و سرویس دوباره بالا می‌آید؛
 * در استارتاپ بعدی، backup_restore_apply_pending() کار را تمام می‌کند.
 */
function backup_restore_request(): array
{
    $path = trim(set_get('restore_pending', ''));
    if ($path === '' || !is_file($path)) {
        return [false, 'فایل بکاپ در انتظار پیدا نشد؛ دوباره بفرستید.'];
    }
    if (@file_put_contents(backup_restore_flag(), $path) === false) {
        return [false, 'ثبت درخواست بازگردانی انجام نشد.'];
    }
    return [true, '♻️ بازگردانی شروع شد؛ ربات چند ثانیه قطع می‌شود و با دادهٔ بکاپ برمی‌گردد.'];
}

/** در استارتاپ: اگر درخواست بازگردانی هست، همان‌جا اعمال می‌شود */
function backup_restore_apply_pending(): void
{
    $flag = backup_restore_flag();
    if (!is_file($flag)) {
        return;
    }
    $path = trim((string) @file_get_contents($flag));
    @unlink($flag);
    set_put('restore_pending', '');
    if ($path === '' || !is_file($path)) {
        log_line('warning', 'restore pending but the file is gone');
        return;
    }
    [$ok, $note] = backup_restore_apply($path);
    @unlink($path);
    log_line($ok ? 'info' : 'error', 'restore: ' . $note);
    foreach (admin_ids() as $adminId) {
        Tg::send($adminId, ($ok ? '✅ ' : '⚠️ ') . $note);
    }
}
