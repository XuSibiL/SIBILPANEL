<?php
declare(strict_types=1);

/*
   12) FINANCE — دفتر کیف پول · کارت‌به‌کارت و بررسی رسید · کدهای تخفیف ·
       گزارش فروش دوره‌ای · گزارش تراکنش‌ها · بازپرداخت · کانال گزارش.
*/

const FIN_PERIODS = ['today' => ['امروز', 1], '7' => ['۷ روز', 7],
                     '30' => ['۳۰ روز', 30], 'all' => ['کل', 0]];

function period_start(int $days): int
{
    if ($days <= 0) {
        return 0;
    }
    if ($days === 1) {
        $offset = (int) IRAN_OFFSET;
        $localNow = time() + $offset;
        return $localNow - ($localNow % DAY) - $offset;
    }
    return now_ts() - $days * DAY;
}

function finance_stats(int $days): array
{
    $since = period_start($days);
    $where = $since ? ' AND paid_at>=?' : '';
    $params = $since ? [$since] : [];
    $sales = (int) DB::val("SELECT COALESCE(SUM(payable),0) FROM orders "
        . "WHERE status='delivered'$where", $params, 0);
    $count = (int) DB::val("SELECT COUNT(*) FROM orders WHERE status='delivered'$where",
        $params, 0);
    $txWhere = $since ? ' AND created_at>=?' : '';
    return [
        'sales'   => $sales,
        'count'   => $count,
        'topups'  => (int) DB::val("SELECT COALESCE(SUM(amount),0) FROM wallet_tx "
            . "WHERE kind='topup' AND amount>0$txWhere", $params, 0),
        'usage'   => (int) DB::val('SELECT COALESCE(SUM(amount),0) FROM usage_charges '
            . 'WHERE amount>0' . $txWhere, $params, 0),
        'refunds' => (int) DB::val("SELECT COALESCE(SUM(amount),0) FROM wallet_tx "
            . "WHERE kind='refund' AND amount>0$txWhere", $params, 0),
    ];
}

function wallets_total(): int
{
    return (int) DB::val('SELECT COALESCE(SUM(balance),0) FROM users', [], 0);
}

function sales_by_product(int $days, int $limit = 5): array
{
    $since = period_start($days);
    $sql = "SELECT o.product_id, COALESCE(p.name,'حذف‌شده') AS name, COUNT(*) AS n, "
        . 'COALESCE(SUM(o.payable),0) AS total FROM orders o '
        . "LEFT JOIN products p ON p.id=o.product_id WHERE o.status='delivered'";
    $params = [];
    if ($since) {
        $sql .= ' AND o.paid_at>=?';
        $params[] = $since;
    }
    $sql .= ' GROUP BY o.product_id ORDER BY total DESC LIMIT ' . (int) $limit;
    return DB::all($sql, $params);
}

function screen_finance(int|string $chat, ?int $messageId): void
{
    $today = finance_stats(1);
    $week = finance_stats(7);
    $total = finance_stats(0);
    $pending = (int) DB::val("SELECT COUNT(*) FROM receipts WHERE status='pending'", [], 0);
    $openOrders = (int) DB::val("SELECT COUNT(*) FROM orders "
        . "WHERE status IN ('pending','partial','review')", [], 0);
    $text = "💳 <b>مالی</b>\n\n"
        . '📈 امروز: <b>' . money($today['sales']) . '</b> (' . $today['count'] . " سفارش)\n"
        . '📅 ۷ روز: ' . money($week['sales']) . "\n"
        . '💰 فروش کل: <b>' . money($total['sales']) . "</b>\n"
        . '👛 کیف پول کاربران: ' . money(wallets_total()) . "\n"
        . '🧾 رسید در انتظار: ' . $pending . ' · 🛒 سفارش باز: ' . $openOrders;
    /* بخش مالی
       PaySetting → 💳 درگاه‌ها · Discount → 🎁 کد هدیه ·
       Payment_report → 🧾 گزارش پرداخت‌ها · کیف پول کاربران · minbalance/maxbalance */
    Tg::show($chat, $messageId, $text, [
        [btn('💳 درگاه‌های پرداخت', 'ad:pay', null, 'success')],
        [btn('🧾 رسیدهای در انتظار', 'ad:receipts'),
         btn('📊 گزارش فروش', 'ad:fin:sales:today')],
        [btn('🏷 کدهای تخفیف', 'ad:fin:disc'), btn('👛 کیف پول کاربران', 'ad:fin:wallets:all')],
        [btn('📅 گزارش روزانه', 'ad:fin:day'),
         btn('⚙️ حداقل و حداکثر شارژ', 'ad:finset', null, 'primary')],
        [btn('⏱ قوانین کسر موجودی و توقف خودکار', 'ad:setg:billing', null, 'success')],
        [btn('« مدیریت', 'ad:home', null, 'danger')],
    ]);
}

