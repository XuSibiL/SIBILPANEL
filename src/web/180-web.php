<?php
declare(strict_types=1);

/*
   22) WEB ENDPOINT — کال‌بک درگاه‌ها (php -S):
       /pay/zarinpal · /pay/aqayepardakht · /pay/iranpay1|2|4 ·
       /pay/nowpayment (IPN) · /pay/plisio · /pay/variza + webhook · /pay/trx
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

/** مسیریابی درخواست وب */
function web_handle(): void
{
    load_env();
    db_bootstrap();
    settings_bootstrap();
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $path = rtrim($path, '/');
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

    /* ── بازگشت کاربر از درگاه: پیدا کردن پرداخت ──────────────────────── */
    $payment = null;
    $authority = (string) ($input['get']['Authority'] ?? $input['get']['authority']
        ?? $input['post']['authority'] ?? $input['get']['txn_id'] ?? '');
    if ($authority !== '') {
        $payment = pay_find_by_authority($authority);
    }
    if ($payment === null && $gw === 'aqayepardakht' && isset($input['post']['invoice_id'])) {
        $payment = pay_get((int) $input['post']['invoice_id']);
    }
    if ($payment === null && in_array($gw, ['iranpay2', 'iranpay4', 'variza'], true)) {
        $orderRef = (string) ($input['get']['order_id'] ?? $input['post']['order_id']
            ?? $input['get']['order'] ?? '');
        if ($orderRef !== '') {
            $order = DB::one('SELECT * FROM orders WHERE ref=?', [$orderRef]);
            if ($order !== null) {
                $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method=? "
                    . 'ORDER BY id DESC LIMIT 1', [(int) $order['id'], $gw]);
            }
        }
    }
    if ($payment === null && $gw === 'plisio') {
        $orderRef = (string) ($input['get']['order_number'] ?? '');
        if ($orderRef !== '') {
            $order = DB::one('SELECT * FROM orders WHERE ref=?', [$orderRef]);
            if ($order !== null) {
                $payment = DB::one("SELECT * FROM payments WHERE order_id=? AND method='plisio' "
                    . 'ORDER BY id DESC LIMIT 1', [(int) $order['id']]);
            }
        }
    }

    if ($payment === null) {
        web_status(404);
        web_html(pay_result_page('notfound'));
        return;
    }
    $order = order_get((int) $payment['order_id']);
    $orderRef = $order !== null ? (string) $order['ref'] : '';

    /* اگر قبلاً تأیید شده، همان را نشان بدهید */
    if ((string) $payment['status'] === 'paid') {
        web_html(pay_result_page('already', $orderRef, (int) $payment['amount']));
        return;
    }

    try {
        $result = match ($gw) {
            'zarinpal'      => pay_zarinpal_verify($payment, $input['get']),
            'aqayepardakht' => pay_aqaye_verify($payment, $input['post'] + $input['get']),
            'iranpay1'      => pay_floypay_verify($payment, $input['json'] + $input['get'] + $input['post']),
            'iranpay2'      => pay_cubepay_verify($payment, $input['json'] + $input['get'] + $input['post']),
            'iranpay4'      => pay_abanpay_verify($payment, $input['get'] + $input['post']),
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

    /* درگاه‌هایی که جواب JSON می‌خواهند */
    if (in_array($gw, ['iranpay2', 'iranpay4', 'nowpayment', 'plisio'], true)
        && (str_contains(web_header('Accept'), 'json') || $input['json'] !== [])) {
        web_json(200, ['status' => (bool) ($result['ok'] ?? false)]);
        return;
    }

    $state = (string) ($result['state'] ?? 'waiting');
    if (!in_array($state, ['success', 'already', 'failed', 'expired', 'notfound', 'partial'], true)) {
        $state = 'waiting';
    }
    web_html(pay_result_page($state, $orderRef, (int) $payment['amount']));
}

