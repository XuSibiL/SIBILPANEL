<?php
declare(strict_types=1);

/* 4) Settings */

const SETTING_DEFAULTS = [
    'card_number'          => '',
    'card_holder'          => '',
    'min_topup'            => '50000',
    'max_topup'            => '20000000',
    'auto_confirm_receipt' => '0',
    'report_channel'       => '',
    'bot_name'             => '',
    'support_link'         => '',
    'channel_link'         => 'https://t.me/sibilpanel',
    'crypto_links'         => 'swapwallet.app|nikpardakht.com|webpurse.org|bitpin.ir|sarmayex.com|ok-ex.io|nobitex.ir|bitbarg.com|cafearz.com|pay98.app',
    'slots_free'           => '3',
    'slot_price'           => '150000',
    'slot_days'            => '30',
    'max_slots'            => '20',
    'billing_enabled'      => '1',
    'billing_grace_hours'  => '6',
    'billing_hourly'       => '1',
    'billing_per_gb'       => '1',
    'meter_grace'          => '0',
    'discount_enabled'     => '1',
    'sales_open'           => '1',
    'ref_percent'          => '10',
    'ref_mode'             => 'percent',      // percent = درصد واریزها · cash = پاداش نقدی ثابت
    'ref_cash'             => '10000',
    'ref_cash_when'        => 'join',        // join = لحظهٔ عضویت · purchase = اولین خرید
    'ref_max_deposits'     => '2',            // قانون طلایی: فقط واریزی اول و دوم
    'web_domain'           => '',
    'web_url'              => '',
    'web_port'             => '8080',
    'stars_rate'           => '1000',
    'pay_expire_minutes'   => '30',
    'trial_on'             => '1',
    'trial_gb'             => '1',
    'trial_hours'          => '24',
    'trial_per_user'       => '1',
    'trial_user_limit'     => '10',   // سقف ساخت کاربر داخلِ پنل تست روی پاسارگاد
    'trial_price'          => '0',
    'panel_test_timeout'   => '20',
    'panel_test_amount'    => '0',
    'suspend_on_zero'      => '1',
    'meter_minutes'        => '60',
    'report_chat'          => '',
    'report_topics'        => '',
    'report_topics_on'     => '1',
    'report_force'         => '1',
    'backup_on'            => '1',
    'backup_at_hour'       => '0',
    'backup_chat'          => '',
    'backup_env'           => '1',     // فایل تنظیمات هم کنار بکاپ ارسال شود
    'last_backup_at'       => '0',
    'panel_pass_len'       => '12',
    'prepaid_hours'        => '24',   // ساعت‌های پیش‌پرداخت‌شدهٔ اول پنل (غیرقابل استرداد)
    'force_channel_on'     => '0',
    'force_channel_id'     => '',
    'trial_in_panels'      => '0',
    'admin_trial_unlimited' => '1',
    'kyc_on'               => '1',
    'kyc_l0_daily'         => '500000',
    'kyc_l0_monthly'       => '5000000',
    'kyc_l1_daily'         => '2000000',
    'kyc_l1_monthly'       => '30000000',
    'kyc_l2_daily'         => '10000000',
    'kyc_l2_monthly'       => '150000000',
    'kyc_topic_1'          => '0',
    'kyc_topic_2'          => '0',
    'kyc_banner_file'      => '',
    'kyc_banner2_file'     => '',
    'device_guard_on'      => '1',
    'device_cut_minutes'   => '10',
    'device_max_devices'   => '10',
    'backup_ext'           => 'panelbak',
    'bc_segment'           => 'all',
    'bc_inactive_days'     => '7',
    'bc_pin'               => '0',
];

function set_put(string $key, string $value): void
{
    DB::exec('INSERT INTO settings(key, value) VALUES(?, ?) '
        . 'ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]);
}

function set_get(string $key, ?string $default = null): string
{
    $row = DB::one('SELECT value FROM settings WHERE key=?', [$key]);
    if ($row === null) {
        return $default ?? (SETTING_DEFAULTS[$key] ?? '');
    }
    return (string) $row['value'];
}

function set_int(string $key, int $default = 0): int
{
    $value = set_get($key, (string) $default);
    return $value === '' ? $default : (int) $value;
}

function set_bool(string $key, bool $default = false): bool
{
    $value = set_get($key, $default ? '1' : '0');
    return $value === '1';
}

function settings_bootstrap(): void
{
    // درخواست کاربر: پنل‌های وصل‌شدهٔ قبلی یک‌بار پاک شوند تا با API Key دوباره وصل شود
    if (DB::one("SELECT key FROM settings WHERE key='panel_reset_3410'") === null) {
        $connected = (int) DB::val('SELECT COUNT(*) FROM pg_panels', [], 0);
        if ($connected > 0) {
            DB::exec('DELETE FROM pg_panels');
            DB::exec('UPDATE products SET panel_id=NULL');
            log_line('info', 'removed ' . $connected . ' connected panel(s); reconnect with an API key');
        }
        set_put('panel_reset_3410', '1');
    }
    if (function_exists('pay_settings_bootstrap')) {
        pay_settings_bootstrap();
    }
    foreach (SETTING_DEFAULTS as $key => $value) {
        if (DB::one('SELECT key FROM settings WHERE key=?', [$key]) === null) {
            set_put($key, $value);
        }
    }
}

