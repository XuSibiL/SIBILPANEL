<?php
/** Regression: bare Tetra validation must not depend on .env/SQLite/bootstrap. */
declare(strict_types=1);

/* An impossible home makes any accidental load_env()/db_bootstrap() fail. The
   only valid result is the static, non-settling public callback health page. */
putenv('PANEL_HOME=/proc/sibil-tetrapay-preflight-unavailable');
putenv('PANEL_ENV=/proc/sibil-tetrapay-preflight-unavailable/.env');
$_SERVER = [
    'REQUEST_URI' => '/pay/tetrapay',
    'REQUEST_METHOD' => 'GET',
];
$_GET = [];
$_POST = [];

require __DIR__ . '/../sibil.php';

http_response_code(200);
ob_start();
web_handle();
$html = (string) ob_get_clean();

$ok = http_response_code() === 200
    && str_contains($html, 'SIBIL payment return is online.')
    && str_contains($html, 'No wallet or order has been credited');
printf("%s - bare Tetra callback health is fast and dependency-free\n", $ok ? 'ok' : 'not ok');
exit($ok ? 0 : 1);
