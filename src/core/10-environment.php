<?php
declare(strict_types=1);

/* 1) Environment */

/**
 * ریشهٔ نصب: وقتی ماژول‌ها از src/ اجرا می‌شوند دو پله بالاتر، و در فایل
 * تک‌فایلیِ ساخته‌شده همان پوشه‌ای که خودِ فایل در آن است.
 */
function panel_root(): string
{
    $modular = dirname(__DIR__, 2);
    return is_dir($modular . '/src/core') ? $modular : __DIR__;
}

function env_file_path(): string
{
    foreach (['PANEL_ENV', 'SIBIL_ENV'] as $key) {
        $explicit = getenv($key);
        if ($explicit !== false && $explicit !== '') {
            return $explicit;
        }
    }
    foreach (['PANEL_HOME', 'SIBIL_HOME'] as $key) {
        $home = getenv($key);
        if ($home !== false && $home !== '') {
            return rtrim($home, '/') . '/.env';
        }
    }
    return panel_root() . '/.env';
}

function load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $file = env_file_path();
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        /* کلید خالیِ صادرشده (مثل install.sh) نباید مقدار .env را بپوشاند */
        if ($key !== '' && (getenv($key) === false || getenv($key) === '')) {
            putenv("$key=$value");
        }
    }
}

/** کلید محیطی هم با پیشوند PANEL_ و هم با پیشوند قدیمی خوانده می‌شود */
function env_keys(string $key): array
{
    $keys = [$key];
    if (str_starts_with($key, 'PANEL_')) {
        $keys[] = 'SIBIL_' . substr($key, 6);
    } elseif (str_starts_with($key, 'SIBIL_')) {
        $keys[] = 'PANEL_' . substr($key, 6);
    }
    return $keys;
}

function cfg(string $key, string $default = ''): string
{
    foreach (env_keys($key) as $candidate) {
        $value = getenv($candidate);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function bot_token(): string
{
    return cfg('PANEL_BOT_TOKEN');
}

function panel_home(): string
{
    $home = cfg('PANEL_HOME');
    return $home !== '' ? rtrim($home, '/') : panel_root();
}

/** نام قدیمی، برای سازگاری با نصب‌های قبلی */
function sibil_home(): string
{
    return panel_home();
}

function admin_ids(): array
{
    $raw = cfg('PANEL_ADMINS', cfg('PANEL_ADMIN_IDS'));
    $ids = [];
    foreach (preg_split('/[,\s]+/', $raw) ?: [] as $piece) {
        if ($piece !== '' && ctype_digit(trim($piece))) {
            $ids[] = (int) trim($piece);
        }
    }
    return $ids;
}

/** شناسهٔ حساب‌های مدیریتی: مالک و ادمین‌ها — این‌ها «کاربر» نیستند */
function staff_ids(): array
{
    $ids = [];
    $owner = owner_id();
    if ($owner > 0) {
        $ids[] = $owner;
    }
    foreach (admin_ids() as $adminId) {
        if (!in_array($adminId, $ids, true)) {
            $ids[] = $adminId;
        }
    }
    return $ids;
}

function is_staff(int $uid): bool
{
    return in_array($uid, staff_ids(), true);
}

/** عبارت SQL برای کنار گذاشتن حساب‌های مدیریتی از فهرست‌ها */
function staff_sql(string $column = 'tg_id', string $prefix = ' AND '): string
{
    $ids = staff_ids();
    if (!$ids) {
        return '';
    }
    return $prefix . $column . ' NOT IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/** شناسهٔ عددی خودِ ربات (بخش عددی توکن) — این حساب هیچ‌وقت کاربر فروشگاه نیست */
function bot_self_id(): int
{
    $token = bot_token();
    if ($token === '') {
        return 0;
    }
    $prefix = strstr($token, ':', true);
    return $prefix !== false && ctype_digit($prefix) ? (int) $prefix : 0;
}

function is_bot_self(int $uid): bool
{
    $self = bot_self_id();
    return $self > 0 && $uid === $self;
}

/** فیلتر SQL: حساب خود ربات نه کیف پول دارد، نه در فهرست‌ها می‌آید */
function bot_self_sql(string $column = 'tg_id', string $prefix = ' AND '): string
{
    $self = bot_self_id();
    return $self > 0 ? $prefix . $column . '<>' . $self : '';
}

function is_admin(int $uid): bool
{
    return in_array($uid, admin_ids(), true);
}

/** مالک ربات: تنها کسی که پنل مدیریت برایش باز است */
function owner_id(): int
{
    $row = DB::one("SELECT value FROM settings WHERE key='owner_id'");
    $id = $row === null ? 0 : (int) $row['value'];
    if ($id > 0) {
        return $id;
    }
    $first = admin_ids()[0] ?? 0;
    if ($first > 0) {
        set_put('owner_id', (string) $first);
        return $first;
    }
    return 0;
}

function is_owner(int $uid): bool
{
    return $uid > 0 && $uid === owner_id();
}

/** آدرس داشبورد پنل کاربر (همان لینکی که خودتان باز می‌کنید) */
function panel_dashboard_url(string $base): string
{
    return rtrim($base, '/') . '/dashboard/';
}

/* ── shim: کار با UTF-8 بدون نیاز به ext-mbstring ─────────────────────── */
if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text, ?string $encoding = null): int
    {
        return count(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text, ?string $encoding = null): string
    {
        return strtolower($text);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $text, ?string $encoding = null): string
    {
        return strtoupper($text);
    }
}

/**
 * نام نمایشی ربات — هیچ نام برندی داخل کد نیست:
 *   ۱) تنظیمات ربات (کلید bot_name)
 *   ۲) متغیر محیطی PANEL_BOT_NAME
 *   ۳) یک نام خنثی
 */
function bot_name(): string
{
    $set = trim(set_get('bot_name', ''));
    if ($set !== '') {
        return $set;
    }
    $env = trim(cfg('PANEL_BOT_NAME', cfg('PANEL_BRAND', '')));
    return $env !== '' ? $env : 'ربات مدیریت سرویس';
}

function brand(): string
{
    return bot_name();
}

function db_path(): string
{
    return cfg('PANEL_DB', panel_home() . '/sibil.sqlite3');
}

