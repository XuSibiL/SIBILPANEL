<?php
/**
 * Panel Service — PasarGuard reseller bot (PHP edition)
 * ---------------------------------------------------------------------------
 * GENERATED FILE — do not edit by hand.
 * The modular source lives in src/ ; this runtime file is produced by
 * `php build/assemble.php` (modules are concatenated in build/manifest.php
 * order, so the deployed code is exactly the reviewed source).
 *
 * No composer, no framework: plain PHP 8 + PDO/SQLite.
 * Secrets are never hard-coded: see .env.example and docs/SECURITY.md
 *
 * CLI:
 *   php sibil.php --check      self check (no Telegram connection)
 *   php sibil.php --poll       run the bot (long polling)
 *   php sibil.php --version
 *   php sibil.php --migrate    create/upgrade tables and exit
 *
 * Terminal output is English on purpose; every bot message is Persian.
 */

declare(strict_types=1);

const PANEL_VERSION = '3.18.6';
const IRAN_OFFSET   = 3.5 * 3600;
const DAY           = 86400;

