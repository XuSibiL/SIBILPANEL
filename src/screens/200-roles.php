<?php
declare(strict_types=1);

/* 24) نقش‌های پنل — خواندن زنده از PasarGuard، بدون مخفی‌کردن نقش تازه
   ─────────────────────────────────────────────────────────────────────────
   کش صرفاً fallback هنگام قطع API است؛ هر نمایش/انتخاب عادی مستقیماً از API
   می‌خواند تا نقش‌هایی مانند «هلند» بلافاصله قابل مشاهده باشند.
*/

function roles_cache(int $panelId, bool $refresh = false): array
{
    unset($refresh); // API زنده منبع حقیقت است؛ پارامتر برای سازگاری callbackهای قدیمی باقی مانده.
    $key = 'roles_cache_' . $panelId;
    $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
    if ($row === null) {
        return [];
    }
    try {
        $client = new PgPanel($row);
        $clean = roles_normalize($client->roles());
        /* حتی پاسخ خالی هم ذخیره می‌شود؛ نقش حذف‌شده از لیست قدیمی برنمی‌گردد. */
        set_put($key, json_encode($clean, JSON_UNESCAPED_UNICODE));
        return $clean;
    } catch (Throwable $ex) {
        log_line('warning', 'live roles fetch failed; cached fallback used: ' . $ex->getMessage(),
            ['panel' => $panelId]);
        $cached = json_decode(set_get($key, '[]'), true);
        return is_array($cached) ? $cached : [];
    }
}

