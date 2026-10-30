<?php
declare(strict_types=1);

/* 7) Domain: users, wallet, orders, catalog */

/**
 * کاربران «شبح»: ردیف‌هایی که فقط از جستجوی مدیر یا شناسهٔ دستی ساخته شده‌اند
 * (بی‌نام، بی‌موجودی و بدون هیچ سفارش/پنل/تراکنش). این‌ها نباید در «کاربران» بمانند.
 */
function purge_ghost_users(): int
{
    $rows = DB::all("SELECT u.tg_id FROM users u "
        . "WHERE COALESCE(TRIM(u.name), '') = '' "
        . "AND COALESCE(TRIM(u.uname), '') = '' "
        . "AND COALESCE(u.balance, 0) = 0 "
        . "AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.tg_id = u.tg_id) "
        . "AND NOT EXISTS (SELECT 1 FROM wallet_tx w WHERE w.tg_id = u.tg_id) "
        . "AND NOT EXISTS (SELECT 1 FROM panels p WHERE p.tg_id = u.tg_id) "
        . "AND NOT EXISTS (SELECT 1 FROM slots s WHERE s.tg_id = u.tg_id)");
    $gone = 0;
    foreach ($rows as $row) {
        DB::exec('DELETE FROM users WHERE tg_id=?', [(int) $row['tg_id']]);
        $gone++;
    }
    return $gone;
}

function user_ensure(int $tgId, string $name, string $uname): array
{
    if (is_bot_self($tgId)) {
        /* حساب خود ربات هیچ‌وقت کاربر نمی‌شود و کیف پول ندارد */
        return ['tg_id' => $tgId, 'name' => $name, 'uname' => $uname, 'balance' => 0,
                'is_blocked' => 0, 'created_at' => now_ts(), 'last_seen' => now_ts()];
    }
    $isNew = DB::one('SELECT tg_id FROM users WHERE tg_id=?', [$tgId]) === null;
    DB::exec('INSERT INTO users(tg_id, name, uname, created_at, last_seen) VALUES(?,?,?,?,?) '
        . 'ON CONFLICT(tg_id) DO UPDATE SET name=excluded.name, uname=excluded.uname, '
        . 'last_seen=excluded.last_seen',
        [$tgId, $name, $uname, now_ts(), now_ts()]);
    if ($isNew) {
        try {
            post_report('👥 <b>کاربر تازه</b>' . "\n"
                . '👤 ' . e(mb_substr($name !== '' ? $name : (string) $tgId, 0, 24))
                . ' <code>' . $tgId . '</code>'
                . ($uname !== '' ? "\n" . '🔗 @' . e($uname) : ''), 'users');
        } catch (Throwable $ex) {
            log_line('warning', 'report user failed: ' . $ex->getMessage());
        }
    }
    return user_get($tgId) ?? ['tg_id' => $tgId, 'name' => $name, 'balance' => 0];
}

function user_get(int $tgId): ?array
{
    return DB::one('SELECT * FROM users WHERE tg_id=?', [$tgId]);
}

function balance_of(int $tgId): int
{
    if (is_bot_self($tgId)) {
        return 0; // حساب خود ربات کیف پول ندارد
    }
    return (int) DB::val('SELECT balance FROM users WHERE tg_id=?', [$tgId], 0);
}

function add_balance(int $tgId, int $amount, string $kind, string $ref = '', string $note = ''): int
{
    /* تغییر موجودی همیشه اتمی است: دو شارژ/کسر هم‌زمان پشت سر هم اجرا می‌شوند،
       نه روی هم — و شمارش مصرف همیشه از خودِ دیتابیس خوانده می‌شود. */
    return db_tx(static function () use ($tgId, $amount, $kind, $ref, $note): int {
        $applied = DB::update('UPDATE users SET balance = balance + ? WHERE tg_id=?',
            [$amount, $tgId]);
        if ($applied === 0) {
            log_line('warning', 'add_balance: user not found', ['tg' => $tgId, 'amount' => $amount]);
            return 0;
        }
        $newBalance = (int) DB::val('SELECT balance FROM users WHERE tg_id=?', [$tgId], 0);
        DB::exec('INSERT INTO wallet_tx(tg_id, amount, kind, ref, note, balance, created_at) '
            . 'VALUES(?,?,?,?,?,?,?)',
            [$tgId, $amount, $kind, $ref, mb_substr($note, 0, 200), $newBalance, now_ts()]);
        return $newBalance;
    });
}

