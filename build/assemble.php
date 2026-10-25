#!/usr/bin/env php
<?php
/**
 * SIBIL PANEL — source assembler.
 *
 * The modular files under src/ are the source of truth on GitHub; the runtime
 * that gets installed on a server is ONE file (sibil.php) because the updater
 * replaces exactly one file in place. This script concatenates the modules in
 * manifest order and writes that single file — byte for byte the same code
 * that is reviewed in src/.
 *
 * Usage:  php build/assemble.php [--check]
 *   --check   only verify that the built file is identical to the committed one
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = require $root . '/build/manifest.php';

$prefix = "<?php\ndeclare(strict_types=1);\n\n";
$parts = [];
$first = true;
foreach ($manifest as $rel) {
    $path = $root . '/src/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "missing module: src/$rel\n");
        exit(1);
    }
    $code = str_replace("\r\n", "\n", (string) file_get_contents($path));
    if ($first) {
        $parts[] = $code;
        $first = false;
        continue;
    }
    /* هر ماژول با <?php و declare شروع می‌شود؛ هنگام چسباندن فقط همان سرصفحه
       حذف می‌شود و بقیهٔ بایت‌ها دست‌نخورده می‌مانند */
    if (str_starts_with($code, $prefix)) {
        $code = substr($code, strlen($prefix));
    } else {
        $code = preg_replace('/^<\?php\n(declare\(strict_types=1\);\n)?\n?/', '', $code, 1) ?? $code;
    }
    $parts[] = $code;
}

$out = implode('', $parts);
$target = $root . '/sibil.php';

if (in_array('--check', $argv ?? [], true)) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current === $out) {
        echo "OK: sibil.php matches src/\n";
        exit(0);
    }
    fwrite(STDERR, "DIFF: sibil.php does not match src/ (run: php build/assemble.php)\n");
    exit(1);
}

file_put_contents($target, $out);
echo 'built : sibil.php' . "\n";
echo 'size  : ' . strlen($out) . " bytes\n";
echo 'md5   : ' . md5($out) . "\n";
