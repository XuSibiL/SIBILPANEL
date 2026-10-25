<?php
declare(strict_types=1);

/* 15) Wizard engine — هر ورودی چندمرحله‌ای از همین‌جا رد می‌شود */

function wiz_start(int $uid, string $flow, array $steps, array $context = []): void
{
    $state = ['flow' => $flow, 'i' => 0, 'steps' => $steps, 'values' => [],
              'ctx' => $context];
    State::set($uid, 'wiz', $state);
    wiz_render($uid, $state, $uid, null);
}

function wiz_header(array $state): string
{
    $i = (int) $state['i'];
    $total = count($state['steps']);
    $title = $state['steps'][$i]['title'] ?? 'دریافت اطلاعات';
    return '<b>' . e($title) . '</b> · گام ' . fa_digits($i + 1) . ' از ' . fa_digits($total);
}

/**
 * تعریف یک گام ویزارد.
 * گزینه‌های دکمه‌ای باید قابل ذخیره در دیتابیس باشند (state به JSON می‌رود)،
 * پس یا «برچسب آماده» است («panels»، «roles») یا فهرستی از
 * ['label' => متن, 'value' => مقدار, 'style' => رنگ].
 */
function step_def(string $key, string $prompt, string $kind = 'text', mixed $default = null,
                  string $title = '', string|array|null $options = null): array
{
    return ['key' => $key, 'prompt' => $prompt, 'kind' => $kind,
            'default' => $default, 'title' => $title, 'options' => $options];
}

/** دکمه‌های یک گام را از روی برچسب/فهرست می‌سازد (همیشه از state خوانده می‌شود) */
function wiz_options(array $step, array $data): array
{
    $spec = $step['options'] ?? null;
    if (is_string($spec) && $spec === 'panels') {
        $rows = [];
        foreach (DB::all('SELECT * FROM pg_panels WHERE active=1 ORDER BY is_default DESC, id') as $row) {
            $rows[] = [btn('🔌 ' . (string) $row['name'], 'wiz:' . (int) $row['id'], null, 'success')];
        }
        if (!$rows) {
            $rows[] = [btn('➕ افزودن پنل پاسارگارد', 'ad:panel:new', null, 'danger')];
        }
        return $rows;
    }
    if (is_string($spec) && $spec === 'roles') {
        $panelId = (int) ($data['values']['panel_id'] ?? 0);
        if ($panelId <= 0) {
            $first = DB::one('SELECT id FROM pg_panels WHERE active=1 '
                . 'ORDER BY is_default DESC, id LIMIT 1');
            $panelId = $first === null ? 0 : (int) $first['id'];
        }
        if ($panelId <= 0) {
            return [[btn('➕ افزودن پنل پاسارگارد', 'ad:panel:new', null, 'danger')]];
        }
        $page = (int) ($data['ctx']['role_page'] ?? 1);
        $rows = role_buttons($panelId, 0, 'wizrole', $page);
        if (!$rows) {
            $rows = [[btn('🔄 خواندن نقش‌های این پنل', 'wizrole:' . $panelId . ':refresh:0',
                null, 'danger')]];
        } else {
            $rows[] = [btn('🔄 به‌روزرسانی نقش‌های این پنل', 'wizrole:' . $panelId . ':refresh:0')];
        }
        return $rows;
    }
    if (is_array($spec)) {
        $rows = [];
        foreach ($spec as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $style = (string) ($choice['style'] ?? 'auto');
            $rows[] = [btn((string) ($choice['label'] ?? ''),
                'wiz:' . (string) ($choice['value'] ?? ''), null, $style)];
        }
        return $rows;
    }
    return [];
}

/** نمایش گام فعلی ویزارد (با دکمه‌ها اگر گام گزینه‌ای باشد) */
function wiz_render(int $uid, array $data, int|string $chat, ?int $messageId): void
{
    $i = (int) $data['i'];
    $step = $data['steps'][$i];
    $text = wiz_header($data) . "\n\n" . $step['prompt'];
    $rows = wiz_options($step, $data);
    if (!$rows) {
        $text .= "\n\nبرای لغو: <code>لغو</code>";
    }
    $rows[] = [btn('« بازگشت', 'cancel', null, 'danger')];
    if ($messageId) {
        Tg::show($chat, $messageId, $text, $rows);
    } else {
        Tg::send($chat, $text, $rows);
    }
}