const CATEGORY_LABELS = [
    'v2ray'     => '🌐 V2ray / Xray',
    'wireguard' => '🛡 WireGuard',
    'openvpn'   => '🔐 OpenVPN',
    'l2tp'      => '📡 L2TP / IKEv2',
    'special'   => '💎 ویژه',
];

function cat_label(?array $cat): string
{
    if ($cat === null) {
        return 'بدون دسته';
    }
    return trim(((string) ($cat['emoji'] ?? '')) . ' ' . ((string) ($cat['name'] ?? '')));
}

function cat_list(bool $activeOnly = false): array
{
    return DB::all('SELECT * FROM categories' . ($activeOnly ? ' WHERE active=1' : '')
        . ' ORDER BY sort, id');
}

function cat_get(int $id): ?array
{
    return DB::one('SELECT * FROM categories WHERE id=?', [$id]);
}

function cat_create(string $name, string $emoji = '🗂'): int
{
    return DB::exec('INSERT INTO categories(name, emoji, created_at) VALUES(?,?,?)',
        [mb_substr(trim($name), 0, 60), $emoji ?: '🗂', now_ts()]);
}

function products_list(bool $activeOnly = false): array
{
    return DB::all('SELECT * FROM products' . ($activeOnly ? ' WHERE active=1' : '')
        . ' ORDER BY sort, id');
}

function product_get(int $id): ?array
{
    return DB::one('SELECT * FROM products WHERE id=?', [$id]);
}

const PRODUCT_SALE_METERED = 'metered';
const PRODUCT_SALE_UNLIMITED_USERS = 'unlimited_users';
const UNLIMITED_USERS_MAX = 1000000;
/* `legacy` is deliberately the migration default.  A sold plan must never be
   re-priced merely because its catalog product later changes. */
const UNLIMITED_BILLING_LEGACY = 'legacy';
const UNLIMITED_BILLING_HOURLY = 'hourly';
const UNLIMITED_ROLE_GUIDE = 'ℹ️ راهنما: تعداد روز (مدت اعتبار) و محدودیت تعداد دستگاه در پنل‌های نامحدود، مستقیماً از روی تنظیمات نقش (Role) در پنل پاسارگاد اعمال می‌شود. ربات فقط وظیفه ساخت تعداد یوزر/کلاینت درخواستی را بر عهده دارد.';

/** نوع فروش فقط روی محصول تازه اثر دارد؛ پنل فروخته‌شده snapshot خودش را دارد. */
function product_sale_type(array $product): string
{
    return (string) ($product['sale_type'] ?? '') === PRODUCT_SALE_UNLIMITED_USERS
        ? PRODUCT_SALE_UNLIMITED_USERS : PRODUCT_SALE_METERED;
}

function product_is_unlimited(array $product): bool
{
    return product_sale_type($product) === PRODUCT_SALE_UNLIMITED_USERS;
}

/** Billing is a product choice at purchase time, then copied to the sold panel. */
function unlimited_billing_mode(array $row): string
{
    return (string) ($row['unlimited_billing_mode'] ?? '') === UNLIMITED_BILLING_HOURLY
        ? UNLIMITED_BILLING_HOURLY : UNLIMITED_BILLING_LEGACY;
}

function product_is_hourly_unlimited(array $product): bool
{
    return product_is_unlimited($product)
        && unlimited_billing_mode($product) === UNLIMITED_BILLING_HOURLY;
}

/**
 * سرویس تست فقط برای محصولات متری است. این کنترل مرکزی، ورودی‌های دستی و
 * سفارش‌هایی را که محصولشان پس از ثبت تغییر کرده نیز از ساخت پنل نامحدود بازمی‌دارد.
 */
function trial_product_allowed(?array $product): bool
{
    return $product !== null && (int) ($product['active'] ?? 0) === 1
        && !product_is_unlimited($product);
}

/** پنل نامحدود هرگز از تغییر بعدی محصول یا حذف محصول استنتاج نمی‌شود. */
function panel_is_unlimited(array $panel): bool
{
    return (int) ($panel['is_unlimited'] ?? 0) === 1;
}

