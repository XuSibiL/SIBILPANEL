<?php
declare(strict_types=1);

/* 5) Telegram client */

/**
 * POST JSON با ext-curl یا (اگر نصب نبود) با باینری curl.
 * خروجی: [بدنه یا null, متن خطا]
 */
function http_post_json(string $url, array $payload, int $timeout = 60): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return $body === false ? [null, $error] : [(string) $body, ''];
    }
    $binary = curl_binary();
    if ($binary === '') {
        return [null, 'neither ext-curl nor the curl binary is available'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'panel-post-');
    file_put_contents($tmp, $json);
    $cmd = escapeshellcmd($binary) . ' -sS --max-time ' . (int) $timeout
        . ' -X POST -H ' . escapeshellarg('Content-Type: application/json')
        . ' --data-binary ' . escapeshellarg('@' . $tmp)
        . ' ' . escapeshellarg($url) . ' 2>&1';
    $body = shell_exec($cmd);
    @unlink($tmp);
    if ($body === null || $body === '') {
        return [null, 'curl binary returned nothing'];
    }
    return [(string) $body, ''];
}

function curl_binary(): string
{
    static $path = null;
    if ($path === null) {
        $path = '';
        foreach (['/usr/bin/curl', '/usr/local/bin/curl', '/bin/curl'] as $candidate) {
            if (is_executable($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === '' && trim((string) shell_exec('command -v curl 2>/dev/null')) !== '') {
            $path = trim((string) shell_exec('command -v curl'));
        }
    }
    return $path;
}

/**
 * ایموجی‌های استاندارد یونیکد که روی هر اکانتی (عادی و پریمیوم) درست
 * نمایش داده می‌شوند. اگر جایی از ایموجی سفارشی (custom emoji) استفاده شد،
 * این لایه آن را به همین یونیکدها برمی‌گرداند تا دکمه هرگز خطای ۴۰۰ ندهد.
 */
const PLAIN_EMOJI_FALLBACK = [
    '⚙️', '🔌', '📊', '💳', '👥', '📢', '📦', '👤', '💰', '🛡',
    '🧾', '📈', '🔍', '🎁', '🎫', '💾', '♻️', '🪪', '☎️', '🔙',
];

/** متن را از تگ‌های ایموجی سفارشی پاک می‌کند و متن سادهٔ آن را می‌گذارد */
function tg_plain_text(string $text): string
{
    /* <tg-emoji emoji-id="…">😀</tg-emoji> → 😀 */
    $clean = preg_replace('~<tg-emoji[^>]*>(.*?)</tg-emoji>~su', '$1', $text);
    return is_string($clean) ? $clean : $text;
}

/** یک دکمه را از ایموجی سفارشی خالی می‌کند */
function tg_plain_button(array $button): array
{
    if (isset($button['icon_custom_emoji_id'])) {
        unset($button['icon_custom_emoji_id']);
    }
    if (isset($button['text'])) {
        $button['text'] = tg_plain_text((string) $button['text']);
    }
    foreach (['copy_text', 'switch_inline_query_chosen_chat'] as $key) {
        if (isset($button[$key]) && is_array($button[$key])) {
            $button[$key] = $button[$key];
        }
    }
    return $button;
}

/** کیبورد کامل را به شکل سازگار با همهٔ اکانت‌ها برمی‌گرداند */
function tg_plain_kb(?array $kb): ?array
{
    if ($kb === null) {
        return null;
    }
    $out = [];
    foreach ($kb as $row) {
        $cleanRow = [];
        foreach ((array) $row as $button) {
            $cleanRow[] = tg_plain_button((array) $button);
        }
        if ($cleanRow) {
            $out[] = $cleanRow;
        }
    }
    return $out;
}

/** آیا خطای تلگرام مربوط به ایموجی سفارشی / نبود پریمیوم است؟ */
function tg_custom_emoji_error(string $description): bool
{
    $needle = mb_strtolower($description);
    foreach (['custom_emoji', 'custom emoji', 'emoji_id', 'premium', 'button_text_invalid',
              "can't parse entities", 'unsupported'] as $hint) {
        if (str_contains($needle, $hint)) {
            return true;
        }
    }
    return false;
}

final class Tg
{
    public static bool $dry = false;
    public static array $answered = [];
    public static ?string $pendingAnswer = null;
    public static int $photoSeq = 500;
    public static array $outbox = [];
    /** جلوگیری از حلقهٔ بی‌پایان وقتی تلگرام حتی با ایموجی استاندارد هم خطا می‌دهد */
    public static bool $plainRetry = false;
    /** برای تست: پاسخ ساختگی getChat در حالت خشک */
    public static ?array $chatStub = null;
    /** برای تست: پاسخ ساختگی getChatMember در حالت خشک (true/false/null) */
    public static ?bool $memberStub = null;
    private static int $topicSeq = 100;
    private static int $updateOffset = 0;

    public static function call(string $method, array $params = []): ?array
    {
        if (self::$dry) {
            self::$outbox[] = [$method, $params];
            return ['ok' => true, 'result' => ['message_id' => count(self::$outbox)]];
        }
        $token = bot_token();
        if ($token === '') {
            log_line('error', 'PANEL_BOT_TOKEN is empty');
            return null;
        }
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        [$body, $error] = http_post_json($url, $params);
        if ($body === null) {
            log_line('warning', "telegram $method failed: $error");
            return null;
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            $description = is_array($json) ? (string) ($json['description'] ?? 'unknown error')
                : 'bad json';
            /* اگر خطا مربوط به ایموجی سفارشی/پریمیوم باشد، همان درخواست یک‌بار
               با ایموجی‌های استاندارد تکرار می‌شود تا دکمه‌ها هرگز از کار نیفتند */
            if (tg_custom_emoji_error($description) && empty(self::$plainRetry)) {
                self::$plainRetry = true;
                try {
                    $plain = $params;
                    if (isset($plain['text'])) {
                        $plain['text'] = tg_plain_text((string) $plain['text']);
                    }
                    if (isset($plain['caption'])) {
                        $plain['caption'] = tg_plain_text((string) $plain['caption']);
                    }
                    if (isset($plain['reply_markup']['inline_keyboard'])) {
                        $plain['reply_markup']['inline_keyboard'] =
                            tg_plain_kb((array) $plain['reply_markup']['inline_keyboard']);
                    }
                    log_line('info', 'telegram ' . $method . ': retried with standard emoji',
                        ['reason' => $description]);
                    $retry = self::call($method, $plain);
                    return $retry;
                } finally {
                    self::$plainRetry = false;
                }
            }
            if (!str_contains($description, 'message is not modified')) {
                log_line('warning', "telegram $method: $description");
            }
            return is_array($json) ? $json : null;
        }
        return $json;
    }

    public static function send(int|string $chat, string $text, ?array $kb = null): ?int
    {
        $params = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
                   'disable_web_page_preview' => true];
        $kb = kb_ensure_back(kb_clean($kb));
        if ($kb !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $kb];
        }
        $result = self::call('sendMessage', $params);
        return (int) ($result['result']['message_id'] ?? 0);
    }

    public static function edit(int|string $chat, int $messageId, string $text, ?array $kb = null): void
    {
        $params = ['chat_id' => $chat, 'message_id' => $messageId, 'text' => $text,
                   'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        $kb = kb_ensure_back(kb_clean($kb));
        if ($kb !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $kb];
        }
        self::call('editMessageText', $params);
    }

    public static function delete(int|string $chat, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }
        self::call('deleteMessage', ['chat_id' => $chat, 'message_id' => $messageId]);
    }

    /** ادیت اگر شد، وگرنه پیام تازه — همان رفتار show() در نسخهٔ پایتون */
    public static function show(int|string $chat, ?int $messageId, string $text, ?array $kb = null): void
    {
        $kb = kb_ensure_back(kb_clean($kb));
        if ($messageId) {
            $result = self::call('editMessageText', [
                'chat_id' => $chat, 'message_id' => $messageId, 'text' => $text,
                'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
                'reply_markup' => $kb === null ? null : ['inline_keyboard' => $kb],
            ]);
            if ($result !== null && (($result['ok'] ?? false) === true
                    || str_contains((string) ($result['description'] ?? ''), 'not modified'))) {
                return;
            }
        }
        self::send($chat, $text, $kb);
    }

    public static function photo(int|string $chat, string $fileId, string $caption, ?array $kb = null): void
    {
        $params = ['chat_id' => $chat, 'photo' => $fileId, 'caption' => $caption,
                   'parse_mode' => 'HTML'];
        $kb = kb_ensure_back(kb_clean($kb));
        if ($kb !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $kb];
        }
        self::call('sendPhoto', $params);
    }

    /** ارسال عکس به یک تاپیک — خروجی: شناسهٔ پیام */
    public static function photoThread(int|string $chat, string $fileId, string $caption,
                                       int $threadId = 0, ?array $kb = null): int
    {
        $params = ['chat_id' => $chat, 'photo' => $fileId, 'caption' => $caption,
                   'parse_mode' => 'HTML'];
        if ($threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        if ($kb !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $kb];
        }
        if (self::$dry) {
            self::$outbox[] = ['sendPhoto', $params];
            return (int) (self::$photoSeq = self::$photoSeq + 1);
        }
        $result = self::call('sendPhoto', $params);
        return (int) ($result['result']['message_id'] ?? 0);
    }

    /**
     * آپلود یک عکس از مسیر محلی (multipart) و برگرداندن file_id.
     * برای ارسال بنر نمونهٔ کارت در KYC لازم است؛ اگر ناموفق باشد '' برمی‌گردد.
     */
    public static function uploadPhoto(int|string $chat, string $path, string $caption = '',
                                       int $threadId = 0): string
    {
        if (self::$dry) {
            self::$outbox[] = ['sendPhoto', ['chat_id' => $chat, 'path' => $path,
                'caption' => $caption, 'message_thread_id' => $threadId]];
            return 'DRY-PHOTO-ID';
        }
        $token = bot_token();
        if ($token === '' || !is_file($path)) {
            return '';
        }
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return '';
        }
        $boundary = 'panel' . bin2hex(random_bytes(12));
        $fields = ['chat_id' => (string) $chat];
        if ($caption !== '') {
            $fields['caption'] = $caption;
            $fields['parse_mode'] = 'HTML';
        }
        if ($threadId > 0) {
            $fields['message_thread_id'] = (string) $threadId;
        }
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n"
                . $value . "\r\n";
        }
        $body .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="photo"; filename="banner.jpg"' . "\r\n"
            . "Content-Type: image/jpeg\r\n\r\n"
            . $content . "\r\n"
            . '--' . $boundary . "--\r\n";
        $url = "https://api.telegram.org/bot{$token}/sendPhoto";
        [$raw, $error] = http_request('POST', $url,
            ['Content-Type: multipart/form-data; boundary=' . $boundary], $body, false, 120);
        if ($raw === null) {
            log_line('warning', 'sendPhoto upload failed: ' . $error);
            return '';
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            log_line('warning', 'sendPhoto upload: ' . mb_substr((string) $raw, 0, 200));
            return '';
        }
        $sizes = $json['result']['photo'] ?? [];
        $best = is_array($sizes) && $sizes ? end($sizes) : [];
        return (string) ($best['file_id'] ?? '');
    }

    /** ویرایش کپشن یک عکس (کارت رسید) */
    public static function editCaption(int|string $chat, int $messageId, string $caption,
                                       ?array $kb = null): void
    {
        $params = ['chat_id' => $chat, 'message_id' => $messageId, 'caption' => $caption,
                   'parse_mode' => 'HTML'];
        if ($kb !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $kb];
        } else {
            $params['reply_markup'] = ['inline_keyboard' => []];
        }
        if (self::$dry) {
            self::$outbox[] = ['editMessageCaption', $params];
            return;
        }
        self::call('editMessageCaption', $params);
    }

    /** پاسخ سرتاسری را به آخر کار موکول می‌کند تا شعبه‌ها بتوانند خودشان جواب بدهند */
    public static function answer_later(string $callbackId): void
    {
        self::$pendingAnswer = $callbackId;
    }

    public static function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        if (self::$pendingAnswer !== null && self::$pendingAnswer === $callbackId) {
            self::$pendingAnswer = null;
        }
        if ($callbackId !== '' && isset(self::$answered[$callbackId])) {
            return;   // تلگرام برای پاسخ دوم خطا می‌دهد
        }
        if ($callbackId !== '') {
            self::$answered[$callbackId] = true;
            if (count(self::$answered) > 200) {
                self::$answered = array_slice(self::$answered, -100, null, true);
            }
        }
        self::call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $alert,
        ]);
    }

    /** ارسال پیام به یک تاپیک داخل گروه (message_thread_id) */
    public static function sendThread(int|string $chat, string $text, int $threadId = 0,
                                      ?array $kb = null): ?int
    {
        $params = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
                   'disable_web_page_preview' => true];
        if ($threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        $kb = kb_ensure_back(kb_clean($kb));
        if ($kb !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $kb];
        }
        $result = self::call('sendMessage', $params);
        return (int) ($result['result']['message_id'] ?? 0);
    }

    /** کپی یک پیام در چت دیگر (برای فروارد همگانی) */
    public static function copyMessage(int|string $chat, int|string $fromChat, int $messageId): bool
    {
        if (self::$dry) {
            self::$outbox[] = ['copyMessage', ['chat_id' => $chat, 'from_chat_id' => $fromChat,
                'message_id' => $messageId]];
            return true;
        }
        $result = self::call('copyMessage', ['chat_id' => $chat, 'from_chat_id' => $fromChat,
            'message_id' => $messageId]);
        return is_array($result) && (($result['ok'] ?? false) === true);
    }

    /** سنجاق / لغو سنجاق پیام در چت کاربر */
    public static function pin(int|string $chat, int $messageId, bool $on = true): void
    {
        if (self::$dry) {
            self::$outbox[] = [$on ? 'pinChatMessage' : 'unpinChatMessage',
                ['chat_id' => $chat, 'message_id' => $messageId]];
            return;
        }
        self::call($on ? 'pinChatMessage' : 'unpinChatMessage',
            ['chat_id' => $chat, 'message_id' => $messageId]);
    }

    public static function unpinAll(int|string $chat): void
    {
        if (self::$dry) {
            self::$outbox[] = ['unpinAllChatMessages', ['chat_id' => $chat]];
            return;
        }
        self::call('unpinAllChatMessages', ['chat_id' => $chat]);
    }

    /** اطلاعات یک چت (برای بررسی گروه گزارشات) */
    public static function getChat(int|string $chat): ?array
    {
        if (self::$dry) {
            self::$outbox[] = ['getChat', ['chat_id' => $chat]];
            return self::$chatStub;
        }
        $result = self::call('getChat', ['chat_id' => $chat]);
        $info = $result['result'] ?? null;
        return is_array($info) ? $info : null;
    }

    /** ساخت تاپیک در گروه انجمنی — خروجی: شناسهٔ تاپیک یا 0 */
    public static function createTopic(int|string $chat, string $name): int
    {
        if (self::$dry) {
            self::$outbox[] = ['createForumTopic', ['chat_id' => $chat, 'name' => $name]];
            if (self::$chatStub === null) {
                return 0;
            }
            return ++self::$topicSeq;
        }
        $result = self::call('createForumTopic', ['chat_id' => $chat, 'name' => $name]);
        return (int) ($result['result']['message_thread_id'] ?? 0);
    }

    /**
     * ارسال فایل به چت/تاپیک.
     * در حالت خشک فقط در outbox ثبت می‌شود.
     */
    public static function document(int|string $chat, string $path, string $caption = '',
                                    int $threadId = 0): bool
    {
        if (!is_file($path)) {
            return false;
        }
        if (self::$dry) {
            self::$outbox[] = ['sendDocument', ['chat_id' => $chat, 'path' => $path,
                'caption' => $caption, 'message_thread_id' => $threadId]];
            return true;
        }
        $token = bot_token();
        if ($token === '') {
            return false;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            log_line('warning', 'sendDocument: cannot read ' . $path);
            return false;
        }
        $name = basename($path);
        $boundary = 'panel' . bin2hex(random_bytes(12));
        $fields = ['chat_id' => (string) $chat, 'caption' => $caption];
        if ($threadId > 0) {
            $fields['message_thread_id'] = (string) $threadId;
        }
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n"
                . $value . "\r\n";
        }
        $body .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="document"; filename="' . $name . '"' . "\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $content . "\r\n"
            . '--' . $boundary . "--\r\n";
        $url = "https://api.telegram.org/bot{$token}/sendDocument";
        $headers = ['Content-Type: multipart/form-data; boundary=' . $boundary];
        [$raw, $error, $status] = http_request('POST', $url, $headers, $body, false, 300);
        if ($raw === null) {
            log_line('warning', 'sendDocument failed: ' . $error);
            return false;
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            log_line('warning', 'sendDocument: ' . mb_substr($raw, 0, 200));
            return false;
        }
        return true;
    }

    /**
     * گرفتن فایل از تلگرام (بکاپ ارسالی مالک) و ذخیره در مسیر موقت.
     * خروجی: [مسیر فایل یا '', خطا]
     */
    public static function download(string $fileId, string $destDir = ''): array
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return ['', 'شناسهٔ فایل خالی است'];
        }
        if (self::$dry) {
            self::$outbox[] = ['getFile', ['file_id' => $fileId]];
            return ['', 'dry-run'];
        }
        $token = bot_token();
        if ($token === '') {
            return ['', 'توکن ربات تنظیم نشده'];
        }
        $info = self::call('getFile', ['file_id' => $fileId]);
        $remote = (string) ($info['result']['file_path'] ?? '');
        if ($remote === '') {
            return ['', 'مسیر فایل از تلگرام نیامد'];
        }
        [$body, $error, $status] = http_request('GET',
            "https://api.telegram.org/file/bot{$token}/{$remote}", [], null, false, 300);
        if ($body === null || $status !== 200) {
            return ['', 'دانلود فایل انجام نشد: ' . ($error !== '' ? $error : 'HTTP ' . $status)];
        }
        $dir = $destDir !== '' ? rtrim($destDir, '/') : sys_get_temp_dir();
        $path = $dir . '/tg-' . bin2hex(random_bytes(4)) . '-'
            . basename($remote);
        if (@file_put_contents($path, $body) === false) {
            return ['', 'نوشتن فایل روی سرور انجام نشد'];
        }
        @chmod($path, 0600);
        return [$path, ''];
    }

    public static function getUpdates(int $timeout = 30): array
    {
        $result = self::call('getUpdates', [
            'offset'  => self::$updateOffset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        return $result['result'] ?? [];
    }

    public static function setOffset(int $offset): void
    {
        self::$updateOffset = $offset;
    }
}

/**
 * درخواست HTTP عمومی: با ext-curl اگر بود، وگرنه با باینری curl.
 * خروجی: [بدنه یا null, متن خطا, کد وضعیت]
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null,
                      bool $insecure = false, int $timeout = 25): array
{
    /* قلاب تست: درایورهای تست می‌توانند بدون شبکه پاسخ درگاه را شبیه‌سازی کنند */
    if (isset($GLOBALS['panel_http_mock']) && is_callable($GLOBALS['panel_http_mock'])) {
        return (array) call_user_func($GLOBALS['panel_http_mock'], $method, $url, $headers, $body);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($insecure) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $result === false ? [null, $error, 0] : [(string) $result, '', $status];
    }
    $binary = curl_binary();
    if ($binary === '') {
        return [null, 'neither ext-curl nor the curl binary is available', 0];
    }
    $tmpBody = null;
    $cmd = escapeshellcmd($binary) . ' -sS --max-time ' . (int) $timeout
        . ' -X ' . escapeshellarg(strtoupper($method))
        . ' -w ' . escapeshellarg('\n%{http_code}');
    if ($insecure) {
        $cmd .= ' -k';
    }
    foreach ($headers as $header) {
        $cmd .= ' -H ' . escapeshellarg($header);
    }
    if ($body !== null) {
        $tmpBody = tempnam(sys_get_temp_dir(), 'panel-body-');
        file_put_contents($tmpBody, $body);
        $cmd .= ' --data-binary ' . escapeshellarg('@' . $tmpBody);
    }
    $cmd .= ' ' . escapeshellarg($url) . ' 2>&1';
    $raw = shell_exec($cmd);
    if ($tmpBody !== null) {
        @unlink($tmpBody);
    }
    if ($raw === null || $raw === '') {
        return [null, 'curl binary returned nothing', 0];
    }
    $split = strrpos(rtrim($raw, "\n"), "\n");
    if ($split === false) {
        return [$raw, '', 0];
    }
    $payload = substr($raw, 0, $split);
    $status = (int) trim(substr($raw, $split + 1));
    return [$payload, '', $status];
}

