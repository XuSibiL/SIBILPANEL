<?php
declare(strict_types=1);

/* 14) Review of card-to-card receipts */

/** مقصد کارت رسید: مالک و ادمین‌های ربات، در چت خصوصی */
function receipt_targets(): array
{
    $ids = [];
    $owner = owner_id();
    if ($owner > 0) {
        $ids[] = $owner;
    }
    foreach (admin_ids() as $adminId) {
        if (!in_array($adminId, $ids, true)) {
            $ids[] = $adminId;
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
 * ارسال کارت رسید به «یک» مقصد:
 * تاپیک رسیدهای گروه گزارشات؛ اگر گروه تنظیم نشده باشد، به پیام خصوصی ادمین اصلی.
 * خروجی: [چت, شناسهٔ پیام]
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
    /* کارت (عکس + دکمه‌ها) فقط در چت خصوصی مدیر می‌آید؛ دکمه در گروه کار نمی‌کند */
    $sent = ['', 0];
    foreach (receipt_targets() as $adminId) {
        if ($photo !== '') {
            $msgId = Tg::photoThread($adminId, $photo, $caption, 0, kb_ensure_back(kb_clean($kb)));
        } else {
            $msgId = (int) Tg::send($adminId, $caption, $kb);
        }
        if ($msgId && $sent[1] === 0) {
            $sent = [(string) $adminId, (int) $msgId];
        }
    }
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
    $chat = (string) ($receipt['card_chat'] ?? '');
    $msgId = (int) ($receipt['card_msg'] ?? 0);
    if ($chat === '' || $msgId <= 0) {
        return;
    }
    Tg::editCaption($chat, $msgId, $caption, null);
}

function review_receipt(int $receiptId, bool $approve, int $adminId): array
{
    $receipt = DB::one('SELECT * FROM receipts WHERE id=?', [$receiptId]);
    if ($receipt === null) {
        return [false, 'رسید پیدا نشد'];
    }
    if ($receipt['status'] !== 'pending') {
        return [false, 'این رسید قبلاً بررسی شده است'];
    }
    $order = order_get((int) $receipt['order_id']);
    if ($order === null) {
        return [false, 'سفارش پیدا نشد'];
    }
    DB::exec('UPDATE receipts SET status=?, reviewed_by=?, reviewed_at=? WHERE id=?',
        [$approve ? 'approved' : 'rejected', $adminId, now_ts(), $receiptId]);
    log_payment('card receipt reviewed', [
        'receipt' => $receiptId, 'approve' => $approve, 'admin' => $adminId,
        'tg' => (int) $receipt['tg_id'], 'amount' => (int) $receipt['amount'],
        'ref' => (string) $receipt['ref'],
    ]);
    $stamp = '🕒 ' . fa_ts(now_ts()) . ' · 👮 بررسی: <code>' . $adminId . '</code>';
    if (!$approve) {
        order_mark((int) $order['id'], 'rejected');
        receipt_close_card($receiptId, false, $adminId, $stamp);
        Tg::send((int) $order['tg_id'], '❌ <b>رسید تأیید نشد</b>' . "\n\n"
            . '🧾 سفارش: <code>' . e((string) $order['ref']) . '</code>' . "\n"
            . '💵 مبلغ: ' . money((int) $receipt['amount']) . "\n\n"
            . 'اگر مبلغ را واریز کرده‌ای، رسید را دوباره با پشتیبانی هماهنگ کنید.',
            [[btn('☎️ پشتیبانی', 'menu:support', null, 'primary')],
             [btn('« خانه', 'home', null, 'danger')]]);
        return [true, 'رسید رد شد'];
    }
    $balanceBefore = balance_of((int) $order['tg_id']);
    [$ok, $text] = settle_payment($order, 'card', $adminId);
    $balanceAfter = balance_of((int) $order['tg_id']);
    Tg::send((int) $order['tg_id'], $text, home_kb((int) $order['tg_id']));
    $footer = $stamp;
    if ($ok) {
        $footer .= "\n👛 موجودی کاربر: " . money($balanceAfter)
            . ($balanceBefore !== $balanceAfter ? ' <i>(+' . money($balanceAfter - $balanceBefore) . ')</i>' : '');
    } else {
        $footer .= "\n⚠️ ولی تحویل با خطا مواجه شد — گزارش خطا را ببینید.";
    }
    receipt_close_card($receiptId, $ok, $adminId, $footer);
    return [true, $ok ? 'تأیید و تحویل شد ✅' : 'تأیید شد ولی تحویل خطا داد ⚠️'];
}

function screen_receipts(int|string $chat, ?int $messageId): void
{
    $rows = DB::all("SELECT * FROM receipts WHERE status='pending' ORDER BY id LIMIT 10");
    if (!$rows) {
        Tg::show($chat, $messageId, '🧾 رسید در انتظاری نیست.',
            [[btn('🔎 جستجو با کد پیگیری', 'ad:rsearch', null, 'success')],
             [btn('« مالی', 'ad:finance', null, 'danger')]]);
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
}

