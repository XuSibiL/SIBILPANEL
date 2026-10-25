<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    web_handle();
    return;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(cli_main($argv));
}
