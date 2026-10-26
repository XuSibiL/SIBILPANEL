<?php
declare(strict_types=1);

/* 6) RBAC — نقش‌های مدیریتی ربات
   ─────────────────────────────────────────────────────────────────────────
   مالک اصلی از فایل محیطی قفل می‌شود؛ نقش‌های دیگر در SQLite نگهداری می‌شوند
   تا با حذف یک ادمین، دسترسی او واقعاً همان لحظه قطع شود.

   نقش‌ها:
   - finance  : فقط رسید کارت‌به‌کارت
   - support  : کاربران، آمار ساده و پاسخ به کاربر
   - manager  : عملیات پنل/سرور، تمدید و لاگ امن
   - coowner  : همهٔ بخش‌های عملیاتی مالک

   هیچ نقش دیتابیسی نمی‌تواند مالک اصلی را بسازد، حذف کند یا تنزل دهد.
*/

const ADMIN_ROLE_FINANCE = 'finance';
const ADMIN_ROLE_SUPPORT = 'support';
const ADMIN_ROLE_MANAGER = 'manager';
const ADMIN_ROLE_COOWNER = 'coowner';

const ADMIN_ROLE_LABELS = [
    ADMIN_ROLE_FINANCE => '💳 ادمین مالی و رسید',
    ADMIN_ROLE_SUPPORT => '💬 ادمین پشتیبانی',
    ADMIN_ROLE_MANAGER => '🛠 مدیر فنی / اپراتور',
    ADMIN_ROLE_COOWNER => '👑 هم‌مالک',
];

/** همهٔ نقش‌هایی که مالک اصلی می‌تواند به یک حساب بدهد. */
function admin_role_choices(): array
{
    return ADMIN_ROLE_LABELS;
}

function admin_role_valid(string $role): bool
{
    return array_key_exists($role, ADMIN_ROLE_LABELS);
}

/**
 * مالک قفل‌شدهٔ فایل .env. در نصب‌های قدیمی، اولین PANEL_ADMINS همان قفل است.
 * این fallback عمداً وجود دارد تا آپدیت، .env کاربر را تغییر ندهد.
 */
function primary_owner_env_id(): int
{
    $explicit = trim(cfg('PANEL_PRIMARY_OWNER_ID', ''));
    if ($explicit !== '' && ctype_digit($explicit) && (int) $explicit > 0) {
        return (int) $explicit;
    }
    $legacy = admin_ids();
    return (int) ($legacy[0] ?? 0);
}

/**
 * شناسهٔ مالک اصلی: ENV بر DB اولویت دارد و مقدار DB را به همان قفل همگام می‌کند.
 * بنابراین حتی اگر کسی از مسیر قدیمی owner_id را دستکاری کند، در اجرای بعدی
 * با مقدار فایل محیطی برمی‌گردد. نوشتن به .env هرگز انجام نمی‌شود.
 */
function primary_owner_id(): int
{
    $envId = primary_owner_env_id();
    $dbId = 0;
    try {
        $row = DB::one("SELECT value FROM settings WHERE key='primary_owner_id'");
        $dbId = $row === null ? 0 : (int) ($row['value'] ?? 0);
    } catch (Throwable $ignored) {
        // در مراحل بسیار ابتدایی بوت، جدول settings شاید هنوز ساخته نشده باشد.
    }

    $winner = $envId > 0 ? $envId : $dbId;
    if ($winner <= 0) {
        try {
            $legacy = DB::one("SELECT value FROM settings WHERE key='owner_id'");
            $winner = (int) ($legacy['value'] ?? 0);
        } catch (Throwable $ignored) {
        }
    }
    if ($winner <= 0) {
        return 0;
    }

    try {
        if ($dbId !== $winner) {
            set_put('primary_owner_id', (string) $winner);
        }
        /* سازگاری با نسخه‌های قبلی که owner_id را می‌خوانند. */
        if ((int) set_get('owner_id', '0') !== $winner) {
            set_put('owner_id', (string) $winner);
        }
    } catch (Throwable $ignored) {
    }
    return $winner;
}

function admin_is_primary(int $uid): bool
{
    return $uid > 0 && $uid === primary_owner_id();
}

/** نقش یک کاربر؛ owner فقط نقش محاسباتی است و هیچ‌وقت در DB ثبت نمی‌شود. */
function admin_role_of(int $uid): string
{
    if ($uid <= 0) {
        return '';
    }
    if (admin_is_primary($uid)) {
        return 'owner';
    }
    try {
        $row = DB::one('SELECT role FROM admin_roles WHERE tg_id=?', [$uid]);
        $role = trim((string) ($row['role'] ?? ''));
        return admin_role_valid($role) ? $role : '';
    } catch (Throwable $ignored) {
        return '';
    }
}

function admin_role_label(string $role): string
{
    return $role === 'owner' ? '👑 مالک اصلی (قفل‌شده)' : (ADMIN_ROLE_LABELS[$role] ?? '—');
}

