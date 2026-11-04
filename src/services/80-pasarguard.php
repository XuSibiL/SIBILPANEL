<?php
declare(strict_types=1);

/* 8) PasarGuard API client */

final class PanelError extends RuntimeException
{
    public function __construct(string $message, public int $status = 0)
    {
        parent::__construct($message);
    }
}

/**
 * پیام خطای پنل را خوانا می‌کند.
 * پاسخ اعتبارسنجی پاسارگارد یک آرایه است و قبلاً به‌صورت «Array» چاپ می‌شد:
 *   {"detail":[{"loc":["body","password"],"msg":"Password must contain at least 2 digits"}]}
 */
function panel_error_detail(mixed $decoded, string $raw = ''): string
{
    $parts = [];
    $detail = is_array($decoded) ? ($decoded['detail'] ?? ($decoded['message'] ?? null)) : null;
    if (is_string($detail) && trim($detail) !== '') {
        $parts[] = trim($detail);
    } elseif (is_array($detail)) {
        foreach ($detail as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $loc = $item['loc'] ?? [];
            $loc = is_array($loc) ? implode('.', array_map('strval', $loc)) : (string) $loc;
            $msg = (string) ($item['msg'] ?? ($item['message'] ?? ''));
            if ($msg === '' && isset($item['ctx']['error'])) {
                $msg = (string) $item['ctx']['error'];
            }
            $parts[] = trim(($loc !== '' ? $loc . ': ' : '') . $msg);
        }
    }
    if ($parts === []) {
        $trimmed = trim($raw);
        if ($trimmed !== '' && str_starts_with($trimmed, '{')) {
            $trimmed = '';
        }
        $parts[] = $trimmed !== '' ? mb_substr($trimmed, 0, 200) : 'پاسخ نامعتبر';
    }
    $text = implode(' | ', array_filter($parts));
    return mb_substr($text, 0, 300);
}

function normalize_panel_url(string $url): string
{
    $value = rtrim(trim($url), '/');
    if ($value === '') {
        return '';
    }
    foreach (['/dashboard', '/login', '/admin'] as $suffix) {
        if (str_ends_with($value, $suffix)) {
            $value = rtrim(substr($value, 0, -strlen($suffix)), '/');
        }
    }
    if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
        $value = (str_ends_with($value, ':80') ? 'http://' : 'https://') . $value;
    }
    return rtrim($value, '/');
}

final class PgPanel
{
    private ?string $token = null;
    private string $apiKey = '';

    public function __construct(private array $row)
    {
        $this->apiKey = trim((string) ($row['api_key'] ?? ''));
    }

    /** روش اتصال همیشه کلید API است */
    public function auth_mode(): string
    {
        return 'api';
    }

    public function has_key(): bool
    {
        return $this->apiKey !== '';
    }

    public static function from_id(int $id): self
    {
        $row = DB::one('SELECT * FROM pg_panels WHERE id=?', [$id]);
        if ($row === null) {
            throw new PanelError('panel not found');
        }
        return new self($row);
    }

    public static function default_panel(): ?self
    {
        $row = DB::one('SELECT * FROM pg_panels WHERE active=1 ORDER BY is_default DESC, id LIMIT 1');
        return $row === null ? null : new self($row);
    }

    public function name(): string
    {
        return (string) $this->row['name'];
    }