/**
 * ساخت دکمه. رنگ دکمه‌ها (style) همان قالب Bot API است که نسخهٔ پایتون هم
 * استفاده می‌کند؛ اگر تلگرام پشتیبانی نکند خودش نادیده می‌گیرد.
 */
/**
 * رنگ‌های دکمه‌های غیر از منوی اصلی — هر دکمه رنگ خودش را دارد
 * (ثابت است تا هر بار باز شدن صفحه، رنگ‌ها نپرند).
 */
function auto_style(string $seed): string
{
    $palette = ['primary', 'success', 'danger', 'primary', 'success'];
    $hash = (int) sprintf('%u', crc32($seed));
    return $palette[$hash % count($palette)];
}

function btn(string $text, ?string $data = null, ?string $url = null, string $style = 'auto'): array
{
    if ($style === 'auto') {
        $style = auto_style($text . '|' . (string) $data . '|' . (string) $url);
    }
    $button = ['text' => $text];
    if ($data !== null) {
        $button['callback_data'] = $data;
    }
    if ($url !== null) {
        $button['url'] = $url;
    }
    if ($style !== '') {
        $button['style'] = $style;
    }
    return $button;
}

/**
 * دکمهٔ کپی واقعی — با فیلد copy_text تلگرام همان لحظهٔ زدن دکمه، مقدار
 * داخل کلیپ‌بورد گوشی می‌نشیند؛ لازم نیست کاربر روی عدد بزند.
 */