/** آیا این شناسه یکی از مدیرانِ قابل‌ورود به کنسول است؟ */
function admin_is_console(int $uid): bool
{
    return admin_role_of($uid) !== '';
}

/**
 * capabilityهای ثابت. owner/coowner همه‌چیز دارند؛ فقط مدیریت نقش‌ها برای
 * مالک اصلی است تا او همیشه بتواند هم‌مالک را عزل کند.
 */
function admin_can(int $uid, string $capability): bool
{
    $role = admin_role_of($uid);
    if ($role === '') {
        return false;
    }
    if ($capability === 'console') {
        return true;
    }
    if ($role === 'owner' || $role === ADMIN_ROLE_COOWNER) {
        return true;
    }
    return match ($role) {
        ADMIN_ROLE_FINANCE => in_array($capability, ['receipt'], true),
        ADMIN_ROLE_SUPPORT => in_array($capability, ['support', 'users.view', 'stats.simple'], true),
        ADMIN_ROLE_MANAGER => in_array($capability, ['ops', 'ops.logs', 'users.view', 'support'], true),
        default => false,
    };
}

function admin_can_manage_roles(int $uid): bool
{
    return admin_is_primary($uid);
}

/** همهٔ مدیران فعال، با مالک اصلی در ابتدای فهرست. */
function admin_role_rows(): array
{
    $rows = [];
    $primary = primary_owner_id();
    if ($primary > 0) {
        $rows[] = ['tg_id' => $primary, 'role' => 'owner', 'created_at' => 0, 'created_by' => 0];
    }
    try {
        foreach (DB::all('SELECT tg_id, role, created_at, created_by FROM admin_roles '
            . 'ORDER BY CASE role WHEN \'' . ADMIN_ROLE_COOWNER . '\' THEN 0 '
            . 'WHEN \'' . ADMIN_ROLE_MANAGER . '\' THEN 1 '
            . 'WHEN \'' . ADMIN_ROLE_SUPPORT . '\' THEN 2 ELSE 3 END, tg_id') as $row) {
            $id = (int) ($row['tg_id'] ?? 0);
            $role = (string) ($row['role'] ?? '');
            if ($id > 0 && $id !== $primary && admin_role_valid($role)) {
                $rows[] = ['tg_id' => $id, 'role' => $role,
                    'created_at' => (int) ($row['created_at'] ?? 0),
                    'created_by' => (int) ($row['created_by'] ?? 0)];
            }
        }
    } catch (Throwable $ignored) {
    }
    return $rows;
}

function admin_role_assign(int $actorId, int $targetId, string $role): array
{
    if (!admin_can_manage_roles($actorId)) {
        return [false, 'فقط مالک اصلی می‌تواند سطح دسترسی تعیین کند.'];
    }
    if ($targetId <= 0 || !admin_role_valid($role) || is_bot_self($targetId)) {
        return [false, 'شناسه یا سطح دسترسی معتبر نیست.'];
    }
    if (admin_is_primary($targetId)) {
        return [false, '⚠️ مالک اصلی از فایل .env قفل است و قابل تغییر نیست.'];
    }
    DB::exec('INSERT INTO admin_roles(tg_id, role, created_at, created_by) VALUES(?,?,?,?) '
        . 'ON CONFLICT(tg_id) DO UPDATE SET role=excluded.role, created_at=excluded.created_at, '
        . 'created_by=excluded.created_by', [$targetId, $role, now_ts(), $actorId]);
    State::clear($targetId);  // هیچ ویزارد قدیمی با سطح قبلی ادامه پیدا نمی‌کند
    log_line('info', 'rbac role assigned', ['by' => $actorId, 'to' => $targetId, 'role' => $role]);
    return [true, admin_role_label($role) . ' برای <code>' . $targetId . '</code> ذخیره شد.'];
}

function admin_role_remove(int $actorId, int $targetId): array
{
    if (!admin_can_manage_roles($actorId)) {
        return [false, 'فقط مالک اصلی می‌تواند ادمین را حذف کند.'];
    }
    if ($targetId <= 0) {
        return [false, 'شناسه نامعتبر است.'];
    }
    if (admin_is_primary($targetId)) {
        return [false, '⚠️ مالک اصلی قفل است و قابل حذف یا تنزل نیست.'];
    }
    $deleted = DB::exec('DELETE FROM admin_roles WHERE tg_id=?', [$targetId]);
    State::clear($targetId);
    log_line('info', 'rbac role removed', ['by' => $actorId, 'to' => $targetId]);
    return [$deleted > 0, $deleted > 0 ? 'دسترسی ادمین حذف شد.' : 'این شناسه ادمین ثبت‌شده نیست.'];
}

/**
 * یک‌بار مدیران قدیمی PANEL_ADMINS (غیر از مالک اول) را به سطح محدود پشتیبانی
 * وارد می‌کند. بعد از آن مالک اصلی می‌تواند از خود ربات نقشش را عوض یا حذف کند.
 */
