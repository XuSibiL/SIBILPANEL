<?php
declare(strict_types=1);

/* 16) Router — callbacks */

function report_exception(Throwable $ex, int $uid, string $where): void
{
    $message = $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine();
    log_line('error', $where . ': ' . $message);
    try {
        post_report('⚠️ <b>خطای داخلی</b>' . "\n" . 'جا: <code>' . e($where) . "</code>\n"
            . 'پیام: <code>' . e(mb_substr($message, 0, 300)) . '</code>', 'error');
    } catch (Throwable $ignored) {
    }
    if (is_admin($uid)) {
        Tg::send($uid, "❌ <b>خطای داخلی</b>\n\n" . e(mb_substr($message, 0, 300))
            . "\n\n<i>این متن را برای پشتیبانی بفرستید.</i>",
            [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
        return;
    }
    Tg::send($uid, '❌ یک خطای موقت پیش آمد. یک‌بار دیگر تلاش کنید.',
        [[btn('« منو', 'home', null, 'danger')]]);
}

function handle_callback(array $cb): void
{
    Tg::$answered = [];   // هر آپدیت، پاسخ‌های خودش را دارد
    try {
        handle_callback_inner($cb);
    } catch (Throwable $ex) {
        report_exception($ex, (int) ($cb['from']['id'] ?? 0), 'callback:'
            . (string) ($cb['data'] ?? ''));
    } finally {
        /* اگر هیچ دکمه‌ای جواب نداد، همان‌جا یک پاسخ بی‌صدا بفرستید تا دکمه معلق نماند */
        if (Tg::$pendingAnswer !== null) {
            $pending = Tg::$pendingAnswer;
            Tg::$pendingAnswer = null;
            Tg::answer($pending);
        }
    }
}

function handle_callback_inner(array $cb): void
{
    $uid = (int) ($cb['from']['id'] ?? 0);
    $GLOBALS['panel_act_uid'] = $uid;
    $chatId = (int) ($cb['message']['chat']['id'] ?? $uid);
    $chatType = (string) ($cb['message']['chat']['type'] ?? 'private');
    $messageId = (int) ($cb['message']['message_id'] ?? 0);
    $data = (string) ($cb['data'] ?? '');
    $cbId = (string) ($cb['id'] ?? '');
    if ($uid === 0 || $data === '') {
        return;
    }
    if ($data === 'nav:back' || str_starts_with($data, 'nav:back:')) {
        Tg::answer($cbId);
        State::clear($uid);   // «بازگشت» هیچ نیمه‌کاره‌ای باقی نمی‌گذارد
        /* بخش هر دکمه جداست: بازگشت در پنل مدیریت همان‌جا یک قدم عقب می‌رود،
           و اگر عقب‌تر نبود به صفحهٔ اصلی خرید برمی‌گردد */
        $scope = str_ends_with($data, ':ad') ? 'ad' : 'user';
        $prev = nav_back($uid, $scope);
        if ($prev === '') {
            show_home($chatId, $messageId, $uid);
            return;
        }
        $GLOBALS['panel_nav_skip'] = true;
        try {
            handle_callback_inner(['id' => $cbId, 'from' => $cb['from'] ?? ['id' => $uid],
                'message' => $cb['message'] ?? ['message_id' => $messageId,
                    'chat' => ['id' => $chatId, 'type' => 'private']], 'data' => $prev]);
        } finally {
            $GLOBALS['panel_nav_skip'] = false;
        }
        return;
    }
    if (empty($GLOBALS['panel_nav_skip'])) {
        nav_push($uid, $data);
    }
    if (in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
        // دکمه‌های داخل گروه کار نمی‌کنند؛ پنل مدیریت هم داخل گروه اجرا نمی‌شود
        Tg::answer($cbId, 'این دکمه‌ها فقط در چت خصوصی ربات کار می‌کنند.', true);
        return;
    }
    $userRow = user_ensure($uid, trim((string) ($cb['from']['first_name'] ?? '') . ' '
        . (string) ($cb['from']['last_name'] ?? '')), (string) ($cb['from']['username'] ?? ''));
    if ((int) ($userRow['is_blocked'] ?? 0) === 1 && !is_admin($uid)) {
        Tg::answer($cbId, '⛔️ دسترسی حسابتان بسته شده است.', true);
        return;
    }
    $parts = explode(':', $data);
    $head = $parts[0];
    /* دامنهٔ صفحهٔ جاری برای دکمهٔ بازگشت */
    $GLOBALS['panel_nav_scope'] = str_starts_with($data, 'ad:') ? 'ad' : 'user';

    /* ── ماژول احراز هویت (KYC) ─────────────────────────────────────── */
    /* ── قفل عضویت اجباری کانال ─────────────────────────────────────── */
    if ($data === 'fj:check') {
        /* دکمهٔ تایید عضویت: فقط همین یک دکمه همیشه باز است */
        if (force_join_blocked($uid)) {
            Tg::answer($cbId, '⚠️ عضویت شما تایید نشد. اول در کانال عضو شوید.', true);
            force_join_show($chatId, $messageId);
            return;
        }
        Tg::answer($cbId, '✅ عضویت تایید شد.');
        State::clear($uid);
        show_home($chatId, $messageId, $uid);
        return;
    }
    if (force_join_blocked($uid)) {
        Tg::answer($cbId, '⚠️ اول در کانال عضو شوید', true);
        force_join_show($chatId, $messageId);
        return;
    }

    /* پاسخ به پیام پشتیبانی: ticket باید متعلق به همین کاربر باشد؛ دادهٔ callback
       حتی اگر دستکاری/حدس زده شود مقصد گفتگو یا اطلاعات کاربر دیگری را فاش نمی‌کند. */
    if (preg_match('/^reply_support_([1-9][0-9]*)$/D', $data, $replyMatch)) {
        $ticketId = (int) $replyMatch[1];
        [$replyOk, $replyNote] = support_reply_start($uid, $ticketId);
        Tg::answer($cbId, $replyOk ? '✍️ پیام را بفرستید' : 'گفتگو در دسترس نیست', !$replyOk);
        Tg::send($chatId, $replyNote, $replyOk
            ? [[btn('❌ لغو پاسخ', 'support_reply_cancel', null, 'danger')]] : home_kb($uid));
        return;
    }
    if ($data === 'support_reply_cancel') {
        State::clear($uid);
        Tg::answer($cbId, 'پاسخ لغو شد.');
        show_home($chatId, $messageId, $uid);
        return;
    }

    if ($head === 'kyc') {
        Tg::answer($cbId);
        kyc_callback($uid, $chatId, $messageId, $parts);
        return;
    }


    /* ── انتخاب دکمه‌ای داخل ویزارد ──────────────────────────────────── */
    if ($head === 'wiz') {
        wiz_pick($uid, (int) ($parts[1] ?? 0), $chatId, $messageId);
        return;
    }
    if ($head === 'wizrole') {
        $op = (string) ($parts[2] ?? '');
        if ($op === 'page' || $op === 'refresh') {
            // پیمایش نقش‌ها بدون از دست رفتن ویزارد
            $state = State::get($uid);
            if ($state['state'] !== 'wiz') {
                Tg::send($chatId, 'این انتخاب منقضی شده.',
                    [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
                return;
            }
            $data = $state['data'];
            $panelId = (int) ($parts[1] ?? 0);
            $page = $op === 'page' ? max(1, (int) ($parts[3] ?? 1)) : 1;
            if ($op === 'refresh' && $panelId > 0) {
                roles_cache($panelId, true);
            }
            $data['ctx']['role_page'] = $page;
            State::set($uid, 'wiz', $data);
            wiz_render($uid, $data, $chatId, $messageId);
            return;
        }
        wiz_pick($uid, (int) ($parts[2] ?? 0), $chatId, $messageId);
        return;
    }

    Tg::answer_later($cbId);

    /* ── منوی کاربر ───────────────────────────────────────────────────── */
    if ($data === 'home') {
        State::clear($uid);
        show_home($chatId, $messageId, $uid);
        return;
    }
    if ($head === 'menu') {
        $what = $parts[1] ?? '';
        switch ($what) {
            case 'buy':
                screen_buy($chatId, $messageId, $uid);
                return;
            case 'panels':
                screen_panels($chatId, $messageId, $uid);
                return;
            case 'wallet':
                screen_wallet($chatId, $messageId, $uid);
                return;
            case 'slots':
                screen_slots($chatId, $messageId, $uid);
                return;
            case 'support':
                show_support($chatId, $messageId, $uid);
                return;
            case 'track':
                /* فهرست سفارش‌ها حذف شد؛ فقط جستجو با کد پیگیری */
                wiz_start($uid, 'trackcode', [
                    step_def('value', 'کد پیگیری (همان کدی که بعد از پرداخت گرفتی) یا شمارهٔ سفارش را بفرستید.',
                        'text', null, '🔎 پیگیری با کد'),
                ]);
                return;
            case 'orders':
                /* مسیر قدیمی: حالا مستقیم به جستجوی کد می‌رود */
                wiz_start($uid, 'trackcode', [
                    step_def('value', 'کد پیگیری سفارش را بفرستید.', 'text', null, '🔎 پیگیری با کد'),
                ]);
                return;
            case 'trial':
                screen_trial($chatId, $messageId, $uid);
                return;
            case 'ref':
                /* لینک از یوزرنیم رسمی ربات ساخته می‌شود (تنظیمات → .env → getMe) */
                $link = ref_link($uid);
                $percent = set_int('ref_percent', 10);
                $minBuy = set_int('ref_min', 0);
                $invited = (int) DB::val('SELECT COUNT(*) FROM users WHERE referred_by=?',
                    [$uid], 0);
                $paidRef = (int) DB::val('SELECT COUNT(*) FROM users WHERE referred_by=? '
                    . 'AND ref_paid=1', [$uid], 0);
                $earned = (int) DB::val('SELECT COALESCE(SUM(amount),0) FROM wallet_tx '
                    . "WHERE tg_id=? AND kind='ref'", [$uid], 0);
                [$freeSlots, $boughtSlots, $usedSlots] = slots_total($uid);
                $text = "🎁 <b>دعوت دوستان</b>\n\n"
                    . '🧩 اسلات: ' . fa_digits($freeSlots + $boughtSlots)
                    . ' · مصرف‌شده: ' . fa_digits($usedSlots) . "\n"
                    . '💰 موجودی: <b>' . money(balance_of($uid)) . "</b>\n\n"
                    . '👥 دعوت‌شده: <b>' . fa_digits($invited) . '</b> · خرید کرده: '
                    . fa_digits($paidRef) . "\n"
                    . '🎉 درآمد از دعوت: <b>' . money($earned) . "</b>\n\n"
                    . (ref_mode() === 'cash'
                        ? 'به‌ازای هر نفری که با لینک شما وارد ربات شود، '
                            . money(set_int('ref_cash', 10000)) . ' به کیف پولتان اضافه می‌شود.'
                        : 'به‌ازای واریزی اول و دوم هر نفر، ' . fa_digits($percent)
                            . '٪ به کیف پولتان اضافه می‌شود'
                            . ($minBuy > 0 ? ' (واریز بالای ' . money($minBuy) . ')' : ''))
                    . "\n\n"
                    . ($link !== ''
                        ? 'لینک اختصاصی شما:' . "\n<code>" . e($link) . '</code>'
                        : '⚠️ لینک دعوت ساخته نشد: یوزرنیم ربات تنظیم نشده است.');
                if (!set_bool('ref_enabled', true)) {
                    $text .= "\n\n🔴 برنامهٔ دعوت فعلاً خاموش است.";
                }
                $refRows = [];
                if ($link !== '') {
                    $refRows[] = [btn('📤 اشتراک‌گذاری لینک', null,
                        ref_share_url($link, '🎁 با لینک من در ' . bot_name() . ' عضو شو'),
                        'success')];
                    $refRows[] = [btn('⚡️ ارسال سریع در تلگرام', null,
                        ref_share_deeplink($link, '🎁 با لینک من در ' . bot_name() . ' عضو شو'),
                        'primary')];
                    $refRows[] = [btn('📋 فرستادن لینک به دوست', 'ref:share', null, 'primary')];
                }
                $refRows[] = [btn('« بازگشت', 'home', null, 'danger')];
                Tg::show($chatId, $messageId, $text, $refRows);
                return;
        }
    }
    if ($data === 'menu:admin') {
        if (!is_staff($uid)) {
            Tg::answer($cbId, '⛔️ فقط برای مدیر', true);
            return;
        }
        screen_console($chatId, $messageId);
        return;
    }
    if ($head === 'ref') {
        $link = ref_link($uid);
        if ($link === '') {
            Tg::answer($cbId, '⚠️ یوزرنیم ربات تنظیم نشده است.', true);
            $fetched = bot_username_fetch();      // یک‌بار دیگر از خود تلگرام
            $link = $fetched !== '' ? ref_link($uid) : '';
            if ($link === '') {
                Tg::show($chatId, $messageId,
                    "⚠️ <b>تلگرام یوزرنیم ربات را برنگرداند</b>\n\n"
                    . 'ربات خودکار با <code>getMe</code> یوزرنیمش را می‌خواند؛ این بار '
                    . 'پاسخ نداد (قطعی موقت شبکه یا توکن نامعتبر).' . "\n\n"
                    . 'چند لحظه بعد دوباره بزنید. اگر تکرار شد:'
                    . "\n" . '<code>sibil doctor</code>',
                    [[btn('🔄 تلاش دوباره', 'ref:share', null, 'primary')],
                     [btn('« دعوت دوستان', 'menu:ref', null, 'danger')]]);
                return;
            }
        }
        Tg::answer($cbId, '📤 لینک آمادهٔ ارسال است', false);
        Tg::send($chatId, '📤 <b>لینک اختصاصی شما</b>' . "\n" . SEP . "\n"
            . '<code>' . e($link) . '</code>' . "\n\n"
            . 'با یک ضربه روی لینک کپی می‌شود؛ با دکمهٔ زیر مستقیم در تلگرام '
            . 'برای دوستتان بفرستید.' . "\n\n"
            . '🎁 پاداش شما: ' . (ref_mode() === 'cash'
                ? money(set_int('ref_cash', 10000)) . ' نقدی برای هر عضو جدید'
                : fa_digits(set_int('ref_percent', 10)) . '٪ از واریزی اول و دوم'),
            [[btn('📲 ارسال به مخاطب تلگرام', null,
                ref_share_url($link, '🎁 با لینک من در ' . bot_name() . ' عضو شو'),
                'success')],
             [btn('⚡️ ارسال سریع (tg)', null,
                ref_share_deeplink($link, '🎁 با لینک من در ' . bot_name() . ' عضو شو'),
                'primary')],
             [btn('« دعوت دوستان', 'menu:ref', null, 'danger')]]);
        return;
    }
    if ($head === 'help') {
        /* راهنمای حاوی اتصال/API و مالی فقط برای سطح کامل است. */
        $admin = admin_can($uid, 'full');
        if ($data === 'help:home') {
            Tg::show($chatId, $messageId,
                "🎓 <b>راهنما</b>\n\nیک بخش را انتخاب کنید یا جستجو کنید.",
                help_home_kb($admin));
            return;
        }
        if ($data === 'help:search') {
            State::set($uid, 'help_search');
            Tg::show($chatId, $messageId,
                "🔎 <b>جستجوی راهنما</b>\n\nبنویسید دنبال چه چیزی هستی؛ "
                . 'مثلاً: <code>شارژ</code>، <code>تعلیق</code>، <code>کد تخفیف</code>.',
                [[btn('« لغو', 'help:home', null, 'danger')]]);
            return;
        }
        if (str_starts_with($data, 'help:s:')) {
            $key = $parts[2] ?? '';
            $pool = help_pool($admin);
            if (!isset($pool[$key])) {
                Tg::show($chatId, $messageId, 'این بخش پیدا نشد.', help_home_kb($admin));
                return;
            }
            Tg::show($chatId, $messageId,
                '<b>' . e($pool[$key]['title']) . "</b>\n\n" . help_body($key, $pool[$key]),
                [[btn('« راهنما', 'help:home', null, 'danger')]]);
            return;
        }
    }
    if ($head === 'buy') {
        $what = $parts[1] ?? '';
        if ($what === 'cat') {
            screen_buy_category($chatId, $messageId, (int) ($parts[2] ?? 0));
            return;
        }
        if ($what === 'p') {
            screen_product($chatId, $messageId, (int) ($parts[2] ?? 0));
            return;
        }
        if ($what === 'users') {
            $productId = (int) ($parts[2] ?? 0);
            $product = product_get($productId);
            if ($product === null || (int) $product['active'] !== 1 || !product_is_unlimited($product)) {
                Tg::show($chatId, $messageId, 'این پنل نامحدود فعال نیست.',
                    [[btn('« بازگشت', 'menu:buy', null, 'danger')]]);
                return;
            }
            screen_unlimited_initial_users($chatId, $messageId, $uid, $productId);
            return;
        }
        if ($what === 'confirm') {
            $product = product_get((int) ($parts[2] ?? 0));
            if ($product === null || (int) $product['active'] !== 1) {
                Tg::show($chatId, $messageId, 'محصول پیدا نشد.', home_kb($uid));
                return;
            }
            /* callback قدیمی یا دستی نباید پنل نامحدود را بدون تعداد کاربر بسازد. */
            if (product_is_unlimited($product)) {
                screen_unlimited_initial_users($chatId, $messageId, $uid, (int) $product['id']);
                return;
            }
            if (slots_available($uid) < (int) $product['slots_needed']) {
                Tg::show($chatId, $messageId,
                    '🧩 اسلات آزاد کافی ندارید.' . "\n" . 'از «🧩 اسلات‌ها» اسلات بخر.',
                    [[btn('🧩 اسلات‌ها', 'menu:slots', null, 'success')],
                     [btn('« بازگشت', 'home', null, 'danger')]]);
                return;
            }
            $minBalance = (int) $product['min_balance'];
            if ($minBalance > 0 && balance_of($uid) < $minBalance) {
                Tg::show($chatId, $messageId,
                    '💰 <b>حداقل موجودی لازم</b>' . "\n\n"
                    . 'برای این محصول باید حداقل <b>' . money($minBalance) . '</b> موجودی داشته باشید.'
                    . "\n" . 'موجودی فعلی: ' . money(balance_of($uid)) . "\n\n"
                    . 'کیف پولتان را شارژ کنید و دوباره بیا.',
                    [[btn('💳 شارژ کیف پول', 'menu:wallet', null, 'success')],
                     [btn('« بازگشت', 'menu:buy', null, 'danger')]]);
                return;
            }
            screen_username_choice($chatId, $messageId, (int) $product['id'], 'buy');
            return;
        }
        if ($what === 'exact') {
            $product = product_get((int) ($parts[2] ?? 0));
            if ($product === null) {
                return;
            }
            if (product_is_unlimited($product)) {
                screen_product($chatId, $messageId, (int) $product['id']);
                return;
            }
            $perGb = (int) $product['per_gb_price'];
            $hourly = (int) $product['hourly_price'];
            $text = "🔢 <b>قیمت دقیق بر اساس مصرف</b>\n\n"
                . 'قیمت هر گیگ: ' . money($perGb) . "\n"
                . 'اجارهٔ ساعتی: ' . money($hourly) . "\n\n";
            foreach ([1, 5, 10, 50, 100] as $gb) {
                $text .= '· ' . fa_digits($gb) . ' گیگ → ' . money($gb * $perGb) . "\n";
            }
            $hours = prepaid_hours();
            $text .= "\n" . 'اجارهٔ روزانه (۲۴ ساعت): ' . money($hourly * 24) . "\n"
                . 'پیش‌پرداخت ' . fa_digits($hours) . ' ساعت اول: <b>'
                . money(panel_prepaid_amount($product)) . '</b>' . "\n\n"
                . '🧮 فرمول: پیش‌پرداخت ساعت‌های اول + (گیگ مصرف‌شده × قیمت هر گیگ) + '
                . '(ساعت فعال × اجارهٔ ساعتی)';
            Tg::show($chatId, $messageId, $text,
                [[btn('« بازگشت', 'buy:p:' . $product['id'], null, 'danger')]]);
            return;
        }
    }
    if ($head === 'wallet') {
        $what = $parts[1] ?? '';
        if ($what === 'amt') {
            $amount = (int) ($parts[2] ?? 0);
            $min = set_int('min_topup', 50000);
            $max = set_int('max_topup', 20000000);
            if ($amount < $min || $amount > $max) {
                Tg::send($chatId, 'مبلغ باید بین ' . money($min) . ' و ' . money($max) . ' باشد.');
                return;
            }
            $order = create_order($uid, 'topup', $amount, null, ['topup' => $amount]);
            Tg::show($chatId, $messageId,
                invoice_text($order, 'شارژ کیف پول', 'مبلغ شارژ: ' . money($amount)),
                invoice_kb($order, $uid));
            return;
        }
        if ($what === 'charge') {
            State::set($uid, 'topup_amount');
            Tg::show($chatId, $messageId,
                '💰 مبلغ شارژ را به تومان بفرستید.' . "\n" . 'حداقل '
                . money(set_int('min_topup', 50000)),
                [[btn('« لغو', 'menu:wallet', null, 'danger')]]);
            return;
        }
    }
    if ($head === 'inv') {
        $what = $parts[1] ?? '';
        $orderId = (int) ($parts[2] ?? 0);
        $order = order_get($orderId);
        if ($order === null || (int) $order['tg_id'] !== $uid) {
            Tg::show($chatId, $messageId, 'این سفارش معتبر نیست.', home_kb($uid));
            return;
        }
        if ($what === 'wallet') {
            [$ok, $text] = pay_from_wallet($order);
            if ($ok) {
                Tg::show($chatId, $messageId, $text, home_kb($uid));
            } else {
                /* شکست باید با دلیل و پیام دیده شود، نه یک تولتیپ گذرا */
                Tg::answer($cbId, 'پرداخت انجام نشد', true);
                Tg::send($chatId, '❌ ' . $text,
                    [[btn('🔁 تلاش دوباره', 'inv:back:' . (int) $order['id'], null, 'success')],
                     [btn('« خانه', 'home', null, 'danger')]]);
            }
            return;
        }
        if ($what === 'zero') {
            [$ok, $text] = settle_payment($order, 'free');
            Tg::show($chatId, $messageId, $text, home_kb($uid));
            return;
        }
        if ($what === 'gw') {
            screen_gateways($chatId, $messageId, $order);
            return;
        }
        if ($what === 'code') {
            if (!discount_allowed_for((string) $order['kind'])) {
                Tg::answer($cbId, 'کد تخفیف فقط برای خرید پنل و محصول است، نه شارژ کیف پول.', true);
                return;
            }
            State::set($uid, 'discount_order', ['order_id' => (int) $order['id']]);
            Tg::show($chatId, $messageId,
                "🏷 <b>ثبت کد تخفیف</b>\n\n"
                . 'کد تخفیفت را بفرستید تا روی همین سفارش اعمال شود.' . "\n"
                . 'برای لغو: <code>لغو</code>',
                [[btn('« بازگشت به سفارش', 'inv:back:' . $order['id'], null, 'danger')]]);
            return;
        }
        if ($what === 'back') {
            State::clear($uid);
            Tg::show($chatId, $messageId,
                invoice_text($order, 'پرداخت سفارش'), invoice_kb($order, $uid));
            return;
        }
        if ($what === 'pay') {
            $gw = (string) ($parts[3] ?? '');
            /* مهلت پرداخت واقعاً اعمال می‌شود: اگر فاکتور از حد گذشته باشد هیچ
               درگاهی باز نمی‌شود و سفارش بسته می‌شود — نه فقط یک نوشته. */
            payments_expire(set_int('pay_expire_minutes', 30));
            $fresh = order_get((int) $order['id']);
            if ($fresh === null || $fresh['status'] !== 'pending') {
                $minutes = max(1, set_int('pay_expire_minutes', 30));
                Tg::show($chatId, $messageId,
                    '⌛️ <b>مهلت پرداخت تمام شد</b>' . "\n\n"
                    . 'این فاکتور ' . fa_digits($minutes) . ' دقیقه اعتبار داشت و اکنون بسته شد.'
                    . "\n" . 'هیچ مبلغی از کیف پولتان کم نشد.' . "\n\n"
                    . 'یک سفارش تازه بساز تا فاکتور جدید بگیرید.',
                    [[btn('🔁 سفارش تازه', 'home', null, 'success')],
                     [btn('📦 پنل‌های من', 'menu:panels', null, 'primary')]]);
                return;
            }
            if ($gw === 'wallet') {
                [$ok, $text] = pay_from_wallet($order);
                if ($ok) {
                    Tg::show($chatId, $messageId, $text, home_kb($uid));
                } else {
                    Tg::answer($cbId, 'پرداخت انجام نشد', true);
                    Tg::send($chatId, '❌ ' . $text,
                        [[btn('🔁 تلاش دوباره', 'inv:back:' . (int) $order['id'], null, 'success')],
                         [btn('« بازگشت', 'home', null, 'danger')]]);
                }
                return;
            }
            if ($gw === 'card') {
                /* سقف ریالی فقط همین‌جا و فقط برای کارت‌به‌کارت اعمال می‌شود؛
                   کریپتو و استارز هیچ سقفی ندارند. */
                if (kyc_guard_gateway($chatId, $messageId, $uid, $fresh)) {
                    return;
                }
                screen_card_payment($chatId, $messageId, $uid, $order);
                return;
            } else {
                $result = pay_start($order, $gw);
                if (!$result['ok']) {
                    Tg::answer($cbId, $result['text'], true);
                    screen_gateways($chatId, $messageId, $order, $result['text']);
                    return;
                }
                if (($result['url'] ?? '') !== '') {
                    $gwType = PAY_GATEWAYS[$gw]['type'] ?? 'online';
                    $paymentRow = DB::one('SELECT * FROM payments WHERE order_id=? AND method=? '
                        . "AND status='unpaid' ORDER BY id DESC LIMIT 1", [(int) $order['id'], $gw]);
                    $invoiceNo = (string) ($paymentRow['authority'] ?? '');
                    pay_note_message((int) $order['id'], $gw, (int) $messageId);
                    if ($gwType === 'crypto') {
                        /* قالب پیام تأیید */
                        $meta = $paymentRow ? pay_meta($paymentRow) : [];
                        Tg::show($chatId, $messageId,
                            crypto_invoice_header($order, (string) ($result['text'] ?: $gw))
                            . "\n\n" . crypto_invoice_text($order, (string) $gw, $invoiceNo,
                                (float) ($meta['fiat_amount'] ?? 0)),
                            [[btn('💳 پرداخت', null, (string) $result['url'], 'success')],
                             [btn('« بازگشت', 'inv:gw:' . $order['id'], null, 'danger')]]);
                        return;
                    }
                    pay_note_message((int) $order['id'], $gw, (int) $messageId);
                    Tg::show($chatId, $messageId,
                        '🌐 <b>پرداخت ' . e($result['text']) . "</b>\n\n"
                        . 'مبلغ: <b>' . money((int) $order['payable']) . "</b>\n"
                        . 'کد سفارش: <code>' . e((string) $order['ref']) . "</code>\n\n"
                        . 'روی دکمهٔ زیر بزنید و پرداخت را کامل کنید. ' .
                        'بعد از پرداخت خودکار به همین‌جا برمی‌گردی.',
                        [[btn('💳 پرداخت ' . money((int) $order['payable']),
                            null, $result['url'], 'success')],
                         [btn('« بازگشت', 'inv:gw:' . $order['id'], null, 'danger')]]);
                    return;
                }
            }
        }
        if ($what === 'card') {
            screen_card_payment($chatId, $messageId, $uid, $order);
            return;
        }
    }
    if ($head === 'pay' && ($parts[1] ?? '') === 'receipt') {
        /* دکمهٔ «واریز کردم، ارسال رسید» — کاربر را در حالت انتظار عکس می‌گذارد */
        $orderId = (int) ($parts[2] ?? 0);
        $order = $orderId > 0 ? order_get($orderId) : null;
        if ($order === null || (int) $order['tg_id'] !== $uid) {
            Tg::show($chatId, $messageId, 'این سفارش معتبر نیست.', home_kb($uid));
            return;
        }
        if ((string) $order['status'] !== 'pending' && (string) $order['status'] !== 'review') {
            Tg::show($chatId, $messageId,
                '⌛️ این سفارش دیگر باز نیست.' . "\n" . 'یک سفارش تازه بساز.',
                [[btn('💳 شارژ کیف پول', 'menu:wallet', null, 'success')],
                 [btn('« خانه', 'home', null, 'danger')]]);
            return;
        }
        State::set($uid, 'receipt', ['order_id' => $orderId]);
        Tg::show($chatId, $messageId,
            '📷 <b>عکس رسید را همین‌جا بفرستید</b>' . "\n\n"
            . 'کد سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n"
            . 'مبلغ: <b>' . money((int) $order['payable']) . '</b>' . "\n\n"
            . '<i>عکس واریزی یا فایل تصویری — هر کدام راحت‌تر است.</i>',
            [[btn('« لغو', 'home', null, 'danger')]]);
        return;
    }
    if ($head === 'pn') {
        $panel = DB::one('SELECT * FROM panels WHERE id=? AND tg_id=?',
            [(int) ($parts[1] ?? 0), $uid]);
        if ($panel === null) {
            Tg::show($chatId, $messageId, 'پنل پیدا نشد.', home_kb($uid));
            return;
        }
        $panelId = (int) $panel['id'];
        $op = (string) ($parts[2] ?? '');
        $product = $panel['product_id'] ? product_get((int) $panel['product_id']) : null;
        $blockSize = (int) ($product['extra_block_size'] ?? 1000) ?: 1000;
        $blockPrice = (int) ($product['extra_block_price'] ?? 0);
        if ($op === 'users') {
            if (!panel_is_unlimited($panel)) {
                Tg::answer($cbId, 'این پنل شارژ دلخواهِ کاربر ندارد.', true);
                screen_panel_detail($chatId, $messageId, $panelId);
                return;
            }
            State::set($uid, 'unlimited_add_users', ['panel_id' => $panelId]);
            Tg::show($chatId, $messageId,
                '➕ <b>شارژ / افزودن کاربر</b>' . "\n\n"
                . 'تعداد کاربری را که می‌خواهید اضافه شود فقط به‌صورت عدد بفرستید.' . "\n"
                . 'حداقل: <b>' . fa_digits(panel_unlimited_min_users($panel)) . "</b> کاربر\n"
                . 'قیمت هر کاربر: <b>' . money(panel_unlimited_user_price($panel)) . "</b>\n\n"
                . 'برای لغو: <code>لغو</code>',
                [[btn('« بازگشت', 'pn:' . $panelId, null, 'danger')]]);
            return;
        }
        if ($op === 'extra') {
            if (panel_is_unlimited($panel)) {
                Tg::answer($cbId, 'برای این پنل تعداد دلخواه را وارد کنید.', true);
                screen_panel_detail($chatId, $messageId, $panelId);
                return;
            }
            if ($blockPrice <= 0) {
                Tg::answer($cbId, 'برای این محصول بلاک اضافه تعریف نشده.', true);
                return;
            }
            $order = create_order($uid, 'users', $blockPrice, (int) $panel['product_id'],
                ['panel_id' => $panelId, 'block' => $blockSize]);
            Tg::show($chatId, $messageId,
                invoice_text($order, 'افزودن ' . fa_digits($blockSize) . ' کاربر',
                    '📦 پنل <code>' . e((string) $panel['panel_username']) . '</code>'),
                invoice_kb($order, $uid));
            return;
        }
        if ($op === 'disc') {
            if ((int) ($panel['discount_until'] ?? 0) > now_ts()) {
                Tg::answer($cbId, 'روی این پنل تخفیف فعال داری؛ بعد از پایانش کد جدید بزنید.', true);
                return;
            }
            State::set($uid, 'discount_panel', ['panel_id' => $panelId]);
            Tg::send($chatId,
                "🏷 <b>ثبت کد تخفیف روی این پنل</b>\n"
                . SEP . "\n"
                . 'کد تخفیف خود را ارسال کنید.' . "\n\n"
                . '💡 تخفیف به صورت خودکار روی تعرفهٔ مصرف هر گیگابایت این پنل اعمال می‌شود.',
                [[btn('❌ لغو', 'pn:' . $panelId . ':disccancel', null, 'danger')],
                 [btn('« پنل', 'pn:' . $panelId, null, 'primary')]]);
            return;
        }
        if ($op === 'disccancel') {
            State::clear($uid);
            Tg::answer($cbId, 'لغو شد', false);
            screen_panel_detail($chatId, $messageId, $panelId);
            return;
        }
        if ($op === 'sync') {
            panel_sync_now($panel);
            Tg::answer($cbId, 'اطلاعات از پنل خوانده شد', false);
            screen_panel_detail($chatId, $messageId, (int) $panel['id']);
            return;
        }
        if ($op === 'del') {
            if ((int) ($panel['is_trial'] ?? 0) === 1) {
                Tg::show($chatId, $messageId,
                    '🧪 پنل تست دستی حذف نمی‌شود.' . "\n\n"
                    . 'همین‌که زمان تستش تمام شود خودکار پاک می‌شود.',
                    [[btn('« پنل', 'pn:' . $panelId, null, 'danger')]]);
                return;
            }
            $blockPrice = (int) ($product['extra_block_price'] ?? 0);
            unset($blockPrice);
            /* هشدار: پیش‌پرداخت ساعت‌های اول غیرقابل استرداد است */
            $prepaid = (int) ($panel['prepaid_amount'] ?? 0);
            $warn = '';
            if ($prepaid > 0 && panel_prepaid_remaining($panel) > 0) {
                $warn = "\n" . '⚠️ <b>توجه:</b> پیش‌پرداخت ' . fa_digits(prepaid_hours())
                    . ' ساعت اول این پنل (<b>' . money($prepaid) . '</b>) '
                    . 'در صورت حذف <b>بازگردانده نمی‌شود</b>.';
            }
            Tg::show($chatId, $messageId,
                "🗑 <b>حذف پنل</b>\n\n"
                . 'پنل <code>' . e((string) $panel['panel_username']) . "</code> از روی پنل و از "
                . "ربات حذف می‌شود.\n"
                . '<i>این کار برگشت‌پذیر نیست.</i>'
                . $warn,
                [[btn('✅ بله، حذف کنید', 'pn:' . $panelId . ':delok', null, 'danger')],
                 [btn('« انصراف', 'pn:' . $panelId, null, 'primary')]]);
            return;
        }
        if ($op === 'delok') {
            [$okDel, $noteDel] = panel_delete_own($panel);
            Tg::answer($cbId, $okDel ? 'حذف شد' : 'حذف روی پنل انجام نشد', !$okDel);
            Tg::send($chatId, ($okDel ? '' : '⚠️ ') . $noteDel,
                [[btn('« پنل‌های من', 'menu:panels', null, 'primary')]]);
            return;
        }
        if ($op === 'pass') {
            screen_panel_password($chatId, $messageId, (int) $panel['id']);
            return;
        }
        if ($op === 'passnew') {
            [$okPass, $notePass] = panel_change_password((int) $panel['id'], '');
            Tg::send($chatId, ($okPass ? '' : '⚠️ ') . $notePass,
                [[btn('« پنل', 'pn:' . $panelId, null, 'danger')]]);
            return;
        }
        if ($op === 'passset') {
            wiz_start($uid, 'panel_pass', [
                step_def('password', 'رمز تازه را بفرستید — حداقل ۸ کاراکتر، با حرف بزرگ، رقم و نماد '
                    . '(مثل <code>7P20g8Z!ot%k</code>)', 'text', null, '🔑 رمز دلخواه'),
            ], ['panel_id' => $panelId]);
            return;
        }
        if ($op === 'sec') {
            screen_panel_security($chatId, $messageId, (int) $panel['id']);
            return;
        }
        if ($op === 'hwreset') {
            [$okHw, $noteHw] = panel_reset_devices((int) $panel['id']);
            Tg::send($chatId, ($okHw ? '' : '⚠️ ') . $noteHw,
                [[btn('« امنیت پنل', 'pn:' . $panelId . ':sec', null, 'primary')]]);
            return;
        }
        screen_panel_detail($chatId, $messageId, $panelId);
        return;
    }
    if ($head === 'slot' && ($parts[1] ?? '') === 'buy') {
        $price = set_int('slot_price', 150000);
        $order = create_order($uid, 'slot', $price, null, ['count' => 1]);
        Tg::show($chatId, $messageId,
            invoice_text($order, 'خرید اسلات', '🧩 یک اسلات اضافه'),
            invoice_kb($order, $uid));
        return;
    }
    if (str_starts_with($data, 'trial:p:')) {
        screen_trial_product($chatId, $messageId, $uid, (int) substr($data, 8));
        return;
    }
    if (str_starts_with($data, 'trial:go:')) {
        $productId = (int) substr($data, 9);
        $product = product_get($productId);
        if ($product === null || (int) $product['active'] !== 1) {
            Tg::answer($cbId, 'این محصول فعال نیست.', true);
            screen_trial($chatId, $messageId, $uid);
            return;
        }
        $isManager = admin_trial_unlimited($uid);
        if (!$isManager && trial_used($uid) >= max(1, set_int('trial_per_user', 1))) {
            Tg::answer($cbId, 'سهمیهٔ تستت تمام شده.', true);
            screen_trial($chatId, $messageId, $uid);
            return;
        }
        screen_username_choice($chatId, $messageId, $productId, 'trial');
        return;
    }
    if ($head === 'uname') {
        $mode = (string) ($parts[1] ?? '');
        $productId = (int) ($parts[2] ?? 0);
        $how = (string) ($parts[3] ?? '');
        $unlimitedUsers = $mode === 'buyu' ? (int) ($parts[4] ?? 0) : 0;
        $product = product_get($productId);
        if ($product === null || (int) $product['active'] !== 1) {
            Tg::answer($cbId, 'این محصول فعال نیست.', true);
            show_home($chatId, $messageId, $uid);
            return;
        }
        if ($mode === 'buyu') {
            [$countOk, $countWhy] = unlimited_user_count_check($product, $unlimitedUsers);
            if (!$countOk) {
                Tg::answer($cbId, strip_tags($countWhy), true);
                screen_unlimited_initial_users($chatId, $messageId, $uid, $productId);
                return;
            }
        }
        if ($how === 'rand') {
            $name = random_panel_username();
            if ($mode === 'trial') {
                start_trial_order($uid, $chatId, $messageId, $productId, $name);
            } else {
                start_panel_order($uid, $chatId, $messageId, $productId, $name, $unlimitedUsers);
            }
            return;
        }
        if ($how === 'custom') {
            State::set($uid, 'panel_username', [
                'mode' => $mode, 'product' => $productId, 'unlimited_users' => $unlimitedUsers,
            ]);
            Tg::show($chatId, $messageId,
                '✍️ <b>نام کاربری دلخواه</b>' . "\n\n"
                . 'نام کاربری پنل را بفرستید.' . "\n"
                . 'فقط حروف انگلیسی، عدد و _ · بین ۳ تا ۳۲ حرف.' . "\n\n"
                . '<i>اگر تکراری باشد، همین‌جا می‌گویم و می‌توانید نام دیگری بفرستید.</i>' . "\n"
                . 'برای لغو: <code>لغو</code>',
                [[btn('« بازگشت', 'nav:back', null, 'danger')]]);
            return;
        }
        screen_username_choice($chatId, $messageId, $productId,
            $mode === 'trial' ? 'trial' : ($mode === 'buyu' ? 'buyu' : 'buy'), $unlimitedUsers);
        return;
    }
    if ($data === 'cancel') {
        /* لغو ویزارد هم فقط یک قدم عقب می‌رود، نه پرش به صفحهٔ اول */
        State::clear($uid);
        $prevCancel = nav_back($uid, 'user');
        if ($prevCancel === '') {
            show_home($chatId, $messageId, $uid);
            return;
        }
        $GLOBALS['panel_nav_skip'] = true;
        try {
            handle_callback_inner(['id' => $cbId, 'from' => $cb['from'] ?? ['id' => $uid],
                'message' => $cb['message'] ?? ['message_id' => $messageId,
                    'chat' => ['id' => $chatId, 'type' => 'private']],
                'data' => $prevCancel]);
        } finally {
            $GLOBALS['panel_nav_skip'] = false;
        }
        return;
    }

    /* ── دکمه‌های کپی (کارت به کارت) ──────────────────────────────────── */
    if ($head === 'pay' && ($parts[1] ?? '') === 'copy') {
        Tg::answer($cbId, '📋 آمادهٔ کپی');
        $orderId = (int) ($parts[2] ?? 0);
        $what = (string) ($parts[3] ?? '');
        $order = $orderId > 0 ? order_get($orderId) : null;
        if ($order === null || (int) $order['tg_id'] !== $uid) {
            Tg::send($chatId, 'این سفارش پیدا نشد.',
                [[btn('« منوی اصلی', 'home', null, 'danger')]]);
            return;
        }
        /* دکمه‌های کپی حالا با copy_text کار می‌کنند؛ این مسیر فقط برای
           پیام‌های قدیمی مانده و همان کارت را با یک دکمهٔ کپی واقعی می‌فرستد */
        $card = preg_replace('/[^0-9]/', '', set_get('card_number')) ?? '';
        if ($what === 'card') {
            if ($card === '') {
                Tg::send($chatId, 'شمارهٔ کارت تنظیم نشده؛ با پشتیبانی تماس بگیرید.',
                    [[btn('« منوی اصلی', 'home', null, 'danger')]]);
                return;
            }
            Tg::send($chatId, '📋 <b>شماره کارت</b>' . "\n" . 'صاحب کارت: '
                . e(set_get('card_holder')),
                [[copy_btn('📋 کپی شماره کارت', $card)],
                 [btn('« خانه', 'home', null, 'danger')]]);
            return;
        }
        $toman = (int) $order['payable'];
        Tg::send($chatId, '📋 <b>مبلغ به ریال</b>' . "\n" . '(' . rial_text($toman) . ')',
            [[copy_btn('📋 کپی مبلغ', rial_digits($toman))],
             [btn('« خانه', 'home', null, 'danger')]]);
        return;
    }

    /* ── مدیریت ───────────────────────────────────────────────────────── */
    /* ── 📢 قفل کانال اجباری (مسیر مستقیم پنل مدیریت) ─────────────────── */
    if ($head === 'fj') {
        if (!admin_can($uid, 'full')) {
            Tg::answer($cbId, '⛔️ تنظیم قفل کانال فقط برای مالک و هم‌مالک است.', true);
            return;
        }
        $op = (string) ($parts[1] ?? '');
        if ($op === 'on') {
            if (force_channel_raw() === '') {
                Tg::answer($cbId, '🔗 اول آیدی کانال را تنظیم کنید.', true);
                Tg::show($chatId, $messageId,
                    "⚠️ هنوز کانالی تنظیم نشده است.\n\n" . force_channel_admin_text(),
                    force_channel_admin_kb());
                return;
            }
            $new = !set_bool('force_channel_on', false);
            set_put('force_channel_on', $new ? '1' : '0');
            Tg::answer($cbId, $new ? '🟢 قفل روشن شد.' : '🔴 قفل خاموش شد.');
        } elseif ($op === 'set') {
            State::set($uid, 'fj_channel');
            Tg::show($chatId, $messageId,
                "🔗 <b>تنظیم کانال اجباری</b>\n" . SEP . "\n"
                . 'آیدی یا لینک کانال را بفرستید:' . "\n"
                . '· <code>@my_channel</code>' . "\n"
                . '· <code>https://t.me/my_channel</code>' . "\n"
                . '· یا شناسهٔ عددی <code>-1001234567890</code>' . "\n"
                . '· کانال خصوصی: <code>-100… https://t.me/+…</code>' . "\n\n"
                . '<i>ربات ابتدا نوع چت را بررسی می‌کند، سپس باید در آن «مدیر» باشد. '
                . 'لینک خصوصیِ تنها شناسهٔ کانال را آشکار نمی‌کند.</i>',
                [[btn('🔙 انصراف / بازگشت', 'ad:fj', null, 'danger')]]);
            return;
        } elseif ($op === 'del') {
            set_put('force_channel_id', '');
            set_put('force_channel_username', '');
            set_put('force_channel_join_link', '');
            set_put('force_channel_on', '0');
            Tg::answer($cbId, '🗑 کانال اجباری حذف شد.');
        }
        State::clear($uid);
        Tg::show($chatId, $messageId, force_channel_admin_text(), force_channel_admin_kb());
        return;
    }

    if ($head !== 'ad') {
        Tg::send($chatId, 'این دکمه دیگر معتبر نیست.', home_kb($uid));
        return;
    }
    if (!is_console_admin($uid)) {
        Tg::answer($cbId, '⛔️ این بخش برای مدیران تعیین‌شده است.', true);
        Tg::send($chatId, '⛔️ این بخش فقط برای مدیران تعیین‌شده باز است.', home_kb($uid));
        return;
    }
    /* گارد مرکزی RBAC: حتی callback کپی‌شده هم از سطح دسترسی بالاتر عبور نمی‌کند. */
    if (!admin_callback_allowed($uid, $data)) {
        Tg::answer($cbId, '⛔️ سطح دسترسی شما برای این بخش کافی نیست.', true);
        Tg::send($chatId, '⛔️ <b>دسترسی کافی نیست</b>

'
            . 'این بخش در سطح دسترسی شما فعال نشده است.', console_kb());
        return;
    }
    /* ⛔️ قفل اجباری: تا ثبت گروه گزارشات، فقط همان یک دکمه کار می‌کند */
    if (report_required() && $data !== 'ad:home' && !str_starts_with($data, 'ad:report')) {
        Tg::answer($cbId, '📢 اول گروه گزارشات را تنظیم کنید', true);
        screen_console($chatId, $messageId);
        screen_report_setup($chatId, null,
            '⚠️ برای دسترسی به این بخش، ابتدا گروه گزارشات را تنظیم کنید.');
        return;
    }
    $action = $parts[1] ?? '';
    switch ($action) {
        case 'fj':
            Tg::show($chatId, $messageId, force_channel_admin_text(),
                force_channel_admin_kb());
            return;
        case 'home':
            screen_console($chatId, $messageId);
            return;
        case 'g':
            $map = ['sales' => 'ad:finance', 'shop' => 'ad:cats', 'panels' => 'ad:panel',
                    'tools' => 'ad:tools'];
            $target = $map[$parts[2] ?? ''] ?? 'ad:home';
            handle_callback(['id' => $cbId, 'from' => ['id' => $uid],
                'message' => ['chat' => ['id' => $chatId], 'message_id' => $messageId],
                'data' => $target]);
            return;
        case 'help':
            Tg::show($chatId, $messageId,
                "❓ <b>راهنمای بخش مدیریت</b>\n\n"
                . 'همه‌چیز از دکمه‌ها انجام می‌شود؛ هیچ دستور ترمینالی لازم نیست.',
                [[btn('🔌 افزودن پنل پاسارگارد', 'help:s:adpanel', null, 'success')],
                 [btn('🗂 دسته و محصول', 'help:s:adcat'), btn('💳 مالی', 'help:s:adfin')],
                 [btn('📢 گروه گزارشات و بکاپ', 'help:s:adreport')],
                 [btn('« مدیریت', 'ad:home', null, 'danger')]]);
            return;
        case 'rolep':
            // گام ۱: کدام پنل؟ گام ۲: نقش‌های همان پنل
            $productId = (int) ($parts[2] ?? 0);
            $product = $productId > 0 ? product_get($productId) : null;
            $panels = DB::all('SELECT * FROM pg_panels WHERE active=1 ORDER BY is_default DESC, id');
            if (!$panels) {
                Tg::show($chatId, $messageId,
                    "🔌 هیچ پنلی وصل نیست، پس نقشی هم برای انتخاب نیست.\n"
                    . 'اول پنل را با کلید API وصل کنید.',
                    [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')],
                     [btn('« محصول', 'ad:prod:' . $productId, null, 'danger')]]);
                return;
            }
            $current = $product['panel_id'] ?? null;
            $rows = [];
            foreach ($panels as $panelRow) {
                $mark = ((int) $current === (int) $panelRow['id']) ? '✅ ' : '🔌 ';
                $rows[] = [btn($mark . e((string) $panelRow['name']),
                    'ad:role:' . $panelRow['id'] . ':0:' . $productId, null,
                    ((int) $current === (int) $panelRow['id']) ? 'success' : 'primary')];
            }
            $rows[] = [btn('« محصول', 'ad:prod:' . $productId, null, 'danger')];
            Tg::show($chatId, $messageId,
                "🎭 <b>نقش این محصول از کدام پنل؟</b>\n\n"
                . 'اول پنل را انتخاب کن؛ بعد ربات نقش‌های همان پنل را از خودش می‌خواند.',
                $rows);
            return;
        case 'role':
            $panelId = (int) ($parts[2] ?? 0);
            $roleId = (int) ($parts[3] ?? 0);
            $productId = (int) ($parts[4] ?? 0);
            if (($parts[3] ?? '') === 'refresh') {
                roles_cache($panelId, true);
                screen_roles($chatId, $messageId, $panelId, (int) ($parts[4] ?? 0));
                return;
            }
            if (($parts[3] ?? '') === 'page') {
                $page = (int) ($parts[4] ?? 1);
                screen_roles($chatId, $messageId, $panelId, (int) ($parts[5] ?? 0), $page);
                return;
            }
            if ($roleId <= 0) {
                screen_roles($chatId, $messageId, $panelId, $productId);
                return;
            }
            if ($productId > 0) {
                [$roleOk, $roleWhy] = product_set_role($productId, $roleId, $panelId);
                Tg::answer($cbId, $roleOk ? '✅ نقش تنظیم شد' : 'نقش قابل انتخاب نیست', !$roleOk);
                if (!$roleOk) {
                    Tg::send($chatId, '❌ ' . $roleWhy);
                    screen_roles($chatId, $messageId, $panelId, $productId);
                    return;
                }
                screen_product_admin($chatId, $messageId, $productId);
                return;
            }
            // انتخاب نقش داخل ویزارد ساخت محصول
            $state = State::get($uid);
            if ($state['state'] === 'wiz') {
                wiz_pick($uid, $roleId, $chatId, $messageId);
                return;
            }
            Tg::show($chatId, $messageId, 'این انتخاب منقضی شده.',
                [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
            return;
        case 'ref':
            $op = (string) ($parts[2] ?? '');
            if ($op === 'toggle') {
                set_put('ref_enabled', set_bool('ref_enabled', true) ? '0' : '1');
                screen_referral_settings($chatId, $messageId);
                return;
            }
            if ($op === 'mode') {
                $mode = (string) ($parts[3] ?? 'percent');
                set_put('ref_mode', $mode === 'cash' ? 'cash' : 'percent');
                Tg::answer($cbId, $mode === 'cash' ? 'پاداش نقدی ثابت فعال شد'
                    : 'پاداش درصدی فعال شد', false);
                screen_referral_settings($chatId, $messageId);
                return;
            }
            if ($op === 'when') {
                set_put('ref_cash_when', ref_cash_when() === 'join' ? 'purchase' : 'join');
                Tg::answer($cbId, ref_cash_when() === 'join'
                    ? '💵 پاداش نقدی: لحظهٔ عضویت' : '🛒 پاداش نقدی: اولین خرید', false);
                screen_referral_settings($chatId, $messageId);
                return;
            }
            if (in_array($op, ['percent', 'min', 'cash', 'maxdep'], true)) {
                $current = [
                    'percent' => set_int('ref_percent', 10),
                    'min'     => set_int('ref_min', 0),
                    'cash'    => set_int('ref_cash', 10000),
                    'maxdep'  => ref_max_deposits(),
                ][$op];
                $labels = [
                    'percent' => 'درصد پورسانت از هر واریز (0 تا 100)',
                    'min'     => 'حداقل مبلغ واریز برای پرداخت پورسانت (تومان)',
                    'cash'    => 'مبلغ پاداش نقدی هر دعوت (تومان)',
                    'maxdep'  => 'تعداد واریزی‌های اول که پورسانت دارند (پیش‌فرض 2)',
                ];
                wiz_start($uid, 'reffield', [
                    step_def('value', $labels[$op] . "\n" . 'مقدار فعلی: ' . $current,
                        'int', null, '🎁 دعوت دوستان'),
                ], ['field' => $op]);
                return;
            }
            screen_referral_settings($chatId, $messageId);
            return;
        case 'usearch':
            wiz_start($uid, 'usersearch', [
                step_def('value', 'بخشی از نام، یوزرنیم یا شناسهٔ عددی کاربر را بفرستید',
                    'text', null, '🔎 جستجوی کاربر'),
            ]);
            return;
        case 'stats':
            screen_stats($chatId, $messageId);
            return;
        case 'receipts':
            screen_receipts($chatId, $messageId);
            return;
        case 'rsearch':
            wiz_start($uid, 'rsearch', [
                step_def('value', 'کد پیگیری سفارش (یا شمارهٔ رسید) را بفرستید.', 'text', null,
                    '🔎 جستجوی رسید'),
            ]);
            return;
        case 'psearch':
            wiz_start($uid, 'psearch', [
                step_def('value', 'نام کاربری پنل را بفرستید (همان نامی که موقع ساخت ساخته شد).',
                    'text', null, '🔎 جستجوی پنل'),
            ]);
            return;
        case 'rc':
            [$ok, $note] = review_receipt((int) ($parts[2] ?? 0), ($parts[3] ?? '') === 'ok', $uid);
            Tg::answer($cbId, $note, !$ok);
            Tg::send($chatId, ($ok ? '✅ ' : '⚠️ ') . $note, console_kb());
            return;
        case 'finance':
            screen_finance($chatId, $messageId);
            return;
        case 'paygrp':
            screen_pay_group($chatId, $messageId, (string) ($parts[2] ?? 'fiat'));
            return;
        case 'late':
            /* تصمیم مدیر دربارهٔ واریزی بعد از انقضا */
            $reject = (string) ($parts[2] ?? '') === 'no';
            $payId = (int) ($parts[$reject ? 3 : 2] ?? 0);
            $payment = $payId > 0 ? pay_get($payId) : null;
            if ($payment === null) {
                Tg::answer($cbId, 'این پرداخت پیدا نشد.', true);
                return;
            }
            if ($reject) {
                DB::exec("UPDATE payments SET status='rejected' WHERE id=? AND status='expire'",
                    [$payId]);
                Tg::answer($cbId, 'رد شد.');
                Tg::show($chatId, $messageId, '❌ پرداخت <code>' . $payId
                    . '</code> رد شد و شارژ نشد.');
                return;
            }
            $result = pay_settle($payId, 'admin-late', ['by' => $uid], true);
            Tg::answer($cbId, ($result['ok'] ?? false) ? 'شارژ شد.' : 'انجام نشد.', true);
            Tg::show($chatId, $messageId, ($result['ok'] ?? false)
                ? '✅ پرداخت <code>' . $payId . '</code> تأیید و کیف پول کاربر شارژ شد.'
                : '❌ ' . e((string) ($result['text'] ?? 'انجام نشد')));
            return;
        case 'pay':
            $gw = (string) ($parts[2] ?? '');
            if ($gw === '') {
                screen_pay_gateways($chatId, $messageId);
                return;
            }
            if ($gw === 'swap') {
                $op = (string) ($parts[3] ?? '');
                if ($op === 'key') {
                    wiz_start($uid, 'swapkey', [
                        step_def('value', "کلید API سواپ‌ولت را بفرستید.\n"
                            . 'قالب: <code>apikey-...</code>' . "\n"
                            . 'مسیر دریافت: پروفایل ← کلید API ← ایجاد کلید جدید',
                            'text', null, '💎 سواپ‌ولت'),
                    ], []);
                    return;
                }
                if ($op === 'test') {
                    $test = swap_selftest();
                    $text = "🧪 <b>تست زندهٔ سواپ‌ولت</b>\n\n"
                        . 'آدرس: <code>' . e($test['url']) . "</code>\n"
                        . 'کد HTTP: <b>' . ($test['http'] > 0 ? (string) $test['http'] : 'بدون پاسخ')
                        . "</b>\n"
                        . 'زمان پاسخ: ' . $test['ms'] . " میلی‌ثانیه\n"
                        . 'وضعیت: ' . ($test['ok'] ? '🟢 سالم' : '🔴 خطا') . "\n\n";
                    if ($test['ok']) {
                        $text .= 'دلار (USDT/IRT): <b>' . money($test['usdt']) . "</b>\n"
                            . 'ترون (TRX/IRT): <b>' . money($test['trx']) . "</b>\n\n";
                    } elseif ($test['error'] !== '') {
                        $text .= 'خطا: <code>' . e($test['error']) . "</code>\n\n";
                    }
                    $text .= '<b>پاسخ خام</b>' . "\n<code>" . e($test['body']) . '</code>';
                    Tg::show($chatId, null, $text,
                        [[btn('🔄 دوباره تست', 'ad:pay:swap:test', null, 'primary')],
                         [btn('« سواپ‌ولت', 'ad:pay:swap', null, 'danger')]]);
                    return;
                }
                if ($op === 'refresh') {
                    swap_rates(true);
                    swap_star_usdt(true);
                    screen_swap($chatId, $messageId, true);
                    return;
                }
                screen_swap($chatId, $messageId);
                return;
            }
            $op = (string) ($parts[3] ?? '');
            if ($op === 'toggle' && isset(PAY_GATEWAYS[$gw])) {
                set_put('pay_' . $gw . '_on', set_get('pay_' . $gw . '_on', '1') === '1' ? '0' : '1');
                screen_pay_gateway($chatId, $messageId, $gw);
                return;
            }
            if ($op === 'test') {
                $test = gw_live_test($gw);
                $text = '🧪 <b>تست زندهٔ ' . e(PAY_GATEWAYS[$gw]['label']) . "</b>\n\n"
                    . 'آدرس: <code>' . e((string) ($test['endpoint'] ?? '—')) . "</code>\n"
                    . 'مبلغ تست: ' . money((int) ($test['amount'] ?? 0)) . "\n"
                    . 'نتیجه: ' . ($test['ok'] ? '🟢 ' : '🔴 ') . e((string) $test['title']) . "\n\n"
                    . '<b>پاسخ درگاه:</b>' . "\n<code>"
                    . e(mb_substr((string) $test['body'], 0, 400)) . '</code>';
                if (!empty($test['url'])) {
                    $text .= "\n\nلینک پرداخت تست:" . "\n" . e((string) $test['url']);
                }
                Tg::show($chatId, null, $text,
                    [[btn('🔄 دوباره تست', 'ad:pay:' . $gw . ':test', null, 'success')],
                     [btn('« ' . PAY_GATEWAYS[$gw]['label'], 'ad:pay:' . $gw, null, 'danger')]]);
                return;
            }
            if (in_array($op, ['key', 'key2', 'min', 'max', 'cashback', 'minfiat', 'endpoint'], true)) {
                $labels = ['key' => 'کلید درگاه (API key / merchant / pin)',
                           'key2' => 'کلید دوم (secret / endpoint / آدرس کیف پول / IPN)',
                           'min' => 'حداقل مبلغ برای این درگاه (تومان)',
                           'max' => 'حداکثر مبلغ برای این درگاه (تومان)',
                           'minfiat' => 'حداقل تراکنش این درگاه (دلار، 0 = خودکار از خود درگاه)',
                           'endpoint' => 'آدرس سرویس درگاه (با https؛ خالی = پیش‌فرض رسمی)',
                           'cashback' => 'درصد کش‌بک این درگاه (0 = بدون کش‌بک)'];
                $current = match ($op) {
                    'key' => set_get('pay_' . $gw . '_key'),
                    'key2' => set_get('pay_' . $gw . '_key2'),
                    'min' => (string) gw_min($gw),
                    'max' => (string) gw_max($gw),
                    'minfiat' => (string) (int) set_get('pay_' . $gw . '_min_fiat', '0'),
                    'endpoint' => set_get('pay_' . $gw . '_endpoint',
                        gw_endpoint_default($gw)),
                    default => (string) gw_cashback($gw),
                };
                wiz_start($uid, 'gwfield', [
                    step_def('value', $labels[$op] . "
مقدار فعلی: " . ($current !== '' ? $current : '—'),
                        in_array($op, ['key', 'key2', 'endpoint'], true) ? 'text' : 'int', null,
                        '💳 ' . PAY_GATEWAYS[$gw]['label']),
                ], ['gw' => $gw, 'field' => $op]);
                return;
            }
            screen_pay_gateway($chatId, $messageId, $gw);
            return;
        case 'payweb':
            screen_pay_web($chatId, $messageId);
            return;
        case 'finset':
            screen_fin_settings($chatId, $messageId);
            return;
        case 'fin':
            $sub = $parts[2] ?? '';
            if ($sub === 'sales') {
                screen_sales($chatId, $messageId, $parts[3] ?? 'today');
                return;
            }
            if ($sub === 'tx') {
                screen_transactions($chatId, $messageId, (int) ($parts[3] ?? 1), $parts[4] ?? '');
                return;
            }
            if ($sub === 'card') {
                if (($parts[3] ?? '') === 'auto') {
                    $current = set_bool('auto_confirm_receipt');
                    set_put('auto_confirm_receipt', $current ? '0' : '1');
                }
                /* یک صفحه برای کارت‌به‌کارت، نه دو تا */
                screen_pay_gateway($chatId, $messageId, 'card');
                return;
            }
            if ($sub === 'disc') {
                $op = $parts[3] ?? '';
                if ($op === 'new') {
                    wiz_start($uid, 'disc_new', [
                        step_def('code', 'یک نام برای کد بفرستید — مثل <code>Sjisha50</code>',
                            'text', null, '🏷 کد جدید · گام ۱ از ۷'),
                        step_def('percent', 'چند درصد تخفیف بدهد؟ (۱ تا ۱۰۰)', 'int', 10,
                            'گام ۲ از ۷ · درصد'),
                        step_def('max_users', 'چند کاربر بتوانند از این کد استفاده کنند؟ '
                            . '(0 = نامحدود)', 'int', 0, 'گام ۳ از ۷ · سقف کاربران'),
                        step_def('first_only', 'این کد برای چه خریدهایی باشد؟', 'pick', 0,
                            'گام ۴ از ۷ · نوع خرید', [
                                ['label' => '🛒 فقط خرید اول', 'value' => 1, 'style' => 'success'],
                                ['label' => '🛍 همهٔ خریدها', 'value' => 0],
                            ]),
                        step_def('per_user', 'هر کاربر چند بار بتواند استفاده کند؟ '
                            . '(0 = نامحدود)', 'int', 1, 'گام ۵ از ۷ · سهم هر کاربر'),
                        step_def('hours', 'کد چند ساعت فعال باشد؟ (0 = نامحدود)', 'int', 0,
                            'گام ۶ از ۷ · مهلت کد'),
                        step_def('days', 'این کد چند روز تخفیفِ مصرفِ هر گیگ را بدهد؟ '
                            . '(مثلاً 30)', 'int', 30, 'گام ۷ از ۷ · مدت تخفیف مصرف'),
                    ]);
                    return;
                }
                if ($op === 'stats' && isset($parts[4])) {
                    screen_discount_stats($chatId, $messageId, (string) $parts[4]);
                    return;
                }
                if ($op === 'toggle' && isset($parts[4])) {
                    DB::exec('UPDATE discounts SET active = 1 - active WHERE code=?', [$parts[4]]);
                    screen_discounts($chatId, $messageId);
                    return;
                }
                if ($op === 'del' && isset($parts[4])) {
                    DB::exec('DELETE FROM discounts WHERE code=?', [$parts[4]]);
                    screen_discounts($chatId, $messageId);
                    return;
                }
                screen_discounts($chatId, $messageId);
                return;
            }
            if ($sub === 'refund') {
                wiz_start($uid, 'refund', [
                    step_def('ref', 'کد سفارش (REF) یا شمارهٔ سفارش را بفرستید', 'text', null, '↩️ بازگشت وجه'),
                ]);
                return;
            }
            if ($sub === 'day') {
                Tg::show($chatId, $messageId, daily_report_text(),
                    [[btn('📢 ارسال به کانال گزارش', 'ad:fin:daypost', null, 'success')],
                     [btn('« مالی', 'ad:finance', null, 'danger')]]);
                return;
            }
            if ($sub === 'daypost') {
                if (!report_ready()) {
                    Tg::send($chatId, 'اول از «📢 گروه گزارشات» گروه را ثبت کنید.',
                        [[btn('📢 گروه گزارشات', 'ad:report', null, 'success')]]);
                } else {
                    post_report(daily_report_text(), 'daily');
                    Tg::send($chatId, '📢 گزارش به <code>' . e(report_chat_id()) . '</code> ارسال شد.');
                }
                return;
            }
            if ($sub === 'wallets') {
                screen_wallets($chatId, $messageId, $parts[3] ?? 'all', (int) ($parts[4] ?? 1));
                return;
            }
            screen_finance($chatId, $messageId);
            return;
        case 'find':
            /* 🔍 جستجوی سفارشات: پنل مشتری / فاکتور و تراکنش */
            $op = (string) ($parts[2] ?? '');
            if ($op === '' ) {
                screen_find($chatId, $messageId);
                return;
            }
            if ($op === 'panel' || $op === 'inv') {
                wiz_start($uid, $op === 'panel' ? 'find_panel' : 'find_inv', [
                    step_def('value', $op === 'panel'
                        ? 'نام کاربری پنل یا شناسهٔ عددی آن را بفرستید.'
                        : 'شناسهٔ فاکتور، کد پیگیری یا کد سفارش را بفرستید.',
                        'text', null, $op === 'panel' ? '🔍 جستجوی پنل مشتری'
                            : '🧾 جستجوی فاکتور'),
                ]);
                return;
            }
            if ($op === 'pn') {
                $panelId = (int) ($parts[3] ?? 0);
                $act = (string) ($parts[4] ?? '');
                $panel = $panelId > 0 ? DB::one('SELECT * FROM panels WHERE id=?',
                    [$panelId]) : null;
                if ($panel === null) {
                    Tg::answer($cbId, 'پنل پیدا نشد.', true);
                    screen_find_panel($chatId, $messageId, $panelId);
                    return;
                }
                if ($act === 'sus' || $act === 'res') {
                    if ($act === 'sus') {
                        suspend_panel($panel, 'تعلیق دستی توسط مدیر', $uid);
                    } else {
                        resume_panel($panel, $uid);
                    }
                    Tg::answer($cbId, $act === 'sus' ? 'پنل معلق شد' : 'پنل فعال شد', false);
                    screen_find_panel($chatId, $messageId, $panelId);
                    return;
                }
                if ($act === 'ulup' || $act === 'uldn') {
                    [$okL, $msgL] = admin_panel_user_limit($panelId, $act === 'ulup' ? 5 : -5);
                    Tg::answer($cbId, $okL ? 'سقف کاربر تغییر کرد' : $msgL, !$okL);
                    screen_find_panel($chatId, $messageId, $panelId);
                    return;
                }
                if ($act === 'ext') {
                    wiz_start($uid, 'find_extend', [
                        step_def('gigs', 'چند گیگابایت اضافه شود؟ (۰ = بدون تغییر حجم)',
                            'int', 0, '➕ تمدید پنل'),
                        step_def('days', 'چند روز اضافه شود؟ (۰ = بدون تغییر تاریخ)',
                            'int', 0, '➕ تمدید پنل'),
                    ], ['panel_id' => $panelId]);
                    return;
                }
                if ($act === 'del') {
                    Tg::show($chatId, $messageId,
                        '🗑 <b>حذف کامل پنل</b>' . "
" . SEP . "
"
                        . '📦 <code>' . e((string) $panel['panel_username']) . "</code>
"
                        . '<i>پنل از سرور پاسارگارد و از دیتابیس ربات پاک می‌شود؛ '
                        . 'این کار برگشت‌پذیر نیست.</i>',
                        [[btn('✅ بله، کامل حذف کنید', 'ad:find:pn:' . $panelId . ':delok',
                            null, 'danger')],
                         [btn('« انصراف', 'ad:find:pn:' . $panelId, null, 'primary')]]);
                    return;
                }
                if ($act === 'delok') {
                    [$okD, $msgD] = admin_panel_delete($panelId, $uid);
                    Tg::answer($cbId, $okD ? 'حذف شد' : $msgD, !$okD);
                    Tg::send($chatId, $msgD, [[btn('« جستجوی سفارشات', 'ad:find',
                        null, 'danger')]]);
                    return;
                }
                screen_find_panel($chatId, $messageId, $panelId);
                return;
            }
            screen_find($chatId, $messageId);
            return;
        case 'users':
            $page = (int) ($parts[2] ?? 1);
            $query = isset($parts[3]) ? urldecode((string) $parts[3]) : '';
            if ($page < 1) {
                $page = 1;
            }
            screen_users($chatId, $messageId, $page, $query);
            return;
        case 'u':
            $tgId = (int) ($parts[2] ?? 0);
            $user = user_get($tgId);
            if ($user === null) {
                Tg::show($chatId, $messageId,
                    '🔎 کاربر موردنظر پیدا نشد یا دیگر در ربات ثبت نیست.',
                    [[btn('« کاربران', 'ad:users', null, 'danger')]]);
                return;
            }
            $sub = (string) ($parts[3] ?? '');
            if ($sub === 'bal' || $sub === 'add' || $sub === 'sub') {
                $sign = $sub === 'sub' ? -1 : 1;
                wiz_start($uid, 'user_balance', [
                    step_def('amount', $sub === 'sub'
                        ? 'مبلغی که باید <b>کم</b> شود را بفرستید (تومان)'
                        : 'مبلغی که باید <b>اضافه</b> شود را بفرستید (تومان)', 'int', 0,
                        '💰 تغییر موجودی'),
                ], ['tg_id' => $tgId, 'sign' => $sign]);
                return;
            }
            if ($sub === 'zerobal') {
                $old = balance_of($tgId);
                add_balance($tgId, -$old, 'admin', (string) $uid, 'صفر کردن موجودی توسط ادمین');
                Tg::answer($cbId, 'موجودی صفر شد', false);
                support_send_admin_message($uid, $tgId, '👛 موجودی کیف پولتان توسط پشتیبانی صفر شد.');
                screen_user_card($chatId, $messageId, $tgId);
                return;
            }
            if ($sub === 'slots') {
                [$free, $bought, $used] = slots_total($tgId);
                Tg::show($chatId, $messageId,
                    '🧩 <b>اسلات‌های کاربر</b> <code>' . $tgId . "</code>\n\n"
                    . 'رایگان: ' . fa_digits($free) . "\n"
                    . 'خریداری: ' . fa_digits($bought) . "\n"
                    . 'مصرف‌شده: ' . fa_digits($used) . "\n"
                    . 'آزاد: ' . fa_digits(slots_available($tgId)),
                    [[btn('➕ یک اسلات هدیه', 'ad:u:' . $tgId . ':slotplus', null, 'success'),
                      btn('➖ یک اسلات کم', 'ad:u:' . $tgId . ':slotminus', null, 'danger')],
                     [btn('« کاربر', 'ad:u:' . $tgId, null, 'danger')]]);
                return;
            }
            if ($sub === 'slotplus' || $sub === 'slotminus') {
                if ($sub === 'slotplus') {
                    DB::exec('INSERT INTO slots(tg_id, count, expires_at, created_at) '
                        . 'VALUES(?,?,?,?)', [$tgId, 1, now_ts() + 30 * DAY, now_ts()]);
                    support_send_admin_message($uid, $tgId, '🎁 یک اسلات هدیه گرفتی!');
                } else {
                    $row = DB::one('SELECT * FROM slots WHERE tg_id=? AND count>0 ORDER BY id DESC '
                        . 'LIMIT 1', [$tgId]);
                    if ($row !== null) {
                        DB::exec('UPDATE slots SET count=count-1 WHERE id=?', [(int) $row['id']]);
                    }
                }
                Tg::answer($cbId, 'انجام شد', false);
                screen_user_card($chatId, $messageId, $tgId);
                return;
            }
            if ($sub === 'trialreset') {
                DB::exec("UPDATE orders SET status='canceled' WHERE tg_id=? AND kind='trial' "
                    . 'AND status=\'delivered\'', [$tgId]);
                Tg::answer($cbId, 'سهمیهٔ تست صفر شد', false);
                screen_user_card($chatId, $messageId, $tgId);
                return;
            }
            if ($sub === 'block') {
                $blocked = (int) $user['is_blocked'] === 1 ? 0 : 1;
                DB::exec('UPDATE users SET is_blocked=? WHERE tg_id=?', [$blocked, $tgId]);
                if (!$blocked) {
                    support_send_admin_message($uid, $tgId, '✅ دسترسی حسابتان دوباره باز شد.');
                }
                Tg::answer($cbId, $blocked ? 'مسدود شد' : 'رفع مسدودی شد', false);
                screen_user_card($chatId, $messageId, $tgId);
                return;
            }
            if ($sub === 'msg') {
                wiz_start($uid, 'usermsg', [
                    step_def('text', 'متن پیام برای این کاربر را بفرستید', 'text', null, '✉️ پیام به کاربر'),
                ], ['tg_id' => $tgId]);
                return;
            }
            if ($sub === 'panels') {
                $rows2 = DB::all('SELECT * FROM panels WHERE tg_id=? ORDER BY id DESC LIMIT 10',
                    [$tgId]);
                $icons = ['active' => '🟢', 'suspended' => '🟡', 'expired' => '🔴',
                          'deleted' => '⚪️'];
                $text = '📦 <b>پنل‌ها و سرویس‌های کاربر</b> <code>' . $tgId . "</code>\n"
                    . SEP . "\n";
                if (!$rows2) {
                    $text .= 'این کاربر هیچ پنلی ندارد.';
                }
                foreach ($rows2 as $panelRow2) {
                    $pgName = (string) DB::val('SELECT name FROM pg_panels WHERE id=?',
                        [(int) ($panelRow2['pg_panel_id'] ?? 0)], '');
                    $text .= ($icons[(string) $panelRow2['status']] ?? '⚪️')
                        . ((int) ($panelRow2['is_trial'] ?? 0) === 1 ? '🧪 ' : ' ')
                        . '<code>' . e((string) $panelRow2['panel_username']) . '</code>'
                        . ($pgName !== '' ? ' · ' . e($pgName) : '') . "\n"
                        . '   👥 ' . fa_digits((int) ($panelRow2['last_users'] ?? 0)) . '/'
                        . fa_digits((int) ($panelRow2['max_users'] ?? 0)) . ' کاربر'
                        . ' · 📊 ' . gb_step_text((int) ($panelRow2['last_usage'] ?? 0)) . ' گیگ'
                        . ' · 📅 تا ' . fa_ts((int) ($panelRow2['expires_at'] ?? 0)) . "\n";
                }
                Tg::show($chatId, $messageId, $text,
                    [[btn('« کاربر', 'ad:u:' . $tgId, null, 'danger')]]);
                return;
            }
            if ($sub === 'gift') {
                wiz_start($uid, 'user_gift', [
                    step_def('percent', 'چند درصد تخفیف اختصاصی بده؟ (۰ تا ۱۰۰ — ۰ یعنی حذف)',
                        'int', null, '🎁 تخفیف اختصاصی'),
                    step_def('days', 'این تخفیف چند روز فعال باشد؟ (۰ = بی‌پایان)',
                        'int', null, '🎁 تخفیف اختصاصی'),
                ], ['tg_id' => $tgId]);
                return;
            }
            if ($sub === 'kyc') {
                $level = kyc_level($tgId);
                Tg::show($chatId, $messageId,
                    '🪪 <b>تغییر دستی سطح احراز هویت</b> <code>' . $tgId . "</code>\n" . SEP
                    . "\n" . 'سطح فعلی: ' . kyc_level_emoji($level) . ' '
                    . e(KYC_LEVEL_TITLES[$level]) . "\n\n"
                    . '<i>با این کار سطح کاربر مستقیم عوض می‌شود و به خودش هم اطلاع داده می‌شود.</i>',
                    [[btn('⚪️ سطح ۰', 'ad:u:' . $tgId . ':kycset:0'),
                      btn('🥇 سطح ۱', 'ad:u:' . $tgId . ':kycset:1', null, 'success')],
                     [btn('💎 سطح ۲', 'ad:u:' . $tgId . ':kycset:2', null, 'success')],
                     [btn('« کاربر', 'ad:u:' . $tgId, null, 'danger')]]);
                return;
            }
            if ($sub === 'kycset') {
                $level = max(0, min(KYC_MAX_LEVEL, (int) ($parts[4] ?? 0)));
                kyc_set_level($tgId, $level);
                log_line('info', 'admin ' . $uid . ' set kyc level ' . $level . ' for ' . $tgId);
                Tg::answer($cbId, 'سطح احراز شد: ' . $level, false);
                support_send_admin_message($uid, $tgId,
                    '🪪 سطح احراز هویتت توسط پشتیبانی روی <b>'
                    . e(KYC_LEVEL_TITLES[$level]) . '</b> تنظیم شد.');
                screen_user_card($chatId, $messageId, $tgId);
                return;
            }
            if ($sub === 'sync') {
                $rows2 = DB::all('SELECT * FROM panels WHERE tg_id=? AND status<>\'deleted\'',
                    [$tgId]);
                $synced = 0;
                foreach ($rows2 as $panelRow2) {
                    if (panel_sync_usage((int) $panelRow2['id']) > 0) {
                        $synced++;
                    }
                }
                Tg::answer($cbId, 'بروزرسانی شد (' . $synced . ' پنل)', false);
                screen_user_card($chatId, $messageId, $tgId);
                return;
            }
            if ($sub === 'orders') {
                $rows2 = DB::all('SELECT * FROM orders WHERE tg_id=? ORDER BY id DESC LIMIT 10', [$tgId]);
                $text = '🧾 <b>سفارش‌های کاربر</b> <code>' . $tgId . "</code>\n\n";
                if (!$rows2) {
                    $text .= 'سفارشی ندارد.';
                }
                foreach ($rows2 as $orderRow) {
                    $text .= '· <code>' . e((string) $orderRow['ref']) . '</code> · '
                        . e(order_kind_label((string) $orderRow['kind'])) . ' · '
                        . money((int) $orderRow['payable']) . ' · '
                        . e(order_status_label((string) $orderRow['status'])) . "\n";
                }
                Tg::show($chatId, $messageId, $text,
                    [[btn('« کاربر', 'ad:u:' . $tgId, null, 'danger')]]);
                return;
            }
            if ($sub === 'tx') {
                $rows2 = DB::all('SELECT * FROM wallet_tx WHERE tg_id=? ORDER BY id DESC LIMIT 12', [$tgId]);
                $text = '📜 <b>تراکنش‌های کاربر</b> <code>' . $tgId . "</code>\n\n";
                if (!$rows2) {
                    $text .= 'تراکنشی ندارد.';
                }
                foreach ($rows2 as $tx) {
                    $sign = (int) $tx['amount'] >= 0 ? '+' : '−';
                    $text .= '· ' . fa_ts((int) $tx['created_at']) . ' · '
                        . tx_kind_label((string) $tx['kind']) . ' · '
                        . $sign . money(abs((int) $tx['amount'])) . "\n";
                }
                Tg::show($chatId, $messageId, $text,
                    [[btn('« کاربر', 'ad:u:' . $tgId, null, 'danger')]]);
                return;
            }
            screen_user_card($chatId, $messageId, $tgId);
            return;
        case 'cats':
            screen_categories($chatId, $messageId);
            return;
        case 'cat':
            $catId = $parts[2] ?? '';
            if ($catId === 'new') {
                wiz_start($uid, 'cat_new', [
                    step_def('name', 'نام دسته را بفرستید — مثلاً: حجمی، تانل، مستقیم', 'text', null, '🗂 دستهٔ جدید'),
                    step_def('emoji', 'یک ایموجی برای دسته بفرستید — یا - برای 🗂', 'text', '🗂'),
                ]);
                return;
            }
            $catId = (int) $catId;
            $op = $parts[3] ?? '';
            if ($op === 'new') {
                $saleType = (string) ($parts[4] ?? '');
                if (!in_array($saleType, ['metered', 'unlimited'], true)) {
                    Tg::show($chatId, $messageId,
                        "➕ <b>نوع محصول جدید</b>\n\n"
                        . 'نوع فروش را انتخاب کنید. محصولات حجمی/ساعتی قبلی بدون تغییر می‌مانند.'
                        . "\n\n" . UNLIMITED_ROLE_GUIDE,
                        [[btn('📊 محصول حجمی / ساعتی', 'ad:cat:' . $catId . ':new:metered', null, 'primary')],
                         [btn('♾️ پنل نامحدود', 'ad:cat:' . $catId . ':new:unlimited', null, 'success')],
                         [btn('« بازگشت', 'ad:cat:' . $catId, null, 'danger')]]);
                    return;
                }
                $panels = DB::all('SELECT * FROM pg_panels WHERE active=1 ORDER BY is_default DESC, id');
                $rowsPanel = [];
                foreach ($panels as $panelRow) {
                    $rowsPanel[] = [btn('🔌 ' . e((string) $panelRow['name']),
                        'wiz:' . $panelRow['id'], null, 'success')];
                }
                if (!$rowsPanel) {
                    Tg::show($chatId, $messageId,
                        'اول باید یک پنل پاسارگارد وصل شود.',
                        [[btn('🔌 افزودن پنل', 'ad:panel:new', null, 'success')],
                         [btn('« بازگشت', 'ad:cat:' . $catId, null, 'danger')]]);
                    return;
                }
                if ($saleType === 'unlimited') {
                    wiz_start($uid, 'prod_new_unlimited', [
                        step_def('name', 'نام محصول نامحدود را بفرستید — مثلاً: پنل نامحدود هلند'
                            . "\n\n" . UNLIMITED_ROLE_GUIDE,
                            'text', null, '♾️ پنل نامحدود جدید'),
                        step_def('emoji', 'ایموجی محصول — یا - برای ♾️', 'text', '♾️'),
                        step_def('panel_id', 'پنل این محصول را انتخاب کنید:', 'pick', 0,
                            '🔌 انتخاب پنل', 'panels'),
                        step_def('role_id', 'نقش این محصول روی همین پنل را انتخاب کنید '
                            . "(فقط نقش‌های همان پنل):\n<i>اگر نقشی نمی‌بینی، «🔄 خواندن نقش‌های این پنل» را بزنید.</i>",
                            'pick', 0, '🎭 انتخاب نقش', 'roles'),
                        step_def('per_user_price', 'قیمت هر کاربر (تومان) را بفرستید.', 'int', 0,
                            '💵 قیمت هر کاربر'),
                        step_def('min_users', 'حداقل تعداد کاربر در هر خرید یا شارژ را بفرستید.',
                            'int', 1, '👥 حداقل تعداد کاربر'),
                    ], ['cat_id' => $catId, 'role_page' => 1]);
                    return;
                }
                wiz_start($uid, 'prod_new', [
                    step_def('name', 'نام محصول را بفرستید — مثلاً: پنل تانل', 'text', null, '➕ محصول جدید'),
                    step_def('emoji', 'ایموجی محصول — یا - برای 💎', 'text', '💎'),
                    step_def('panel_id', 'پنل این محصول را انتخاب کنید:', 'pick', 0, '🔌 انتخاب پنل',
                        'panels'),
                    step_def('role_id', 'نقش این محصول روی همین پنل را انتخاب کنید '
                        . "(فقط نقش‌های همان پنل):\n<i>اگر نقشی نمی‌بینی، «🔄 خواندن نقش‌های این پنل» را بزنید.</i>",
                        'pick', 0, '🎭 انتخاب نقش', 'roles'),
                    step_def('hourly_price', 'اجارهٔ ساعتی این پنل (تومان) — 0 یعنی فقط مصرف', 'int', 0),
                    step_def('per_gb_price', 'قیمت هر گیگ مصرف (تومان)', 'int', 5000),
                ], ['cat_id' => $catId, 'role_page' => 1]);
                return;
            }
            if ($op === 'edit') {
                $cat = cat_get($catId);
                wiz_start($uid, 'cat_new', [
                    step_def('name', 'نام تازهٔ دسته را بفرستید', 'text', null, '✏️ ویرایش دسته'),
                    step_def('emoji', 'ایموجی دسته', 'text', $cat['emoji'] ?? '🗂'),
                ], ['edit_id' => $catId]);
                return;
            }
            if ($op === 'toggle') {
                DB::exec('UPDATE categories SET active = 1 - active WHERE id=?', [$catId]);
            }
            if ($op === 'del') {
                $used = (int) DB::val('SELECT COUNT(*) FROM products WHERE category_id=?', [$catId], 0);
                if ($used > 0) {
                    Tg::answer($cbId, 'این دسته ' . fa_digits($used) . ' محصول دارد؛ اول محصول‌ها را حذف کنید.', true);
                    return;
                }
                DB::exec('DELETE FROM categories WHERE id=?', [$catId]);
                Tg::answer($cbId, 'دسته حذف شد');
                screen_categories($chatId, $messageId);
                return;
            }
            screen_category($chatId, $messageId, $catId);
            return;
        case 'products':
            $rows = products_list();
            if (!$rows) {
                screen_categories($chatId, $messageId);
                return;
            }
            $text = "📦 <b>همهٔ محصولات</b>\n\n";
            $buttons = [];
            $catMap = [];
            foreach (cat_list() as $cat) {
                $catMap[(int) $cat['id']] = cat_label($cat);
            }
            foreach ($rows as $product) {
                $text .= '#' . $product['id'] . ' ' . $product['emoji'] . ' <b>'
                    . e((string) $product['name']) . '</b> ['
                    . ($catMap[(int) ($product['category_id'] ?? 0)] ?? 'بدون دسته') . '] '
                    . ((int) $product['active'] === 1 ? '✅' : '⛔️') . "\n"
                    . '   هر گیگ ' . money((int) $product['per_gb_price'])
                    . ' · ساعت ' . money((int) $product['hourly_price']) . "\n\n";
                $buttons[] = [btn($product['emoji'] . ' ' . $product['name'],
                    'ad:prod:' . $product['id'])];
            }
            $buttons[] = [btn('🗂 دسته‌بندی‌ها', 'ad:cats', null, 'success')];
            $buttons[] = [btn('« بازگشت', 'ad:cats', null, 'danger')];
            Tg::show($chatId, $messageId, $text, $buttons);
            return;
        case 'prod':
            $productId = (int) ($parts[2] ?? 0);
            $op = $parts[3] ?? '';
            if ($op === 'sp') {
                $rows2 = [];
                foreach (DB::all('SELECT * FROM pg_panels ORDER BY is_default DESC, id') as $panelRow) {
                    $rows2[] = [btn('🔌 ' . e((string) $panelRow['name'])
                        . ' · ' . fa_digits((int) $panelRow['id']),
                        'ad:prod:' . $productId . ':setp:' . $panelRow['id'], null, 'success')];
                }
                $rows2[] = [btn('« بازگشت', 'ad:prod:' . $productId, null, 'danger')];
                Tg::show($chatId, $messageId,
                    "🔌 پنل مقصد این محصول را انتخاب کنید:\n" .
                    '<i>بعد از تغییر پنل، نقش را هم دوباره از همان پنل انتخاب کنید.</i>', $rows2);
                return;
            }
            if ($op === 'setp') {
                $newPanel = (int) ($parts[4] ?? 0);
                DB::exec('UPDATE products SET panel_id=?, role_id=0, active=0 WHERE id=?',
                    [$newPanel, $productId]);
                Tg::answer($cbId, 'پنل عوض شد؛ حالا نقش را انتخاب کنید', false);
                screen_roles($chatId, $messageId, $newPanel, $productId);
                return;
            }
            if ($op === 'toggle') {
                DB::exec('UPDATE products SET active = 1 - active WHERE id=?', [$productId]);
            }
            if ($op === 'del') {
                $product = product_get($productId);
                Tg::show($chatId, $messageId,
                    '🗑 <b>حذف محصول</b>' . "\n\n"
                    . 'محصول «' . e((string) ($product['name'] ?? '')) . '» حذف شود؟' . "\n"
                    . '<i>پنل‌هایی که قبلاً فروخته شده‌اند دست‌نخورده می‌مانند.</i>',
                    [[btn('🗑 بله، حذف کنید', 'ad:prod:' . $productId . ':delc', null, 'danger')],
                     [btn('« بازگشت', 'ad:prod:' . $productId, null, 'primary')]]);
                return;
            }
            if ($op === 'delc') {
                $product = product_get($productId);
                DB::exec('DELETE FROM products WHERE id=?', [$productId]);
                Tg::answer($cbId, 'محصول حذف شد. پنل‌های فروخته‌شده دست‌نخورده‌اند.', false);
                screen_category($chatId, $messageId, (int) ($product['category_id'] ?? 0));
                return;
            }
            screen_product_admin($chatId, $messageId, $productId);
            return;
        case 'pf':
            $productId = (int) ($parts[2] ?? 0);
            $field = $parts[3] ?? '';
            $meta = PRODUCT_FIELDS[$field] ?? null;
            if ($meta === null) {
                Tg::send($chatId, 'این گزینه پیدا نشد.', console_kb());
                return;
            }
            $product = product_get($productId);
            if ($product === null) {
                Tg::send($chatId, 'محصول پیدا نشد.', [[btn('« دسته‌ها', 'ad:cats', null, 'danger')]]);
                return;
            }
            if (product_is_unlimited($product)
                && !in_array($field, ['name', 'emoji', 'peruser', 'minusers'], true)) {
                Tg::answer($cbId, 'این تنظیم برای پنل نامحدود با نقش پاسارگارد مدیریت می‌شود.', true);
                screen_product_admin($chatId, $messageId, $productId);
                return;
            }
            wiz_start($uid, 'prod_field', [
                step_def('value', $meta[0] . "\nمقدار فعلی: " . ($product[$meta[2]] ?? '—'),
                    $meta[1] === 'text' ? 'text' : $meta[1], null, '✏️ ' . $meta[0]),
            ], ['product_id' => $productId, 'field' => $field]);
            return;
        case 'set':
            $key = $parts[2] ?? '';
            if ($key === 'back') {
                screen_settings($chatId, $messageId, setting_group_of((string) ($parts[3] ?? '')));
                return;
            }
            $label = SETTING_LABELS[$key] ?? 'این مقدار';
            wiz_start($uid, 'setting', [
                step_def('value', 'مقدار تازهٔ «' . $label . '» را بفرستید.', 'text', null,
                    '⚙️ ' . $label),
            ], ['key' => $key]);
            return;
        case 'report':
            $op = (string) ($parts[2] ?? '');
            if ($op === 'setup' || $op === 'chat') {
                State::clear($uid);
                screen_report_setup($chatId, $messageId);
                return;
            }
            if ($op === 'input') {
                State::set($uid, 'report_group_id');
                $GLOBALS['panel_kb_locked'] = true;
                Tg::send($chatId, "📢 <b>شناسهٔ عددی گروه گزارشات</b>\n" . SEP . "\n"
                    . 'آیدی عددی استخراج‌شده (مثال: <code>-1003861909935</code>) را '
                    . 'همین‌جا در چت ربات ارسال کنید.' . "\n\n"
                    . 'ربات قبل از ذخیره، یک پیام تست داخل گروه می‌فرستد؛ '
                    . 'اگر پیام تست نرسید، شناسه ذخیره نمی‌شود.',
                    [[btn('🔙 انصراف / بازگشت', 'ad:report:setup', null, 'danger')]]);
                return;
            }
            if ($op === 'topics') {
                [$topicsOk, $topicsNote] = report_topics_build();
                Tg::answer($cbId, $topicsOk ? 'تاپیک‌ها آماده شد' : 'تاپیک ساخته نشد', !$topicsOk);
                screen_report($chatId, $messageId, $topicsNote);
                return;
            }
            if ($op === 'test') {
                if (!report_ready()) {
                    Tg::answer($cbId, 'اول گروه گزارشات را ثبت کنید.', true);
                    screen_report($chatId, $messageId);
                    return;
                }
                post_report('🧪 <b>پیام آزمایشی</b>' . "\n" . 'این پیام یعنی تاپیک گزارشات درست کار می‌کند.',
                    'general');
                Tg::send($chatId, '📢 یک پیام آزمایشی به گروه گزارشات فرستادم.');
                screen_report($chatId, $messageId);
                return;
            }
            screen_report($chatId, $messageId);
            return;
        case 'restore':
            $op = (string) ($parts[2] ?? '');
            if ($op === 'ok') {
                [$restoreOk, $restoreNote] = backup_restore_request();
                Tg::send($chatId, ($restoreOk ? '✅ ' : '⚠️ ') . $restoreNote);
                if ($restoreOk) {
                    /* حلقهٔ نظرسنجی با دیدن این فایل بیرون می‌آید و سرویس
                       دوباره بالا می‌آید؛ بازگردانی در استارتاپ اعمال می‌شود */
                    @file_put_contents(panel_home() . '/.restart-request', 'restore');
                }
                return;
            }
            if ($op === 'no') {
                set_put('restore_pending', '');
                State::clear($uid);
                Tg::answer($cbId, 'لغو شد');
                Tg::send($chatId, 'بازگردانی لغو شد؛ دادهٔ فعلی دست‌نخورده است.',
                    [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
                return;
            }
            screen_backup($chatId, $messageId);
            return;
        case 'backup':
            $op = (string) ($parts[2] ?? '');
            if ($op === 'settings') {
                screen_settings($chatId, $messageId, 'backup');
                return;
            }
            if ($op === 'now') {
                [$backupOk, $backupNote] = backup_send('بکاپ دستی از پنل مدیریت', true);
                Tg::send($chatId, ($backupOk ? '✅ ' : '⚠️ ') . e($backupNote));
                screen_backup($chatId, $messageId);
                return;
            }
            screen_backup($chatId, $messageId);
            return;
        case 'panel':
            screen_pg_panels($chatId, $messageId);
            return;
        case 'pg':
            $what = $parts[2] ?? '';
            if ($what === 'new') {
                Tg::show($chatId, $messageId,
                    "🔌 <b>افزودن پنل پاسارگارد</b>\n\n"
                    . 'اتصال فقط با <b>کلید API</b> انجام می‌شود (بدون یوزرنیم و پسورد).' . "\n\n"
                    . 'کلید را از پنل بگیرید: <b>تنظیمات → API Keys → ساخت کلید</b>' . "\n"
                    . '<i>سه گام: نام پنل → آدرس → کلید API</i>',
                    [[btn('🔑 شروع اتصال با API Key', 'ad:pg:newapi', null, 'success')],
                     [btn('« پنل‌ها', 'ad:panel', null, 'danger')]]);
                return;
            }
            if ($what === 'savekey') {
                $pending = State::get($uid);
                State::clear($uid);
                $values = (array) ($pending['data'] ?? []);
                if ($pending['state'] !== 'panel_key_confirm' || empty($values['url'])) {
                    Tg::send($chatId, 'این ثبت منقضی شده؛ دوباره «➕ افزودن پنل» را بزنید.',
                        [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')]]);
                    return;
                }
                panel_save_api($chatId, (string) $values['name'],
                    (string) $values['url'], (string) $values['api_key']);
                return;
            }
            if ($what === 'newapi') {
                wiz_start($uid, 'panel_api', [
                    step_def('name', 'یک نام برای این پنل بفرستید — مثلاً: <b>V2RAY آنکارا</b>',
                        'text', null, '🔑 افزودن پنل با API · گام ۱ از ۳'),
                    step_def('url', 'آدرس پنل را بفرستید — مثلاً:' . "\n"
                        . '<code>https://panel.example.com:8000</code>', 'text', null,
                        'گام ۲ از ۳ · آدرس'),
                    step_def('api_key', 'کلید API پنل را بفرستید — با <code>pg_key_</code> شروع می‌شود',
                        'text', null, 'گام ۳ از ۳ · کلید API'),
                ]);
                return;
            }
            if ($what === 'newpass' || $what === 'quick') {
                Tg::show($chatId, $messageId,
                    'اتصال با یوزرنیم و پسورد حذف شد؛ فقط کلید API پذیرفته می‌شود.',
                    [[btn('🔑 اتصال با API Key', 'ad:pg:newapi', null, 'success')],
                     [btn('« پنل‌ها', 'ad:panel', null, 'danger')]]);
                return;
            }
            if ($what === 'testall') {
                $lines = [];
                foreach (DB::all('SELECT * FROM pg_panels ORDER BY is_default DESC, id') as $row) {
                    [$ok, $note] = panel_test($row);
                    $lines[] = ($ok ? '✅ ' : '❌ ') . e((string) $row['name']) . ' — '
                        . e(mb_substr(str_replace("\n", ' · ', $note), 0, 90));
                }
                Tg::show($chatId, $messageId,
                    "🧪 <b>تست همهٔ پنل‌ها</b>\n\n" . ($lines ? implode("\n", $lines)
                        : 'پنلی ثبت نشده.'),
                    [[btn('🔄 دوباره', 'ad:pg:testall'), btn('« پنل‌ها', 'ad:panel', null, 'danger')]]);
                return;
            }
            $panelId = (int) $what;
            $op = $parts[3] ?? '';
            if ($op === 'test') {
                $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
                [$ok, $note] = $row ? panel_test($row) : [false, 'پنل پیدا نشد'];
                Tg::send($chatId, ($ok ? '✅ ' : '❌ ') . e($note));
                screen_pg_panel($chatId, $messageId, $panelId);
                return;
            }
            if ($op === 'dev') {
                screen_pg_devices($chatId, $messageId, $panelId);
                return;
            }
            if ($op === 'edit') {
                wiz_start($uid, 'pg_edit', [
                    step_def('name', 'نام تازهٔ این پنل را بفرستید.' . "\n"
                        . 'برای بدون تغییر، یک <b>-</b> بفرستید.', 'text', null, '✏️ نام پنل'),
                    step_def('url', 'آدرس تازهٔ پنل را بفرستید (مثل <code>https://panel.example.com:8000</code>).'
                        . "\n" . 'برای بدون تغییر، یک <b>-</b> بفرستید.', 'text', null,
                        '✏️ آدرس پنل'),
                ], ['panel_id' => $panelId]);
                return;
            }
            if ($op === 'sync') {
                $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
                $live = $row === null ? ['online' => false, 'users' => 0]
                    : pg_panel_dashboard_data($row);
                $touched = 0;
                foreach (DB::all('SELECT * FROM panels WHERE pg_panel_id=?', [$panelId]) as $sold) {
                    $info = panel_live_info($sold);
                    if ($info['ok']) {
                        panel_store_live((int) $sold['id'], $info);
                        $touched++;
                    }
                }
                Tg::answer($cbId, $live['online'] ? 'همگام شد' : 'پنل در دسترس نیست',
                    !$live['online']);
                Tg::send($chatId, ($live['online'] ? '✅ ' : '❌ ')
                    . 'همگام‌سازی انجام شد · ' . fa_digits($touched) . ' پنل فروخته‌شده '
                    . 'به‌روزرسانی شد.');
                screen_pg_panel($chatId, $messageId, $panelId);
                return;
            }
            if ($op === 'default') {
                DB::exec('UPDATE pg_panels SET is_default=0');
                DB::exec('UPDATE pg_panels SET is_default=1, active=1 WHERE id=?', [$panelId]);
                Tg::send($chatId, '⭐ این پنل پیش‌فرض شد.');
                screen_pg_panels($chatId, $messageId);
                return;
            }
            if ($op === 'toggle') {
                DB::exec('UPDATE pg_panels SET active = 1 - active WHERE id=?', [$panelId]);
                screen_pg_panel($chatId, $messageId, $panelId);
                return;
            }
            if ($op === 'cat') {
                $rows = [[btn('🚫 بدون دسته', 'ad:pg:' . $panelId . ':setcat:0', null, 'danger')]];
                foreach (cat_list() as $cat) {
                    $rows[] = [btn(cat_label($cat), 'ad:pg:' . $panelId . ':setcat:' . $cat['id'])];
                }
                $rows[] = [btn('« بازگشت', 'ad:pg:' . $panelId, null, 'danger')];
                Tg::show($chatId, $messageId, '🗂 دستهٔ این پنل را انتخاب کنید.', $rows);
                return;
            }
            if ($op === 'setcat') {
                $catId = (int) ($parts[4] ?? 0);
                DB::exec('UPDATE pg_panels SET category_id=? WHERE id=?',
                    [$catId ?: null, $panelId]);
                screen_pg_panel($chatId, $messageId, $panelId);
                return;
            }
            if ($op === 'roles') {
                screen_roles($chatId, $messageId, $panelId, 0);
                return;
            }
            if ($op === 'key') {
                wiz_start($uid, 'panel_key', [
                    step_def('api_key', 'کلید API تازهٔ این پنل را بفرستید — با <code>pg_key_</code> شروع می‌شود',
                        'text', null, '🔑 کلید API'),
                ], ['panel_id' => $panelId]);
                return;
            }
            if ($op === 'del') {
                $panelRow = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
                Tg::show($chatId, $messageId,
                    '🗑 <b>حذف پنل</b>' . "\n\n"
                    . 'پنل «' . e((string) ($panelRow['name'] ?? '')) . '» از ربات حذف شود؟' . "\n"
                    . '<i>محصولات و پنل‌های فروخته‌شده پاک نمی‌شوند؛ فقط اتصال این پنل برداشته می‌شود.</i>',
                    [[btn('🗑 بله، حذف کنید', 'ad:pg:' . $panelId . ':del2', null, 'danger')],
                     [btn('« بازگشت', 'ad:pg:' . $panelId, null, 'primary')]]);
                return;
            }
            if ($op === 'del2') {
                DB::exec('UPDATE products SET panel_id=NULL WHERE panel_id=?', [$panelId]);
                DB::exec('DELETE FROM pg_panels WHERE id=?', [$panelId]);
                set_put('roles_cache_' . $panelId, '[]');
                Tg::answer($cbId, 'پنل حذف شد', false);
                screen_pg_panels($chatId, $messageId);
                return;
            }
            screen_pg_panel($chatId, $messageId, $panelId);
            return;
        case 'panels':
            $rowsDb = DB::all("SELECT * FROM panels WHERE status<>'deleted' ORDER BY id DESC LIMIT 25");
            $text = "🏢 <b>پنل‌های فروخته‌شده</b>\n\n";
            foreach ($rowsDb as $row) {
                $text .= '· <code>' . e((string) $row['panel_username']) . '</code> | کاربر <code>'
                    . $row['tg_id'] . '</code> | ' . e((string) $row['status']) . "\n";
            }
            if (!$rowsDb) {
                $text .= 'پنلی فروخته نشده است.';
            }
            Tg::show($chatId, $messageId, $text,
                [[btn('🔎 جستجوی نام کاربری پنل', 'ad:psearch', null, 'success')],
                 [btn('« مدیریت', 'ad:home', null, 'danger')]]);
            return;
        case 'slots':
            Tg::show($chatId, $messageId,
                "🧩 <b>اسلات‌ها</b>\n\n"
                . 'اسلات رایگان هر کاربر: ' . fa_digits(set_int('slots_free', 3)) . "\n"
                . 'قیمت هر اسلات: ' . money(set_int('slot_price', 150000)) . "\n"
                . 'سقف اسلات هر کاربر: ' . fa_digits(set_int('max_slots', 20)) . "\n"
                . 'اسلات‌های فروخته‌شده: ' . fa_digits((int) DB::val('SELECT COALESCE(SUM(count),0) FROM slots', [], 0)),
                [[btn('🔢 رایگان', 'ad:set:slots_free'), btn('💵 قیمت', 'ad:set:slot_price')],
                 [btn('📈 سقف', 'ad:set:max_slots'), btn('« مدیریت', 'ad:home', null, 'danger')]]);
            return;
        case 'tools':
            /* صفحهٔ قدیمی حذف شد؛ همه‌چیز یک‌جا در «تنظیمات» است */
            screen_settings($chatId, $messageId, 'home');
            return;
        case 'settings':
            /* همیشه پیام تازه می‌آید تا دکمه هیچ‌وقت «بی‌واکنش» به نظر نرسد */
            screen_settings($chatId, null, 'home');
            return;
        case 'setg':
            screen_settings($chatId, null, (string) ($parts[2] ?? 'home'));
            return;
        case 'staff':
            admin_staff_callback($uid, $chatId, $messageId, $cbId, $parts);
            return;
        case 'ops':
            screen_ops_logs($chatId, $messageId);
            return;
        case 'upd':
            /* بروزرسانی از مخزن گیت‌هاب: نمایش وضعیت، بررسی مجدد، اجرا */
            $op = (string) ($parts[2] ?? '');
            if ($op === 'check') {
                Tg::answer($cbId, 'در حال بررسی نسخهٔ تازه…', false);
                screen_update($chatId, $messageId, true);
                return;
            }
            if ($op === 'run') {
                Tg::answer($cbId, 'در حال بروزرسانی…', false);
                update_run_from_bot($chatId, $messageId);
                return;
            }
            screen_update($chatId, $messageId);
            return;
        case 'tgl':
            $key = (string) ($parts[2] ?? '');
            if ($key !== '' && array_key_exists($key, SETTING_DEFAULTS)) {
                set_put($key, set_bool($key, true) ? '0' : '1');
                Tg::answer($cbId, set_bool($key, true) ? 'روشن شد' : 'خاموش شد', false);
            }
            screen_settings($chatId, $messageId, (string) ($parts[3] ?? 'home'));
            return;
        case 'bc':
            $op = (string) ($parts[2] ?? '');
            if ($op === 'text') {
                wiz_start($uid, 'bc_text', [
                    step_def('text', 'متن پیام همگانی را بفرستید. می‌توانید از HTML ساده '
                        . '(<code>&lt;b&gt;پررنگ&lt;/b&gt;</code>) هم استفاده کنید.', 'text', null,
                        '📨 ارسال همگانی'),
                ]);
                return;
            }
            if ($op === 'fwd') {
                State::set($uid, 'bc_forward', []);
                Tg::send($chatId, "🔁 <b>فروارد همگانی</b>\n\n"
                    . 'حالا همان پیامی که می‌خواهید برای همه برود را برای ربات '
                    . '<b>فروارد یا ارسال</b> کنید (متن، عکس، ویدیو، فایل — هر چیزی).' . "\n\n"
                    . 'برای لغو: <code>لغو</code>',
                    [[btn('« پیام همگانی', 'ad:bc', null, 'danger')]]);
                return;
            }
            if ($op === 'seg') {
                $seg = (string) ($parts[3] ?? '');
                if (isset(BC_SEGMENTS[$seg])) {
                    set_put('bc_segment', $seg);
                    Tg::answer($cbId, 'مخاطب: ' . BC_SEGMENTS[$seg], false);
                    screen_broadcast($chatId, $messageId);
                    return;
                }
                $rows = [];
                foreach (BC_SEGMENTS as $key => $label) {
                    $rows[] = [btn(($key === bc_segment() ? '✅ ' : '') . $label
                        . ' (' . fa_digits(count(bc_targets($key))) . ')', 'ad:bc:seg:' . $key)];
                }
                $rows[] = [btn('« پیام همگانی', 'ad:bc', null, 'danger')];
                Tg::show($chatId, $messageId, "🎯 <b>مخاطب پیام همگانی</b>\n\n"
                    . 'یک دسته را انتخاب کن؛ ارسال بعدی فقط برای همان‌ها می‌رود.', $rows);
                return;
            }
            if ($op === 'pin') {
                set_put('bc_pin', set_bool('bc_pin', false) ? '0' : '1');
                Tg::answer($cbId, set_bool('bc_pin', false) ? 'سنجاق روشن شد' : 'سنجاق خاموش شد',
                    false);
                screen_broadcast($chatId, $messageId);
                return;
            }
            if ($op === 'unpin') {
                $count = 0;
                foreach (DB::all('SELECT tg_id FROM users') as $row) {
                    Tg::unpinAll((int) $row['tg_id']);
                    $count++;
                }
                Tg::answer($cbId, 'سنجاق‌ها برداشته شد', false);
                screen_broadcast($chatId, $messageId, '📌 سنجاق پیام‌ها در '
                    . fa_digits($count) . ' چت برداشته شد.');
                return;
            }
            screen_broadcast($chatId, $messageId);
            return;
        case 'broadcast':
            screen_broadcast($chatId, $messageId);
            return;
    }
    Tg::send($chatId, 'این بخش پیدا نشد.', console_kb());
}