/** APIهای مختلف نام فیلدهای متفاوت دارند؛ فقط نقشِ صریحاً غیرفعال کنار گذاشته می‌شود. */
function roles_normalize(array $roles): array
{
    $clean = [];
    $seen = [];
    foreach ($roles as $role) {
        if (!is_array($role)) {
            continue;
        }
        $id = (int) ($role['id'] ?? $role['role_id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $activeRaw = $role['active'] ?? $role['is_active'] ?? $role['enabled'] ?? true;
        $status = mb_strtolower(trim((string) ($role['status'] ?? '')));
        $activeToken = mb_strtolower(trim((string) $activeRaw));
        $active = !($activeRaw === false || $activeRaw === 0
            || in_array($activeToken, ['0', 'false', 'off', 'disabled', 'inactive'], true)
            || in_array($status, ['disabled', 'inactive', 'deleted', 'archived'], true));
        if (!$active) {
            continue;
        }
        $seen[$id] = true;
        $name = trim((string) ($role['name'] ?? $role['title'] ?? $role['label'] ?? ''));
        $clean[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : ('نقش ' . $id),
            'owner' => !empty($role['is_owner']) || !empty($role['owner']),
            'active' => true,
        ];
    }
    usort($clean, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    return $clean;
}

function role_title(int $panelId, int $roleId): string
{
    if ($roleId <= 0) {
        return '⚠️ تنظیم نشده';
    }
    foreach (roles_cache($panelId) as $role) {
        if ($role['id'] === $roleId) {
            return e($role['name']) . ' <code>' . $roleId . '</code>';
        }
    }
    return fa_digits($roleId);
}

function role_row(int $panelId, int $roleId): ?array
{
    foreach (roles_cache($panelId) as $role) {
        if ((int) $role['id'] === $roleId) {
            return $role;
        }
    }
    return null;
}

/** نقش مالک دیده می‌شود اما به‌عنوان نقش فروش قابل‌انتخاب نیست تا سطح مالک فروخته نشود. */
function role_sale_allowed(int $panelId, int $roleId): array
{
    $role = role_row($panelId, $roleId);
    if ($role === null) {
        return [false, 'این نقش دیگر روی پنل فعال نیست؛ فهرست نقش‌ها را تازه کنید.'];
    }
    if (!empty($role['owner'])) {
        return [false, 'نقش مالک برای فروش پنل قابل انتخاب نیست.'];
    }
    return [true, ''];
}

/** پنل مؤثر یک محصول: پنل خودش، وگرنه پنل پیش‌فرض */
function product_panel_id(int $productId): int
{
    if ($productId > 0) {
        $product = product_get($productId);
        if ($product !== null && (int) $product['panel_id'] > 0) {
            return (int) $product['panel_id'];
        }
    }
    $row = DB::one('SELECT id FROM pg_panels WHERE active=1 ORDER BY is_default DESC, id LIMIT 1');
    return $row === null ? 0 : (int) $row['id'];
}

const ROLES_PER_PAGE = 6;

/** دکمه‌های انتخاب نقش، صفحه‌بندی‌شده و همیشه بر پایهٔ لیست زنده. */
function role_buttons(int $panelId, int $productId = 0, string $prefix = 'ad:role',
                      int $page = 1, ?array $roles = null): array
{
    $roles = $roles ?? roles_cache($panelId);
    $pages = max(1, (int) ceil(count($roles) / ROLES_PER_PAGE));
    $page = min(max(1, $page), $pages);
    $slice = array_slice($roles, ($page - 1) * ROLES_PER_PAGE, ROLES_PER_PAGE);
    $rows = [];
    foreach ($slice as $role) {
        $label = (!empty($role['owner']) ? '🔒 ' : '🎭 ')
            . mb_substr((string) $role['name'], 0, 40) . ' · ' . $role['id'];
        $rows[] = [btn($label, $prefix . ':' . $panelId . ':' . $role['id'] . ':' . $productId)];
    }
    if ($pages > 1) {
        $nav = [];
        $base = $prefix . ':' . $panelId . ':page:';
        if ($page > 1) {
            $nav[] = btn('⬅️', $base . ($page - 1) . ':' . $productId);
        }
        $nav[] = btn(fa_digits($page) . ' از ' . fa_digits($pages),
            $base . $page . ':' . $productId, null, 'danger');
        if ($page < $pages) {
            $nav[] = btn('➡️', $base . ($page + 1) . ':' . $productId);
        }
        $rows[] = $nav;
    }
    return $rows;
}

function screen_roles(int|string $chat, ?int $messageId, int $panelId, int $productId = 0,
                      int $page = 1, bool $refresh = false): void
{
    unset($refresh);
    if ($panelId <= 0) {
        $panelId = product_panel_id($productId);
    }
    if ($panelId <= 0) {
        Tg::show($chat, $messageId,
            "🎭 <b>نقش‌ها</b>\n\nهنوز هیچ پنل پاسارگاردی وصل نیست، پس نقشی هم نیست.\n"
            . 'اول پنل را با «➕ افزودن پنل» وصل کن؛ ربات نقش‌ها را از خود پنل می‌خواند.',
            [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')],
             [btn('« بازگشت', $productId ? 'ad:prod:' . $productId : 'ad:panel', null, 'danger')]]);
        return;
    }
    $panel = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
    if ($panel === null) {
        Tg::show($chat, $messageId, 'پنل پیدا نشد.',
            [[btn('« پنل‌ها', 'ad:panel', null, 'danger')]]);
        return;
    }
    $roles = roles_cache($panelId);
    $text = '🎭 <b>نقش‌های ' . e((string) $panel['name']) . "</b>\n\n";
    if (!$roles) {
        $text .= "نقش فعالی خوانده نشد.\n"
            . '<i>ارتباط API و وضعیت نقش‌ها را روی پنل بررسی کنید.</i>';
        Tg::show($chat, $messageId, $text, [
            [btn('🔄 خواندن زنده از پنل', 'ad:role:' . $panelId . ':refresh:' . $productId,
                null, 'success')],
            [btn('🧪 تست پنل', 'ad:pg:' . $panelId . ':test')],
            [btn('« بازگشت', $productId ? 'ad:prod:' . $productId : 'ad:pg:' . $panelId, null, 'danger')],
        ]);
        return;
    }
    $text .= 'تعداد نقش‌های فعال: <b>' . fa_digits(count($roles)) . "</b>\n"
        . 'فهرست مستقیماً از API پنل خوانده شده است.';
    if ($productId > 0) {
        $current = (int) (product_get($productId)['role_id'] ?? 0);
        $text .= "\n\nنقش فعلی این محصول: " . role_title($panelId, $current);
    }
    $rows = role_buttons($panelId, $productId, 'ad:role', $page, $roles);
    $rows[] = [btn('🔄 به‌روزرسانی زنده', 'ad:role:' . $panelId . ':refresh:' . $productId)];
    if ($productId > 0) {
        $rows[] = [btn('🔌 تغییر پنل این نقش', 'ad:rolep:' . $productId)];
    }
    $rows[] = [btn('« بازگشت', $productId ? 'ad:prod:' . $productId : 'ad:pg:' . $panelId, null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** ثبت نقش انتخاب‌شده روی محصول؛ نقش مالک هرگز وارد جریان فروش نمی‌شود. */
function product_set_role(int $productId, int $roleId, int $panelId): array
{
    [$ok, $why] = role_sale_allowed($panelId, $roleId);
    if (!$ok) {
        return [false, $why];
    }
    DB::exec('UPDATE products SET role_id=?, panel_id=?, active=1 WHERE id=?',
        [$roleId, $panelId, $productId]);
    return [true, ''];
}
