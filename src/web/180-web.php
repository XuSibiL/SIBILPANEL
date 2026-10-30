<?php
declare(strict_types=1);

/*
   22) WEB ENDPOINT — کال‌بک درگاه‌ها (php -S):
       /pay/zarinpal · /pay/aqayepardakht · /pay/iranpay1|2|4 ·
       /pay/nowpayment (IPN) · /pay/plisio · /pay/variza + webhook · /pay/tetrapay + webhook · /pay/trx
*/

function web_input(): array
{
    $raw = (string) file_get_contents('php://input');
    $json = json_decode($raw, true);
    return [
        'raw'  => $raw,
        'json' => is_array($json) ? $json : [],
        'get'  => $_GET ?? [],
        'post' => $_POST ?? [],
    ];
}

/** Normalize callback lookup hints from browser query/form returns and modern
    provider JSON webhooks. Values remain untrusted: every route below still
    calls its provider verify/status API (or checks a documented signature)
    before it can settle a local payment. */
function web_callback_fields(array $input): array
{
    $fields = [];
    foreach (['get', 'post', 'json'] as $source) {
        $part = is_array($input[$source] ?? null) ? $input[$source] : [];
        $nested = is_array($part['data'] ?? null) ? $part['data'] : [];
        $fields = array_replace($fields, $nested, $part);
    }
    return $fields;
}

function web_callback_value(array $fields, array $keys): string
{
    foreach ($keys as $key) {
        $value = $fields[$key] ?? null;
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return mb_substr($value, 0, 255);
            }
        }
    }
    return '';
}

function web_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === strtolower($name)) {
                return (string) $v;
            }
        }
    }
    return '';
}

function web_json(int $code, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function web_html(string $html): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $html;
}

function web_status(int $code): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
}

/**
 * This is the legacy website-style Tetra callback landing page. Current
 * SIBIL Tetra bot checkouts return through Telegram; this harmless response is
 * retained for older website-style links and can never reach settlement code.
 */
function tetrapay_callback_ready_page(): string
{
    // Keep this page dependency-free: provider callback validation must not be
    // delayed by SQLite, configuration, Telegram, or the bot settings layer.
    return '<!doctype html><meta charset="utf-8"><title>SIBIL payment return</title>'
        . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px">'
        . '<h2>SIBIL payment return is online.</h2>'
        . '<p>This endpoint verifies a payment server-side after a provider token arrives.</p>'
        . '<p>No wallet or order has been credited by this health response.</p></body>';
}