function panel_is_hourly_unlimited(array $panel): bool
{
    return panel_is_unlimited($panel)
        && unlimited_billing_mode($panel) === UNLIMITED_BILLING_HOURLY;
}

/** قیمت و حداقلِ پنل فروخته‌شده snapshot هستند، نه تنظیمات قابل‌تغییر محصول امروز. */
function panel_unlimited_user_price(array $panel): int
{
    return max(0, (int) ($panel['unlimited_user_price'] ?? 0));
}

function panel_unlimited_min_users(array $panel): int
{
    return max(1, (int) ($panel['unlimited_min_users'] ?? 1));
}

/** Fixed hourly rent is stored in the normal hourly_price snapshot for new plans. */
function panel_unlimited_fixed_hourly_price(array $panel): int
{
    return panel_is_hourly_unlimited($panel) ? max(0, (int) ($panel['hourly_price'] ?? 0)) : 0;
}

function unlimited_fixed_hourly_price(array $product): int
{
    return product_is_hourly_unlimited($product) ? max(0, (int) ($product['hourly_price'] ?? 0)) : 0;
}

function panel_unlimited_user_count_check(array $panel, int $count): array
{
    if (!panel_is_unlimited($panel)) {
        return [false, 'این پنل از نوع نامحدود نیست.'];
    }
    $min = panel_unlimited_min_users($panel);
    if ($count < $min) {
        return [false, 'حداقل تعداد قابل افزودن <b>' . fa_digits($min) . '</b> کاربر است.'];
    }
    $price = panel_unlimited_user_price($panel);
    if ($count > UNLIMITED_USERS_MAX || $price <= 0 || $count > intdiv(PHP_INT_MAX, $price)) {
        return [false, 'تعداد کاربر یا قیمت ذخیره‌شدهٔ این پنل معتبر نیست؛ با پشتیبانی تماس بگیرید.'];
    }
    return [true, ''];
}

function unlimited_user_price(array $product): int
{
    return max(0, (int) ($product['per_user_price'] ?? 0));
}

function unlimited_min_users(array $product): int
{
    return max(1, (int) ($product['min_users'] ?? 1));
}

/** اعتبارسنجی واحد برای خرید نخست و شارژ کاربر؛ شمارش فارسی هم با to_int پشتیبانی می‌شود. */
function unlimited_user_count_check(array $product, int $count): array
{
    if (!product_is_unlimited($product)) {
        return [false, 'این محصول از نوع پنل نامحدود نیست.'];
    }
    $min = unlimited_min_users($product);
    if ($count < $min) {
        return [false, 'حداقل تعداد قابل سفارش برای این پنل <b>' . fa_digits($min)
            . '</b> کاربر است.'];
    }
    if ($count > UNLIMITED_USERS_MAX) {
        return [false, 'تعداد کاربر بسیار بزرگ است؛ حداکثر مجاز <b>'
            . fa_digits(UNLIMITED_USERS_MAX) . '</b> است.'];
    }
    $price = unlimited_user_price($product);
    if ($price <= 0 || $count > intdiv(PHP_INT_MAX, $price)) {
        return [false, 'قیمت هر کاربر این محصول معتبر نیست؛ با پشتیبانی تماس بگیرید.'];
    }
    return [true, ''];
}

function unlimited_user_order_total(array $product, int $count): int
{
    return $count * unlimited_user_price($product);
}

/** First hour is paid with the purchase; later hours are wallet-metered. */
function unlimited_initial_hourly_total(array $product, int $count): int
{
    $users = unlimited_user_order_total($product, $count);
    $fixed = unlimited_fixed_hourly_price($product);
    return $users > PHP_INT_MAX - $fixed ? PHP_INT_MAX : $users + $fixed;
}

function panel_unlimited_users_hourly_total(array $panel, int $count): int
{
    $unit = panel_unlimited_user_price($panel);
    if ($count < 0 || ($unit > 0 && $count > intdiv(PHP_INT_MAX, $unit))) {
        return PHP_INT_MAX;
    }
    return $count * $unit;
}

/**
 * Immutable hourly components: a new user block starts at its own paid-through
 * time, avoiding a charge for hours before that block existed.
 */