function copy_btn(string $text, string $value, string $style = 'success'): array
{
    $button = ['text' => $text, 'copy_text' => ['text' => $value]];
    if ($style !== '') {
        $button['style'] = $style;
    }
    return $button;
}

/** @param array<int, array<int, array>> $rows */
function kb(array $rows): array
{
    return kb_clean($rows);
}

/**
 * هر صفحه‌ای که دکمه دارد، حتماً یک دکمهٔ «بازگشت» هم دارد.
 * مقصد: اگر صفحه مدیریتی باشد «مدیریت»، وگرنه «منوی اصلی».
 * @param array<int, array<int, array>>|null $rows
 */
/* ── تاریخچهٔ صفحه‌ها: دکمهٔ «بازگشت» یک‌مرحله برمی‌گردد ───────────────── */

function nav_scope(string $data): string
{
    return str_starts_with($data, 'ad:') ? 'ad' : 'user';
}

/** تاریخچهٔ هر بخش جداست: خرید/پنل‌ها یک تاریخچه، پنل مدیریت تاریخچهٔ خودش */
function nav_state(int $uid): array
{
    $out = ['user' => [], 'ad' => []];
    if ($uid <= 0) {
        return $out;
    }
    $row = DB::one('SELECT data FROM nav_stack WHERE uid=?', [$uid]);
    $raw = $row === null ? [] : (json_decode((string) $row['data'], true) ?: []);
    if (!is_array($raw)) {
        return $out;
    }
    if (isset($raw['user']) || isset($raw['ad'])) {
        $out['user'] = array_values(array_filter((array) ($raw['user'] ?? []), 'is_string'));
        $out['ad'] = array_values(array_filter((array) ($raw['ad'] ?? []), 'is_string'));
        return $out;
    }
    /* ردیف‌های نسخهٔ قبل یک آرایهٔ ساده بودند */
    $out['user'] = array_values(array_filter($raw, 'is_string'));
    return $out;
}

