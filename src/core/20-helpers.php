<?php
declare(strict_types=1);

/* 2) Small helpers */

function now_ts(): int
{
    return time();
}

/** تبدیل تاریخ میلادی به شمسی (الگوریتم استاندارد، بدون کتابخانهٔ بیرونی) */
function g2j(int $gy, int $gm, int $gd): array
{
    $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd;
    for ($i = 0; $i < $gm - 1; $i++) {
        $days += $gDaysInMonth[$i];
    }
    if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
        $days++;
    }
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $jm = 0;
    for ($i = 0; $i < 12 && $days >= $jDaysInMonth[$i]; $i++) {
        $days -= $jDaysInMonth[$i];
        $jm = $i + 1;
    }
    return [$jy, $jm + 1, $days + 1];
}

/** بخش‌های تاریخ/ساعت شمسی برای یک زمان یونیکس */
function jparts(?int $ts): array
{
    if (!$ts) {
        return [0, 0, 0, 0, 0, 0];
    }
    $local = (new DateTimeImmutable('@' . ($ts + (int) IRAN_OFFSET)));
    [$jy, $jm, $jd] = g2j((int) $local->format('Y'), (int) $local->format('n'),
        (int) $local->format('j'));
    return [$jy, $jm, $jd, (int) $local->format('G'), (int) $local->format('i'),
        (int) $local->format('s')];
}

function jdate(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    [$jy, $jm, $jd, $hh, $ii] = jparts($ts);
    return sprintf('%04d/%02d/%02d %02d:%02d', $jy, $jm, $jd, $hh, $ii);
}

/** فقط تاریخ شمسی: 1405/08/03 */
function fa_date_only(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    [$jy, $jm, $jd] = jparts($ts);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/** فقط ساعت با ثانیه: 07:58:40 */
function fa_time_only(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    [, , , $hh, $ii, $ss] = jparts($ts);
    return sprintf('%02d:%02d:%02d', $hh, $ii, $ss);
}

function fa_ts(?int $ts): string
{
    return fa_digits(jdate($ts));
}

/**
 * تمام اعداد ربات با ارقام لاتین نمایش داده می‌شوند (خواستهٔ مالک).
 * این تابع قبلاً ارقام را فارسی می‌کرد؛ حالا فقط نرمال‌سازی می‌کند تا
 * اگر جایی عدد فارسی وارد شد، خروجی باز هم لاتین باشد.
 */
function fa_digits(string|int|float $value): string
{
    return str_replace(
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹',
         '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '٬', '،'],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
         '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ',', ','],
        (string) $value
    );
}

/** مبلغ به ریال، با ارقام فارسی — برای دکمهٔ کپی */
function rial_text(int $toman): string
{
    return number_format($toman * 10) . ' ریال';
}

/** عدد خام ریالی (بدون جداکننده) برای کپی */
function rial_digits(int $toman): string
{
    return (string) ($toman * 10);
}

function money(int|float|string|null $amount): string
{
    /* ارقام لاتین با جداکنندهٔ کاما: 4,873,600 تومان */
    return number_format((float) ($amount ?? 0)) . ' تومان';
}

function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function to_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    $clean = str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', ',', '،', ' '],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', '', ''], (string) $value);
    return is_numeric($clean) ? (int) $clean : null;
}

function to_float(mixed $value): ?float
{
    $clean = str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', ','],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ''], (string) $value);
    return is_numeric($clean) ? (float) $clean : null;
}

function log_dir(): string
{
    $dir = sibil_home() . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** نوشتن یک خط در یک فایل لاگ با گردش حجم (پیش‌فرض ۵ مگابایت) */
function log_to_file(string $file, string $line, int $maxBytes = 5242880): void
{
    $path = log_dir() . '/' . $file;
    if (is_file($path) && filesize($path) > $maxBytes) {
        @rename($path, $path . '.1');
    }
    if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        return;
    }
    @chmod($path, 0640);
}

/**
 * لاگ استاندارد پروژه:
 *   · همه‌چیز روی stderr (برای journalctl)
 *   · خطاها و هشدارها در logs/bot_error.log
 */
function log_line(string $level, string $message, array $context = []): void
{
    $line = sprintf("[%s] %-7s %s", date('Y-m-d H:i:s'), strtoupper($level), $message);
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $handle = fopen('php://stderr', 'wb');
    if ($handle !== false) {
        fwrite($handle, $line . PHP_EOL);
        fclose($handle);
    }
    if (in_array(strtolower($level), ['error', 'warning', 'critical'], true)) {
        log_to_file('bot_error.log', $line);
    }
}

/** لاگ اختصاصی تراکنش‌ها: کارت‌به‌کارت، درگاه‌های ریالی و ارزی */
function log_payment(string $event, array $context = []): void
{
    $line = sprintf("[%s] %s", date('Y-m-d H:i:s'), $event);
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    log_to_file('payments.log', $line);
}

/**
 * لاگ اختصاصی پایش دستگاه‌ها (logs/device_guard.log)
 * هر قطع/وصل/رد شدن با زمان دقیق ثبت می‌شود تا از منوی logs ترمینال قابل پیگیری باشد.
 */
function log_device_guard(string $event, array $context = []): void
{
    $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), $event);
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    log_to_file('device_guard.log', $line);
}

