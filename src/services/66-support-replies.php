<?php
declare(strict_types=1);

/* 6.5) پاسخ پشتیبانی داخل ربات
   ─────────────────────────────────────────────────────────────────────────
   پیام‌های خروجیِ انسانی با ticket یک‌بارمصرفِ متعلق به همان کاربر جفت می‌شوند.
   پس callback صرفاً «شناسهٔ یک ادمین» نیست: حدس‌زدن یا دستکاریِ آن نمی‌تواند
   کاربر دیگری را به گفتگو وصل کند یا مقصد دریافت پاسخ را افشا کند.
*/

const SUPPORT_REPLY_TICKET_TTL = 2592000; // ۳۰ روز

/** آیا این حساب اکنون اجازهٔ دریافت/ارسال پاسخ پشتیبانی دارد؟ */
function support_reply_admin_active(int $adminId): bool
{
    return $adminId > 0 && admin_is_console($adminId) && admin_can($adminId, 'support');
}

/** اولین مسئول فعال در صورت حذف‌شدن نقش مسئول اصلی گفتگو. */
function support_reply_fallback_admin(): int
{
    $primary = primary_owner_id();
    if (support_reply_admin_active($primary)) {
        return $primary;
    }
    foreach (admin_role_rows() as $row) {
        $id = (int) ($row['tg_id'] ?? 0);
        if (support_reply_admin_active($id)) {
            return $id;
        }
    }
    return 0;
}

/** مقصد پاسخ باید یک مسئول فعال باشد؛ ادمین عملیاتیِ غیرپشتیبان به fallback می‌رود. */
function support_reply_recipient(int $requestedAdminId): int
{
    return support_reply_admin_active($requestedAdminId)
        ? $requestedAdminId : support_reply_fallback_admin();
}

/** ticket جدید را فقط برای گیرندهٔ واقعی پیام ثبت می‌کند. */
function support_reply_create_ticket(int $userId, int $requestedAdminId): int
{
    $adminId = support_reply_recipient($requestedAdminId);
    if ($userId <= 0 || $adminId <= 0) {
        return 0;
    }
    $now = now_ts();
    return DB::exec('INSERT INTO support_reply_tickets(tg_id, admin_id, created_at, expires_at) '
        . 'VALUES(?,?,?,?)', [$userId, $adminId, $now, $now + SUPPORT_REPLY_TICKET_TTL]);
}

/** callback کوتاه و قابل‌بررسی برای دکمهٔ زیر پیام ادمین. */
function support_reply_callback_data(int $ticketId): string
{
    return 'reply_support_' . max(0, $ticketId);
}

/** ticket فقط برای صاحبش، تا پیش از انقضا و پیش از مصرف، معتبر است. */
function support_reply_ticket_for_user(int $userId, int $ticketId): ?array
{
    if ($userId <= 0 || $ticketId <= 0) {
        return null;
    }
    return DB::one('SELECT * FROM support_reply_tickets '
        . 'WHERE id=? AND tg_id=? AND consumed_at IS NULL AND expires_at>?',
        [$ticketId, $userId, now_ts()]);
}

/** کیبورد موجود را نگه می‌دارد و کنترل پاسخ را به ردیف آخر اضافه می‌کند. */
function support_reply_keyboard(array $keyboard, int $ticketId): array
{
    if ($ticketId <= 0) {
        return $keyboard;
    }
    $keyboard[] = [btn('✍️ پاسخ به پیام / پشتیبانی',
        support_reply_callback_data($ticketId), null, 'success')];
    return $keyboard;
}

/**
 * مسیر استاندارد همهٔ پیام‌های مستقیمِ حاصل از اقدام ادمین/پشتیبانی.
 * در نبود مسئول فعال، خود اطلاع‌رسانی از بین نمی‌رود؛ فقط امکان پاسخ امن ندارد.
 */
function support_send_admin_message(int $adminId, int $userId, string $text, array $keyboard = []): bool
{
    $text = trim($text);
    if ($userId <= 0 || $text === '') {
        return false;
    }
    $ticketId = support_reply_create_ticket($userId, $adminId);
    $sent = (int) Tg::send($userId, $text, support_reply_keyboard($keyboard, $ticketId)) > 0;
    if (!$sent && $ticketId > 0) {
        DB::exec('DELETE FROM support_reply_tickets WHERE id=?', [$ticketId]);
    }
    return $sent;
}

