# یادداشت انتشار ۳.۱۶.۳

## خلاصه

این نسخه سازوکار بررسی نسخه و دریافت به‌روزرسانی را اصلاح می‌کند تا کش محلی یا
کش CDN نسخهٔ تازه را پنهان نکند. انتشار بسته نیز با اسکریپت اختصاصی خودکار شده است.

## اصلاح بررسی نسخه

دو عامل باعث می‌شد پیام «نسخهٔ جدیدی در دسترس نیست» در حالی نمایش داده شود که نسخهٔ
تازه در مخزن وجود داشت:

1. `bin/sibil` نسخهٔ دوردست را یک ساعت در `/opt/sibil/.update-cache` نگه می‌داشت.
2. `update_remote_version()` در ربات به‌صورت پیش‌فرض از کش پنج‌دقیقه‌ای استفاده می‌کرد
   و منبع نخست آن، انتشار رسمی بود.

تغییرهای اعمال‌شده:

| مورد | وضعیت پیشین | وضعیت این نسخه |
|---|---|---|
| نسخهٔ دوردست در `sibil update` و `sibil check` | کش یک‌ساعته | خواندن تازه با `remote_version_now` |
| کش | منبع اصلی | پشتیبان در حالت آفلاین با برچسب `offline-cache` |
| منبع نسخه | انتشار، سپس `VERSION` | بیشترین مقدار بین Contents API، انتشار، برچسب‌ها و `VERSION` |
| کش CDN | احتمال دریافت نسخهٔ قدیمی | `Cache-Control: no-cache` و `?t=<زمان>` |
| دریافت بسته | احتمال استفاده از کش CDN | هدر no-cache برای دریافت آرشیو |
| پایان به‌روزرسانی | پیام کلی | نمایش نسخهٔ نصب‌شده و مقایسه با مخزن |
| نصب نیمه‌به‌روزرسانی‌شده | رد به‌روزرسانی | اجرای به‌روزرسانی هنگام ناهم‌خوانی `VERSION` و کد |
| اجرای اجباری | وجود نداشت | `sibil update --force` |
| نشان نسخه در پنل مدیریت | درخواست در هر بازشدن صفحه | کش پنج‌دقیقه‌ای؛ دکمهٔ بررسی تازه می‌خواند |

اشکال نمایشی `sibil check` نیز برطرف شد؛ نسخه دیگر به‌صورت تکراری مانند
`v3.16.23.16.2` نمایش داده نمی‌شود.

## انتشار خودکار بسته

اسکریپت `build/publish_release.sh` برای انتشار نسخه افزوده شد:

```bash
GITHUB_TOKEN=<token> bash build/publish_release.sh
```

این اسکریپت نسخهٔ محلی را بررسی می‌کند، برچسب `v<VERSION>` را روی همان کامیت می‌سازد،
انتشار GitHub را ایجاد می‌کند و فایل‌های `panel-service-<ver>-src.tar.gz`،
`panel-service-<ver>-src.zip`، `sibil-installer-<ver>.sh` و `sha256` را پیوست می‌کند.
اجرای دوبارهٔ آن دارایی‌های همان نسخه را جایگزین می‌کند.

مسیر انتشار جاری:

```
https://github.com/XuSibiL/SIBILPANEL/releases/latest
```

## به‌روزرسانی روی سرور

```bash
sudo sibil update        # اگر گفت «آخرین نسخه»، یک‌بار:
sudo rm -f /opt/sibil/.update-cache && sudo sibil update
```

در نسخه‌های ۳.۱۶.۱ و ۳.۱۶.۲، خود دستور `sibil update` هنوز از منطق کش قدیمی استفاده
می‌کند. در این وضعیت، کش را حذف کنید یا نصب‌کننده را دوباره اجرا کنید؛ پایگاه داده و
`.env` حفظ می‌شوند:

```bash
bash <(curl -Ls https://raw.githubusercontent.com/XuSibiL/SIBILPANEL/main/install.sh)
```

از نسخهٔ ۳.۱۶.۳ به بعد، `update` و `check` نسخه را به‌صورت تازه بررسی می‌کنند.

## نتیجهٔ آزمون‌ها

```
test_all 848/0 · test_panel_flow 225/0 · test_kyc 188/0
test_payments 166/0 · test_update 101/0 · audit_buttons OK · audit_owner OK
```

آزمون به‌روزرسانی روی سرور شبیه‌سازی‌شده، دریافت و نصب نسخهٔ تازه را با وجود کش
دست‌کاری‌شده تأیید کرده است:

```
cache (planted): 3.16.1
▸ Updating v3.16.1 -> v3.16.2
  ✓ code replaced (12 items) — .env, database, logs and backups untouched
  ✓ installed package version: v3.16.2
  ✓ update finished: v3.16.2 (data kept)
```

و `sibil check` با همان کش قدیمی، `remote : v3.16.2 → update available` را نمایش می‌دهد.

## فایل‌های انتشار

| فایل | حجم | MD5 |
|---|---:|---|
| `release/panel-service-3.16.3-src.tar.gz` | 1,052,184 | `6a285e7857d04335edb3746f777c0c1a` |
| `release/panel-service-3.16.3-src.zip` | 1,084,652 | `5b19a2a91a30fd303b440d4311429c8b` |
| `dist/sibil-installer.sh` | 1,786,2 | `abb874a5f1fbdc3a7b131322145d8bc3` |
