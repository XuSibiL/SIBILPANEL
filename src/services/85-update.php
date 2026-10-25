<?php
declare(strict_types=1);

/*
   8) SELF-UPDATE — نسخهٔ تازه از مخزن گیت‌هاب، بدون دست‌زدن به داده.

   مسیر کار:
     ۱) نسخهٔ منتشرشده از API گیت‌هاب خوانده می‌شود (ریلیز، وگرنه فایل VERSION)،
     ۲) اگر از نسخهٔ نصب‌شده تازه‌تر بود، آرشیو همان برنچ دانلود می‌شود،
     ۳) فقط فایل‌های کد جایگزین می‌شوند؛ .env و دیتابیس و لاگ و بکاپ دست‌نخورده
        می‌مانند،
     ۴) مایگریشن اجرا و سرویس نرم ری‌استارت می‌شود.
*/

const UPDATE_DEFAULT_REPO = 'XuSibiL/SIBILPANEL';
/* کش فقط برای تیک خودکار/نمایش است؛ هر بررسیِ کاربر همیشه تازه خوانده می‌شود */
const UPDATE_CACHE_TTL = 1800;   // فقط برای نشان نسخه در پنل مدیریت
const UPDATE_RAW_CACHE_TTL = 300;

/** مخزن رسمی: مقدار .env وگرنه مخزن پیش‌فرض */
function update_repo(): string
{
    $repo = trim(cfg('PANEL_UPDATE_REPO'));
    if ($repo === '') {
        $repo = UPDATE_DEFAULT_REPO;
    }
    $repo = preg_replace('#^https?://(www\.)?github\.com/#i', '', $repo) ?? $repo;
    $repo = preg_replace('#\.git$#i', '', $repo) ?? $repo;
    return trim($repo, "/ \t");
}

function update_branch(): string
{
    $branch = trim(cfg('PANEL_UPDATE_BRANCH'));
    return $branch !== '' ? $branch : 'main';
}

/** آدرس دانلود آرشیو کد همین برنچ */
function update_archive_url(): string
{
    $mirror = trim(cfg('PANEL_UPDATE_ARCHIVE_URL'));
    if ($mirror !== '') {
        return $mirror;
    }
    return 'https://codeload.github.com/' . update_repo() . '/tar.gz/refs/heads/' . rawurlencode(update_branch());
}

/** مقایسهٔ نسخه؛ true یعنی نسخهٔ دوردست تازه‌تر است */
function update_is_newer(string $remote, string $local): bool
{
    $remote = trim(ltrim($remote, 'vV'));
    $local = trim(ltrim($local, 'vV'));
    if ($remote === '' || !preg_match('/^\d+(\.\d+)*$/', $remote)) {
        return false;
    }
    return version_compare($remote, $local, '>');
}

/**
 * یک GET ساده با هدر گیت‌هاب؛ خروجی [بدنه، کد وضعیت].
 * هدر no-cache اجباری است تا CDN گیت‌هاب نسخهٔ قدیمی را برنگرداند.
 */
function update_http_get(string $url, int $timeout = 12): array
{
    [$body, $error, $status] = http_request('GET', $url, [
        'User-Agent: panel-updater',
        'Accept: application/vnd.github+json',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ], null, false, $timeout);
    if ($body === null || $body === '') {
        return ['', (int) $status];
    }
    return [(string) $body, (int) $status];
}

/** آدرس با پارامتر ضدفشرده‌سازی تا همان لحظه نسخهٔ تازه بیاید */
function update_bust_url(string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 't=' . time() . mt_rand(100, 999);
}

/**
 * نسخهٔ فایل VERSION از Contents API گیت‌هاب.
 * `raw.githubusercontent.com` تا ۵ دقیقه پس از پوش، نسخهٔ کهنه را از کش
 * (x-cache: HIT) برمی‌گرداند؛ این مسیر همان لحظه به‌روز است.
 */