function nav_save(int $uid, array $state): void
{
    if ($uid <= 0) {
        return;
    }
    $clean = [
        'user' => array_slice(array_values(array_filter((array) ($state['user'] ?? []), 'is_string')), -25),
        'ad'   => array_slice(array_values(array_filter((array) ($state['ad'] ?? []), 'is_string')), -25),
    ];
    DB::exec('INSERT INTO nav_stack(uid, data, updated_at) VALUES(?,?,?) '
        . 'ON CONFLICT(uid) DO UPDATE SET data=excluded.data, updated_at=excluded.updated_at',
        [$uid, json_encode($clean, JSON_UNESCAPED_UNICODE), now_ts()]);
}

function nav_get(int $uid, string $scope = 'user'): array
{
    $state = nav_state($uid);
    return $state[$scope] ?? [];
}

function nav_set(int $uid, array $stack, string $scope = 'user'): void
{
    if ($uid <= 0) {
        return;
    }
    $state = nav_state($uid);
    $state[$scope] = $stack;
    nav_save($uid, $state);
}

function nav_push(int $uid, string $data): void
{
    if ($uid <= 0 || $data === '') {
        return;
    }
    foreach (['nav:', 'wiz:', 'wizrole:', 'cancel'] as $skip) {
        if (str_starts_with($data, $skip) || $data === $skip) {
            return;
        }
    }
    $scope = nav_scope($data);
    if (($scope === 'user' && $data === 'home') || ($scope === 'ad' && $data === 'ad:home')) {
        /* صفحهٔ اول همان بخش، تاریخچهٔ خودش را صفر می‌کند و خودش نقطهٔ شروع می‌شود */
        nav_set($uid, [$data], $scope);
        return;
    }
    $stack = nav_get($uid, $scope);
    /* اگر مقصد از قبل در تاریخچه هست، به همان نقطه برمی‌گردیم؛
       وگرنه دکمه‌های «بازگشت» قدیمی باعث رفت‌وبرگشت بین دو صفحه می‌شوند */
    $pos = array_search($data, $stack, true);
    if ($pos !== false) {
        $stack = array_slice($stack, 0, (int) $pos + 1);
        nav_set($uid, $stack, $scope);
        return;
    }
    if ($stack !== [] && end($stack) === $data) {
        return;
    }
    $stack[] = $data;
    nav_set($uid, $stack, $scope);
}