/**
 * ⚙️ تنظیمات مالی — همهٔ تنظیم‌های پولی ربات، یک‌جا و فقط از «مالی».
 * هیچ‌کدام از این‌ها جای دیگری تکرار نشده‌اند.
 */
function screen_fin_settings(int|string $chat, ?int $messageId): void
{
    $expire = set_int('pay_expire_minutes', 30);
    $disc = set_bool('discount_enabled', true);
    $minTop = set_int('min_topup', 50000);
    $maxTop = set_int('max_topup', 20000000);
    $auto = set_bool('auto_confirm_receipt');
    $text = "⚙️ <b>تنظیمات مالی</b>\n\n"
        . '⏳ مهلت پرداخت هر فاکتور: <b>' . fa_digits($expire) . "</b> دقیقه\n"
        . '🏷 کد تخفیف: ' . ($disc ? '🟢 فعال' : '🔴 خاموش') . "\n"
        . '⬇️ حداقل شارژ کیف پول: ' . money($minTop) . "\n"
        . '⬆️ حداکثر شارژ کیف پول: ' . money($maxTop) . "\n"
        . '🧾 تأیید خودکار رسید: ' . ($auto ? '🟢 روشن' : '🔴 خاموش') . "\n"
        . '🔗 صرافی‌های فاکتور کریپتو: ' . fa_digits(count(crypto_links_list())) . " مورد\n\n"
        . '<i>درگاه‌ها، کارت‌به‌کارت و نرخ‌ها داخل «💳 درگاه‌های پرداخت» هستند.</i>';
    Tg::show($chat, $messageId, $text, [
        [btn('⏳ مهلت پرداخت', 'ad:set:pay_expire_minutes')],
        [btn($disc ? '🔴 کد تخفیف را خاموش کنید' : '🟢 کد تخفیف را روشن کنید',
            'ad:tgl:discount_enabled:finset', null, $disc ? 'danger' : 'success')],
        [btn('⬇️ حداقل شارژ', 'ad:set:min_topup'), btn('⬆️ حداکثر شارژ', 'ad:set:max_topup')],
        [btn('🌐 صرافی‌های فاکتور کریپتو', 'ad:set:crypto_links')],
        [btn('💳 درگاه‌های پرداخت', 'ad:pay', null, 'success')],
        [btn('« مالی', 'ad:finance', null, 'danger')],
    ]);
}

