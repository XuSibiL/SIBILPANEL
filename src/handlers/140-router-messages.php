<?php
declare(strict_types=1);

/* 17) Router — messages */

/** ذخیرهٔ پنل با کلید API + تست اتصال + پیام نتیجه */
function panel_save_api(int|string $chat, string $name, string $url, string $apiKey): int
{
    $url = normalize_panel_url($url);
    $count = (int) DB::val('SELECT COUNT(*) FROM pg_panels', [], 0);
    $panelName = trim($name) !== '' ? trim($name) : 'پنل ' . fa_digits($count + 1);
    $panelId = DB::exec('INSERT INTO pg_panels(name, url, api_key, is_default, active, created_at) '
        . 'VALUES(?,?,?,?,1,?)', [$panelName, $url, trim($apiKey), $count === 0 ? 1 : 0, now_ts()]);
    $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
    [$okTest, $note] = $row ? panel_test($row) : [false, 'پنل پیدا نشد'];
    if ($okTest) {
        post_report('🔌 <b>پنل پاسارگارد وصل شد</b>' . "\n"
            . 'نام: ' . e($panelName) . "\n<code>" . e($url) . '</code>', 'panel');
    }
    Tg::send($chat, ($okTest ? '✅ پنل «' : '⚠️ پنل «') . e($panelName) . '» ذخیره شد.' . "\n\n"
        . ($okTest ? '✅ ' : '❌ ') . e($note)
        . ($okTest ? '' : "\n\nکلید یا آدرس را با «🔑 تغییر کلید API» اصلاح کنید."),
        [[btn('🎭 نقش‌های این پنل', 'ad:role:' . $panelId . ':0:0', null, 'success')],
         [btn('➕ محصول روی این پنل', 'ad:cats')],
         [btn('🧪 تست دوباره', 'ad:pg:' . $panelId . ':test'),
          btn('🔌 پنل‌ها', 'ad:panel', null, 'danger')]]);
    return $panelId;
}

function handle_message(array $message): void
{
    Tg::$answered = [];
    try {
        handle_message_inner($message);
    } catch (Throwable $ex) {
        report_exception($ex, (int) ($message['from']['id'] ?? 0), 'message');
    }
}

