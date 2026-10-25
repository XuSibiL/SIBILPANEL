<?php
/**
 * ترتیب دقیق ماژول‌ها برای ساخت فایل تک‌فایلی sibil.php.
 * همین ترتیب همان ترتیبی است که کد در نسخهٔ تک‌فایل اجرا می‌شود.
 */
declare(strict_types=1);

return [
    'core/00-preamble.php',
    'core/10-environment.php',
    'core/20-helpers.php',
    'db/30-schema.php',
    'config/40-settings.php',
    'services/50-telegram.php',
    'core/60-state.php',
    'services/70-domain.php',
    'services/80-pasarguard.php',
    'services/85-update.php',
    'screens/90-user-screens.php',
    'screens/91-help.php',
    'screens/92-admin-console.php',
    'screens/93-finance.php',
    'screens/94-discounts.php',
    'services/95-backup-restore.php',
    'services/100-payments-delivery.php',
    'handlers/110-receipts.php',
    'handlers/120-wizard.php',
    'handlers/130-router-callbacks.php',
    'handlers/140-router-messages.php',
    'services/150-meter.php',
    'cli/160-cli.php',
    'services/170-gateways.php',
    'web/180-web.php',
    'screens/190-pay-admin.php',
    'screens/200-roles.php',
    'screens/210-users.php',
    'core/900-runtime.php',
];
