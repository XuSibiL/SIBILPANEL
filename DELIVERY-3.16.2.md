# SIBILPANEL 3.16.2 — یوزرنیم خودکار + لینک دعوت + `sibil uninstall`

```bash
sibil update          # روی سرور، دیتابیس و .env دست‌نخورده
```

## ۱) ریشهٔ واقعی باگ «یوزرنیم ربات تنظیم نشده است»

در 3.16.1 شاخهٔ `getMe` نوشته شده بود ولی شرطش این بود:

```php
if ($handle === '' && function_exists('Tg')) {   // ❌ همیشه false
```

`Tg` یک **کلاس** است، نه تابع؛ `function_exists('Tg')` همیشه `false` برمی‌گرداند،
پس `getMe` هرگز صدا زده نمی‌شد و متن خطا نمایش داده می‌شد. اصلاح:

```php
if ($handle === '' && class_exists('Tg')) { $handle = bot_username_fetch(); }
```

`bot_username_fetch()` → `getMe` → `result.username` → ذخیره در تنظیمات (کش) →
لینک `https://t.me/<BOT_USERNAME>?start=ref_<USER_ID>` بدون هیچ ورودی دستی.

سه مسیر خودکارسازی اضافه شد:

| مسیر | کار |
|---|---|
| شروع `--poll` | یک‌بار در استارتاپ یوزرنیم خوانده و لاگ می‌شود |
| `sibil doctor` | با `getMe` چک می‌کند و اگر `PANEL_BOT_USERNAME` در `.env` نبود خودش اضافه می‌کند |
| `php sibil.php --sync-bot` | همگام‌سازی دستی/اسکریپتی (`OK   @username`) |

اگر تلگرام پاسخ نداد، لینک **خراب ساخته نمی‌شود**؛ پیام «تلگرام یوزرنیم ربات را
برنگرداند» با دکمهٔ «🔄 تلاش دوباره» می‌آید.

**دکمه‌های اشتراک‌گذاری در صفحهٔ دعوت**

- `📤 اشتراک‌گذاری لینک` → `https://t.me/share/url?url=…&text=…`
- `⚡️ ارسال سریع در تلگرام` → `tg://msg_url?url=…&text=…`
- `📋 فرستادن لینک به دوست` → پیام مستقل با لینک کپی‌شونده و هر دو دکمه

پاداش‌ها مثل 3.16.1: ثبت فقط برای عضو جدید، بدون خودمعرفی، بدون پرداخت دوباره،
واریز نقدی (لحظهٔ عضویت یا اولین خرید) و درصدی روی واریزی اول/دوم، همراه با
اعلان «🎉 کاربری با لینک دعوت شما عضو شد …».

## ۲) `sibil uninstall` — گزینهٔ ۱۰ منوی CLI

```
  Are you sure you want to completely uninstall SIBILPANEL? [y/N]
```

با `y` این شش مرحله اجرا می‌شود:

1. `systemctl stop sibil` + `systemctl disable sibil` (و `sibil-web`)، سپس kill پروسه‌های `--poll`/`--broadcast`
2. `rm -f /etc/systemd/system/sibil.service` (+ `sibil-web.service`) و `systemctl daemon-reload` + `reset-failed`
3. **کپی ایمن** دیتابیس و `.env` در `/root/sibil-uninstall-YYYYmmdd-HHMMSS`، سپس `rm -rf` پوشهٔ نصب
4. `rm -f /usr/local/bin/sibil` (+ `/usr/bin/sibil`)
5. پاک‌کردن فقط **بلوک دامنهٔ ربات** از `/etc/caddy/Caddyfile` (با بکاپ زمان‌دار و بازخوانی Caddy) و کانفیگ `nginx`
6. بررسی نهایی؛ اگر چیزی نمانده باشد:

```
  ✓ SIBILPANEL was successfully uninstalled from your server.
```

ایمنی‌ها: بدون `y` هیچ کاری انجام نمی‌شود؛ بدون ترمینال (اسکریپت/CI) تأیید از
stdin خوانده می‌شود؛ مسیر مشکوک (`/`، `/opt`) حذف نمی‌شود؛ بلوک سایت‌های دیگر
Caddy دست‌نخورده می‌ماند.

نمونهٔ اجرای واقعی در سندباکس (نصب آزمایشی در `/tmp/untest`):

```
=== n:  ✓ cancelled — nothing was changed        files: VERSION sibil.php sibil.sqlite3
=== y:  ▸ 3/6 ✓ installation directory removed
        ▸ 4/6 ✓ command removed: sibil
        ▸ 5/6 ✓ web server entries cleaned
        ▸ 6/6 ✓ SIBILPANEL was successfully uninstalled from your server.
=== after: (install dir and command are gone)
```

همچنین `sibil remove` قدیمی (فقط سرویس را برمی‌داشت و داده را نگه می‌داشت) سر جایش است.

## تست‌ها (همه سبز)

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

## فایل‌ها

| فایل | حجم | md5 |
|---|---|---|
| `release/panel-service-3.16.2-src.tar.gz` | 1,043,157 | `d35b35323b6ec0e70cce862070a853db` |
| `release/panel-service-3.16.2-src.zip` | 1,075,597 | `cdd561f3f48185d535bade8a632f5e51` |
| `dist/sibil-installer.sh` | 1,773,907 | `13ad5ae75ff2b7b6edf65a2d4daa4a53` |

بسته هیچ فایل تست، دیتابیس، `.env` یا لاگ ندارد.