/** یک صفحه عقب در همان بخش؛ خروجی: دادهٔ صفحهٔ قبلی یا رشتهٔ خالی */
function nav_back(int $uid, string $scope = 'user'): string
{
    $stack = nav_get($uid, $scope);
    if ($stack === []) {
        return '';
    }
    array_pop($stack);
    $prev = $stack === [] ? '' : (string) end($stack);
    nav_set($uid, $stack, $scope);
    return $prev;
}

function kb_ensure_back(?array $rows): ?array
{
    if ($rows === null || $rows === []) {
        return $rows;
    }
    $isAdmin = false;
    $isRoot = false;
    $hasBack = false;
    if (!empty($GLOBALS['panel_console_root'])) {
        return $rows;   // صفحهٔ اول پنل مدیریت، بدون دکمهٔ بازگشت
    }
    if (!empty($GLOBALS['panel_kb_locked'])) {
        return $rows;   // مرحلهٔ فرم: فقط «❌ انصراف»، نه بازگشت اضافه
    }
    $menuCount = 0;
    foreach ($rows as $row) {
        foreach ($row as $button) {
            $data = (string) ($button['callback_data'] ?? '');
            $text = (string) ($button['text'] ?? '');
            if (str_starts_with($data, 'menu:')) {
                $menuCount++;
            }
            if (str_starts_with($data, 'ad:')) {
                $isAdmin = true;
            }
            if ($data === 'nav:back' || str_starts_with($data, 'nav:back:')) {
                $hasBack = true;
            }
            if (preg_match('/^[«↩🔙]/u', $text) === 1) {
                $hasBack = true;   // این صفحه خودش دکمهٔ بازگشت دارد
            }
        }
    }
    if ($menuCount >= 5) {
        return $rows;   // منوی اصلی صفحهٔ اول است و دکمهٔ «⚙️ پنل مدیریت» باید همان بماند
    }
    /* بخش از روی صفحهٔ جاری تعیین می‌شود، نه از وجود یک دکمهٔ «ad:» در
       صفحه — همین باعث می‌شد بازگشت از صفحهٔ خرید به پنل مدیریت بپرد. */
    $scope = $GLOBALS['panel_nav_scope'] ?? ($isAdmin ? 'ad' : 'user');
    $backData = $scope === 'ad' ? 'nav:back:ad' : 'nav:back';

    /* هر دکمه‌ای که به «خانه» می‌رفت، حالا فقط یک قدم عقب می‌رود */
    foreach ($rows as $rowIndex => $row) {
        foreach ($row as $buttonIndex => $button) {
            $data = (string) ($button['callback_data'] ?? '');
            if ($scope === 'ad' && $data === 'ad:home') {
                $rows[$rowIndex][$buttonIndex]['callback_data'] = $backData;
                $hasBack = true;
            }
            if ($scope === 'user' && $data === 'home') {
                $rows[$rowIndex][$buttonIndex]['callback_data'] = $backData;
                $hasBack = true;
            }
        }
    }
    if (!$hasBack) {
        $rows[] = [btn('🔙 بازگشت', $backData, null, 'danger')];
    }
    return $rows;
}


