<?php
declare(strict_types=1);

/* 14) Review of card-to-card receipts */

/**
 * مقصد کارت رسید فقط مالک اصلی، هم‌مالک و ادمین مالی است؛ سایر نقش‌ها هرگز
 * عکس رسید یا callback تأیید پرداخت دریافت نمی‌کنند.
 */
function receipt_targets(): array
{
    $ids = [];
    foreach (admin_role_rows() as $row) {
        $id = (int) ($row['tg_id'] ?? 0);
        $role = (string) ($row['role'] ?? '');
        if ($id > 0 && in_array($role, ['owner', ADMIN_ROLE_COOWNER, ADMIN_ROLE_FINANCE], true)
            && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** نام نمایشی کاربر برای کارت رسید */
function receipt_who(int $tgId): array
{
    $user = user_get($tgId);
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        $name = 'کاربر تلگرام';
    }
    $uname = trim((string) ($user['uname'] ?? ''));
    return [mb_substr($name, 0, 24), $uname !== '' ? '@' . $uname : '—'];
}

/** نام فارسی روش پرداخت — هیچ‌جا نام خام انگلیسی به کاربر نشان داده نمی‌شود */
function pay_source_label(string $source): string
{
    $source = trim($source);
    if ($source === '') {
        return 'نامشخص';
    }
    if (isset(PAY_GATEWAYS[$source])) {
        return (string) PAY_GATEWAYS[$source]['label'];
    }
    return match ($source) {
        'wallet'      => 'کیف پول',
        'receipt'     => 'کارت به کارت (رسید)',
        'admin'       => 'تأیید ادمین',
        'admin-late'  => 'تأیید ادمین (بعد از انقضا)',
        'stars'       => 'Telegram Stars',
        default       => $source,
    };
}

function receipt_kind_label(array $order): string
{
    return match ((string) ($order['kind'] ?? '')) {
        'topup'  => '💰 افزایش موجودی',
        'panel'  => '🛒 خرید پنل',
        'slot'   => '🧩 خرید اسلات',
        'trial'  => '🧪 پنل تست',
        'users'  => '➕ افزایش کاربر',
        'renew'  => '♻️ تمدید پنل',
        default  => '💳 پرداخت',
    };
}

function receipt_method_label(array $order): string
{
    return (string) ($order['channel'] ?? '') === 'gateway' ? '🌐 درگاه آنلاین' : '💳 کارت به کارت';
}

/**
 * متن کارت رسید.
 * $state: pending | approved | rejected
 */
function receipt_card_text(array $receipt, array $order, string $state = 'pending',
                           string $footer = ''): string
{
    [$name, $uname] = receipt_who((int) $receipt['tg_id']);
    $line = str_repeat('─', 14);
    $ref = trim((string) ($receipt['ref'] !== null && $receipt['ref'] !== ''
        ? $receipt['ref'] : ($order['ref'] ?? '')));
    $text = '🧾 <b>رسید پرداخت</b> · <code>#' . (int) $receipt['id'] . '</code>' . "\n"
        . $line . "\n"
        . '👤 کاربر: <b>' . e($name) . '</b> · <code>' . (int) $receipt['tg_id'] . '</code>' . "\n";
    if ($uname !== '' && $uname !== '—') {
        $text .= '🔗 نام کاربری: ' . e($uname) . "\n";
    }
    if ($ref !== '') {
        $text .= '🛒 کد سفارش: <code>' . e($ref) . '</code>' . "\n";
    }
    $text .= '📦 بابت: ' . receipt_kind_label($order) . "\n"
        . '💵 مبلغ: <b>' . money((int) $receipt['amount']) . '</b>' . "\n"
        . '💳 روش: ' . receipt_method_label($order) . "\n"
        . '🕒 ثبت: ' . fa_ts((int) $receipt['created_at']) . "\n"
        . $line . "\n";
    if ($state === 'approved') {
        $text .= '✅ <b>پرداخت تأیید شد</b>';
    } elseif ($state === 'rejected') {
        $text .= '❌ <b>پرداخت رد شد</b>';
    } else {
        $text .= '⏳ <b>در انتظار بررسی</b>';
    }
    if ($footer !== '') {
        $text .= "\n" . $footer;
    }
    return $text;
}

function receipt_card_kb(int $receiptId): array
{
    return [[
        btn('✅ تأیید پرداخت', 'ad:rc:' . $receiptId . ':ok', null, 'success'),
        btn('❌ رد پرداخت', 'ad:rc:' . $receiptId . ':no', null, 'danger'),
    ]];
}

/**
 * ارسال کارت رسید به همهٔ مسئولان مجاز در چت خصوصی؛ هر کارت جداگانه ثبت می‌شود
 * تا تأیید یا رد در تمام مقصدها بسته شود. خروجی: نخستین [چت، شناسهٔ پیام].
 */
function receipt_post_card(int $receiptId): array
{
    $receipt = DB::one('SELECT * FROM receipts WHERE id=?', [$receiptId]);
    $order = $receipt !== null ? order_get((int) $receipt['order_id']) : null;
    if ($receipt === null || $order === null) {
        return ['', 0];
    }
    $caption = receipt_card_text($receipt, $order, 'pending');
    $kb = receipt_card_kb($receiptId);
    $photo = (string) ($receipt['photo_id'] ?? '');
    $type = $photo !== '' ? 'photo' : 'text';

    /* در retry احتمالی، پیام دوباره برای همان مسئول ارسال نشود. */
    $known = [];
    foreach (DB::all('SELECT chat_id, message_id, content_type FROM receipt_cards WHERE receipt_id=?',
        [$receiptId]) as $card) {
        $chat = (string) ($card['chat_id'] ?? '');
        $msg = (int) ($card['message_id'] ?? 0);
        if ($chat !== '' && $msg > 0) {
            $known[$chat] = ['message_id' => $msg, 'content_type' => (string) ($card['content_type'] ?? 'photo')];
        }
    }
    /* رسیدهای ثبت‌شده با نسخه‌های قدیمی فقط یک card_chat/card_msg دارند. */
    $legacyChat = (string) ($receipt['card_chat'] ?? '');
    $legacyMsg = (int) ($receipt['card_msg'] ?? 0);
    if ($legacyChat !== '' && $legacyMsg > 0 && !isset($known[$legacyChat])) {
        $known[$legacyChat] = ['message_id' => $legacyMsg, 'content_type' => $type];
        DB::exec('INSERT OR IGNORE INTO receipt_cards(receipt_id, chat_id, message_id, content_type) VALUES(?,?,?,?)',
            [$receiptId, $legacyChat, $legacyMsg, $type]);
    }

    $sent = ['', 0];
    foreach (receipt_targets() as $adminId) {
        $chat = (string) $adminId;
        if (isset($known[$chat])) {
            if ($sent[1] === 0) {
                $sent = [$chat, (int) $known[$chat]['message_id']];
            }
            continue;
        }
        if ($photo !== '') {
            $msgId = Tg::photoThread($adminId, $photo, $caption, 0, kb_ensure_back(kb_clean($kb)));
        } else {
            $msgId = (int) Tg::send($adminId, $caption, $kb);
        }
        if ($msgId <= 0) {
            continue;
        }
        DB::exec('INSERT OR IGNORE INTO receipt_cards(receipt_id, chat_id, message_id, content_type) VALUES(?,?,?,?)',
            [$receiptId, $chat, $msgId, $type]);
        $known[$chat] = ['message_id' => $msgId, 'content_type' => $type];
        if ($sent[1] === 0) {
            $sent = [$chat, $msgId];
        }
    }
    /* ستون‌های قدیمی برای سازگاری و بازیابی یک کارت نمونه باقی می‌مانند. */
    if ($sent[1] > 0) {
        DB::exec('UPDATE receipts SET card_chat=?, card_msg=? WHERE id=?',
            [$sent[0], $sent[1], $receiptId]);
    }
    /* و فقط یک گزارش متنی، بدون دکمه، در تاپیک رسیدها */
    [$name, $uname] = receipt_who((int) $receipt['tg_id']);
    post_report('🧾 <b>رسید تازه #' . $receiptId . '</b>' . "\n"
        . '👤 ' . e($name) . ' · <code>' . (int) $receipt['tg_id'] . '</code>' . "\n"
        . '🔗 ' . e($uname) . "\n"
        . '📦 ' . receipt_kind_label($order) . ' · ' . money((int) $receipt['amount']) . "\n"
        . '🕒 ' . fa_ts((int) $receipt['created_at']) . "\n"
        . '<i>عکس رسید و دکمه‌های تأیید در چت خصوصی ربات آمده است.</i>', 'receipt');
    return $sent;
}

/** پایان کار رسید: کارت را همان‌جا به‌روز می‌کند (پیام تکراری ساخته نمی‌شود) */
function receipt_close_card(int $receiptId, bool $approve, int $adminId, string $footer = ''): void
{
    $receipt = DB::one('SELECT * FROM receipts WHERE id=?', [$receiptId]);
    $order = $receipt !== null ? order_get((int) $receipt['order_id']) : null;
    if ($receipt === null || $order === null) {
        return;
    }
    $caption = receipt_card_text($receipt, $order, $approve ? 'approved' : 'rejected', $footer);
    $cards = DB::all('SELECT chat_id, message_id, content_type FROM receipt_cards WHERE receipt_id=?', [$receiptId]);
    /* fallback برای رسیدهای ساخته‌شده پیش از جدول receipt_cards */
    if ($cards === []) {
        $chat = (string) ($receipt['card_chat'] ?? '');
        $msgId = (int) ($receipt['card_msg'] ?? 0);
        if ($chat !== '' && $msgId > 0) {
            $cards[] = ['chat_id' => $chat, 'message_id' => $msgId,
                'content_type' => (string) ($receipt['photo_id'] ?? '') !== '' ? 'photo' : 'text'];
        }
    }
    foreach ($cards as $card) {
        $chat = (string) ($card['chat_id'] ?? '');
        $msgId = (int) ($card['message_id'] ?? 0);
        if ($chat === '' || $msgId <= 0) {
            continue;
        }
        if ((string) ($card['content_type'] ?? 'photo') === 'text') {
            Tg::edit($chat, $msgId, $caption, []);
        } else {
            Tg::editCaption($chat, $msgId, $caption, null);
        }
    }
}

/** Apply manual-card cashback at most once, after approved order delivery. */
function receipt_apply_cashback_once(int $receiptId): int
{
    return DB::tx(static function () use ($receiptId): int {
        $receipt = DB::one('SELECT * FROM receipts WHERE id=?', [$receiptId]);
        if ($receipt === null || (string) $receipt['status'] !== 'approved') {
            return 0;
        }
        $note = (string) ($receipt['note'] ?? '');
        if (str_contains($note, '[cashback-paid:')) {
            return 0;
        }
        $percent = gw_cashback('card');
        $reward = $percent > 0 ? intdiv((int) $receipt['amount'] * $percent, 100) : 0;
        if ($reward <= 0) {
            return 0;
        }
        $tag = '[cashback-paid:' . $reward . ']';
        $newNote = trim($note . ($note === '' ? '' : "\n") . $tag);
        $changed = DB::update("UPDATE receipts SET note=? WHERE id=? AND status='approved'",
            [$newNote, $receiptId]);
        if ($changed !== 1) {
            return 0;
        }
        add_balance((int) $receipt['tg_id'], $reward, 'ref', 'card-receipt:' . $receiptId,
            'کش‌بک ' . $percent . '٪ کارت‌به‌کارت');
        return $reward;
    });
}

function review_receipt(int $receiptId, bool $approve, int $adminId): array
{
    /* دفاع لایه‌ای: حتی فراخوانی مستقیم این سرویس هم فقط برای نقش رسید است. */
    if (!admin_can($adminId, 'receipt')) {
        return [false, 'دسترسی بررسی رسید ندارید.'];
    }
    /* Claiming a receipt and reserving today's card capacity share one
       BEGIN IMMEDIATE transaction. Two finance operators therefore cannot both
       approve the last available amount of the daily ceiling. */
    $claim = DB::tx(static function () use ($receiptId, $approve, $adminId): array {
        $receipt = DB::one('SELECT * FROM receipts WHERE id=?', [$receiptId]);
        if ($receipt === null) {
            return ['state' => 'missing'];
        }
        if ((string) $receipt['status'] !== 'pending') {
            return ['state' => 'already', 'receipt' => $receipt];
        }
        $order = order_get((int) $receipt['order_id']);
        if ($order === null) {
            return ['state' => 'order-missing'];
        }
        if ($approve && !gw_daily_capacity_available('card', (int) $receipt['amount'])) {
            return ['state' => 'limit', 'receipt' => $receipt, 'order' => $order];
        }
        $claimed = DB::update("UPDATE receipts SET status=?, reviewed_by=?, reviewed_at=? "
            . "WHERE id=? AND status='pending'",
            [$approve ? 'approved' : 'rejected', $adminId, now_ts(), $receiptId]);
        if ($claimed !== 1) {
            return ['state' => 'already'];
        }
        /* A receipt is evidence submitted before the deadline. Normalize old
           rows that predate the review status so delivery never treats it as a
           newly-expired pending invoice. */
        DB::update("UPDATE orders SET status='review' WHERE id=? AND status='pending'", [(int) $order['id']]);
        $order = order_get((int) $order['id']) ?? $order;
        return ['state' => 'claimed', 'receipt' => DB::one('SELECT * FROM receipts WHERE id=?', [$receiptId]) ?? $receipt,
            'order' => $order];
    });
    $claimState = (string) ($claim['state'] ?? 'missing');
    if ($claimState === 'missing') {
        return [false, 'رسید پیدا نشد'];
    }
    if ($claimState === 'order-missing') {
        return [false, 'سفارش پیدا نشد'];
    }
    if ($claimState === 'limit') {
        return [false, 'سقف تراکنشِ تأییدشدهٔ امروز برای کارت‌به‌کارت پر شده است؛ رسید همچنان در انتظار بررسی ماند.'];
    }
    if ($claimState !== 'claimed') {
        return [false, 'این رسید قبلاً بررسی شده است'];
    }
    $receipt = $claim['receipt'];
    $order = $claim['order'];
    log_payment('card receipt reviewed', [
        'receipt' => $receiptId, 'approve' => $approve, 'admin' => $adminId,
        'tg' => (int) $receipt['tg_id'], 'amount' => (int) $receipt['amount'],
        'ref' => (string) $receipt['ref'],
    ]);
    $stamp = '🕒 ' . fa_ts(now_ts()) . ' · 👮 بررسی: <code>' . $adminId . '</code>';
    if (!$approve) {
        order_mark((int) $order['id'], 'rejected');
        receipt_close_card($receiptId, false, $adminId, $stamp);
        support_send_admin_message($adminId, (int) $order['tg_id'],
            '❌ <b>رسید تأیید نشد</b>' . "\n\n"
            . '🧾 سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n"
            . '💵 مبلغ: ' . money((int) $receipt['amount']) . "\n\n"
            . 'اگر مبلغ را واریز کرده‌ای، رسید را دوباره با پشتیبانی هماهنگ کنید.',
            [[btn('☎️ پشتیبانی', 'menu:support', null, 'primary')],
             [btn('« خانه', 'home', null, 'danger')]]);
        return [true, 'رسید رد شد'];
    }
    $balanceBefore = balance_of((int) $order['tg_id']);
    [$ok, $text] = settle_payment($order, 'card', $adminId);
    $reward = $ok ? receipt_apply_cashback_once($receiptId) : 0;
    $balanceAfter = balance_of((int) $order['tg_id']);
    if ($reward > 0) {
        $text .= "\n🎁 " . money($reward) . ' کش‌بک کارت‌به‌کارت به کیف پولتان اضافه شد.';
    }
    support_send_admin_message($adminId, (int) $order['tg_id'], $text, home_kb((int) $order['tg_id']));
    $footer = $stamp;
    if ($ok) {
        $footer .= "\n👛 موجودی کاربر: " . money($balanceAfter)
            . ($balanceBefore !== $balanceAfter ? ' <i>(+' . money($balanceAfter - $balanceBefore) . ')</i>' : '');
    } else {
        $footer .= "\n⚠️ ولی تحویل با خطا مواجه شد — گزارش خطا را ببینید.";
    }
    /* The money receipt remains approved even when downstream panel delivery
       needs repair; showing it as rejected would invite a duplicate payment. */
    receipt_close_card($receiptId, true, $adminId, $footer);
    return [true, $ok ? 'تأیید و تحویل شد ✅' : 'تأیید شد ولی تحویل خطا داد ⚠️'];
}

function screen_receipts(int|string $chat, ?int $messageId): void
{
    $full = admin_can(console_actor_id(), 'full');
    $backData = $full ? 'ad:finance' : 'ad:home';
    $backText = $full ? '« مالی' : '« مدیریت';
    $rows = DB::all("SELECT * FROM receipts WHERE status='pending' ORDER BY id LIMIT 10");
    if (!$rows) {
        Tg::show($chat, $messageId, '🧾 رسید در انتظاری نیست.',
            [[btn('🔎 جستجو با کد پیگیری', 'ad:rsearch', null, 'success')],
             [btn($backText, $backData, null, 'danger')]]);
        return;
    }
    Tg::send($chat, '🔎 اگر رسید مشخصی را می‌خواهی، با کد پیگیری جستجو کنید.',
        [[btn('🔎 جستجوی رسید', 'ad:rsearch', null, 'success')]]);
    foreach ($rows as $row) {
        $caption = '🧾 رسید #' . $row['id'] . ' — سفارش #' . $row['order_id'] . "\n"
            . 'کاربر <code>' . $row['tg_id'] . '</code> | ' . money((int) $row['amount']) . "\n"
            . 'کد: <code>' . e((string) $row['ref']) . '</code>';
        $markup = [[
            btn('✅ تأیید', 'ad:rc:' . $row['id'] . ':ok', null, 'success'),
            btn('❌ رد', 'ad:rc:' . $row['id'] . ':no', null, 'danger'),
        ]];
        if (!empty($row['photo_id'])) {
            Tg::photo($chat, (string) $row['photo_id'], $caption, $markup);
        } else {
            Tg::send($chat, $caption, $markup);
        }
    }
    Tg::send($chat, 'برای بازگشت به منوی مدیریت:',
        [[btn($backText, $backData, null, 'danger')]]);
}