function screen_sales(int|string $chat, ?int $messageId, string $key): void
{
    $days = FIN_PERIODS[$key][1] ?? 1;
    $label = FIN_PERIODS[$key][0] ?? 'امروز';
    $stats = finance_stats($days);
    $text = "📊 <b>گزارش فروش · $label</b>\n\n"
        . '💰 جمع فروش: <b>' . money($stats['sales']) . "</b>\n"
        . '🧾 تعداد سفارش: ' . fa_digits($stats['count']) . "\n";
    if ($stats['count']) {
        $text .= '📐 میانگین هر سفارش: ' . money(intdiv($stats['sales'], $stats['count'])) . "\n";
    }
    $text .= "\n🔋 شارژ کیف پول: " . money($stats['topups']) . "\n"
        . '⚡️ مصرف: ' . money($stats['usage']) . "\n"
        . '↩️ بازگشت وجه: ' . money($stats['refunds']) . "\n\n";
    $byProduct = sales_by_product($days);
    if ($byProduct) {
        $text .= "<b>پرفروش‌ترین محصول‌ها</b>\n";
        foreach ($byProduct as $row) {
            $text .= '· ' . e((string) $row['name']) . ' — ' . fa_digits((int) $row['n'])
                . ' فروش · ' . money((int) $row['total']) . "\n";
        }
    } else {
        $text .= 'در این بازه فروشی ثبت نشده.';
    }
    $buttons = [];
    $line = [];
    foreach (FIN_PERIODS as $periodKey => $info) {
        $line[] = btn($info[0], 'ad:fin:sales:' . $periodKey, null,
            $periodKey === $key ? 'success' : 'primary');
        if (count($line) === 2) {
            $buttons[] = $line;
            $line = [];
        }
    }
    if ($line) {
        $buttons[] = $line;
    }
    $buttons[] = [btn('« مالی', 'ad:finance', null, 'danger')];
    Tg::show($chat, $messageId, $text, $buttons);
}

function screen_transactions(int|string $chat, ?int $messageId, int $page, string $kind = ''): void
{
    $perPage = 10;
    $page = max(1, $page);
    $where = '';
    $params = [];
    if ($kind !== '' && isset(FIN_KIND_LABELS[$kind])) {
        $where = ' WHERE kind=?';
        $params[] = $kind;
    }
    $total = (int) DB::val('SELECT COUNT(*) FROM wallet_tx' . $where, $params, 0);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $rows = DB::all('SELECT * FROM wallet_tx' . $where . ' ORDER BY id DESC LIMIT '
        . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
    $text = '📜 <b>تراکنش‌ها</b> · ' . fa_digits($page) . ' از ' . fa_digits($pages)
        . ' · ' . fa_digits($total) . " مورد\n\n";
    foreach ($rows as $row) {
        $sign = (int) $row['amount'] >= 0 ? '+' : '−';
        $user = user_get((int) $row['tg_id']);
        $who = e(mb_substr((string) ($user['name'] ?? $row['tg_id']), 0, 20));
        $text .= '· ' . fa_ts((int) $row['created_at']) . " | $who | "
            . tx_kind_label((string) $row['kind']) . ' | '
            . $sign . money(abs((int) $row['amount'])) . "\n";
    }
    if (!$rows) {
        $text .= 'موردی ثبت نشده.';
    }
    $buttons = [];
    $nav = [];
    if ($page > 1) {
        $nav[] = btn('⬅️', "ad:fin:tx:" . ($page - 1) . ":$kind");
    }
    if ($page < $pages) {
        $nav[] = btn('➡️', 'ad:fin:tx:' . ($page + 1) . ":$kind");
    }
    if ($nav) {
        $buttons[] = $nav;
    }
    $buttons[] = [btn('همه', 'ad:fin:tx:1'), btn('شارژ', 'ad:fin:tx:1:topup'),
                  btn('خرید', 'ad:fin:tx:1:order')];
    $buttons[] = [btn('مصرف', 'ad:fin:tx:1:usage'), btn('بازگشت', 'ad:fin:tx:1:refund')];
    $buttons[] = [btn('« مالی', 'ad:finance', null, 'danger')];
    Tg::show($chat, $messageId, $text, $buttons);
}

const FIN_KIND_LABELS = ['topup' => 1, 'order' => 1, 'usage' => 1, 'refund' => 1,
                         'admin' => 1, 'ref' => 1, 'slot' => 1];



/** کیف پول کاربران: دارای موجودی / صفر / منفی */