function handle_message_inner(array $message): void
{
    $uid = (int) ($message['from']['id'] ?? 0);
    $GLOBALS['panel_act_uid'] = $uid;
    $chatId = (int) ($message['chat']['id'] ?? 0);
    if ($uid === 0) {
        return;
    }
    $chatType = (string) ($message['chat']['type'] ?? 'private');
    if (in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
        // در گروه‌ها و کانال‌ها ربات هیچ پیامی نمی‌فرستد
        return;
    }
    $name = trim((string) ($message['from']['first_name'] ?? '') . ' '
        . (string) ($message['from']['last_name'] ?? ''));
    /* «کاربر تازه» یعنی هنوز هیچ رکوردی برایش نبود — ملاک ثبت دعوت و پاداش */
    $isNewUser = user_get($uid) === null;
    $userRow = user_ensure($uid, $name, (string) ($message['from']['username'] ?? ''));
    $text = trim((string) ($message['text'] ?? ''));
    $state = State::get($uid);
    if ((int) ($userRow['is_blocked'] ?? 0) === 1 && !is_admin($uid)) {
        Tg::send($chatId, '⛔️ دسترسی حسابتان بسته شده است. برای پیگیری «☎️ پشتیبانی» را بزنید.',
            home_kb($uid));
        return;
    }

    /* ── قفل عضویت اجباری کانال: تا عضویت تایید نشود هیچ بخشی باز نیست ── */
    if (force_join_blocked($uid)) {
        State::clear($uid);
        force_join_show($chatId, null);
        return;
    }

    /* ── پرداخت موفق استارز ─────────────────────────────────────────── */
    if (isset($message['successful_payment'])) {
        pay_stars_success($message);
        return;
    }

    /* ── /start و /help ─────────────────────────────────────────────── */
    if ($text !== '' && str_starts_with($text, '/start')) {
        $payload = trim(substr($text, 6));
        $referrer = ref_parse_start($payload);
        if ($referrer > 0) {
            /* فقط کاربر تازه دعوت ثبت می‌کند؛ کاربر قدیمی پاداش دوباره نمی‌گیرد */
            referral_credit($referrer, $uid, $isNewUser);
        }
        State::clear($uid);
        show_home($chatId, null, $uid);
        return;
    }
    if ($text === '/help' || $text === '/راهنما') {
        State::clear($uid);
        Tg::send($chatId, "🎓 <b>راهنما</b>\n\nیک بخش را انتخاب کنید یا جستجو کنید.",
            help_home_kb(admin_can($uid, 'full')));
        return;
    }
    if ($text === '/admin' || $text === '/مدیریت') {
        State::clear($uid);
        if (!is_console_admin($uid)) {
            Tg::send($chatId, '⛔️ پنل مدیریت برای شما فعال نیست.');
            return;
        }
        screen_console($chatId, null);
        return;
    }
    if ($text === '/wallet') {
        screen_wallet($chatId, null, $uid);
        return;
    }
    if ($text === '/my' || $text === '/panels') {
        screen_panels($chatId, null, $uid);
        return;
    }
    if ($text === '/version') {
        Tg::send($chatId, 'نسخهٔ ' . e(bot_name()) . ': <code>' . PANEL_VERSION . '</code>');
        return;
    }

    /* ── بازگردانی بکاپ: مالک فایل sibil-backup-*.sqlite3 را می‌فرستد ──── */
    $doc = $message['document'] ?? null;
    if (is_array($doc) && !empty($doc['file_id']) && admin_can($uid, 'full')) {
        $docName = (string) ($doc['file_name'] ?? '');
        $docSize = (int) ($doc['file_size'] ?? 0);
        $isBackupName = preg_match('/\.(sqlite3|db|panelbak)$/i', $docName) === 1
            || str_contains($docName, 'sibil-backup');
        if ($isBackupName) {
            if ($docSize > 45 * 1024 * 1024) {
                Tg::send($chatId, '⚠️ فایل بکاپ بزرگ‌تر از حد تلگرام است (۴۵ مگابایت).');
                return;
            }
            [$stageOk, $stageNote] = backup_restore_stage((string) $doc['file_id'], $docName);
            if (!$stageOk) {
                Tg::send($chatId, '⚠️ ' . $stageNote,
                    [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
                return;
            }
            Tg::send($chatId, $stageNote . "\n" . SEP . "\n"
                . '⚠️ <b>بازگردانی، دادهٔ فعلی را با این بکاپ جایگزین می‌کند.</b>' . "\n"
                . 'پیش از جایگزینی، یک نسخهٔ ایمن از دادهٔ فعلی روی سرور ذخیره می‌شود. '
                . 'ربات چند ثانیه قطع می‌شود و بعد با دادهٔ بکاپ برمی‌گردد.' . "\n\n"
                . 'مطمئنید؟',
                [[btn('♻️ بله، بازگردانی کن', 'ad:restore:ok', null, 'success')],
                 [btn('🔙 انصراف / بازگشت', 'ad:restore:no', null, 'danger')]]);
            return;
        }
    }

    /* ── حالت‌های ذخیره‌شده ─────────────────────────────────────────── */
    if (str_starts_with((string) $state['state'], 'kyc_')) {
        kyc_message_router($message, $state);
        return;
    }
    switch ($state['state']) {
        case 'bc_forward':
            if (in_array(mb_strtolower($text, 'UTF-8'), ['لغو', 'cancel'], true)) {
                State::clear($uid);
                Tg::send($chatId, 'لغو شد.', [[btn('« پیام همگانی', 'ad:bc', null, 'danger')]]);
                return;
            }
            $msgId = (int) ($message['message_id'] ?? 0);
            if ($msgId <= 0) {
                return;
            }
            State::clear($uid);
            $targets = bc_targets(bc_segment());
            if (!$targets) {
                Tg::send($chatId, 'برای این مخاطب کاربری پیدا نشد.',
                    [[btn('🎯 تغییر مخاطب', 'ad:bc:seg', null, 'success')]]);
                return;
            }
            bc_job_start($targets, 'copy', '', $chatId, $msgId, set_bool('bc_pin', false),
                $uid, '🔁 فروارد همگانی تمام شد', BC_SEGMENTS[bc_segment()]);
            Tg::send($chatId, '🚀 <b>فروارد همگانی در پس‌زمینه آغاز شد</b>' . "\n" . SEP . "\n"
                . '🎯 مخاطب: ' . BC_SEGMENTS[bc_segment()] . "\n"
                . '👥 تعداد کل: ' . fa_digits(count($targets)) . "\n\n"
                . '<i>گزارش کامل (تعداد کل / موفق / مسدود شده توسط کاربر) '
                . 'همین‌جا برایت می‌آید.</i>',
                [[btn('« پیام همگانی', 'ad:bc', null, 'success')]]);
            bc_job_dispatch();
            return;

        case 'wiz':
            wiz_advance($message);
            return;

        case 'help_search':
            State::clear($uid);
            $hits = help_matches($text, admin_can($uid, 'full'));
            if (!$hits) {
                Tg::send($chatId, 'چیزی پیدا نشد. یک کلمهٔ دیگر امتحان کنید.',
                    [[btn('« راهنما', 'help:home', null, 'danger')]]);
                return;
            }
            $buttons = [];
            foreach ($hits as $key => $hit) {
                $buttons[] = [btn($hit['title'], 'help:s:' . $key)];
            }
            $buttons[] = [btn('« راهنما', 'help:home', null, 'danger')];
            Tg::send($chatId, '🔎 نتیجهٔ جستجو — یک بخش را باز کنید:', $buttons);
            return;

        case 'discount_order':
            State::clear($uid);
            $orderId = (int) ($state['data']['order_id'] ?? 0);
            $order = order_get($orderId);
            if ($order === null) {
                Tg::send($chatId, 'این سفارش پیدا نشد.', [[btn('« منو', 'home', null, 'danger')]]);
                return;
            }
            if (in_array(mb_strtolower($text, 'UTF-8'), ['لغو', 'cancel'], true)) {
                Tg::show($chatId, null, invoice_text($order, 'پرداخت سفارش'),
                    invoice_kb($order, $uid));
                return;
            }
            [$okApply, $noteApply] = apply_discount_to_order($orderId, $text, $uid);
            $order = order_get($orderId) ?? $order;
            Tg::answer('', '', false);
            Tg::send($chatId, ($okApply ? '✅ ' : '❌ ') . e($noteApply));
            Tg::show($chatId, null, invoice_text($order, 'پرداخت سفارش'), invoice_kb($order, $uid));
            return;

        case 'discount_panel':
            State::clear($uid);
            $panelId = (int) ($state['data']['panel_id'] ?? 0);
            if (in_array(mb_strtolower($text, 'UTF-8'), ['لغو', 'cancel'], true)) {
                Tg::send($chatId, 'لغو شد.', [[btn('« پنل‌ها', 'menu:panels', null, 'danger')]]);
                return;
            }
            [$okPanel, $notePanel] = panel_apply_discount($panelId, $text, $uid);
            Tg::send($chatId, ($okPanel ? '✅ ' : '❌ ') . $notePanel,
                [[btn('« پنل‌های من', 'menu:panels', null, 'success')]]);
            return;

        case 'topup_amount':
            State::clear($uid);
            $amount = to_int($text);
            $min = set_int('min_topup', 50000);
            $max = set_int('max_topup', 20000000);
            if ($amount === null || $amount < $min || $amount > $max) {
                Tg::send($chatId, 'مبلغ نامعتبر. باید بین ' . money($min) . ' و '
                    . money($max) . ' باشد.', [[btn('« کیف پول', 'menu:wallet', null, 'danger')]]);
                return;
            }
            $order = create_order($uid, 'topup', $amount, null, ['topup' => $amount]);
            Tg::send($chatId, invoice_text($order, 'شارژ کیف پول', 'مبلغ: ' . money($amount)),
                invoice_kb($order, $uid));
            return;

        case 'discount_code':
            State::clear($uid);
            $orderId = (int) ($state['data']['order_id'] ?? 0);
            $order = order_get($orderId);
            if ($order === null) {
                Tg::send($chatId, 'سفارش پیدا نشد.', home_kb($uid));
                return;
            }
            $basePrice = (int) $order['payable'] + (int) $order['discount'];
            [$amount, $note] = discount_check($text, $basePrice);
            if ($amount <= 0) {
                Tg::send($chatId, '❌ ' . e($note), [[btn('« بازگشت', 'home', null, 'danger')]]);
                return;
            }
            DB::exec('UPDATE orders SET payable=?, discount=? WHERE id=?',
                [max(0, $basePrice - $amount), $amount, $orderId]);
            DB::exec('UPDATE discounts SET uses = uses + 1 WHERE code=?',
                [strtoupper(trim($text))]);
            $discount = $amount;
            $order = order_get($orderId) ?? $order;
            Tg::send($chatId, '✅ کد اعمال شد.' . "\n" . invoice_text($order, 'پرداخت سفارش',
                'تخفیف: ' . money($discount)), invoice_kb($order, $uid));
            return;

        case 'fj_channel':
            /* ثبت کانال اجباری: تجزیه → بررسی مدیر بودن ربات → ذخیره */
            if (!admin_can($uid, 'full')) {
                State::clear($uid);
                Tg::send($chatId, '⛔️ سطح دسترسی تنظیم کانال را ندارید.');
                return;
            }
            if (in_array(trim($text), ['لغو', 'انصراف', 'cancel', '/cancel'], true)) {
                State::clear($uid);
                Tg::send($chatId, 'انصراف داده شد.', force_channel_admin_kb());
                return;
            }
            /* parse → getChat(type) → getChatMember(admin); شناسهٔ نهایی همیشه canonical است. */
            [$chOk, $chChat, $chUsername, $chLink, $chWhy] = force_channel_validate($text);
            if (!$chOk) {
                Tg::send($chatId, $chWhy . "\n\n" . 'دوباره بفرستید '
                    . 'یا «🔙 انصراف / بازگشت» را بزنید.',
                    [[btn('🔙 انصراف / بازگشت', 'ad:fj', null, 'danger')]]);
                return;
            }
            State::clear($uid);
            set_put('force_channel_id', $chChat);
            set_put('force_channel_username', $chUsername);
            set_put('force_channel_join_link', $chLink);
            set_put('force_channel_on', '1');
            Tg::send($chatId, "✅ <b>کانال اجباری ذخیره و فعال شد</b>\n" . SEP . "\n"
                . 'شناسهٔ عددی: <code>' . e($chChat) . '</code>' . "\n"
                . 'نوع: ' . ($chUsername !== '' ? 'کانال عمومی' : 'کانال/گروه خصوصی') . "\n"
                . 'لینک عضویت: ' . ($chLink !== '' ? '<code>' . e($chLink) . '</code>'
                    : '— (برای نمایش دکمهٔ عضویت، لینک خصوصی را همراه شناسه بفرستید)') . "\n\n"
                . '<i>نوع چت و مدیر بودن ربات تأیید شد؛ قفل برای کاربران فعال است. '
                . 'مالک و ادمین‌ها قفل نمی‌شوند.</i>',
                force_channel_admin_kb());
            return;

        case 'report_group_id':
            /* ثبت گروه گزارشات: اعتبارسنجی (-100…) → پیام تست → ذخیره */
            if (!admin_can($uid, 'full')) {
                State::clear($uid);
                Tg::send($chatId, '⛔️ سطح دسترسی تنظیم گروه گزارشات را ندارید.');
                return;
            }
            if (in_array(trim($text), ['لغو', 'انصراف', 'cancel'], true)) {
                State::clear($uid);
                Tg::send($chatId, 'انصراف داده شد.', console_kb());
                return;
            }
            [$groupIdOk, $groupIdWhy] = report_group_id_check($text);
            if (!$groupIdOk) {
                Tg::send($chatId, '❌ ' . $groupIdWhy . "\n\n" . 'دوباره بفرستید.',
                    [[btn('🔙 انصراف / بازگشت', 'ad:report:setup', null, 'danger')]]);
                return;
            }
            State::clear($uid);
            [$bound, $boundNote] = report_group_setup($text);
            if (!$bound) {
                Tg::send($chatId, '⚠️ ' . $boundNote . "\n\n" . 'شناسه را دوباره بفرستید.',
                    [[btn('🔙 بازگشت به آموزش', 'ad:report:setup', null, 'danger')]]);
                return;
            }
            Tg::send($chatId, $boundNote);
            screen_console($chatId, null);
            return;

        case 'panel_username':
            $ctxName = $state['data'];
            $mode = (string) ($ctxName['mode'] ?? 'buy');
            $productId = (int) ($ctxName['product'] ?? 0);
            [$nameOk, $nameWhy] = panel_username_check($text);
            if (!$nameOk) {
                Tg::send($chatId, '❌ ' . e($nameWhy) . "\n" . 'یک نام دیگر بفرستید.');
                return;
            }
            State::clear($uid);
            if ($mode === 'trial') {
                start_trial_order($uid, $chatId, null, $productId, trim($text));
            } else {
                start_panel_order($uid, $chatId, null, $productId, trim($text));
            }
            return;

        case 'receipt':
            $orderId = (int) ($state['data']['order_id'] ?? 0);
            $order = order_get($orderId);
            if ($order === null) {
                State::clear($uid);
                Tg::send($chatId, 'سفارش پیدا نشد.', home_kb($uid));
                return;
            }
            $photoId = '';
            if (isset($message['photo']) && is_array($message['photo'])) {
                $sizes = $message['photo'];
                $photoId = (string) (end($sizes)['file_id'] ?? '');
            } elseif (isset($message['document']['file_id'])) {
                $doc = $message['document'];
                if (str_starts_with((string) ($doc['mime_type'] ?? ''), 'image/')) {
                    $photoId = (string) $doc['file_id'];
                }
            }
            if ($photoId === '') {
                Tg::send($chatId, '📷 لطفاً <b>عکس رسید</b> را بفرستید (یا فایل تصویری).');
                return;
            }
            State::clear($uid);
            $receiptId = DB::exec('INSERT INTO receipts(tg_id, order_id, amount, photo_id, ref, '
                . 'status, created_at) VALUES(?,?,?,?,?,?,?)',
                [$uid, $orderId, (int) $order['payable'], $photoId, (string) $order['ref'],
                 'pending', now_ts()]);
            DB::exec("UPDATE orders SET status='review' WHERE id=?", [$orderId]);
            log_payment('card receipt submitted', [
                'receipt' => $receiptId, 'order' => $orderId, 'tg' => $uid,
                'amount' => (int) $order['payable'], 'ref' => (string) $order['ref'],
            ]);
            receipt_post_card($receiptId);
            Tg::send($chatId, '✅ <b>رسیدت ثبت شد</b>' . "\n\n"
                . '🧾 کد پیگیری: <code>' . e((string) $order['ref']) . '</code>' . "\n"
                . '💵 مبلغ: <b>' . money((int) $order['payable']) . '</b>' . "\n"
                . '⏳ وضعیت: در انتظار تأیید مدیر' . "\n\n"
                . '<i>به‌محض تأیید، همین‌جا خبرت می‌کنیم. مهلتی هم در کار نیست.</i>',
                [[btn('🔎 پیگیری با کد', 'menu:track', null, 'success')],
                 [btn('« خانه', 'home', null, 'danger')]]);
            return;

    }

    /* ── پیام آزاد: ربات فقط به /start و دستورهای خودش جواب می‌دهد ──── */
    if ($text !== '' && str_starts_with($text, '/')) {
        Tg::send($chatId, 'این دستور را نمی‌شناسم. دستورها: /start', home_kb($uid));
    }
    /* غیر از این، ربات ساکت می‌ماند و به پیام‌های آزاد جواب نمی‌دهد */
}

/**
 * ثبت دعوت و واریز قطعی پاداش.
 *  · فقط کاربر تازه ثبت می‌شود (کاربر قدیمی معرف عوض نمی‌کند)
 *  · کسی با لینک خودش عضو نمی‌شود
 *  · حالت «نقدی ثابت» → همان لحظهٔ عضویت به کیف پول معرف
 *  · حالت «درصدی» → با اولین/دومین واریزی زیرمجموعه (در settle_payment)
 * خروجی: مبلغ پاداش نقدی پرداخت‌شده (۰ = پاداش نقدی نبود یا قبلاً پرداخت شده).
 */
function referral_credit(int $referrerId, int $newUserId, bool $isNewUser = true): int
{
    if ($referrerId <= 0 || $newUserId <= 0 || $referrerId === $newUserId) {
        return 0;   // دعوت خود
    }
    if (!set_bool('ref_enabled', true)) {
        return 0;
    }
    if (!$isNewUser) {
        return 0;   // فقط عضو جدید
    }
    if (user_get($referrerId) === null) {
        return 0;   // معرف در ربات نیست
    }
    $user = user_get($newUserId);
    if ($user === null) {
        return 0;
    }
    $current = (int) ($user['referred_by'] ?? 0);
    if ($current > 0 && $current !== $referrerId) {
        return 0;   // قبلاً به کسی نسبت داده شده
    }
    DB::exec('INSERT OR IGNORE INTO settings(key, value) VALUES(?,?)',
        ['_ref_' . $newUserId, (string) $referrerId]);
    $attached = DB::update('UPDATE users SET referred_by=? WHERE tg_id=? '
        . 'AND (referred_by IS NULL OR referred_by=0)', [$referrerId, $newUserId]);
    if ($attached === 0) {
        return 0;   // پیش‌تر ثبت شده؛ پاداش دوباره پرداخت نمی‌شود
    }
    log_line('info', 'referral joined', ['referrer' => $referrerId, 'invited' => $newUserId]);
    post_report('👥 <b>دعوت تازه</b>' . "\n"
        . '👤 معرف: <code>' . $referrerId . "</code>\n"
        . '🆕 دعوت‌شده: <code>' . $newUserId . '</code>', 'sales');
    if (ref_mode() !== 'cash' || ref_cash_when() !== 'join') {
        return 0;   // درصدی است، یا مدیر گفته با اولین خرید پرداخت شود
    }
    /* پاداش نقدی ثابت: همان لحظهٔ عضویت، با پیام اعلان برای معرف */
    try {
        return referral_welcome_cash($newUserId);
    } catch (Throwable $ex) {
        log_line('warning', 'referral welcome cash failed: ' . $ex->getMessage());
        return 0;
    }
}

/** اصلی‌ترین نقطهٔ ورود یک آپدیت */
function handle_update(array $update): void
{
    if (isset($update['callback_query'])) {
        try {
            handle_callback($update['callback_query']);
        } catch (Throwable $ex) {
            log_line('error', 'callback failed: ' . $ex->getMessage(),
                ['data' => $update['callback_query']['data'] ?? '']);
        }
        return;
    }
    if (isset($update['message'])) {
        try {
            handle_message($update['message']);
        } catch (Throwable $ex) {
            log_line('error', 'message failed: ' . $ex->getMessage());
            $uid = (int) ($update['message']['from']['id'] ?? 0);
            if ($uid) {
                Tg::send($uid, '⚠️ خطایی رخ داد. دوباره تلاش کنید یا /start بزنید.');
            }
        }
    }
}

