<?php
declare(strict_types=1);

/* 3) Database — same schema as the Python edition */

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $path = db_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA busy_timeout=5000');
        return self::$pdo;
    }

    /** بستن اتصال — لازمهٔ جایگزینی فایل دیتابیس در بازگردانی بکاپ */
    public static function close(): void
    {
        self::$pdo = null;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = [], mixed $default = 0): mixed
    {
        $row = self::q($sql, $params)->fetch(PDO::FETCH_NUM);
        if ($row === false || $row[0] === null) {
            return $default;
        }
        return $row[0];
    }

    public static function exec(string $sql, array $params = []): int
    {
        self::q($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** تعداد ردیف‌هایی که UPDATE/DELETE واقعاً دست زده — برای قفل‌های اتمی */
    public static function update(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    /**
     * تراکنش اتمی («START TRANSACTION … COMMIT») با قفل نوشتن.
     * در SQLite، BEGIN IMMEDIATE همان قفل انحصاری نوشتن را می‌گیرد؛ بنابراین
     * دو شارژ/کسر هم‌زمان (Race Condition) نمی‌توانند هر دو اعمال شوند.
     * اگر بدنه استثنا بدهد، همه‌چیز ROLLBACK می‌شود.
     */
    public static function tx(callable $body): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $body();       // تراکنش تودرتو لازم نیست
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $body();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $ex) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $ex;
        }
    }
}

/** میان‌بر تراکنش برای کدهای غیرشیءگرا */
function db_tx(callable $body): mixed
{
    return DB::tx($body);
}

const PANEL_SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    tg_id        INTEGER PRIMARY KEY,
    name         TEXT,
    uname        TEXT,
    balance      INTEGER NOT NULL DEFAULT 0,
    referred_by  INTEGER,
    ref_paid     INTEGER NOT NULL DEFAULT 0,
    is_blocked   INTEGER NOT NULL DEFAULT 0,
    created_at   INTEGER NOT NULL,
    last_seen    INTEGER NOT NULL DEFAULT 0,
    phone            TEXT,
    discount_percent INTEGER NOT NULL DEFAULT 0,
    discount_until   INTEGER,
    deposit_count    INTEGER NOT NULL DEFAULT 0,
    ref_cash_paid    INTEGER NOT NULL DEFAULT 0,
    ref_reward_total INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    emoji      TEXT    NOT NULL DEFAULT '🗂',
    note       TEXT,
    sort       INTEGER NOT NULL DEFAULT 0,
    active     INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS products (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    name           TEXT    NOT NULL,
    emoji          TEXT    NOT NULL DEFAULT '💎',
    category       TEXT    NOT NULL DEFAULT 'v2ray',
    category_id    INTEGER,
    role_id        INTEGER NOT NULL DEFAULT 0,
    group_ids      TEXT    NOT NULL DEFAULT '[]',
    max_users      INTEGER NOT NULL DEFAULT 10,
    device_limit   INTEGER NOT NULL DEFAULT 1,
    days           INTEGER NOT NULL DEFAULT 30,
    data_cap_gb    REAL    NOT NULL DEFAULT 0,
    per_gb_price   INTEGER NOT NULL DEFAULT 0,
    hourly_price   INTEGER NOT NULL DEFAULT 0,
    min_balance    INTEGER NOT NULL DEFAULT 0,
    renew_price    INTEGER NOT NULL DEFAULT 0,
    slots_needed   INTEGER NOT NULL DEFAULT 1,
    panel_prefix   TEXT    NOT NULL DEFAULT '',
    template_id    INTEGER,
    note           TEXT,
    trial          INTEGER NOT NULL DEFAULT 0,
    active         INTEGER NOT NULL DEFAULT 1,
    sort           INTEGER NOT NULL DEFAULT 0,
    created_at     INTEGER NOT NULL,
    panel_id       INTEGER
);