function admin_roles_bootstrap(): void
{
    try {
        if (set_get('rbac_legacy_imported', '') === '1') {
            return;
        }
        $primary = primary_owner_id();
        foreach (admin_ids() as $id) {
            if ($id > 0 && $id !== $primary) {
                DB::exec('INSERT OR IGNORE INTO admin_roles(tg_id, role, created_at, created_by) '
                    . 'VALUES(?,?,?,?)', [$id, ADMIN_ROLE_SUPPORT, now_ts(), $primary]);
            }
        }
        set_put('rbac_legacy_imported', '1');
    } catch (Throwable $ex) {
        log_line('warning', 'rbac bootstrap failed: ' . $ex->getMessage());
    }
}

/**
 * حداقل capability لازم برای هر callback مدیریتی. این گارد علاوه بر پنهان شدن
 * دکمه‌هاست؛ پس کپی کردن callback_data نیز سطح دسترسی را دور نمی‌زند.
 */
function admin_callback_capability(string $data): string
{
    $parts = explode(':', $data);
    if (($parts[0] ?? '') !== 'ad') {
        return '';
    }
    $action = (string) ($parts[1] ?? '');
    return match ($action) {
        'home' => 'console',
        'receipts', 'rsearch', 'rc' => 'receipt',
        'stats' => 'stats.simple',
        'users', 'usearch' => 'users.view',
        'u' => admin_user_callback_capability($parts),
        'support' => 'support',
        'panel', 'panels', 'psearch', 'ops' => admin_panel_callback_capability($parts),
        'pg' => admin_pg_callback_capability($parts),
        'find' => admin_find_callback_capability($parts),
        'role', 'rolep' => admin_role_callback_capability($parts),
        'staff' => 'roles.manage',
        /* همهٔ بخش‌های مالی، درگاه، تنظیمات، دیتابیس، بکاپ، آپدیت، محصولات و
           پیام همگانی فقط برای مالک/هم‌مالک است. */
        'finance', 'paygrp', 'late', 'pay', 'payweb', 'finset', 'fin', 'cats', 'cat',
        'products', 'prod', 'pf', 'set', 'setg', 'report', 'restore', 'backup', 'slots',
        'tools', 'settings', 'upd', 'tgl', 'bc', 'broadcast', 'ref', 'fj', 'g', 'help' => 'full',
        default => 'full',
    };
}

function admin_user_callback_capability(array $parts): string
{
    $sub = (string) ($parts[3] ?? '');
    if ($sub === '' || $sub === 'panels') {
        return 'users.view';
    }
    if ($sub === 'msg') {
        return 'support';
    }
    if ($sub === 'sync') {
        return 'ops';
    }
    return 'full';
}

function admin_panel_callback_capability(array $parts): string
{
    /* مسیر قدیمی ad:panel:new نباید بتواند گارد افزودن اتصال را دور بزند. */
    return (($parts[2] ?? '') === 'new') ? 'full' : 'ops';
}

function admin_pg_callback_capability(array $parts): string
{
    $id = (string) ($parts[2] ?? '');
    $op = (string) ($parts[3] ?? '');
    /* فقط مشاهده، تست، دستگاه، همگام‌سازی و نقش‌های سرور برای اپراتور است.
       هر تغییری که اتصال/ساختار سرور را عوض کند، در سطح کامل باقی می‌ماند. */
    if (in_array($id, ['new', 'newapi', 'savekey', 'newpass', 'quick'], true)
        || in_array($op, ['key', 'edit', 'del', 'del2', 'default', 'toggle', 'cat', 'setcat'], true)) {
        return 'full';
    }
    if ($id === 'testall' || ctype_digit($id)) {
        return 'ops';
    }
    return 'full';
}

function admin_find_callback_capability(array $parts): string
{
    $op = (string) ($parts[2] ?? '');
    if ($op === 'inv') {
        return 'full';
    }
    if ($op !== 'pn') {
        return 'ops';
    }
    $action = (string) ($parts[4] ?? '');
    /* تمدید، تعلیق و سقف کاربر عملیات روزانه‌اند؛ حذف دائمی فقط مالک/هم‌مالک. */
    return in_array($action, ['', 'sus', 'res', 'ulup', 'uldn', 'ext'], true) ? 'ops' : 'full';
}

function admin_role_callback_capability(array $parts): string
{
    /* نقش روی محصول یعنی تغییر کالای فروش؛ فقط سطح کامل. نقش صرفاً برای دیدن
       یک سرور در صفحهٔ اپراتور، محصول صفر دارد و مجاز است. */
    $last = (int) ($parts[count($parts) - 1] ?? 0);
    return $last > 0 ? 'full' : 'ops';
}

function admin_callback_allowed(int $uid, string $data): bool
{
    if (!str_starts_with($data, 'ad:')) {
        return true;
    }
    if (!admin_is_console($uid)) {
        return false;
    }
    $cap = admin_callback_capability($data);
    if ($cap === 'roles.manage') {
        return admin_can_manage_roles($uid);
    }
    return admin_can($uid, $cap);
}
