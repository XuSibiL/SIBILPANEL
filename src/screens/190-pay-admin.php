<?php
declare(strict_types=1);

/* 23) ADMIN — مدیریت درگاه‌های پرداخت */

function pay_gateway_state(string $gw): array
{
    $meta = PAY_GATEWAYS[$gw];
    $on = ($gw === 'card') ? set_get('pay_card_on', '1') === '1' : gw_on($gw);
    [$ready, $missing] = gw_readiness($gw);
    return ['on' => $on, 'ready' => $ready, 'missing' => $missing, 'type' => $meta['type']];
}

/* خانوادهٔ هر درگاه: کارت‌به‌کارت / کریپتو / ریالی / تلگرام */
function pay_gateway_group(string $gw): string
{
    return match ($gw) {
        'card' => 'card',
        'nowpayment', 'plisio', 'trx' => 'crypto',
        'stars' => 'stars',
        'wallet' => 'wallet',
        default => 'fiat',
    };
}

function pay_group_title(string $group): string
{
    return ['card' => '💳 کارت به کارت', 'crypto' => '₿ ارز دیجیتال',
            'fiat' => '🏦 درگاه‌های ریالی', 'stars' => '⭐️ تلگرام استارز',
            'wallet' => '👛 کیف پول'][$group] ?? $group;
}

/** شمارش وضعیت یک خانواده، برای نشان دادن روی دکمهٔ گروه */
function pay_group_state(string $group): array
{
    $on = 0;
    $all = 0;
    foreach (PAY_GATEWAYS as $id => $meta) {
        if (pay_gateway_group($id) !== $group) {
            continue;
        }
        $all++;
        if ($id === 'wallet' || pay_gateway_state($id)['on']) {
            $on++;
        }
    }
    return [$on, $all];
}