CREATE TABLE IF NOT EXISTS panels (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id           INTEGER NOT NULL,
    product_id      INTEGER NOT NULL,
    panel_admin_id  INTEGER,
    panel_username  TEXT NOT NULL UNIQUE,
    panel_password  TEXT,
    label           TEXT,
    max_users       INTEGER NOT NULL DEFAULT 0,
    device_limit    INTEGER NOT NULL DEFAULT 0,
    data_cap        INTEGER NOT NULL DEFAULT 0,
    expires_at      INTEGER,
    per_gb_price    INTEGER NOT NULL DEFAULT 0,
    hourly_price    INTEGER NOT NULL DEFAULT 0,
    min_balance     INTEGER NOT NULL DEFAULT 0,
    status          TEXT NOT NULL DEFAULT 'active',
    last_usage      INTEGER NOT NULL DEFAULT 0,
    last_meter_at   INTEGER NOT NULL DEFAULT 0,
    spent_total     INTEGER NOT NULL DEFAULT 0,
    suspended_at    INTEGER,
    order_id        INTEGER,
    created_at      INTEGER NOT NULL,
    pg_panel_id     INTEGER,
    is_trial        INTEGER NOT NULL DEFAULT 0,
    hourly_paused   INTEGER NOT NULL DEFAULT 0,
    violation_until INTEGER,
    violation_count INTEGER NOT NULL DEFAULT 0,
    last_devices    INTEGER NOT NULL DEFAULT 0,
    last_hwid_limit INTEGER NOT NULL DEFAULT 0,
    last_users      INTEGER NOT NULL DEFAULT 0,
    last_synced_at  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS pg_panels (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    url          TEXT    NOT NULL,
    username     TEXT    NOT NULL DEFAULT '',
    password     TEXT    NOT NULL DEFAULT '',
    api_key      TEXT    NOT NULL DEFAULT '',
    note         TEXT,
    active       INTEGER NOT NULL DEFAULT 1,
    is_default   INTEGER NOT NULL DEFAULT 0,
    last_ok_at   INTEGER NOT NULL DEFAULT 0,
    last_error   TEXT,
    created_at   INTEGER NOT NULL,
    category_id  INTEGER
);

CREATE TABLE IF NOT EXISTS slots (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id      INTEGER NOT NULL,
    count      INTEGER NOT NULL DEFAULT 1,
    expires_at INTEGER,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id       INTEGER NOT NULL,
    kind        TEXT    NOT NULL,
    product_id  INTEGER,
    panel_id    INTEGER,
    price       INTEGER NOT NULL,
    discount    INTEGER NOT NULL DEFAULT 0,
    payable     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    ref         TEXT    NOT NULL,
    payload     TEXT,
    error       TEXT,
    created_at  INTEGER NOT NULL,
    paid_at     INTEGER
);

CREATE TABLE IF NOT EXISTS receipts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    tg_id       INTEGER NOT NULL,
    amount      INTEGER NOT NULL,
    photo_id    TEXT,
    ref         TEXT,
    status      TEXT NOT NULL DEFAULT 'pending',
    note        TEXT,
    created_at  INTEGER NOT NULL,
    reviewed_by INTEGER,
    reviewed_at INTEGER,
    card_chat   TEXT,
    card_msg    INTEGER
);

CREATE TABLE IF NOT EXISTS payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    tg_id       INTEGER NOT NULL,
    method      TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    authority   TEXT,
    status      TEXT    NOT NULL DEFAULT 'unpaid',
    ref         TEXT,
    payload     TEXT,
    created_at  INTEGER NOT NULL,
    paid_at     INTEGER
);

CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(order_id);
CREATE INDEX IF NOT EXISTS idx_payments_auth  ON payments(authority);

