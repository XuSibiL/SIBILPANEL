# یادداشت انتشار ۳.۱۶.۲

## خلاصه

این نسخه تشخیص خودکار نام کاربری ربات، پیوند دعوت و دستور `sibil uninstall` را
تکمیل می‌کند. به‌روزرسانی روی سرور، پایگاه داده و `.env` را تغییر نمی‌دهد.

```bash
sibil update          # روی سرور، دیتابیس و .env دست‌نخورده
```

## اصلاح تشخیص نام کاربری ربات

در نسخهٔ پیشین، شرط زیر برای تشخیص `Tg` به‌کار می‌رفت:

```php
if ($handle === '' && function_exists('Tg')) {   // ❌ همیشه false
```

`Tg` یک کلاس است، نه تابع؛ بنابراین `function_exists('Tg')` همواره `false` برمی‌گرداند
و `getMe` فراخوانی نمی‌شد. شرط اصلاح‌شده:

```php
if ($handle === '' && class_exists('Tg')) { $handle = bot_username_fetch(); }
```

تابع `bot_username_fetch()` نام کاربری را از `getMe` و `result.username` دریافت می‌کند،
در تنظیمات ذخیره می‌کند و پیوند دعوت را بدون نیاز به ورود دستی می‌سازد.

نام کاربری در سه مسیر بررسی می‌شود:

| مسیر | عملکرد |
|---|---|
| شروع `--poll` | خواندن نام کاربری در آغاز اجرا و ثبت در گزارش |
| `sibil doctor` | بررسی `getMe` و افزودن `PANEL_BOT_USERNAME` در صورت نبودن آن در `.env` |
| `php sibil.php --sync-bot` | همگام‌سازی دستی یا اسکریپتی |

اگر Telegram پاسخی ندهد، پیوند ناقص ساخته نمی‌شود و پیام خطا همراه دکمهٔ
«🔄 تلاش دوباره» نمایش داده می‌شود.

در صفحهٔ دعوت، دکمه‌های «📤 اشتراک‌گذاری لینک»، «⚡️ ارسال سریع در تلگرام» و
«📋 فرستادن لینک به دوست» برای ارسال و کپی‌کردن پیوند دعوت در دسترس هستند.

قواعد پاداش همان قواعد نسخهٔ ۳.۱۶.۱ است: ثبت فقط برای عضو جدید، جلوگیری از
خودمعرفی و پرداخت تکراری، پرداخت نقدی در زمان تعیین‌شده و محاسبهٔ درصدی بر
پایهٔ واریزی‌های مجاز.

## `sibil uninstall`

دستور `sibil uninstall` به‌عنوان گزینهٔ ۱۰ منوی CLI اضافه شده است. پیش از حذف،
برنامه تأیید زیر را دریافت می‌کند:

```
  Are you sure you want to completely uninstall SIBILPANEL? [y/N]
```

با تأیید `y`، مراحل زیر اجرا می‌شود:

1. توقف و غیرفعال‌سازی `sibil` و `sibil-web` و پایان‌دادن به فرایندهای `--poll` و `--broadcast`.
2. حذف واحدهای systemd، اجرای `systemctl daemon-reload` و `reset-failed`.
3. تهیهٔ نسخهٔ ایمن از پایگاه داده و `.env` در `/root/sibil-uninstall-YYYYmmdd-HHMMSS` و سپس حذف پوشهٔ نصب.
4. حذف `sibil` از `/usr/local/bin/sibil` و `/usr/bin/sibil`.
5. حذف صرفاً بلوک دامنهٔ ربات از `/etc/caddy/Caddyfile` و پیکربندی `nginx`، همراه با نسخهٔ پشتیبان و بازخوانی سرویس وب.
6. بررسی نهایی حذف منابع نصب.

```
  ✓ SIBILPANEL was successfully uninstalled from your server.
```

بدون تأیید `y` هیچ عملیاتی اجرا نمی‌شود. در اجرای غیرتعاملی، تأیید از ورودی استاندارد
خوانده می‌شود. مسیرهای مشکوک مانند `/` و `/opt` حذف نمی‌شوند و پیکربندی وب‌سایت‌های
دیگر دست‌نخورده باقی می‌ماند.

نمونهٔ اجرای آزمایشی در محیط ایزوله:

```
=== n:  ✓ cancelled — nothing was changed        files: VERSION sibil.php sibil.sqlite3
=== y:  ▸ 3/6 ✓ installation directory removed
        ▸ 4/6 ✓ command removed: sibil
        ▸ 5/6 ✓ web server entries cleaned
        ▸ 6/6 ✓ SIBILPANEL was successfully uninstalled from your server.
=== after: (install dir and command are gone)
```

دستور قدیمی `sibil remove` نیز همچنان در دسترس است و فقط سرویس را برمی‌دارد؛ داده‌ها را
حفظ می‌کند.

## نتیجهٔ آزمون‌ها

```
test_all          823 / 0     (بخش ۴۶ دعوت + بخش ۴۷ uninstall)
test_panel_flow   225 / 0
test_kyc          188 / 0
test_payments     166 / 0
test_update        97 / 0
audit_buttons     OK
audit_owner       OK — checked 25, failed 0
```

`php build/assemble.php --check` → `OK: sibil.php matches src/` · `bash -n bin/sibil` و `bash -n install.sh` بدون خطا

## فایل‌های انتشار

| فایل | حجم | MD5 |
|---|---:|---|
| `release/panel-service-3.16.2-src.tar.gz` | 1,043,157 | `d35b35323b6ec0e70cce862070a853db` |
| `release/panel-service-3.16.2-src.zip` | 1,075,597 | `cdd561f3f48185d535bade8a632f5e51` |
| `dist/sibil-installer.sh` | 1,773,907 | `13ad5ae75ff2b7b6edf65a2d4daa4a53` |

بستهٔ انتشار شامل فایل آزمون، پایگاه داده، `.env` یا گزارش نیست.