/**
 * استانداردسازی همهٔ دکمه‌های بازگشت در سرتاسر ربات.
 * قانون: متن دکمه فقط «🔙 بازگشت» است و کارش رفتن به یک قدم قبل
 * (پشتهٔ ناوبری) — نه پرش به یک بخش نامربوط.
 * دکمه‌های «لغو» و «انصراف» دکمهٔ بازگشت نیستند و دست نمی‌خورند.
 */
function kb_back_normalize(array $button): array
{
    $text = trim((string) ($button['text'] ?? ''));
    if ($text === '') {
        return $button;
    }
    $data = (string) ($button['callback_data'] ?? '');
    $scope = (string) ($GLOBALS['panel_nav_scope'] ?? '');
    if ($scope === '') {
        $scope = str_starts_with($data, 'ad:') ? 'ad' : 'user';
    }
    $backData = $scope === 'ad' ? 'nav:back:ad' : 'nav:back';
    /* انصراف/لغو: متن تمیز می‌شود ولی کارکردش همان لغو مرحله است */
    if (preg_match('/لغو|انصراف/u', $text) === 1
            && preg_match('/^[«↩🔙❌]/u', $text) === 1 && !str_contains($text, 'بازگشت')) {
        $button['text'] = '❌ ' . preg_replace('/^[«↩🔙❌\s]+/u', '', $text);
        return $button;
    }
    /* هر دکمه‌ای که با « شروع می‌شود، دکمهٔ بازگشت است */
    if (preg_match('/^[«↩]/u', $text) === 1) {
        $button['text'] = '🔙 بازگشت';
        $button['callback_data'] = $backData;
        return $button;
    }
    /* دکمه‌های بازگشتِ استاندارد همیشه به پشته وصل می‌شوند (به‌جز KYC که
       مسیر داخلی خودش را دارد) */
    if (preg_match('/^🔙/u', $text) === 1 && !str_starts_with($data, 'kyc:')) {
        $button['text'] = '🔙 بازگشت';
        if (!str_starts_with($data, 'nav:back')) {
            $button['callback_data'] = $backData;
        }
        return $button;
    }
    return $button;
}

function kb_clean(?array $rows): ?array
{
    if ($rows === null) {
        return null;
    }
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $cleanRow = [];
        foreach ($row as $button) {
            $key = (string) ($button['callback_data'] ?? '') . '|' . (string) ($button['url'] ?? '')
                . '|' . (string) ($button['copy_text']['text'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            /* هر دکمه باید رنگ داشته باشد؛ بی‌رنگ یعنی «شیشه‌ای» و کاربر
               دکمه را به‌عنوان دکمه نمی‌بیند. رنگ‌های خود ادمین حفظ می‌شود. */
            if (!isset($button['style']) || (string) $button['style'] === '') {
                $button['style'] = 'primary';
            }
            $button = kb_back_normalize($button);
            $cleanRow[] = $button;
        }
        if ($cleanRow) {
            $out[] = $cleanRow;
        }
    }
    return $out;
}