/** انتخاب دکمه‌ای در ویزارد */
function wiz_pick(int $uid, mixed $value, int|string $chat, ?int $messageId): void
{
    $state = State::get($uid);
    if ($state['state'] !== 'wiz') {
        Tg::send($chat, 'این انتخاب منقضی شده.', [[btn('« مدیریت', 'ad:home', null, 'danger')]]);
        return;
    }
    $data = $state['data'];
    $i = (int) $data['i'];
    $step = $data['steps'][$i] ?? null;
    if ($step === null) {
        return;
    }
    $data['values'][$step['key']] = $value;
    $data['i'] = $i + 1;
    if ($data['i'] < count($data['steps'])) {
        $next = $data['steps'][$data['i']];
        if (($next['options'] ?? null) === 'roles') {
            /* فقط نقش‌های همان پنلی که انتخاب شد، تازه از خودش خوانده می‌شود */
            $panelId = (int) ($data['values']['panel_id'] ?? 0);
            $data['ctx']['role_page'] = 1;
            if ($panelId > 0) {
                roles_cache($panelId, true);
            }
        }
        State::set($uid, 'wiz', $data);
        wiz_render($uid, $data, $chat, $messageId);
        return;
    }
    State::clear($uid);
    wiz_finish($uid, $data);
}

function parse_step_value(array $step, string $raw): array
{
    $raw = trim($raw);
    if ($raw === '-') {
        return [true, $step['default']];
    }
    return match ($step['kind']) {
        'int'   => to_int($raw) === null ? [false, null] : [true, to_int($raw)],
        'float' => to_float($raw) === null ? [false, null] : [true, to_float($raw)],
        default => $raw === '' ? [false, null] : [true, mb_substr($raw, 0, 80)],
    };
}

function wiz_advance(array $message): void
{
    $uid = (int) $message['from']['id'];
    $state = State::get($uid);
    $data = $state['data'];
    $raw = (string) ($message['text'] ?? '');
    if (in_array(trim($raw), ['لغو', 'cancel', '/cancel'], true)) {
        State::clear($uid);
        Tg::send($uid, 'لغو شد.', console_kb() ?? home_kb($uid));
        return;
    }
    $i = (int) $data['i'];
    $step = $data['steps'][$i];
    [$ok, $value] = parse_step_value($step, $raw);
    if (!$ok) {
        Tg::send($uid, 'مقدار نامعتبر. دوباره بفرستید:' . "\n" . $step['prompt']);
        return;
    }
    $data['values'][$step['key']] = $value;
    $data['i'] = $i + 1;
    if ($data['i'] < count($data['steps'])) {
        State::set($uid, 'wiz', $data);
        $next = $data['steps'][$data['i']];
        if (wiz_options($next, $data)) {
            wiz_render($uid, $data, $uid, null);
        } else {
            Tg::send($uid, 'دریافت شد ✅' . "\n\n" . $next['prompt']
                . "\n\nبرای لغو: <code>لغو</code>");
        }
        return;
    }
    State::clear($uid);
    wiz_finish($uid, $data);
}