function unlimited_billing_unit_add(int $panelId, ?int $sourceOrderId, string $component,
                                    int $units, int $hourlyPrice, int $paidUntil): void
{
    if ($panelId <= 0 || $units < 0 || $hourlyPrice < 0 || $paidUntil <= 0) {
        throw new InvalidArgumentException('invalid unlimited hourly billing component');
    }
    DB::exec('INSERT OR IGNORE INTO unlimited_billing_units(panel_id, source_order_id, component, '
        . 'units, hourly_price, paid_until, active, created_at) VALUES(?,?,?,?,?,?,1,?)',
        [$panelId, $sourceOrderId, mb_substr($component, 0, 20), $units, $hourlyPrice,
         $paidUntil, now_ts()]);
}

function unlimited_billing_next_due(int $panelId): int
{
    return (int) DB::val('SELECT MIN(paid_until) FROM unlimited_billing_units '
        . 'WHERE panel_id=? AND active=1 AND (units * hourly_price) > 0', [$panelId], 0);
}

function product_update(int $id, string $column, mixed $value): void
{
    $allowed = ['name', 'emoji', 'hourly_price', 'per_gb_price', 'per_user_price', 'min_users',
                'min_balance', 'max_users', 'extra_block_size', 'extra_block_price',
                'device_limit', 'days', 'data_cap_gb', 'slots_needed', 'renew_price',
                'panel_prefix', 'role_id', 'panel_id', 'active', 'trial'];
    if (!in_array($column, $allowed, true)) {
        throw new InvalidArgumentException("column not editable: $column");
    }
    DB::exec("UPDATE products SET $column=? WHERE id=?", [$value, $id]);
}

function ref_code(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

function create_order(int $tgId, string $kind, int $price, ?int $productId = null,
                      array $payload = [], int $discount = 0, string $discountCode = ''): array
{
    $payable = max(0, $price - $discount);
    $id = DB::exec('INSERT INTO orders(tg_id, kind, product_id, price, discount, payable, status, '
        . 'ref, payload, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$tgId, $kind, $productId, $price, $discount, $payable, 'pending', ref_code(),
         json_encode($payload, JSON_UNESCAPED_UNICODE), now_ts()]);
    if ($discountCode !== '' && $discount > 0) {
        DB::exec('UPDATE discounts SET uses = uses + 1 WHERE code=?', [strtoupper($discountCode)]);
    }
    return order_get($id) ?? [];
}

function order_get(int $id): ?array
{
    return DB::one('SELECT * FROM orders WHERE id=?', [$id]);
}

function order_mark(int $id, string $status): void
{
    DB::exec('UPDATE orders SET status=?, paid_at=? WHERE id=?',
        [$status, $status === 'delivered' ? now_ts() : null, $id]);
}

function discount_check(string $code, int $price): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return [0, ''];
    }
    if (!set_bool('discount_enabled', true)) {
        return [0, 'کد تخفیف غیرفعال است.'];
    }
    $row = DB::one('SELECT * FROM discounts WHERE code=? AND active=1', [$code]);
    if ($row === null) {
        return [0, 'کد تخفیف نامعتبر است.'];
    }
    if ($row['expires_at'] && (int) $row['expires_at'] < now_ts()) {
        return [0, 'کد تخفیف منقضی شده است.'];
    }
    if ((int) $row['max_uses'] && (int) $row['uses'] >= (int) $row['max_uses']) {
        return [0, 'ظرفیت این کد تکمیل شده است.'];
    }
    $amount = (int) ($price * (int) $row['percent'] / 100) + (int) $row['amount'];
    $amount = min($amount, $price);
    return [$amount, 'کد تخفیف اعمال شد: ' . money($amount)];
}

function slots_total(int $tgId): array
{
    $free = set_int('slots_free', 3);
    $bought = (int) DB::val('SELECT COALESCE(SUM(count),0) FROM slots WHERE tg_id=?', [$tgId], 0);
    $used = (int) DB::val("SELECT COUNT(*) FROM panels WHERE tg_id=? "
        . "AND status IN ('active','suspended') AND COALESCE(is_trial,0)=0", [$tgId], 0);
    return [$free, $bought, $used];
}

function slots_available(int $tgId): int
{
    [$free, $bought, $used] = slots_total($tgId);
    return max(0, $free + $bought - $used);
}