function update_version_contents_api(string $repo, string $branch): string
{
    [$body, $status] = update_http_get(update_bust_url(
        'https://api.github.com/repos/' . $repo . '/contents/VERSION?ref='
        . rawurlencode($branch)), 10);
    if ($status < 200 || $status >= 300 || $body === '') {
        return '';
    }
    $json = json_decode($body, true);
    if (!is_array($json) || ($json['encoding'] ?? '') !== 'base64'
        || !isset($json['content'])) {
        return '';
    }
    /* JSON رشتهٔ base64 را با «\\n» می‌فرستد؛ قبل از decode پاک می‌شود */
    $b64 = (string) $json['content'];
    $b64 = str_replace(chr(92) . 'n', '', $b64);
    $b64 = str_replace(["\n", "\r", ' '], '', $b64);
    $decoded = base64_decode($b64, true);
    if ($decoded === false) {
        return '';
    }
    $version = trim($decoded);
    return preg_match('/^\d+(\.\d+)*$/', $version) ? $version : '';
}

/** بالاترین نسخهٔ معتبر از میان چند مقدار */
function update_max_version(string ...$versions): string
{
    $best = '';
    foreach ($versions as $raw) {
        $candidate = trim(ltrim(trim($raw), 'vV'));
        if (!preg_match('/^\d+(\.\d+)*$/', $candidate)) {
            continue;
        }
        if ($best === '' || version_compare($candidate, $best, '>')) {
            $best = $candidate;
        }
    }
    return $best;
}

/**
 * آخرین نسخهٔ منتشرشده در گیت‌هاب.
 *  · `$force = true` (پیش‌فرض): همیشه تازه خوانده می‌شود — دکمهٔ «بررسی» و CLI
 *  · `$force = false`: تیک خودکار/نمایش، با کش کوتاه
 * مقدار نهایی = بالاترین نسخهٔ بین «ریلیز»، «تگ‌ها» و «فایل VERSION برنچ»، تا
 * هیچ منبع قدیمی نسخهٔ تازه‌تر را پنهان نکند. کش فقط وقتی استفاده می‌شود که
 * هیچ منبعی جواب ندهد (آفلاین).
 */
function update_remote_version(bool $force = true): array
{
    $repo = update_repo();
    $cached = json_decode((string) set_get('update_remote', ''), true);
    if (!$force && is_array($cached)
        && (int) ($cached['at'] ?? 0) > time() - UPDATE_CACHE_TTL
        && (string) ($cached['version'] ?? '') !== '') {
        return ['version' => (string) $cached['version'],
                'source' => (string) ($cached['source'] ?? 'cache'), 'cached' => true];
    }

    $release = '';
    $versionFile = '';
    $contents = '';
    $tags = '';

    /* ۱) فایل VERSION خام — سریع و بدون مصرف سهمیهٔ API گیت‌هاب */
    [$raw, $code] = update_http_get(update_bust_url(
        'https://raw.githubusercontent.com/' . $repo . '/' . update_branch() . '/VERSION'), 10);
    $raw = trim($raw);
    if ($code >= 200 && $code < 300 && preg_match('/^\d+(\.\d+)*$/', $raw)) {
        $versionFile = $raw;
    }

    /* ۲) اگر همین حالا نسخهٔ تازه‌تری دیده شد، کار تمام است (بدون درخواست API).
       وگرنه ممکن است فایل خام از کش CDN عقب باشد؛ پس API را می‌پرسیم:
       اول ریلیز (بعد از هر انتشار همان لحظه به‌روز)، بعد Contents API. */
    if ($versionFile === '' || !update_is_newer($versionFile, PANEL_VERSION)) {
        [$body, $status] = update_http_get(update_bust_url(
            'https://api.github.com/repos/' . $repo . '/releases/latest'));
        if ($status >= 200 && $status < 300 && $body !== '') {
            $json = json_decode($body, true);
            $tag = is_array($json) ? (string) ($json['tag_name'] ?? '') : '';
            if ($tag !== '') {
                $release = ltrim($tag, 'vV');
            }
        }
        if ($release === '' || !update_is_newer($release, PANEL_VERSION)) {
            $contents = update_version_contents_api($repo, update_branch());
        }
        if ($release === '' && $contents === '' && $versionFile === '') {
            [$tagBody, $tagStatus] = update_http_get(update_bust_url(
                'https://api.github.com/repos/' . $repo . '/tags?per_page=50'), 10);
            if ($tagStatus >= 200 && $tagStatus < 300 && $tagBody !== '') {
                $list = json_decode($tagBody, true);
                if (is_array($list)) {
                    foreach ($list as $row) {
                        $tags = update_max_version($tags, (string) ($row['name'] ?? ''));
                    }
                }
            }
        }
    }

    $version = update_max_version($versionFile, $release, $contents, $tags);

    if ($version !== '') {
        $source = $version === $versionFile ? 'version-file'
            : ($version === $release ? 'release'
                : ($version === $contents ? 'contents-api' : 'tags'));
        set_put('update_remote', json_encode(
            ['version' => $version, 'source' => $source, 'at' => time()]));
        return ['version' => $version, 'source' => $source, 'cached' => false];
    }

    /* هیچ منبعی جواب نداد: آخرین مقدار شناخته‌شده (حالت آفلاین) */
    if (is_array($cached) && (string) ($cached['version'] ?? '') !== '') {
        return ['version' => (string) $cached['version'],
                'source' => 'offline-cache', 'cached' => true];
    }
    return ['version' => '', 'source' => 'unavailable', 'cached' => false];
}

