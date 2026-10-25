<?php
declare(strict_types=1);

/* 24) نقش‌های پنل — فقط انتخاب با دکمه (هیچ تغییری روی پنل انجام نمی‌شود) */

/** کش نقش‌ها برای هر پنل تا هر بار به API نزنیم */
function roles_cache(int $panelId, bool $refresh = false): array
{
    $key = 'roles_cache_' . $panelId;
    if (!$refresh) {
        $cached = json_decode(set_get($key, '[]'), true);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
    }
    $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
    if ($row === null) {
        return [];
    }
    try {
        $client = new PgPanel($row);
        $roles = $client->roles();
    } catch (Throwable $ex) {
        log_line('warning', 'roles fetch failed: ' . $ex->getMessage());
        return json_decode(set_get($key, '[]'), true) ?: [];
    }
    $clean = roles_normalize($roles);
    set_put($key, json_encode($clean, JSON_UNESCAPED_UNICODE));
    return $clean;
}

function roles_normalize(array $roles): array
{
    $clean = [];
    foreach ($roles as $role) {
        if (!is_array($role) || !isset($role['id'])) {
            continue;
        }
        $clean[] = ['id' => (int) $role['id'],
                    'name' => (string) ($role['name'] ?? ('نقش ' . $role['id'])),
                    'owner' => !empty($role['is_owner'])];
    }
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

/** دکمه‌های انتخاب نقش — صفحه‌بندی‌شده، نام کامل، فقط خواندن از پنل */
function role_buttons(int $panelId, int $productId = 0, string $prefix = 'ad:role',
                      int $page = 1): array
{
    $roles = array_values(array_filter(roles_cache($panelId),
        static fn(array $role): bool => !$role['owner']));
    $pages = max(1, (int) ceil(count($roles) / ROLES_PER_PAGE));
    $page = min(max(1, $page), $pages);
    $slice = array_slice($roles, ($page - 1) * ROLES_PER_PAGE, ROLES_PER_PAGE);
    $rows = [];
    foreach ($slice as $role) {
        $label = '🎭 ' . mb_substr($role['name'], 0, 40) . ' · ' . $role['id'];
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
    $roles = roles_cache($panelId, $refresh);
    $usable = array_values(array_filter($roles, static fn(array $r): bool => !$r['owner']));
    $text = '🎭 <b>نقش‌های ' . e((string) $panel['name']) . "</b>\n\n";
    if (!$usable) {
        $text .= "نقشی خوانده نشد.\n"
            . '<i>یا پنل در دسترس نیست، یا هنوز لیست نقش‌ها کش نشده است.</i>';
        Tg::show($chat, $messageId, $text, [
            [btn('🔄 خواندن از پنل', 'ad:role:' . $panelId . ':refresh:' . $productId, null, 'success')],
            [btn('🧪 تست پنل', 'ad:pg:' . $panelId . ':test')],
            [btn('« بازگشت', $productId ? 'ad:prod:' . $productId : 'ad:pg:' . $panelId, null, 'danger')],
        ]);
        return;
    }
    $text .= 'تعداد نقش‌ها: <b>' . fa_digits(count($usable)) . "</b>\n"
        . 'یکی را انتخاب کنید — <i>ربات نقشی نمی‌سازد و تغییر نمی‌دهد؛ فقط همان نقش‌های پنل را نشان می‌دهد.</i>';
    if ($productId > 0) {
        $current = (int) (product_get($productId)['role_id'] ?? 0);
        $text .= "\n\nنقش فعلی این محصول: " . role_title($panelId, $current);
    }
    $rows = role_buttons($panelId, $productId, 'ad:role', $page);
    $rows[] = [btn('🔄 به‌روزرسانی لیست', 'ad:role:' . $panelId . ':refresh:' . $productId)];
    if ($productId > 0) {
        $rows[] = [btn('🔌 تغییر پنل این نقش', 'ad:rolep:' . $productId)];
    }
    $rows[] = [btn('« بازگشت', $productId ? 'ad:prod:' . $productId : 'ad:pg:' . $panelId, null, 'danger')];
    Tg::show($chat, $messageId, $text, $rows);
}

/** ثبت نقش انتخاب‌شده روی محصول */
function product_set_role(int $productId, int $roleId, int $panelId): void
{
    DB::exec('UPDATE products SET role_id=?, panel_id=?, active=1 WHERE id=?',
        [$roleId, $panelId, $productId]);
}