/** شروع پاسخ از سمت کاربر؛ state فقط ticket را نگه می‌دارد، نه مقصد قابل جعل را. */
function support_reply_start(int $userId, int $ticketId): array
{
    $ticket = support_reply_ticket_for_user($userId, $ticketId);
    if ($ticket === null) {
        return [false, 'این گفتگو دیگر در دسترس نیست. از بخش پشتیبانی دوباره اقدام کنید.'];
    }
    State::set($userId, 'awaiting_support_reply', [
        'ticket_id' => $ticketId,
        'opened_at' => now_ts(),
    ]);
    return [true, '✍️ <b>پاسخ به پشتیبانی</b>' . "\n\n"
        . 'پیامتان را همین‌جا به‌صورت متن بفرستید؛ مستقیم برای مسئول پشتیبانی ارسال می‌شود.'];
}

/** متن بایگانی‌شده و خصوصیِ پاسخ کاربر برای مسئول پشتیبانی. */
function support_reply_admin_text(int $userId, string $text, int $sentAt = 0, int $ticketId = 0): string
{
    $user = user_get($userId) ?? [];
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        $name = 'کاربر تلگرام';
    }
    $username = trim(ltrim((string) ($user['uname'] ?? ''), '@'));
    $when = $sentAt > 0 ? $sentAt : now_ts();
    return '✉️ <b>پاسخ جدید کاربر به پشتیبانی</b>' . "\n" . SEP . "\n"
        . 'وضعیت: <b>پاسخ جدید</b>' . "\n"
        . 'زمینه: پاسخ به پیام مستقیم پشتیبانی' . ($ticketId > 0 ? ' #' . $ticketId : '') . "\n"
        . '👤 نام: <b>' . e(mb_substr($name, 0, 80)) . "</b>\n"
        . '🆔 شناسهٔ عددی: <code>' . $userId . "</code>\n"
        . '🔗 نام کاربری: ' . ($username !== '' ? '<code>@' . e($username) . '</code>' : '—') . "\n"
        . '🕒 زمان ارسال: ' . fa_ts($when) . "\n"
        . SEP . "\n"
        . '💬 پیام:' . "\n" . e($text);
}

/** تحویل متن کاربر به همان مسئول یا جایگزین امن؛ state جعل‌شده هم ticket را دور نمی‌زند. */
function support_reply_deliver(int $userId, array $message, array $state): array
{
    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        return [false, 'لطفاً پاسخ را به‌صورت <b>متن</b> بفرستید یا لغو کنید.'];
    }
    $ticketId = (int) ($state['data']['ticket_id'] ?? 0);
    $ticket = support_reply_ticket_for_user($userId, $ticketId);
    if ($ticket === null) {
        State::clear($userId);
        return [false, 'این گفتگو منقضی یا بسته شده است. از بخش پشتیبانی دوباره اقدام کنید.'];
    }
    $adminId = support_reply_recipient((int) $ticket['admin_id']);
    if ($adminId <= 0) {
        return [false, 'مسئول پشتیبانی فعالی پیدا نشد. کمی بعد دوباره تلاش کنید.'];
    }
    $sentAt = (int) ($message['date'] ?? 0);
    $body = support_reply_admin_text($userId, $text, $sentAt, $ticketId);
    $buttons = [[btn('✍️ پاسخ به کاربر', 'ad:u:' . $userId . ':msg', null, 'success')]];
    $delivered = (int) Tg::send($adminId, $body, $buttons) > 0;
    /* نسخهٔ بایگانی‌شده برای گروه/تاپیک گزارشات؛ نبودن گروه مانع پیوی ادمین نیست. */
    post_report($body, 'support');
    if (!$delivered) {
        log_line('warning', 'support reply delivery failed', ['user' => $userId, 'admin' => $adminId]);
        return [false, 'ارسال به پشتیبانی انجام نشد؛ لطفاً دوباره تلاش کنید.'];
    }
    DB::exec('UPDATE support_reply_tickets SET consumed_at=? WHERE id=? AND tg_id=? AND consumed_at IS NULL',
        [now_ts(), $ticketId, $userId]);
    State::clear($userId);
    return [true, '✅ پاسختان برای پشتیبانی ارسال شد.'];
}