/** وضعیت کامل: نسخهٔ محلی، نسخهٔ دوردست، و این‌که آپدیت هست یا نه */
function update_status(bool $force = true): array
{
    $remote = update_remote_version($force);
    return [
        'local'     => PANEL_VERSION,
        'remote'    => $remote['version'],
        'source'    => $remote['source'],
        'available' => update_is_newer($remote['version'], PANEL_VERSION),
        'repo'      => update_repo(),
        'branch'    => update_branch(),
    ];
}

/** آرشیو را دانلود و در پوشهٔ موقت باز می‌کند؛ خروجی [مسیر ریشه یا null، پیام خطا] */
function update_download_archive(): array
{
    $tmp = sys_get_temp_dir() . '/panel-update-' . bin2hex(random_bytes(4));
    if (!@mkdir($tmp, 0700, true)) {
        return [null, 'temp directory is not writable'];
    }
    $archive = $tmp . '/code.tar.gz';
    $url = update_archive_url();

    [$body, $status] = update_http_get($url, 60);
    if ($status < 200 || $status >= 300 || $body === '') {
        return [null, 'download failed (HTTP ' . (int) $status . ')'];
    }
    if (@file_put_contents($archive, $body) === false) {
        return [null, 'could not write the archive'];
    }

    $extract = $tmp . '/x';
    @mkdir($extract, 0700, true);
    $tar = trim((string) shell_exec('command -v tar 2>/dev/null'));
    if ($tar === '') {
        return [null, 'tar is not available on this server'];
    }
    @shell_exec(escapeshellcmd($tar) . ' -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($extract) . ' 2>&1');
    $roots = glob($extract . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($roots as $root) {
        if (is_file($root . '/sibil.php')) {
            return [$root, ''];
        }
    }
    return [null, 'the archive does not contain the bot files'];
}

/** لیست چیزهایی که آپدیت هرگز به آن‌ها دست نمی‌زند */
function update_protected(): array
{
    return ['.env', '.env.local', '.installer-url', 'sibil.sqlite3', 'panel.sqlite3',
        'sibil.pid', 'logs', 'backups', 'uploads', 'tests', 'release'];
}

/** کپی امن: فقط فایل‌های کد، بدون حذف داده و تنظیمات */
function update_copy_tree(string $from, string $to, string $rel = ''): array
{
    $copied = 0;
    $protected = update_protected();
    $items = @scandir($from);
    if ($items === false) {
        return [0, ['cannot read ' . $from]];
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') {
            continue;
        }
        if ($rel === '' && in_array($item, $protected, true)) {
            continue;
        }
        if (str_starts_with($item, '.env') || str_ends_with($item, '.sqlite3')
            || str_ends_with($item, '.sqlite3-wal') || str_ends_with($item, '.sqlite3-shm')) {
            continue;
        }
        $src = $from . '/' . $item;
        $dst = $to . '/' . $item;
        if (is_dir($src)) {
            if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
                return [$copied, ['cannot create ' . $dst]];
            }
            [$n, $err] = update_copy_tree($src, $dst, $rel === '' ? $item : $rel . '/' . $item);
            $copied += $n;
            if ($err) {
                return [$copied, $err];
            }
            continue;
        }
        if (@copy($src, $dst)) {
            @chmod($dst, 0644);
            $copied++;
        }
    }
    return [$copied, []];
}