    private function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $base = normalize_panel_url((string) $this->row['url']);
        $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
        $this->login();
        $headers = ['Accept: application/json', 'X-Api-Key: ' . $this->apiKey];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        [$payload, $error, $status] = http_request($method, $url, $headers, $body, true,
            max(5, min(120, set_int('panel_test_timeout', 20))));
        if ($payload === null) {
            throw new PanelError('connection failed: ' . $error);
        }
        $decoded = json_decode((string) $payload, true);
        if ($status >= 400) {
            $detail = panel_error_detail($decoded, (string) $payload);
            throw new PanelError('HTTP ' . $status . ($detail !== '' ? ' — ' . $detail : ''), $status);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** فقط کلید API پذیرفته می‌شود */
    public function login(): void
    {
        if ($this->apiKey === '') {
            throw new PanelError('کلید API این پنل تنظیم نشده است؛ از «🔌 پنل‌ها → ➕ افزودن پنل» '
                . 'کلید را ثبت کنید');
        }
    }

    public function me(): array
    {
        $this->login();
        return $this->request('GET', '/api/admin');
    }

    /** همهٔ نقش‌ها را همان لحظه از API می‌خواند؛ نسخه‌های مختلف API wrapper متفاوت دارند. */
    public function roles(): array
    {
        $this->login();
        $data = $this->request('GET', '/api/admin-roles/simple');
        foreach (['roles', 'items', 'results', 'data'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            $candidate = $data[$key];
            if (array_is_list($candidate)) {
                return $candidate;
            }
            /* بعضی wrapperها پاسخ را به شکل {data:{roles:[…]}} می‌پیچند. */
            foreach (['roles', 'items', 'results'] as $nested) {
                if (isset($candidate[$nested]) && is_array($candidate[$nested])
                    && array_is_list($candidate[$nested])) {
                    return $candidate[$nested];
                }
            }
        }
        if (array_is_list($data)) {
            return $data;
        }
        /* پاسخ نامفهوم «فهرست خالی» تلقی نمی‌شود؛ cache سالم را نابود نکن. */
        throw new PanelError('unexpected admin roles response');
    }

    public function groups(): array
    {
        $this->login();
        $data = $this->request('GET', '/api/groups/simple');
        return $data['groups'] ?? $data;
    }

    public function create_admin(string $username, string $password, int $roleId, array $extra = []): array
    {
        $this->login();
        /* فیلدهای معتبر خود پنل (AdminCreate/AdminModify در پاسارگارد v5):
           username, password, role_id, status, data_limit, note, permission_overrides.
           فیلدهای قدیمی (is_active/users_limit/expire) در v5 وجود ندارند و
           باعث 422 می‌شدند. */
        $status = (string) ($extra['status'] ?? '') !== '' ? (string) $extra['status'] : 'active';
        $body = ['username' => $username, 'password' => $password, 'role_id' => $roleId,
                 'status' => $status];
        if (isset($extra['data_limit']) && (int) $extra['data_limit'] > 0) {
            $body['data_limit'] = (int) $extra['data_limit'];
        }
        if (!empty($extra['note'])) {
            $body['note'] = (string) $extra['note'];
        }
        if (isset($extra['users_limit']) && (int) $extra['users_limit'] > 0) {
            $body['permission_overrides'] = ['max_users' => (int) $extra['users_limit']];
        }
        try {
            return $this->request('POST', '/api/admin', $body);
        } catch (PanelError $ex) {
            if ($ex->status === 422) {
                /* آخرین تلاش: فقط سه فیلد اجباری، بدون هیچ چیزی که پنل نشناسد */
                return $this->request('POST', '/api/admin', [
                    'username' => $username,
                    'password' => $password,
                    'role_id'  => $roleId,
                ]);
            }
            throw $ex;
        }
    }

    public function admin_by_username(string $username): ?array
    {
        $this->login();
        $data = $this->request('GET', '/api/admins', null, ['username' => $username, 'limit' => 1]);
        $admins = $data['admins'] ?? [];
        return $admins[0] ?? null;
    }

    /**
     * جزئیات کامل یک ادمین (مصرف، سقف، تعداد کاربر، وضعیت).
     * PasarGuard این اطلاعات را در فهرست ادمین‌ها برمی‌گرداند:
     * GET /api/admins?username=<u>  → { admins: [AdminDetails], ... }
     */
    public function admin_info(string $username): array
    {
        $this->login();
        $username = trim($username);
        if ($username === '') {
            return $this->request('GET', '/api/admin');
        }
        $data = $this->request('GET', '/api/admins', null, [
            'username' => $username,
            'limit'    => 1,
        ]);
        $admins = $data['admins'] ?? [];
        if (is_array($admins)) {
            foreach ($admins as $row) {
                if (is_array($row)
                    && mb_strtolower((string) ($row['username'] ?? '')) === mb_strtolower($username)) {
                    return $row;
                }
            }
            if (isset($admins[0]) && is_array($admins[0])) {
                return $admins[0];
            }
        }
        return $data;
    }

    public function delete_admin(int $id): void
    {
        $this->login();
        $this->request('DELETE', '/api/admin/by-id/' . $id);
    }

    /** حذف ادمین با نام کاربری (فقط برای پنل‌های تست استفاده می‌شود) */
    public function delete_admin_by_username(string $username): void
    {
        $this->login();
        $this->request('DELETE', '/api/admin/by-username/' . rawurlencode($username));
    }

    /**
     * حذف کامل ادمین پنل همراه با همهٔ کاربرانی که زیرمجموعهٔ او ساخته شده‌اند.
     * اول کاربران (کانفیگ‌ها) پاک می‌شوند تا هیچ کانفیگ یتیمی روی پنل نماند،
     * بعد خود ادمین. خروجی: ['users' => تعداد حذف‌شده, 'admin' => true]
     */
    public function delete_admin_with_users(string $username): array
    {
        $this->login();
        $deleted = 0;
        for ($round = 0; $round < 20; $round++) {
            $users = $this->users_of($username, 1000, 0);
            if ($users === []) {
                break;
            }
            $ids = [];
            $names = [];
            foreach ($users as $user) {
                if (isset($user['id'])) {
                    $ids[] = (int) $user['id'];
                }
                if (!empty($user['username'])) {
                    $names[] = (string) $user['username'];
                }
            }
            if ($ids === [] && $names === []) {
                break;
            }
            $removed = 0;
            if ($ids !== []) {
                try {
                    $res = $this->request('POST', '/api/users/bulk/delete',
                        ['ids' => array_values($ids)]);
                    $removed = (int) ($res['count'] ?? $res['deleted'] ?? count($ids));
                } catch (Throwable $ex) {
                    $removed = 0; // نسخه‌های قدیمی پنل این مسیر را ندارند
                }
            }
            if ($removed <= 0) {
                foreach ($names as $name) {
                    try {
                        $this->request('DELETE', '/api/user/by-username/' . rawurlencode($name));
                        $removed++;
                    } catch (Throwable $ex) {
                        if (!str_contains($ex->getMessage(), '404')) {
                            // بقیهٔ کاربران را رها نکن
                        }
                    }
                }
            }
            $deleted += max(0, $removed);
            if ($removed <= 0) {
                break;
            }
        }
        $this->request('DELETE', '/api/admin/by-username/' . rawurlencode($username));
        return ['users' => $deleted, 'admin' => true];
    }

    public function update_admin(string $username, array $fields): array
    {
        $this->login();
        return $this->request('PUT', '/api/admin/by-username/' . rawurlencode($username), $fields);
    }

    /**
     * سقف کاربران در PasarGuard v5 یک permission override است، نه فیلد قدیمی users_limit.
     * این روش برای فروش/شارژ پنل استفاده می‌شود تا افزایش محلی بدون افزایش زنده رخ ندهد.
     */
    public function set_admin_users_limit(string $username, int $limit): array
    {
        $limit = max(1, $limit);
        return $this->update_admin($username, [
            'permission_overrides' => ['max_users' => $limit],
        ]);
    }

    /** تغییر رمز ورود پنل کاربر */
    public function set_password(string $username, string $password): array
    {
        return $this->update_admin($username, ['password' => $password]);
    }

    public function set_status(mixed $admin, string $status): array
    {
        $this->login();
        $state = $status === 'active' ? 'active' : 'disabled';
        return $this->request('PUT', '/api/admin/by-username/' . rawurlencode((string) $admin),
            ['status' => $state]);
    }

    /** مصرف/سقف ادمین از خود پنل */
    public function usage(int $adminId, string $username = ''): array
    {
        return $this->admin_info($username);
    }

    /** کاربران یک ادمین (برای شمارش دستگاه‌ها و بررسی تخلف) */
    public function users_of(string $adminUsername, int $limit = 200, int $offset = 0): array
    {
        $this->login();
        $data = $this->request('GET', '/api/users', null, [
            'admin'  => $adminUsername,
            'limit'  => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ]);
        return is_array($data['users'] ?? null) ? $data['users'] : [];
    }

    /**
     * همهٔ کانفیگ‌های زیرمجموعهٔ یک ادمین فروخته‌شده را صفحه‌به‌صفحه می‌خواند.
     *
     * صورت‌حساب زمان آنلاین نباید با اولین ۲۰۰/۱۰۰۰ ردیف، کاربرهای انتهایی را
     * نادیده بگیرد. PasarGuard v5 پاسخ را به شکل {users:[...], total:n}
     * می‌دهد؛ برای wrapperهای قدیمی‌تر، کوتاه شدن صفحه پایان فهرست محسوب می‌شود.
     * پاسخ ناقص/تکراری خطا است، نه «فهرست خالی»، چون در آن حالت هیچ دقیقه‌ای
     * نباید حدسی ثبت شود.
     */
    public function users_of_all(string $adminUsername): array
    {
        $this->login();
        $adminUsername = trim($adminUsername);
        if ($adminUsername === '') {
            throw new PanelError('sold panel username is empty');
        }
        $limit = 1000;
        $offset = 0;
        $total = null;
        $all = [];
        $seen = [];
        $finished = false;
        for ($page = 0; $page < 10000; $page++) {
            $data = $this->request('GET', '/api/users', null, [
                'admin'  => $adminUsername,
                'limit'  => $limit,
                'offset' => $offset,
            ]);
            $users = is_array($data['users'] ?? null) ? $data['users'] : null;
            if ($users === null) {
                throw new PanelError('unexpected users response');
            }
            if ($total === null && isset($data['total']) && is_numeric($data['total'])) {
                $total = max(0, (int) $data['total']);
            }
            if ($users === []) {
                $finished = true;
                break;
            }
            $fresh = 0;
            foreach ($users as $user) {
                if (!is_array($user)) {
                    throw new PanelError('users response contains a malformed user');
                }
                $id = (int) ($user['id'] ?? 0);
                if ($id <= 0) {
                    throw new PanelError('users response has no stable user id');
                }
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $all[] = $user;
                $fresh++;
            }
            if ($fresh === 0) {
                throw new PanelError('users pagination did not advance');
            }
            $offset += count($users);
            if (($total !== null && $offset >= $total) || count($users) < $limit) {
                $finished = true;
                break;
            }
        }
        if (!$finished) {
            throw new PanelError('users pagination exceeded its safety limit');
        }
        if ($total !== null && count($all) < $total) {
            throw new PanelError('users list ended before its advertised total');
        }
        return $all;
    }

    /**
     * اتصال زندهٔ یک کانفیگ از مسیر رسمی node online stats PasarGuard v5.
     *
     * GET /api/node/online_stats/{user_id}/ip
     * → {nodes:{node_id:{ips:{ip:connection_count}}}}
     *
     * برخلاف users?online=true که online_at/پنجرهٔ ۲ دقیقه‌ای است، این endpoint
     * شمارش IP/connection جاری را از nodeهای سالم می‌گیرد. nodes خالی به معنی
     * «آفلاین» تلقی نمی‌شود: وقتی node سالمی برای پاسخ وجود ندارد، وضعیت ناشناخته
     * است و متر نباید دقیقه‌ای را ثبت کند.
     *
     * @return array{available:bool,connections:int,nodes:int}
     */
    public function user_online_connections(int $userId): array
    {
        $this->login();
        if ($userId <= 0) {
            throw new PanelError('invalid PasarGuard user id');
        }
        $data = $this->request('GET', '/api/node/online_stats/' . $userId . '/ip');
        $nodes = $data['nodes'] ?? null;
        if (!is_array($nodes)) {
            throw new PanelError('unexpected node online-stats response');
        }
        $available = 0;
        $connections = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ips = $node['ips'] ?? null;
            if (!is_array($ips)) {
                continue;
            }
            $available++;
            foreach ($ips as $count) {
                if (is_numeric($count) && (int) $count > 0) {
                    $connections += (int) $count;
                }
            }
        }
        return ['available' => $available > 0, 'connections' => $connections, 'nodes' => $available];
    }

    /**
     * حین تست اتصال، دسترسی واقعی لازم برای مدل «زمان آنلاین» را بررسی می‌کند.
     * یک شناسهٔ واقعی از /api/users انتخاب می‌شود؛ پس پاسخ ۲۰۰ از مسیر node stats
     * هم وجود endpoint و هم مجوز users.read / nodes.stats را اثبات می‌کند.
     */
    public function online_billing_capability(): array
    {
        $this->login();
        try {
            $data = $this->request('GET', '/api/users', null, ['limit' => 1, 'offset' => 0]);
            $users = is_array($data['users'] ?? null) ? $data['users'] : null;
            if ($users === null) {
                return ['ok' => false, 'state' => 'error', 'message' => 'پاسخ فهرست کاربران معتبر نیست.'];
            }
            if ($users === []) {
                return ['ok' => false, 'state' => 'unverified',
                    'message' => 'هنوز کاربری برای تست زنده وجود ندارد؛ در نخستین پنل فروخته‌شده دوباره بررسی می‌شود.'];
            }
            $id = (int) (($users[0]['id'] ?? 0));
            if ($id <= 0) {
                return ['ok' => false, 'state' => 'error', 'message' => 'شناسهٔ پایدار کاربر از API دریافت نشد.'];
            }
            $live = $this->user_online_connections($id);
            if (!$live['available']) {
                return ['ok' => false, 'state' => 'unverified',
                    'message' => 'node سالمی برای سنجش اتصال زنده پاسخ نداد؛ فعلاً هیچ دقیقه‌ای کسر نمی‌شود.'];
            }
            return ['ok' => true, 'state' => 'ok',
                'message' => 'دسترسی کاربران و آمار اتصال زندهٔ node تأیید شد.'];
        } catch (PanelError $ex) {
            if ($ex->status === 403) {
                return ['ok' => false, 'state' => 'permission',
                    'message' => 'کلید API باید مجوز users.read و nodes.stats داشته باشد.'];
            }
            if ($ex->status === 404) {
                return ['ok' => false, 'state' => 'unsupported',
                    'message' => 'endpoint آمار اتصال زندهٔ PasarGuard v5 در دسترس نیست.'];
            }
            return ['ok' => false, 'state' => 'error',
                'message' => 'بررسی اتصال زنده ناموفق بود: ' . mb_substr($ex->getMessage(), 0, 160)];
        }
    }

    /**
     * آمار سیستم پنل: نسخه، RAM سرور، CPU و تعداد کاربران.
     * PasarGuard/Marzban: GET /api/system — اگر نبود، خروجی خالی برمی‌گردد.
     */
    public function system_info(): array
    {
        $this->login();
        try {
            $data = $this->request('GET', '/api/system');
        } catch (Throwable $ex) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /** خواندن دوبارهٔ یک کانفیگ از پنل: GET /api/user/by-username/{username} */
    public function user_info(string $username): array
    {
        $this->login();
        return $this->request('GET', '/api/user/by-username/' . rawurlencode($username));
    }

    /** وضعیت یک کانفیگ (کاربر) روی پنل را عوض می‌کند: active | disabled */
    public function set_user_status(string $username, string $status): array
    {
        $this->login();
        return $this->request('PUT', '/api/user/' . rawurlencode($username),
            ['status' => $status === 'active' ? 'active' : 'disabled']);
    }

    /** خروج همهٔ دستگاه‌های ثبت‌شدهٔ یک کاربر پنل */
    public function reset_hwids(int $userId): array
    {
        $this->login();
        return $this->request('POST', '/api/user/' . $userId . '/hwids/reset');
    }
}

function panel_test(array $row): array
{
    $started = microtime(true);
    try {
        $client = new PgPanel($row);
        $me = $client->me();
        $who = (string) ($me['username'] ?? ($row['username'] ?? ''));
        $lines = ['اتصال برقرار شد — ادمین «' . $who . '»'];
        $lines[] = 'روش اتصال: 🔑 API Key';
        try {
            $roles = $client->roles();
            $lines[] = 'نقش‌ها: ' . count($roles) . ' عدد';
            set_put('roles_cache_' . (int) ($row['id'] ?? 0),
                json_encode(roles_normalize($roles), JSON_UNESCAPED_UNICODE));
        } catch (Throwable $ex) {
            $lines[] = 'نقش‌ها خوانده نشد: ' . mb_substr($ex->getMessage(), 0, 80);
        }
        try {
            $groups = $client->groups();
            $lines[] = 'گروه‌ها: ' . count($groups) . ' عدد';
        } catch (Throwable $ex) {
            $lines[] = 'گروه‌ها خوانده نشد';
        }
        /* این probe فقط خواندنی است و روشن می‌کند آیا همین API key واقعاً می‌تواند
           کاربران هر پنل را ببیند و اتصال زندهٔ node را بخواند یا نه. */
        try {
            $online = $client->online_billing_capability();
            $lines[] = 'صورت‌حساب زمان آنلاین: ' . ($online['ok'] ? '✅ ' : '⚠️ ')
                . (string) ($online['message'] ?? 'وضعیت نامشخص');
        } catch (Throwable $ex) {
            $lines[] = 'صورت‌حساب زمان آنلاین: ⚠️ بررسی نشد';
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $lines[] = 'زمان پاسخ: ' . fa_digits($ms) . ' میلی‌ثانیه';
        if (!empty($row['id'])) {
            DB::exec('UPDATE pg_panels SET last_ok_at=?, last_error=NULL WHERE id=?',
                [now_ts(), (int) $row['id']]);
        }
        return [true, implode("\n", $lines)];
    } catch (Throwable $ex) {
        $message = $ex->getMessage();
        if (str_contains($message, 'SSL') || str_contains($message, 'wrong version')) {
            $message .= ' — پنل احتمالاً روی http است، آدرس را با http:// بفرستید';
        }
        if (!empty($row['id'])) {
            DB::exec('UPDATE pg_panels SET last_error=?, last_ok_at=0 WHERE id=?',
                [mb_substr($message, 0, 300), (int) $row['id']]);
        }
        return [false, $message];
    }
}