function screen_pay_group(int|string $chat, ?int $messageId, string $group): void
{
    $members = [];
    foreach (PAY_GATEWAYS as $id => $meta) {
        if (pay_gateway_group($id) === $group) {
            $members[] = $id;
        }
    }
    if (count($members) === 1) {
        /* خانوادهٔ تک‌عضوی واسطه لازم ندارد */
        screen_pay_gateway($chat, $messageId, $members[0]);
        return;
    }
    $rows = [];
    $text = '<b>' . pay_group_title($group) . "</b>\n\n";
    foreach (PAY_GATEWAYS as $id => $meta) {
        if (pay_gateway_group($id) !== $group) {
            continue;
        }
        $state = pay_gateway_state($id);
        $flag = $id === 'wallet' ? '♾ همیشه روشن'
            : ($state['on'] && $state['ready'] ? '🟢 فعال'
            : ($state['on'] ? '🟡 روشن ولی ناقص' : '🔴 خاموش'));
        $text .= $meta['emoji'] . ' <b>' . e($meta['label']) . '</b> — ' . $flag . "\n";
        if ($id !== 'card' && $id !== 'wallet') {
            $text .= '   ' . money(gw_min($id)) . ' تا ' . money(gw_max($id))
                . ' · کش‌بک ' . fa_digits(gw_cashback($id)) . "٪\n";
        }
        $rows[] = [btn($meta['emoji'] . ' ' . $meta['label'],
            'ad:pay:' . $id, null, $state['on'] ? 'success' : 'danger')];
    }
    $rows[] = [btn('« درگاه‌ها', 'ad:pay', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_pay_gateways(int|string $chat, ?int $messageId): void
{
    $text = "💳 <b>درگاه‌های پرداخت</b>\n\n";
    $rows = [];
    foreach (['card', 'crypto', 'fiat', 'stars'] as $group) {
        [$on, $all] = pay_group_state($group);
        $text .= pay_group_title($group) . ': ' . ($on > 0 ? '🟢 ' : '🔴 ')
            . fa_digits($on) . ' از ' . fa_digits($all) . ' روشن' . "\n";
        $rows[] = [btn(pay_group_title($group), 'ad:paygrp:' . $group,
            null, $on > 0 ? 'success' : 'danger')];
    }
    $swap = swap_state();
    $text .= "\n💎 <b>سواپ‌ولت</b> — " . ($swap['ok'] ? '🟢 نرخ لحظه‌ای وصل است'
            : '🔴 نرخ لحظه‌ای در دسترس نیست') . "\n"
        . '   ۱ دلار = ' . money($swap['usdt']) . ' · ۱ استارز = ' . money($swap['star']);
    $rows[] = [btn('💎 سواپ‌ولت (نرخ لحظه‌ای)', 'ad:pay:swap',
        null, $swap['ok'] ? 'success' : 'danger')];
    $rows[] = [btn('👛 پرداخت از کیف پول', 'ad:pay:wallet')];
    $rows[] = [btn('« مالی', 'ad:finance', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/**
 * صفحهٔ سواپ‌ولت: کلید API، نرخ لحظه‌ای دلار/ترون/استارز و آخرین وضعیت.
 * همهٔ اعداد از API خود سواپ‌ولت می‌آیند، نه از تنظیمات دستی.
 */
function screen_swap(int|string $chat, ?int $messageId, bool $refreshed = false): void
{
    $state = swap_state();
    $key = swap_key();
    $masked = $key === '' ? '❌ ثبت نشده'
        : '✅ ' . e(mb_substr($key, 0, 12)) . '…' . e(mb_substr($key, -4));
    $text = "💎 <b>سواپ‌ولت — نرخ لحظه‌ای</b>\n\n"
        . 'کلید API: <code>' . $masked . "</code>\n\n";
    if ($state['ok']) {
        $text .= "📈 <b>نرخ‌های لحظه‌ای</b>\n"
            . '💵 دلار (USDT): ' . money($state['usdt']) . "\n"
            . '💎 ترون (TRX): ' . money($state['trx']) . "\n"
            . '⭐️ استارز: ' . money($state['star']) . ' ('
            . fa_digits(number_format($state['star_usd'], 4)) . " دلار)\n\n";
    } else {
        $text .= "⚠️ <b>نرخ لحظه‌ای در دسترس نیست</b>\n"
            . e($state['error'] !== '' ? $state['error'] : 'پاسخی از سواپ‌ولت نرسید')
            . "\n" . 'تا وقتی وصل نشود، آخرین نرخ سالم استفاده می‌شود.' . "\n\n";
    }
    $text .= 'آخرین به‌روزرسانی: '
        . ($state['at'] > 0 ? fa_ts($state['at']) . ' (' . fa_time_only($state['at']) . ')'
            : '—') . "\n"
        . 'کد HTTP آخرین پاسخ: ' . ($state['http'] > 0 ? (string) $state['http'] : '—') . "\n"
        . "\n<i>مسیر خوانده‌شده:</i>\n"
        . '<code>' . SWAP_API . SWAP_PATH_PRICES . '</code>' . "\n"
        . '<code>' . SWAP_API . SWAP_PATH_SHOP . '</code>' . "\n\n"
        . '<i>این نرخ‌ها مبنای همهٔ درگاه‌های ارزی و استارز هستند و هر ۳۰ ثانیه تازه می‌شوند.</i>';
    $rows = [[btn('🔑 ورود کلید API سواپ‌ولت', 'ad:pay:swap:key', null, 'success')],
             [btn('🧪 تست زندهٔ اتصال', 'ad:pay:swap:test', null, 'primary')],
             [btn('🔄 به‌روزرسانی نرخ‌ها', 'ad:pay:swap:refresh', null, 'success')],
             [btn('📘 آموزش دریافت کلید', null, SWAP_DOCS, 'primary')],
             [btn('« درگاه‌ها', 'ad:pay', null, 'danger')]];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_pay_gateway(int|string $chat, ?int $messageId, string $gw): void
{
    $meta = gw_meta($gw);
    if ($meta === null) {
        screen_pay_gateways($chat, $messageId);
        return;
    }
    $state = pay_gateway_state($gw);
    [$ready, $missing] = gw_readiness($gw);
    $mask = static fn(string $value): string => $value === '' ? '❌ ثبت نشده' : '✅ ثبت شده';
    $text = $meta['emoji'] . ' <b>' . e($meta['label']) . "</b>\n\n"
        . ($gw === 'card' || $gw === 'wallet' ? '' : e($meta['hint']) . "\n\n")
        . 'وضعیت: ' . ($gw === 'wallet' ? '♾️ همیشه روشن'
            : ($state['on'] ? '🟢 روشن' : '🔴 خاموش')) . "\n"
        . 'آمادگی پرداخت: ' . ($ready ? '🟢 کامل' : '🔴 ناقص') . "\n";
    if (!$ready) {
        $text .= 'نیازمندی: <i>' . e(implode('، ', $missing)) . "</i>\n";
    }

    if ($gw !== 'wallet' && $gw !== 'card') {
        $text .= 'کلید اصلی: ' . e($mask(gw_key($gw))) . "\n";
        $key2Label = match ($gw) {
            'variza' => 'کلید امضای وب‌هوک',
            'nowpayment' => 'IPN secret',
            'iranpay2' => 'کلید دوم / امضای کال‌بک',
            'trx' => 'آدرس کیف پول TRX',
            default => '',
        };
        if ($key2Label !== '') {
            $text .= e($key2Label) . ': ' . e($mask(gw_key2($gw))) . "\n";
        }
        if ($gw === 'iranpay4') {
            $endpoint = pay_abanpay_endpoint();
            $callback = pay_callback_url('iranpay4');
            $text .= '🌐 آدرس ابان‌پی: <code>' . e($endpoint !== '' ? $endpoint : 'ثبت نشده') . "</code>\n"
                . '🧩 سازگاری میرزا: <code>' . e($callback !== '' ? $callback : 'آدرس وب ثبت نشده') . "</code>\n"
                . 'آدرس و کلید باید همان دو مقداری باشند که اتصال «میرزا آپدیت‌شده» آبان می‌دهد.\n';
        }
        if (in_array($gw, ['iranpay1', 'iranpay2', 'zarinpal', 'aqayepardakht'], true)) {
            $callback = pay_callback_url($gw);
            if ($callback !== '') {
                $text .= '🧩 کال‌بک سازگار با میرزا: <code>' . e($callback) . "</code>\n";
            }
        }
        if ($gw === 'variza') {
            $return = pay_callback_url('variza');
            $base = rtrim(web_base(), '/');
            $hook = gw_https_url($base . '/payment/variza_webhook.php')
                ? $base . '/payment/variza_webhook.php' : '';
            if ($return !== '') {
                $text .= '🧩 بازگشت سازگار با میرزا: <code>' . e($return) . "</code>\n";
            }
            if ($hook !== '') {
                $text .= '🔐 وب‌هوک سازگار با میرزا: <code>' . e($hook) . "</code>\n";
            }
        }
        if ($gw === 'tetrapay') {
            $text .= 'API رسمی تولید تتراپی به‌صورت خودکار استفاده می‌شود.\n';
        }
        $text .= 'حداقل/حداکثر: ' . money(gw_min($gw)) . ' تا ' . money(gw_max($gw)) . "\n"
            . 'سقف روزانهٔ پرداخت‌شده: ' . (gw_daily_limit($gw) > 0
                ? money(gw_daily_limit($gw)) : 'بدون سقف') . "\n"
            . 'کش‌بک: ' . fa_digits(gw_cashback($gw)) . "٪\n";
        if (gw_help($gw) !== '') {
            $text .= 'راهنمای مشتری: ✅ ثبت شده' . "\n";
        }
        if (gw_is_fiat($gw)) {
            $manualMin = gw_min_fiat($gw);
            $text .= 'حداقل دلاری: ' . ($manualMin > 0
                ? fa_digits(number_format($manualMin, 2)) . ' دلار'
                : 'دریافت خودکار از درگاه') . "\n";
        }
        /* Callback origins are installer-managed. They are intentionally not
           exposed as a TetraPay field; the readiness test reports only whether
           the trusted installation origin is usable. */
        if ($gw !== 'tetrapay' && $gw !== 'iranpay4' && gw_https_url(pay_callback_url($gw))) {
            $text .= 'کال‌بک/IPN: ✅ آماده' . "\n";
        }
    }

    if ($gw === 'card') {
        $text .= 'شماره کارت: <code>' . e(set_get('card_number') !== '' ? set_get('card_number') : 'ثبت نشده') . "</code>\n"
            . 'صاحب کارت: ' . e(set_get('card_holder') !== '' ? set_get('card_holder') : 'ثبت نشده') . "\n"
            . 'حداقل/حداکثر: ' . money(gw_min('card')) . ' تا ' . money(gw_max('card')) . "\n"
            . 'سقف روزانهٔ پرداخت‌شده: ' . (gw_daily_limit('card') > 0
                ? money(gw_daily_limit('card')) : 'بدون سقف') . "\n"
            . 'کش‌بک: ' . fa_digits(gw_cashback('card')) . "٪\n"
            . 'تأیید خودکار رسید: ' . (set_bool('auto_confirm_receipt') ? '🟢 روشن' : '🔴 خاموش') . "\n\n"
            . 'کاربر رسید می‌فرستد و فقط پس از تأیید مسئول مالی، مبلغ شارژ می‌شود.' . "\n";
        if (gw_help('card') !== '') {
            $text .= 'راهنمای مشتری: ✅ ثبت شده' . "\n";
        }
    }
    if ($gw === 'stars') {
        $text .= '⭐️ نرخ لحظه‌ای استارز: ' . money(star_toman_rate()) . ' = ۱ استارز' . "\n"
            . 'سقف روزانهٔ پرداخت‌شده: ' . (gw_daily_limit('stars') > 0
                ? money(gw_daily_limit('stars')) : 'بدون سقف') . "\n";
    }

    $rows = [];
    if ($gw !== 'wallet') {
        $rows[] = [btn($state['on'] ? '🔴 خاموش کنید' : '🟢 روشن کنید',
            'ad:pay:' . $gw . ':toggle', null, $state['on'] ? 'danger' : 'success')];
    }
    if ($gw !== 'wallet' && $gw !== 'card' && $gw !== 'stars') {
        $rows[] = [btn('🔑 کلید اصلی', 'ad:pay:' . $gw . ':key')];
        $key2Button = match ($gw) {
            'variza' => '🔐 کلید وب‌هوک',
            'nowpayment' => '🔐 IPN secret',
            'iranpay2' => '🔐 کلید دوم',
            'trx' => '👛 کیف پول TRX',
            default => '',
        };
        if ($key2Button !== '') {
            $rows[] = [btn($key2Button, 'ad:pay:' . $gw . ':key2')];
        }
        /* AbanPay is the only SIBIL gateway whose service address is an
           administrator setting. Official endpoints (especially TetraPay) are
           fixed by the provider and never appear as editable HTTP fields. */
        if ($gw === 'iranpay4') {
            $rows[] = [btn('🌐 آدرس ابان‌پی', 'ad:pay:' . $gw . ':endpoint')];
        }
        if (gw_is_fiat($gw)) {
            $rows[] = [btn('📉 حداقل دلاری', 'ad:pay:' . $gw . ':minfiat')];
        }
        $rows[] = [btn('⬇️ حداقل', 'ad:pay:' . $gw . ':min'),
                   btn('⬆️ حداکثر', 'ad:pay:' . $gw . ':max')];
        $rows[] = [btn('📅 سقف روزانه', 'ad:pay:' . $gw . ':daily'),
                   btn('🎁 کش‌بک ٪', 'ad:pay:' . $gw . ':cashback')];
        $rows[] = [btn('📘 راهنمای مشتری', 'ad:pay:' . $gw . ':help'),
                   btn('🛡 بررسی امن اتصال', 'ad:pay:' . $gw . ':test', null, 'success')];
    }
    if ($gw === 'card') {
        $rows[] = [btn('🔢 شماره کارت', 'ad:set:card_number'),
                   btn('👤 صاحب کارت', 'ad:set:card_holder')];
        $rows[] = [btn('⬇️ حداقل', 'ad:pay:card:min'),
                   btn('⬆️ حداکثر', 'ad:pay:card:max')];
        $rows[] = [btn('📅 سقف روزانه', 'ad:pay:card:daily'),
                   btn('🎁 کش‌بک ٪', 'ad:pay:card:cashback')];
        $rows[] = [btn('📘 راهنمای مشتری', 'ad:pay:card:help'),
                   btn('⚙️ حداقل/حداکثر مالی', 'ad:finset')];
        $rows[] = [btn(set_bool('auto_confirm_receipt') ? '🔴 تأیید خودکار خاموش'
            : '🟢 تأیید خودکار روشن', 'ad:fin:card:auto', null,
            set_bool('auto_confirm_receipt') ? 'danger' : 'success')];
    }
    if ($gw === 'stars') {
        $rows[] = [btn('📅 سقف روزانه', 'ad:pay:stars:daily'),
                   btn('💎 نرخ لحظه‌ای', 'ad:pay:swap')];
    }
    $rows[] = [btn('« درگاه‌ها', 'ad:pay', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_pay_web(int|string $chat, ?int $messageId): void
{
    /* Kept only for older callbacks. Payment origins are installation-owned,
       not a per-gateway HTTP control in the bot UI. */
    $tetraBot = tetrapay_bot_username();
    $text = "🛡 <b>بازگشت امن پرداخت</b>\n\n"
        . 'آدرس بازگشت از تنظیمات نصب خوانده می‌شود و در این بخش تغییر نمی‌کند.' . "\n"
        . ($tetraBot !== ''
            ? '✅ بازگشت تتراپی با لینک تلگرام همین ربات آماده است: <code>@' . e($tetraBot) . '</code>'
            : '⚠️ نام کاربری معتبر ربات برای بازگشت تتراپی پیدا نشد؛ با مدیر سرور تماس بگیرید.');
    Tg::show($chat, $messageId, $text, [
        [btn('« درگاه‌ها', 'ad:pay', null, 'danger')],
    ]);
}