/** ری‌استارت نرم: پرچم خروج تمیز، وگرنه ری‌استارت پشت‌صحنه */
function update_restart(?string $target = null): string
{
    $target = $target ?? panel_root();
    /* برای نصب‌های دستی یا تست، ری‌استارت خودکار با PANEL_UPDATE_RESTART=0 خاموش می‌شود */
    if (cfg('PANEL_UPDATE_RESTART') === '0') {
        return 'skipped';
    }
    $flag = $target . '/.restart-request';
    @touch($flag);
    $php = PHP_BINARY;
    $script = $target . '/sibil.php';
    $log = $target . '/sibil.log';
    $pid = (int) @file_get_contents($target . '/sibil.pid');
    $inner = 'sleep 4';
    if ($pid > 0) {
        $inner .= '; kill -TERM ' . $pid . ' 2>/dev/null';
    }
    $inner .= '; sleep 1; cd ' . escapeshellarg($target)
        . ' && nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' --poll >> ' . escapeshellarg($log) . ' 2>&1 &';
    @shell_exec('nohup bash -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');
    return 'restarting';
}

/** آپدیت کامل؛ خروجی [موفق؟، پیام] */
function update_apply(?string $target = null): array
{
    $target = $target !== null ? rtrim($target, '/') : panel_root();
    $status = update_status(true);
    /* آینه/فورس: وقتی آرشیو دستی تنظیم شده، نسخه از خودِ آرشیو خوانده می‌شود */
    $forced = trim(cfg('PANEL_UPDATE_ARCHIVE_URL')) !== '' || cfg('PANEL_UPDATE_FORCE') === '1';
    /* اگر فایل VERSION روی دیسک با نسخهٔ کد یکی نیست (سرور نیمه‌آپدیت‌شده)،
       آپدیت باید اجرا شود حتی وقتی گیت‌هاب نسخهٔ تازه‌تری نشان نمی‌دهد */
    $onDisk = trim((string) @file_get_contents($target . '/VERSION'));
    $mismatch = $onDisk !== '' && $onDisk !== PANEL_VERSION;
    if (!$status['available'] && !$forced && !$mismatch) {
        return [false, 'already-latest'];
    }
    $version = (string) $status['remote'];

    [$root, $error] = update_download_archive();
    if ($root === null) {
        return [false, $error];
    }

    /* فایل اصلی باید سالم باشد، وگرنه نسخهٔ فعلی دست‌نخورده می‌ماند */
    $lint = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l '
        . escapeshellarg($root . '/sibil.php') . ' 2>&1'));
    if (!str_contains($lint, 'No syntax errors')) {
        return [false, 'the downloaded code failed the syntax check'];
    }

    [$copied, $errors] = update_copy_tree($root, $target);
    if ($errors) {
        return [false, (string) $errors[0]];
    }
    if ($copied < 3) {
        return [false, 'nothing was copied'];
    }

    /* نسخهٔ واقعیِ نصب‌شده بعد از کپی خوانده می‌شود (برای حالت آینه) */
    $versionFile = trim((string) @file_get_contents($target . '/VERSION'));
    if ($versionFile !== '') {
        $version = $versionFile;
    }

    /* مایگریشن جدا اجرا می‌شود تا جدول‌ها با کد تازه هم‌قدم شوند */
    @shell_exec('cd ' . escapeshellarg($target) . ' && '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($target . '/sibil.php')
        . ' --migrate >/dev/null 2>&1');

    set_put('update_remote', json_encode(['version' => $version, 'source' => 'applied', 'at' => time()]));
    set_put('update_last_at', (string) time());
    @unlink($root . '/../code.tar.gz');

    $mode = update_restart($target);
    return [true, json_encode(['version' => $version, 'copied' => $copied, 'restart' => $mode])];
}

