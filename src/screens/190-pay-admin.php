<?php
declare(strict_types=1);

/* 23) ADMIN — مدیریت درگاه‌های پرداخت */

function pay_gateway_state(string $gw): array
{
    $meta = PAY_GATEWAYS[$gw];
    $on = ($gw === 'card') ? set_get('pay_card_on', '1') === '1' : gw_on($gw);
    $ready = true;
    if (in_array($meta['type'], ['online', 'crypto'], true)) {
        $ready = gw_key($gw) !== '';
    }
    if ($gw === 'card') {
        $ready = set_get('card_number') !== '';
    }
    if ($gw === 'wallet' || $gw === 'stars') {
        $ready = true;
    }
    return ['on' => $on, 'ready' => $ready, 'type' => $meta['type']];
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
        . '   ۱ دلار = ' . money($swap['usdt']) . ' · ۱ استارز = ' . money($swap['star']) . "\n\n"
        . '🌐 آدرس کال‌بک: ' . (web_base() !== '' ? e(web_base()) : '<i>تنظیم نشده</i>');
    $rows[] = [btn('💎 سواپ‌ولت (نرخ لحظه‌ای)', 'ad:pay:swap',
        null, $swap['ok'] ? 'success' : 'danger')];
    $rows[] = [btn('👛 پرداخت از کیف پول', 'ad:pay:wallet'),
               btn('🌐 آدرس وب و کال‌بک', 'ad:payweb')];
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
    $key = gw_key($gw);
    $key2 = gw_key2($gw);
    $mask = fn(string $value) => $value === '' ? '❌ ثبت نشده' : '✅ ثبت شده';
    $text = $meta['emoji'] . ' <b>' . e($meta['label']) . "</b>\n\n"
        . ($gw === 'card' ? '' : e($meta['hint']) . "\n\n")
        . 'وضعیت: ' . ($gw === 'wallet' ? 'همیشه روشن'
            : ($state['on'] ? '🟢 روشن' : '🔴 خاموش')) . "\n";
    if ($gw !== 'wallet' && $gw !== 'card' && $gw !== 'stars') {
        $text .= 'کلید: <code>' . e($mask($key)) . "</code>\n";
        if (in_array($gw, ['iranpay4', 'iranpay2', 'variza', 'trx', 'nowpayment'], true)) {
            $labels2 = ['iranpay4' => 'آدرس درگاه', 'nowpayment' => 'IPN secret'];
            $text .= ($labels2[$gw] ?? 'کلید دوم') . ': ' . e($mask($key2)) . "\n";
        }
        if (gw_is_fiat($gw)) {
            $manualMin = gw_min_fiat($gw);
            $text .= '💱 نرخ لحظه‌ای دلار: ' . money(usd_toman_rate()) . "\n"
                . '📉 حداقل تراکنش: ' . ($manualMin > 0
                    ? '<b>' . fa_digits(number_format($manualMin, 2)) . '</b> دلار (دستی)'
                    : '<i>را خودِ درگاه تعیین می‌کند — فاکتور با هر مبلغی ساخته می‌شود و اگر کم بود، خودش می‌گوید.</i>')
                . "\n"
                . '<i>نرخ از سواپ‌ولت خوانده می‌شود و خودکار به‌روز می‌شود.</i>' . "\n";
        }
        $text .= 'محدوده: ' . money(gw_min($gw)) . ' تا ' . money(gw_max($gw)) . "\n"
            . 'کش‌بک: ' . (string) gw_cashback($gw) . "٪\n"
            . '🌐 آدرس سرویس: <code>'
            . e(gw_endpoint($gw, gw_endpoint_default($gw)) !== ''
                ? gw_endpoint($gw, gw_endpoint_default($gw)) : 'ثبت نشده') . "</code>\n";
    }
    if ($gw === 'card') {
        /* فقط تنظیم‌های لازم؛ بدون کلید خام */
        $text .= 'شماره کارت: <code>'
            . e(set_get('card_number') !== '' ? set_get('card_number') : 'ثبت نشده') . "</code>\n"
            . 'صاحب کارت: ' . e(set_get('card_holder') !== '' ? set_get('card_holder') : 'ثبت نشده') . "\n"
            . 'حداقل شارژ: ' . money(set_int('min_topup', 50000)) . "\n"
            . 'حداکثر شارژ: ' . money(set_int('max_topup', 20000000)) . "\n"
            . 'تأیید خودکار رسید: ' . (set_bool('auto_confirm_receipt') ? '🟢 روشن' : '🔴 خاموش') . "\n\n"
            . 'کاربر بعد از واریز، <b>عکس رسید</b> را در ربات می‌فرستد.' . "\n"
            . 'رسید با دو دکمهٔ «تأیید» و «رد» برای ادمین می‌آید؛ فقط با تأیید ادمین '
            . 'مبلغ به کیف پول کاربر اضافه می‌شود.' . "\n";
    }
    if ($gw === 'stars') {
        $text .= '⭐️ نرخ لحظه‌ای استارز: ' . money(star_toman_rate()) . ' = ۱ استارز'
            . ' (' . fa_digits(number_format(swap_star_usdt(), 4)) . " دلار)\n"
            . '<i>از فروشگاه سواپ‌ولت خوانده می‌شود.</i>' . "\n";
    }
    $rows = [];
    if ($gw !== 'wallet') {
        $rows[] = [btn($state['on'] ? '🔴 خاموش کنید' : '🟢 روشن کنید',
            'ad:pay:' . $gw . ':toggle', null, $state['on'] ? 'danger' : 'success')];
    }
    if ($gw !== 'wallet' && $gw !== 'card' && $gw !== 'stars') {
        $rows[] = [btn('🔑 کلید', 'ad:pay:' . $gw . ':key')];
        if (in_array($gw, ['iranpay4', 'iranpay2', 'variza', 'trx', 'nowpayment'], true)) {
            $rows[] = [btn($gw === 'iranpay4' ? '🌐 آدرس درگاه'
                : ($gw === 'nowpayment' ? '🔐 IPN secret' : '🔐 کلید دوم'),
                'ad:pay:' . $gw . ':key2')];
        }
        if (gw_is_fiat($gw)) {
            $rows[] = [btn('📉 حداقل دلاری درگاه', 'ad:pay:' . $gw . ':minfiat')];
        }
        $rows[] = [btn('🌐 آدرس سرویس', 'ad:pay:' . $gw . ':endpoint')];
        $rows[] = [btn('🧪 تست زندهٔ درگاه', 'ad:pay:' . $gw . ':test', null, 'success')];
        $rows[] = [btn('⬇️ حداقل', 'ad:pay:' . $gw . ':min'),
                   btn('⬆️ حداکثر', 'ad:pay:' . $gw . ':max')];
        $rows[] = [btn('🎁 کش‌بک ٪', 'ad:pay:' . $gw . ':cashback')];
    }
    if ($gw === 'card') {
        $rows[] = [btn('🔢 شماره کارت', 'ad:set:card_number'),
                   btn('👤 صاحب کارت', 'ad:set:card_holder')];
        $rows[] = [btn(set_bool('auto_confirm_receipt') ? '🔴 تأیید خودکار خاموش'
            : '🟢 تأیید خودکار روشن', 'ad:fin:card:auto', null,
            set_bool('auto_confirm_receipt') ? 'danger' : 'success')];
        $rows[] = [btn('⚙️ حداقل و حداکثر شارژ', 'ad:finset')];
    }
    if ($gw === 'stars') {
        $rows[] = [btn('💎 نرخ لحظه‌ای از سواپ‌ولت', 'ad:pay:swap')];
    }
    $rows[] = [btn('« درگاه‌ها', 'ad:pay', null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

function screen_pay_web(int|string $chat, ?int $messageId): void
{
    $text = "🌐 <b>آدرس وب و کال‌بک</b>\n\n"
        . 'آدرس فعلی: ' . (web_base() !== '' ? '<code>' . e(web_base()) . '</code>'
            : '<i>خالی</i>') . "\n\n"
        . 'این آدرس باید روی سرور به پورت وب (پیش‌فرض ۸۰۸۰) وصل باشد. ' .
        "اگر دامنه داری، همین آدرس را با https بدهید:\n"
        . "<code>https://panel.example.com</code>\n\n"
        . 'کال‌بک هر درگاه می‌شود: <code>آدرس/pay/نام‌درگاه</code>' . "\n"
        . 'وبهوک واریزا: <code>آدرس/pay/variza/webhook</code>' . "\n"
        . 'IPN نواپی‌منت: <code>آدرس/pay/nowpayment</code>';
    Tg::show($chat, $messageId, $text, [
        [btn('✏️ تغییر آدرس', 'ad:set:web_url', null, 'success')],
        [btn('✏️ پورت وب', 'ad:set:web_port')],
        [btn('« درگاه‌ها', 'ad:pay', null, 'danger')],
    ]);
}