function wiz_finish(int $uid, array $data): void
{
    $values = $data['values'];
    $ctx = $data['ctx'] ?? [];
    switch ($data['flow']) {
        case 'cat_new':
            $name = trim((string) ($values['name'] ?? ''));
            if ($name === '') {
                Tg::send($uid, 'نام خالی بود. دوباره از «دستهٔ جدید» شروع کنید.', console_kb());
                return;
            }
            $editId = (int) ($ctx['edit_id'] ?? 0);
            if ($editId) {
                DB::exec('UPDATE categories SET name=?, emoji=? WHERE id=?',
                    [$name, $values['emoji'] ?: '🗂', $editId]);
                Tg::send($uid, '✅ دسته به‌روز شد.', [[btn('« دسته', 'ad:cat:' . $editId, null, 'success')]]);
                return;
            }
            $catId = cat_create($name, (string) ($values['emoji'] ?: '🗂'));
            Tg::send($uid, '✅ دسته ساخته شد.' . "\n\n" . e($name)
                . "\n\nحالا داخل همین دسته محصول بساز.",
                [[btn('➕ محصول در این دسته', 'ad:cat:' . $catId . ':new', null, 'success')],
                 [btn('« دسته‌ها', 'ad:cats', null, 'danger')]]);
            return;

        case 'prod_new':
            $catId = (int) ($ctx['cat_id'] ?? 0);
            $panelId = (int) ($values['panel_id'] ?? 0);
            $panelRow = $panelId ? DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]) : null;
            if ($panelRow === null) {
                $panelRow = DB::one('SELECT * FROM pg_panels WHERE active=1 '
                    . 'ORDER BY is_default DESC, id LIMIT 1');
            }
            if ($panelRow === null) {
                Tg::send($uid, '❌ هیچ پنل پاسارگارد فعالی نیست.',
                    [[btn('🔌 پنل‌ها', 'ad:panel', null, 'primary')]]);
                return;
            }
            $roleId = (int) ($values['role_id'] ?? 0);
            if ($roleId <= 0) {
                Tg::send($uid, '❌ نقشی انتخاب نشد؛ محصول ساخته نشد.' . "\n"
                    . 'دوباره از «➕ محصول» شروع کنید.',
                    [[btn('➕ محصول جدید', 'ad:cat:' . $catId . ':new', null, 'success')]]);
                return;
            }
            $roleName = '';
            foreach (roles_cache((int) $panelRow['id']) as $roleRow) {
                if ($roleRow['id'] === $roleId) {
                    $roleName = $roleRow['name'];
                }
            }
            $active = 1;
            $productId = DB::exec('INSERT INTO products(name, emoji, category, category_id, '
                . 'role_id, group_ids, max_users, device_limit, days, data_cap_gb, per_gb_price, '
                . 'hourly_price, min_balance, renew_price, slots_needed, panel_prefix, trial, '
                . 'active, created_at, panel_id) '
                . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [trim((string) $values['name']), $values['emoji'] ?: '💎', 'v2ray', $catId,
                 $roleId, '[]', 10, 1, 30, 0, (int) ($values['per_gb_price'] ?? 0),
                 (int) ($values['hourly_price'] ?? 0), 0, 0, 1, '', 0, $active,
                 now_ts(), (int) $panelRow['id']]);
            $text = '✅ محصول #' . $productId . " ساخته شد.\n\n"
                . $values['emoji'] . ' <b>' . e((string) $values['name']) . "</b>\n"
                . '🔌 پنل: ' . e((string) $panelRow['name']) . "\n"
                . '🎭 نقش: ' . e($roleName !== '' ? $roleName : (string) $roleId) . "\n"
                . '⏱ ' . money((int) ($values['hourly_price'] ?? 0)) . ' /ساعت · 💵 '
                . money((int) ($values['per_gb_price'] ?? 0)) . ' /گیگ';
            $buttons = [[btn('👁 پیش‌نمایش محصول', 'ad:prod:' . $productId)]];
            $buttons[] = [btn('➕ محصول بعدی', 'ad:cat:' . $catId . ':new', null, 'success')];
            $buttons[] = [btn('« بازگشت به دسته', 'ad:cat:' . $catId, null, 'danger')];
            Tg::send($uid, $text, $buttons);
            return;

        case 'panel_pass':
            $panelId = (int) ($ctx['panel_id'] ?? 0);
            $password = trim((string) ($values['password'] ?? ''));
            [$okPass, $notePass] = panel_change_password($panelId, $password);
            Tg::send($uid, ($okPass ? '' : '⚠️ ') . $notePass,
                [[btn('« پنل', 'pn:' . $panelId, null, 'danger')]]);
            return;

        case 'prod_field':
            $productId = (int) ($ctx['product_id'] ?? 0);
            $field = (string) ($ctx['field'] ?? '');
            $meta = PRODUCT_FIELDS[$field] ?? null;
            if ($meta === null) {
                Tg::send($uid, 'این گزینه پیدا نشد.', console_kb());
                return;
            }
            product_update($productId, $meta[2], $values['value']);
            if ($field === 'role') {
                DB::exec('UPDATE products SET active=1 WHERE id=? AND role_id>0', [$productId]);
            }
            Tg::send($uid, '✅ ذخیره شد: <b>' . e((string) $values['value']) . '</b>',
                [[btn('« محصول', 'ad:prod:' . $productId, null, 'success')]]);
            return;

        case 'gwfield':
            $gw = (string) ($ctx['gw'] ?? '');
            $field = (string) ($ctx['field'] ?? '');
            if (!isset(PAY_GATEWAYS[$gw])) {
                Tg::send($uid, 'درگاه پیدا نشد.', console_kb());
                return;
            }
            $value = $values['value'];
            $settingKey = 'pay_' . $gw . ($field === 'minfiat' ? '_min_fiat' : '_' . $field);
            set_put($settingKey, (string) $value);
            if ($field === 'minfiat') {
                set_put('pay_' . $gw . '_min_cache', '0');
                set_put('pay_' . $gw . '_min_at', '0');
            }
            if (in_array($field, ['key', 'key2'], true) && (string) $value !== ''
                && set_get('pay_' . $gw . '_on') !== '1' && $gw !== 'card') {
                set_put('pay_' . $gw . '_on', '1');
            }
            Tg::send($uid, '✅ ذخیره شد: <b>'
                . e((string) $value) . '</b>',
                [[btn('« درگاه', 'ad:pay:' . $gw, null, 'success')]]);
            return;

        case 'swapkey':
            $value = trim((string) $values['value']);
            if ($value === '') {
                Tg::send($uid, '❌ کلید خالی است. دوباره از «💎 سواپ‌ولت» شروع کنید.',
                    [[btn('« سواپ‌ولت', 'ad:pay:swap', null, 'danger')]]);
                return;
            }
            set_put('swap_api_key', $value);
            set_put('swap_rates_at', '0');
            set_put('swap_star_at', '0');
            set_put('swap_rates_cache', '');
            $check = swap_get(SWAP_PATH_CHECK);
            if (!swap_ok($check)) {
                Tg::send($uid, '⚠️ کلید ذخیره شد ولی سواپ‌ولت تأییدش نکرد:'
                    . "\n" . '<code>' . e((string) ($check['_error']
                        ?? ($check['message'] ?? 'پاسخ نامعتبر'))) . '</code>'
                    . "\n" . 'کلید را دوباره بررسی کنید.',
                    [[btn('🔑 کلید دوباره', 'ad:pay:swap:key', null, 'primary')],
                     [btn('« سواپ‌ولت', 'ad:pay:swap', null, 'danger')]]);
                return;
            }
            swap_rates(true);
            swap_star_usdt(true);
            screen_swap($uid, null, true);
            return;

        case 'setting':
            $key = (string) ($ctx['key'] ?? '');
            /* شماره کارت و نام صاحب کارت را می‌شود با هم فرستاد؛ خودمان تفکیک می‌کنیم */
            if ($key === 'card_number') {
                [$cardNo, $holder] = parse_card_input((string) $values['value']);
                if ($cardNo === '') {
                    Tg::send($uid, "❌ در متن ارسالی شمارهٔ کارت پیدا نشد.\n\n"
                        . 'مثال درست:' . "\n"
                        . '<code>6104331002032630 سید ابوالفضل موسوی</code>',
                        [[btn('🔢 دوباره', 'ad:set:card_number', null, 'primary')],
                         [btn('« مالی', 'ad:finance', null, 'danger')]]);
                    return;
                }
                set_put('card_number', $cardNo);
                if ($holder !== '') {
                    set_put('card_holder', $holder);
                }
                Tg::send($uid, "✅ کارت به کارت تنظیم شد\n\n"
                    . 'شماره کارت: <code>' . e($cardNo) . '</code>' . "\n"
                    . 'صاحب کارت: ' . e(set_get('card_holder', '—')) . "\n\n"
                    . '<i>کاربر این شماره را با یک ضربه کپی می‌کند.</i>',
                    [[btn('💳 کارت به کارت', 'ad:pay:card', null, 'success')],
                     [btn('« مالی', 'ad:finance', null, 'danger')]]);
                return;
            }
            /* آیدی پشتیبانی: @name و لینک t.me هر دو پذیرفته و یکسان ذخیره می‌شوند */
            if ($key === 'support_link') {
                $rawSupport = trim((string) $values['value']);
                $supportHandle = '';
                if (preg_match('~t\.me/([A-Za-z0-9_]{3,32})~i', $rawSupport, $sm)) {
                    $supportHandle = $sm[1];
                } elseif (preg_match('~^@?([A-Za-z0-9_]{3,32})$~', $rawSupport, $sm)) {
                    $supportHandle = $sm[1];
                }
                if ($supportHandle === '' && $rawSupport !== '') {
                    Tg::send($uid, "❌ این آیدی معتبر نیست.\n\n"
                        . 'یکی از این دو شکل را بفرستید:' . "\n"
                        . '· <code>@my_support</code>' . "\n"
                        . '· <code>https://t.me/my_support</code>',
                        [[btn('📞 دوباره', 'ad:set:support_link', null, 'primary')],
                         [btn('« تنظیمات', 'ad:setg:report', null, 'danger')]]);
                    return;
                }
                set_put('support_link', $supportHandle !== '' ? '@' . $supportHandle : '');
                Tg::send($uid, "✅ «آیدی پشتیبانی» ذخیره شد: <b>"
                    . e($supportHandle !== '' ? '@' . $supportHandle : '—') . "</b>\n\n"
                    . '<i>دکمهٔ شیشه‌ای «☎️ پشتیبانی» همین حالا به '
                    . '<code>https://t.me/' . e($supportHandle) . '</code> وصل شد.</i>',
                    [[btn('« تنظیمات', 'ad:setg:report', null, 'danger')]]);
                return;
            }
            set_put($key, (string) $values['value']);
            $label = SETTING_LABELS[$key] ?? 'مقدار';
            $backTo = match (setting_group_of($key)) {
                'finset' => 'ad:finset',
                'card' => 'ad:pay:card',
                'kyc' => 'ad:setg:kyc',
                default => 'ad:setg:home',
            };
            Tg::send($uid, '✅ «' . $label . '» ذخیره شد: <b>' . e((string) $values['value'])
                . '</b>', [[btn('« بازگشت', $backTo, null, 'danger')]]);
            return;

        case 'disc_new':
            $code = strtoupper(preg_replace('/\s+/', '', (string) $values['code']) ?? '');
            if (strlen($code) < 3) {
                Tg::send($uid, '❌ کد باید حداقل ۳ حرف باشد. دوباره از «➕ ساخت کد تخفیف» شروع کنید.',
                    [[btn('🏷 کدها', 'ad:fin:disc', null, 'danger')]]);
                return;
            }
            if (discount_row($code) !== null) {
                Tg::send($uid, '❌ این کد قبلاً ساخته شده. یک نام دیگر بفرستید.',
                    [[btn('🏷 کدها', 'ad:fin:disc', null, 'danger')]]);
                return;
            }
            $percent = max(0, min(100, (int) ($values['percent'] ?? 0)));
            if ($percent <= 0) {
                Tg::send($uid, '❌ درصد تخفیف باید بیشتر از صفر باشد.',
                    [[btn('🏷 کدها', 'ad:fin:disc', null, 'danger')]]);
                return;
            }
            $maxUsers = max(0, (int) ($values['max_users'] ?? 0));
            $perUser = max(0, (int) ($values['per_user'] ?? 1));
            if ($maxUsers > 0 && $perUser > $maxUsers) {
                $perUser = $maxUsers;
            }
            $hours = max(0, (int) ($values['hours'] ?? 0));
            $days = max(0, (int) ($values['days'] ?? 30));
            DB::exec('INSERT INTO discounts(code, percent, amount, max_uses, max_users, uses, '
                . 'per_user, first_only, hours, days, expires_at, active, created_at) '
                . 'VALUES(?,?,0,?,?,0,?,?,?,?,?,1,?)',
                [$code, $percent, $maxUsers, $maxUsers, $perUser,
                 (int) ($values['first_only'] ?? 0), $hours, $days,
                 $hours > 0 ? now_ts() + $hours * 3600 : null, now_ts()]);
            Tg::send($uid, '✅ کد تخفیف ساخته شد.' . "\n\n"
                . '🏷 کد: <code>' . e($code) . "</code>\n"
                . '💯 تخفیف: <b>' . fa_digits($percent) . "</b>٪ (روی خرید و مصرف هر گیگ)\n"
                . '👥 سقف: ' . ($maxUsers > 0 ? fa_digits($maxUsers) . ' کاربر'
                    : 'نامحدود') . "\n"
                . '🔁 هر کاربر: ' . ($perUser > 0 ? fa_digits($perUser) . ' بار' : 'نامحدود')
                . "\n"
                . '🛒 نوع: ' . ((int) ($values['first_only'] ?? 0) === 1 ? 'فقط خرید اول'
                    : 'همهٔ خریدها') . "\n"
                . '⏳ مهلت کد: ' . ($hours > 0 ? fa_digits($hours) . ' ساعت' : 'نامحدود') . "\n"
                . '🏷 مدت تخفیف مصرف: ' . fa_digits($days) . ' روز',
                [[btn('🏷 فهرست کدها', 'ad:fin:disc', null, 'success')]]);
            return;

        case 'refund':
            $raw = trim((string) $values['ref']);
            $order = DB::one('SELECT * FROM orders WHERE ref=? OR id=?',
                [strtoupper($raw), to_int($raw) ?? 0]);
            if ($order === null) {
                Tg::send($uid, 'سفارشی با این کد پیدا نشد.',
                    [[btn('« مالی', 'ad:finance', null, 'danger')]]);
                return;
            }
            if ($order['status'] === 'refunded' || (int) $order['payable'] === 0) {
                Tg::send($uid, 'این سفارش قابل برگشت نیست.',
                    [[btn('« مالی', 'ad:finance', null, 'danger')]]);
                return;
            }
            $amount = (int) $order['payable'];
            add_balance((int) $order['tg_id'], $amount, 'refund', (string) $order['id'],
                'بازگشت وجه سفارش #' . $order['id']);
            DB::exec("UPDATE orders SET status='refunded' WHERE id=?", [(int) $order['id']]);
            Tg::send((int) $order['tg_id'], '↩️ مبلغ ' . money($amount) . ' بابت سفارش #'
                . $order['id'] . ' به کیف پولتان برگشت.');
            Tg::send($uid, '✅ بازگشت وجه انجام شد.' . "\n" . 'سفارش #' . $order['id']
                . ' · ' . money($amount) . "\n" . 'موجودی جدید کاربر: '
                . money(balance_of((int) $order['tg_id'])),
                [[btn('« مالی', 'ad:finance', null, 'danger')]]);
            return;

        case 'user_balance':
            $tgId = (int) ($ctx['tg_id'] ?? 0);
            $amount = (int) ($values['amount'] ?? 0);
            if ((int) ($ctx['sign'] ?? 1) < 0) {
                $amount = -abs($amount);
            }
            if ($amount === 0) {
                Tg::send($uid, 'مبلغ صفر است؛ تغییری ندادم.', console_kb());
                return;
            }
            $newBalance = add_balance($tgId, $amount, 'admin', (string) $uid, 'تغییر دستی ادمین');
            post_report('💰 <b>تغییر موجودی توسط ادمین</b>' . "\n" . 'کاربر <code>' . $tgId
                . '</code> · ' . money($amount) . "\n" . 'موجودی جدید: ' . money($newBalance));
            Tg::send($tgId, '💰 موجودی کیف پولتان تغییر کرد: <b>' . money($amount) . '</b>' . "\n"
                . 'موجودی جدید: <b>' . money($newBalance) . '</b>');
            Tg::send($uid, '✅ انجام شد.' . "\n" . 'موجودی جدید: <b>' . money($newBalance) . '</b>',
                [[btn('« کاربر', 'ad:u:' . $tgId, null, 'success')]]);
            return;

        case 'find_panel':
            $q = trim((string) ($values['value'] ?? ''));
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $row = null;
            if ($digits !== '' && $digits === $q) {
                $row = DB::one('SELECT * FROM panels WHERE id=?', [(int) $digits]);
            }
            if ($row === null) {
                $row = DB::one('SELECT * FROM panels WHERE panel_username=?', [$q]);
            }
            if ($row === null) {
                $row = DB::one('SELECT * FROM panels WHERE panel_username LIKE ? '
                    . 'ORDER BY id DESC LIMIT 1', ['%' . $q . '%']);
            }
            if ($row === null) {
                Tg::send($uid, '🔎 پنلی با «' . e($q) . '» پیدا نشد.' . "\n"
                    . '<i>نام کاربری پنل یا شناسهٔ عددی آن را دقیق بفرستید.</i>',
                    [[btn('🔍 جستجوی دوباره', 'ad:find:panel', null, 'success')],
                     [btn('« جستجوی سفارشات', 'ad:find', null, 'danger')]]);
                return;
            }
            screen_find_panel($uid, null, (int) $row['id']);
            return;

        case 'find_inv':
            screen_find_invoice($uid, null, (string) ($values['value'] ?? ''));
            return;

        case 'find_extend':
            $panelId = (int) ($ctx['panel_id'] ?? 0);
            $gigs = max(0, (int) ($values['gigs'] ?? 0));
            $days = max(0, (int) ($values['days'] ?? 0));
            [$okE, $msgE] = admin_panel_extend($panelId, $gigs, $days);
            Tg::send($uid, $msgE);
            screen_find_panel($uid, null, $panelId);
            return;

        case 'usermsg':
            $tgId = (int) ($ctx['tg_id'] ?? 0);
            $okSend = Tg::send($tgId, (string) $values['text']);
            Tg::send($uid, $okSend ? '✅ پیام رسید.' : '❌ پیام نرسید (کاربر ربات را بلاک کرده).',
                [[btn('« کاربر', 'ad:u:' . $tgId, null, 'success')]]);
            return;

        case 'user_gift':
            $tgId = (int) ($ctx['tg_id'] ?? 0);
            $percent = max(0, min(100, (int) ($values['percent'] ?? 0)));
            $days = max(0, (int) ($values['days'] ?? 0));
            [$okGift, $msgGift] = user_discount_grant($tgId, $percent, $days);
            if ($okGift && $percent > 0) {
                Tg::send($tgId, '🎁 یک تخفیف اختصاصی <b>' . fa_digits($percent) . '</b>٪'
                    . ($days > 0 ? ' برای ' . fa_digits($days) . ' روز' : ' بی‌پایان')
                    . ' روی خریدهایت فعال شد.');
                post_report('🎁 <b>تخفیف اختصاصی</b>' . "\n" . 'کاربر: <code>' . $tgId
                    . '</code>' . "\n" . 'درصد: ' . fa_digits($percent) . '٪', 'sales');
            }
            Tg::send($uid, $msgGift, [[btn('« کاربر', 'ad:u:' . $tgId, null, 'danger')]]);
            return;

        case 'trackcode':
            $code = trim((string) $values['value']);
            $found = order_by_code($code, $uid);
            if ($found === null) {
                Tg::send($uid, '🔎 سفارشی با کد <code>' . e($code) . "</code> برای شما پیدا نشد.\n"
                    . '<i>کد پیگیری را دقیق بفرست؛ همان کدی که بعد از پرداخت گرفتی.</i>',
                    [[btn('🔎 دوباره', 'menu:track', null, 'success')],
                     [btn('« خانه', 'home', null, 'danger')]]);
                return;
            }
            order_track_card($uid, null, $found);
            return;

        case 'rsearch':
            receipt_find_card($uid, null, (string) $values['value'], $uid);
            return;

        case 'psearch':
            panel_find_card($uid, null, (string) $values['value']);
            return;

        case 'usersearch':
            $query = trim((string) $values['value']);
            $tgId = preg_replace('/\D/', '', $query) ?? '';
            if ($tgId !== '' && user_get((int) $tgId) !== null) {
                /* شناسه دقیق ⇒ مستقیم کارت کاربر */
                screen_user_card($uid, null, (int) $tgId);
                return;
            }
            if ($tgId !== '' && $tgId === $query && user_get((int) $tgId) === null) {
                /* شناسهٔ ناشناس: هیچ ردیفی ساخته نمی‌شود */
                Tg::send($uid, '🔎 کاربری با شناسهٔ <code>' . e($tgId) . "</code> در ربات نیست.\n"
                    . '<i>هیچ ردیفی هم برای این شماره ثبت نشد.</i>',
                    [[btn('🔎 جستجوی دیگر', 'ad:usearch', null, 'success')],
                     [btn('« کاربران', 'ad:users', null, 'danger')]]);
                return;
            }
            if (str_starts_with($query, '@')) {
                $query = substr($query, 1);
            }
            screen_users($uid, null, 1, $query);
            return;

        case 'reffield':
            $field = (string) ($ctx['field'] ?? '');
            $value = (int) ($values['value'] ?? 0);
            if ($field === 'percent') {
                $value = max(0, min(100, $value));
                set_put('ref_percent', (string) $value);
            } elseif ($field === 'min') {
                $value = max(0, $value);
                set_put('ref_min', (string) $value);
            } elseif ($field === 'cash') {
                $value = max(0, $value);
                set_put('ref_cash', (string) $value);
            } elseif ($field === 'maxdep') {
                /* قانون طلایی: ۱ یا ۲ واریزی؛ بیش از آن پذیرفته نمی‌شود */
                $value = max(1, min(2, $value));
                set_put('ref_max_deposits', (string) $value);
            }
            screen_referral_settings($uid, null);
            return;

        case 'panel_api':
            $name = trim((string) ($values['name'] ?? ''));
            $url = normalize_panel_url(trim((string) ($values['url'] ?? '')));
            $apiKey = trim((string) ($values['api_key'] ?? ''));
            if ($url === '' || $apiKey === '') {
                Tg::send($uid, '❌ اطلاعات ناقص است. دوباره «➕ افزودن پنل» را بزنید و کامل کنید.',
                    [[btn('➕ افزودن پنل', 'ad:pg:new', null, 'success')]]);
                return;
            }
            if (!str_contains($apiKey, 'pg_key_')) {
                State::set($uid, 'panel_key_confirm',
                    ['name' => $name, 'url' => $url, 'api_key' => $apiKey]);
                Tg::send($uid, "⚠️ این کلید شبیه کلید API پاسارگارد نیست (باید با "
                    . '<code>pg_key_</code> شروع شود).' . "\n\n"
                    . 'اگر همین را از پنل گرفته‌ای، تأیید کنید:',
                    [[btn('✅ همین کلید درست است', 'ad:pg:savekey', null, 'success')],
                     [btn('🔁 کلید را دوباره بفرستید', 'ad:pg:newapi'),
                      btn('« لغو', 'ad:panel', null, 'danger')]]);
                return;
            }
            panel_save_api($uid, $name, $url, $apiKey);
            return;

        case 'panel_key':
            $panelId = (int) ($ctx['panel_id'] ?? 0);
            $apiKey = trim((string) ($values['api_key'] ?? ''));
            if ($panelId <= 0 || $apiKey === '') {
                Tg::send($uid, 'کلید خالی بود؛ تغییری ندادم.',
                    [[btn('« پنل‌ها', 'ad:panel', null, 'danger')]]);
                return;
            }
            DB::exec('UPDATE pg_panels SET api_key=?, last_ok_at=0, last_error=NULL WHERE id=?',
                [$apiKey, $panelId]);
            $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
            [$okTest, $note] = $row ? panel_test($row) : [false, 'پنل پیدا نشد'];
            Tg::send($uid, ($okTest ? '✅ ' : '⚠️ ') . 'کلید API ذخیره شد.' . "\n\n" . e($note),
                [[btn('🎭 نقش‌های این پنل', 'ad:role:' . $panelId . ':0:0', null, 'success')],
                 [btn('« پنل', 'ad:pg:' . $panelId, null, 'danger')]]);
            return;

        case 'pg_edit':
            $panelId = (int) ($ctx['panel_id'] ?? 0);
            $panelRow = DB::one('SELECT * FROM pg_panels WHERE id=?', [$panelId]);
            if ($panelId <= 0 || $panelRow === null) {
                Tg::send($uid, 'این پنل پیدا نشد.', [[btn('« پنل‌ها', 'ad:panel', null, 'danger')]]);
                return;
            }
            $newName = trim((string) ($values['name'] ?? ''));
            $newUrl = trim((string) ($values['url'] ?? ''));
            /* «-» یعنی همین مقدار بماند */
            if (in_array($newName, ['-', '—', 'skip'], true)) {
                $newName = '';
            }
            if (in_array($newUrl, ['-', '—', 'skip'], true)) {
                $newUrl = '';
            }
            $changed = [];
            if ($newName !== '' && $newName !== (string) $panelRow['name']) {
                DB::exec('UPDATE pg_panels SET name=? WHERE id=?', [$newName, $panelId]);
                $changed[] = 'نام';
            }
            if ($newUrl !== '') {
                $clean = normalize_panel_url($newUrl);
                if ($clean !== '' && $clean !== (string) $panelRow['url']) {
                    DB::exec('UPDATE pg_panels SET url=?, last_ok_at=0 WHERE id=?',
                        [$clean, $panelId]);
                    $changed[] = 'آدرس';
                }
            }
            Tg::send($uid, $changed
                ? '✅ ' . implode(' و ', $changed) . ' به‌روزرسانی شد.'
                : 'چیزی تغییر نکرد.',
                [[btn('« داشبورد پنل', 'ad:pg:' . $panelId, null, 'success')]]);
            screen_pg_panel($uid, null, $panelId);
            return;

        case 'report_chat':
            $raw = trim((string) ($values['chat'] ?? ''));
            [$reportOk, $reportNote] = report_group_setup($raw);
            Tg::send($uid, ($reportOk ? '' : '⚠️ ') . $reportNote,
                [[btn('📢 گروه گزارشات', 'ad:report', null, 'success')],
                 [btn('« مدیریت', 'ad:home', null, 'danger')]]);
            return;

        case 'bc_text':
            $text = trim((string) $values['text']);
            if ($text === '') {
                Tg::send($uid, 'متن خالی بود؛ چیزی ارسال نشد.', [[btn('« پیام همگانی', 'ad:bc')]]);
                return;
            }
            $targets = bc_targets(bc_segment());
            if (!$targets) {
                Tg::send($uid, 'برای این مخاطب کاربری پیدا نشد.',
                    [[btn('🎯 تغییر مخاطب', 'ad:bc:seg', null, 'success')]]);
                return;
            }
            /* ارسال همگانی در پس‌زمینه: ربات هیچ‌وقت قفل نمی‌کند و تایم‌اوت نمی‌دهد */
            bc_job_start($targets, 'text', $text, 0, 0, set_bool('bc_pin', false),
                $uid, '📣 ارسال همگانی تمام شد', BC_SEGMENTS[bc_segment()]);
            Tg::send($uid, '🚀 <b>ارسال همگانی در پس‌زمینه آغاز شد</b>' . "\n" . SEP . "\n"
                . '🎯 مخاطب: ' . BC_SEGMENTS[bc_segment()] . "\n"
                . '👥 تعداد کل: ' . fa_digits(count($targets)) . "\n\n"
                . '<i>پیام‌ها یکی‌یکی و با فاصله ارسال می‌شوند؛ گزارش کامل '
                . '(تعداد کل / موفق / مسدود شده توسط کاربر) همین‌جا برایت می‌آید. '
                . 'می‌توانی ربات را عادی استفاده کنی.</i>',
                [[btn('« پیام همگانی', 'ad:bc', null, 'success')]]);
            bc_job_dispatch();
            return;
    }
    Tg::send($uid, 'ویزارد نامعتبر.', console_kb());
}

/** اولین نقش غیرمالک پنل را برمی‌گرداند تا محصول بدون پرسش اضافه ساخته شود. */
function auto_role_id(array $panelRow): int
{
    try {
        $client = new PgPanel($panelRow);
        foreach ($client->roles() as $role) {
            if (empty($role['is_owner'])) {
                return (int) ($role['id'] ?? 0);
            }
        }
    } catch (Throwable $ex) {
        log_line('warning', 'auto_role_id failed: ' . $ex->getMessage());
    }
    return 0;
}