/* ── صفحهٔ مدیریت: بروزرسانی ─────────────────────────────────────────────── */

function update_screen_text(bool $force = true): string
{
    $status = update_status($force);
    $remote = $status['remote'] !== '' ? $status['remote'] : 'نامشخص';
    $line = $status['available']
        ? '🟠 نسخهٔ جدید در دسترس است.'
        : ($status['remote'] === ''
            ? '⚪️ نسخهٔ دوردست خوانده نشد؛ اتصال سرور به گیت‌هاب را بررسی کنید.'
            : '🟢 روی آخرین نسخه هستید.');

    $offlineNote = ($status['source'] ?? '') === 'offline-cache'
        ? "⚪️ اتصال به گیت‌هاب برقرار نشد؛ این شماره از آخرین بررسی ذخیره شده است."
            . "\n" : '';
    return '⬆️ <b>بروزرسانی ربات</b>' . "\n" . SEP . "\n"
        . 'نسخهٔ نصب‌شده: <b>' . e($status['local']) . '</b>' . "\n"
        . 'آخرین نسخه در گیت‌هاب: <b>' . e($remote) . '</b>' . "\n"
        . $offlineNote
        . $line . "\n" . SEP . "\n"
        . '📦 منبع: <code>' . e($status['repo']) . '</code> · شاخهٔ ' . e($status['branch']) . "\n"
        . 'دیتابیس، فایل تنظیمات و بکاپ‌ها در آپدیت دست‌نخورده می‌مانند.';
}

function update_screen_kb(bool $available): array
{
    $rows = [];
    if ($available) {
        $rows[] = [btn('⬆️ بروزرسانی کن', 'ad:upd:run', null, 'danger')];
    }
    $rows[] = [btn('🔄 بررسی مجدد', 'ad:upd:check', null, 'primary')];
    $rows[] = [btn('« بازگشت به تنظیمات', 'ad:settings', null, 'danger')];
    return kb_clean($rows);
}

function screen_update(int|string $chat, ?int $messageId, bool $force = true): void
{
    $status = update_status($force);
    Tg::show($chat, $messageId, update_screen_text($force), update_screen_kb((bool) $status['available']));
}

/** دکمهٔ «بروزرسانی کن» — انجام، گزارش نتیجه به همان پیام */
function update_run_from_bot(int|string $chat, ?int $messageId): void
{
    [$ok, $message] = update_apply();
    if (!$ok) {
        $text = $message === 'already-latest'
            ? '✅ شما روی آخرین نسخه هستید و نیازی به بروزرسانی نیست.'
            : '⚠️ بروزرسانی انجام نشد: ' . e($message);
        Tg::show($chat, $messageId, $text . "\n" . SEP . "\n" . update_screen_text(true),
            update_screen_kb(false));
        return;
    }
    $data = (array) json_decode($message, true);
    $new = (string) ($data['version'] ?? '');
    Tg::show($chat, $messageId,
        '✅ ربات با موفقیت به نسخهٔ <b>' . e($new) . '</b> بروزرسانی شد.' . "\n" . SEP . "\n"
        . 'دیتابیس و تنظیمات دست‌نخورده ماندند. سرویس همین حالا ری‌استارت می‌شود.',
        [[btn('🔄 بررسی وضعیت', 'ad:upd', null, 'success')]]);
}
