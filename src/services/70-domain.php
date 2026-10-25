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

function product_update(int $id, string $column, mixed $value): void
{
    $allowed = ['name', 'emoji', 'hourly_price', 'per_gb_price', 'min_balance', 'max_users',
                'extra_block_size', 'extra_block_price',
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