/** مسیریابی درخواست وب */
function web_handle(): void
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $path = rtrim($path, '/');

    /*
     * Keep older website-style callbacks harmless and dependency-free. New
     * bot-gateway checkouts return by Telegram deep link instead. Any query/
     * post/body callback still follows authoritative lookup + provider verify;
     * this bare route can never settle.
     */
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($path === '/pay/tetrapay' && ($method === 'GET' || $method === 'HEAD')
        && ($_GET ?? []) === [] && ($_POST ?? []) === []) {
        web_html(tetrapay_callback_ready_page());
        return;
    }

    load_env();
    db_bootstrap();
    settings_bootstrap();
    $input = web_input();

    if ($path === '' || $path === '/') {
        web_html('<!doctype html><meta charset="utf-8"><title>' . e(bot_name()) . '</title>'
            . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px">'
            . '<h2>' . e(bot_name()) . ' ' . PANEL_VERSION . '</h2>'
            . '<p>payment endpoint is running.</p></body>');
        return;
    }
    if (!str_starts_with($path, '/pay/')) {
        web_status(404);
        web_html('not found');
        return;
    }
    $gw = substr($path, 5);
    /* Existing Aban installations use /pay/iranpay4. Accept the common
       webhook suffix too without changing the configured callback address. */
    if ($gw === 'iranpay4/webhook') {
        $gw = 'iranpay4';
    }

    /*
     * قاعدهٔ طلایی این بخش: هیچ پرداختی با «برگشتن کاربر» موفق نمی‌شود.
     * هر بار که چیزی از سمت کاربر می‌رسد، وضعیت از خود درگاه (سمت سرور)
     * پرسیده می‌شود و فقط جواب مثبتِ آن، سفارش را تحویل می‌دهد.
     */

    /* وبهوک واریزا */
    if ($gw === 'variza/webhook') {
        $result = pay_variza_webhook($input['json'], $input['raw'],
            web_header('X-Webhook-Signature'));
        web_json((int) ($result['code'] ?? 200), ['status' => $result['text'] ?? 'ok']);
        return;
    }

    /* وب‌هوک امضاشدهٔ اختیاری تتراپی؛ پیش از هر پردازش HMAC بررسی می‌شود. */
    if ($gw === 'tetrapay/webhook') {
        $signature = web_header('X-TetraPay-Signature');
        if ($signature === '') {
            $signature = web_header('X-Webhook-Signature'); // سازگاری پراکسی‌های قدیمی
        }
        $result = pay_tetrapay_webhook($input['json'], $input['raw'], $signature);
        web_json((int) ($result['code'] ?? 200), ['status' => $result['text'] ?? 'ok']);
        return;
    }

    if (!isset(PAY_GATEWAYS[$gw])) {
        web_status(404);
        web_html(pay_result_page('notfound'));
        return;
    }

    /* ── IPN / وبهوک درگاه‌های کریپتو ───────────────────────────────── */
    if ($gw === 'nowpayment' && $input['raw'] !== '' && !isset($input['get']['return'])) {
        $body = $input['json'] !== [] ? $input['json'] : $input['post'];
        $result = pay_nowpay_webhook($body, $input['raw'], web_header('x-nowpayments-sig'));
        web_json((int) ($result['code'] ?? 200), ['status' => $result['text'] ?? 'ok']);
        return;
    }

    if ($gw === 'plisio' && !isset($input['get']['return']) && !isset($input['get']['fail'])
        && ($input['post'] !== [] || $input['json'] !== [])) {
        $fields = $input['json'] !== [] ? $input['json'] : $input['post'];
        $result = pay_plisio_webhook($fields, $input['raw'], gw_key('plisio'));
        web_json((int) ($result['code'] ?? 200), ['status' => $result['text'] ?? 'ok']);
        return;
    }

    /* ── بازگشت/وب‌هوک درگاه: پیدا کردن پرداخت ─────────────────────── */
    $fields = web_callback_fields($input);
    $payment = null;
    $authority = web_callback_value($fields,
        ['Authority', 'authority', 'token', 'txn_id', 'transid', 'hashid', 'Hash_id', 'slug']);
    if ($authority === '' && $gw === 'iranpay4') {
        $authority = web_callback_value($fields, ['invoice_id', 'payment_id']);
    }
    if ($authority !== '') {
        $candidate = $gw === 'iranpay4'
            ? pay_abanpay_find_by_invoice($authority) : pay_find_by_authority($authority);
        /* An identifier from one provider must never route another provider's
           row to a different verifier. */
        if ($candidate !== null && (string) $candidate['method'] === $gw) {
            $payment = $candidate;
        }
    }
    if ($payment === null && $gw === 'aqayepardakht') {
        $invoice = web_callback_value($fields, ['invoice_id']);
        if ($invoice !== '' && ctype_digit($invoice)) {
            $candidate = pay_get((int) $invoice);
            if ($candidate !== null && (string) $candidate['method'] === $gw) {
                $payment = $candidate;
            }
        }
    }
    if ($payment === null && in_array($gw, ['iranpay2', 'iranpay4', 'variza'], true)) {
        $orderRef = web_callback_value($fields, ['order_id', 'order', 'merchant_order_id', 'invoice_id']);
        if ($orderRef !== '') {
            $order = DB::one('SELECT * FROM orders WHERE ref=?', [$orderRef]);
            if ($order !== null) {
                $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method=? "
                    . 'ORDER BY id DESC LIMIT 1', [(int) $order['id'], $gw]);
            }
        }
    }
    if ($payment === null && $gw === 'plisio') {
        $orderRef = web_callback_value($fields, ['order_number']);
        if ($orderRef !== '') {
            $order = DB::one('SELECT * FROM orders WHERE ref=?', [$orderRef]);
            if ($order !== null) {
                $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='plisio' "
                    . 'ORDER BY id DESC LIMIT 1', [(int) $order['id']]);
            }
        }
    }

    if ($payment === null && $gw === 'tetrapay') {
        /*
         * Older website-style Tetra links may carry an unbound token. Keep
         * their response non-settling and successful; only a locally bound
         * authority below ever reaches provider verify or wallet settlement.
         */
        web_html(tetrapay_callback_ready_page());
        return;
    }
    if ($payment === null) {
        web_status(404);
        web_html(pay_result_page('notfound'));
        return;
    }
    $order = order_get((int) $payment['order_id']);
    $orderRef = $order !== null ? (string) $order['ref'] : '';

    /* A prior authoritative provider verify may have claimed this payment but
       been interrupted before top-up delivery. Re-enter only the local,
       idempotent delivery claim; callback fields are not trusted or reused. */
    if ((string) $payment['status'] === 'paid') {
        $repair = pay_settle((int) $payment['id']);
        $repairState = (string) ($repair['state'] ?? 'already');
        if ($input['json'] !== [] || str_contains(strtolower(web_header('Accept')), 'json')) {
            web_json(200, ['status' => (bool) ($repair['ok'] ?? false), 'state' => $repairState]);
            return;
        }
        web_html(pay_result_page($repairState === 'success' ? 'success' : 'already',
            $orderRef, (int) $payment['amount']));
        return;
    }

    try {
        $result = match ($gw) {
            'zarinpal'      => pay_zarinpal_verify($payment, $fields),
            'aqayepardakht' => pay_aqaye_verify($payment, $fields),
            'iranpay1'      => pay_floypay_verify($payment, $fields),
            'iranpay2'      => pay_cubepay_verify($payment, $fields),
            'iranpay4'      => pay_abanpay_verify($payment, $fields),
            'tetrapay'      => pay_tetrapay_verify($payment, $fields),
            'plisio'        => pay_plisio_status($payment),
            'nowpayment'    => pay_nowpay_status($payment),
            'variza'        => ['ok' => false, 'state' => 'waiting',
                                'text' => 'منتظر تأیید وبهوک Variza'],
            'trx'           => pay_trx_verify($payment),
            default         => ['ok' => false, 'state' => 'failed', 'text' => 'درگاه نامعتبر'],
        };
    } catch (Throwable $ex) {
        log_line('error', 'callback failed: ' . $ex->getMessage());
        $result = ['ok' => false, 'state' => 'waiting', 'text' => 'خطا در تأیید پرداخت'];
    }

    /* JSON provider callbacks receive a compact machine response. Browser
       returns retain their human result page. */
    if ($input['json'] !== [] || str_contains(strtolower(web_header('Accept')), 'json')) {
        web_json(200, ['status' => (bool) ($result['ok'] ?? false),
            'state' => (string) ($result['state'] ?? 'waiting')]);
        return;
    }

    $state = (string) ($result['state'] ?? 'waiting');
    if (!in_array($state, ['success', 'already', 'failed', 'expired', 'notfound', 'partial'], true)) {
        $state = 'waiting';
    }
    web_html(pay_result_page($state, $orderRef, (int) $payment['amount']));
}