CREATE TABLE IF NOT EXISTS wallet_tx (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id      INTEGER NOT NULL,
    amount     INTEGER NOT NULL,
    kind       TEXT    NOT NULL,
    ref        TEXT,
    note       TEXT,
    balance    INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS usage_charges (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    panel_id   INTEGER NOT NULL,
    tg_id      INTEGER NOT NULL,
    bytes      INTEGER NOT NULL DEFAULT 0,
    hours      REAL    NOT NULL DEFAULT 0,
    amount     INTEGER NOT NULL DEFAULT 0,
    kind       TEXT    NOT NULL DEFAULT 'usage',
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS discounts (
    code       TEXT PRIMARY KEY,
    percent    INTEGER NOT NULL DEFAULT 0,
    amount     INTEGER NOT NULL DEFAULT 0,
    max_uses   INTEGER NOT NULL DEFAULT 0,
    uses       INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER,
    active     INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS discount_uses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    code       TEXT    NOT NULL,
    tg_id      INTEGER NOT NULL,
    order_id   INTEGER,
    panel_id   INTEGER,
    percent    INTEGER NOT NULL DEFAULT 0,
    days       INTEGER NOT NULL DEFAULT 30,
    created_at INTEGER NOT NULL,
    expires_at INTEGER
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS tg_state (
    uid        INTEGER PRIMARY KEY,
    state      TEXT,
    data       TEXT,
    updated_at INTEGER NOT NULL
);

/* محدودسازی موقتِ «همان یک کاربر» (نه کل پنل):
   username، panel_id، restricted_at و unban_at */
CREATE TABLE IF NOT EXISTS temp_restricted_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    panel_id      INTEGER NOT NULL DEFAULT 0,
    tg_id         INTEGER NOT NULL DEFAULT 0,
    username      TEXT    NOT NULL DEFAULT '',
    restricted_at INTEGER NOT NULL DEFAULT 0,
    unban_at      INTEGER NOT NULL DEFAULT 0,
    devices       INTEGER NOT NULL DEFAULT 0,
    max_allowed   INTEGER NOT NULL DEFAULT 0,
    reason        TEXT    NOT NULL DEFAULT 'device_limit'
);
CREATE UNIQUE INDEX IF NOT EXISTS temp_restricted_users_uq
    ON temp_restricted_users(panel_id, username);

CREATE TABLE IF NOT EXISTS kyc_cards (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id       INTEGER NOT NULL,
    card_number TEXT NOT NULL,
    bank        TEXT NOT NULL DEFAULT '',
    status      TEXT NOT NULL DEFAULT 'pending',
    level       INTEGER NOT NULL DEFAULT 0,
    created_at  INTEGER NOT NULL,
    verified_at INTEGER,
    verified_by INTEGER,
    photo_id    TEXT
);

CREATE INDEX IF NOT EXISTS idx_kyc_cards_user ON kyc_cards(tg_id);

CREATE TABLE IF NOT EXISTS kyc_requests (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id       INTEGER NOT NULL,
    level       INTEGER NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending',
    payload     TEXT,
    photo_id    TEXT,
    card_id     INTEGER,
    reason      TEXT,
    created_at  INTEGER NOT NULL,
    reviewed_at INTEGER,
    reviewed_by INTEGER,
    admin_msg   TEXT
);

CREATE INDEX IF NOT EXISTS idx_kyc_req_user ON kyc_requests(tg_id);

CREATE TABLE IF NOT EXISTS nav_stack (
    uid        INTEGER PRIMARY KEY,
    data       TEXT,
    updated_at INTEGER NOT NULL
);
SQL;

function db_bootstrap(): void
{
    DB::pdo()->exec(PANEL_SCHEMA);
    // ستون‌های تازه برای دیتابیس‌های قدیمی‌تر
    $wanted = [
        'products'  => ['panel_id' => 'INTEGER', 'category_id' => 'INTEGER',
                        'extra_block_size' => 'INTEGER DEFAULT 1000',
                        'extra_block_price' => 'INTEGER DEFAULT 0'],
        'pg_panels' => ['category_id' => 'INTEGER', 'api_key' => 'TEXT'],
        'discounts' => ['per_user' => 'INTEGER DEFAULT 1', 'first_only' => 'INTEGER DEFAULT 0',
                        'days' => 'INTEGER DEFAULT 30', 'hours' => 'INTEGER DEFAULT 0',
                        'created_at' => 'INTEGER DEFAULT 0',
                        'max_users' => 'INTEGER DEFAULT 0'],
        'receipts'  => ['card_chat' => 'TEXT', 'card_msg' => 'INTEGER DEFAULT 0'],
        'users'     => ['kyc_level' => 'INTEGER DEFAULT 0', 'kyc_phone' => 'TEXT',
                        'phone' => 'TEXT',
                        'discount_percent' => 'INTEGER DEFAULT 0',
                        'discount_until' => 'INTEGER',
                        'deposit_count' => 'INTEGER DEFAULT 0',
                        'ref_cash_paid' => 'INTEGER DEFAULT 0',
                        'ref_reward_total' => 'INTEGER DEFAULT 0'],
        'panels'    => ['pg_panel_id' => 'INTEGER', 'user_cap' => 'INTEGER DEFAULT 0',
                        'is_trial' => 'INTEGER DEFAULT 0',
                        'hourly_paused' => 'INTEGER DEFAULT 0',
                        'violation_until' => 'INTEGER', 'violation_count' => 'INTEGER DEFAULT 0',
                        'last_devices' => 'INTEGER DEFAULT 0',
                        'billed_bytes' => 'INTEGER DEFAULT 0',
                        'last_devices_max' => 'INTEGER DEFAULT 0',
                        'last_hwid_limit' => 'INTEGER DEFAULT 0',
                        'last_users' => 'INTEGER DEFAULT 0',
                        'last_synced_at' => 'INTEGER DEFAULT 0',
                        'suspended_reason' => 'TEXT',
                        'discount_percent' => 'INTEGER DEFAULT 0',
                        'discount_until' => 'INTEGER'],
    ];
    foreach ($wanted as $table => $columns) {
        $have = [];
        foreach (DB::all("PRAGMA table_info($table)") as $row) {
            $have[] = $row['name'];
        }
        foreach ($columns as $column => $type) {
            if (!in_array($column, $have, true)) {
                DB::pdo()->exec("ALTER TABLE $table ADD COLUMN $column $type");
                log_line('info', "migration: added $table.$column");
                if ($table === 'panels' && $column === 'billed_bytes') {
                    /* پنل‌هایی که از قبل مصرف دارند: گذشته یک‌بار به‌عنوان
                       «حساب‌شده» علامت می‌خورد تا دوباره کسر نشود */
                    DB::pdo()->exec('UPDATE panels SET billed_bytes = '
                        . '(CAST(last_usage / 1073741824 AS INTEGER) * 1073741824)');
                }
            }
        }
    }
    /* مهاجرت: جدول قدیمی device_cuts (قطع کل کانفیگ) → temp_restricted_users
       (محدودسازی فقط همان کاربر). ردیف‌های زنده حفظ می‌شوند. */
    try {
        $legacy = DB::all("SELECT name FROM sqlite_master WHERE type='table' AND name='device_cuts'");
        if ($legacy !== []) {
            foreach (DB::all('SELECT * FROM device_cuts') as $row) {
                $username = (string) ($row['username'] ?? '');
                if ($username === '') {
                    continue;
                }
                $unban = (int) ($row['until'] ?? 0) ?: (int) ($row['created_at'] ?? now_ts()) + 600;
                DB::exec('INSERT OR IGNORE INTO temp_restricted_users'
                    . '(panel_id, tg_id, username, restricted_at, unban_at, devices, max_allowed, reason)'
                    . ' VALUES(?,?,?,?,?,?,?,?)',
                    [(int) ($row['panel_id'] ?? 0), (int) ($row['tg_id'] ?? 0), $username,
                     (int) ($row['created_at'] ?? now_ts()), $unban,
                     (int) ($row['devices'] ?? 0), (int) ($row['max_allowed'] ?? 0), 'device_limit']);
            }
            DB::pdo()->exec('DROP TABLE device_cuts');
            log_line('info', 'migration: device_cuts → temp_restricted_users');
        }
    } catch (Throwable $ex) {
        log_line('warning', 'migration device_cuts failed: ' . $ex->getMessage());
    }

    /* حساب خودِ ربات هیچ‌وقت کاربر یا کیف پول نیست — ردیف‌های سرگردان پاک می‌شوند */
    $selfId = bot_self_id();
    if ($selfId > 0) {
        foreach (['users', 'wallet_tx', 'slots'] as $table) {
            try {
                DB::exec("DELETE FROM $table WHERE tg_id=?", [$selfId]);
            } catch (Throwable $ex) {
                // جدول هنوز ساخته نشده — مشکلی نیست
            }
        }
    }
}

