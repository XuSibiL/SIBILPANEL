# معماری پروژه

## نگاه کلی

```
Telegram ──getUpdates──▶ src/handlers/140-router-messages.php
                              │
                              ├─▶ src/handlers/120-wizard.php   (ورودی‌های چندمرحله‌ای)
                              │
Telegram ──callback──────▶ src/handlers/130-router-callbacks.php
                              │
                              ├─▶ src/screens/*      (رندر صفحه‌ها)
                              ├─▶ src/services/*     (پاسارگارد، پرداخت، متر)
                              └─▶ src/db/30-schema   (SQLite/PDO)
```

- **بدون فریم‌ورک، بدون Composer.** فقط PHP 8 + PDO/SQLite + cURL.
- **حالت گفتگو** در جدول `tg_state` ذخیره می‌شود، پس ری‌استارت سرویس هیچ
  ویزارد نیمه‌کاره‌ای را خراب نمی‌کند.
- **پشتهٔ ناوبری** در جدول `nav_stack` نگه داشته می‌شود؛ همهٔ دکمه‌های
  «🔙 بازگشت» فقط یک قدم عقب می‌روند (`nav_back()`).

## ماژول‌ها

| مسیر | مسئولیت |
|---|---|
| `src/core/00-preamble.php` | ثابت‌ها و نسخه |
| `src/core/10-environment.php` | خواندن `.env`، توکن، آیدی ادمین‌ها، خانهٔ ربات |
| `src/core/20-helpers.php` | `SEP`، `btn()`، اعداد فارسی، پول، تاریخ، `kb_clean()` و استانداردسازی دکمهٔ بازگشت |
| `src/db/30-schema.php` | اسکیمای SQLite + ارتقای خودکار ستون‌ها (migration بی‌خطر) |
| `src/config/40-settings.php` | پیش‌فرض تنظیمات و گروه‌های صفحهٔ تنظیمات |
| `src/services/50-telegram.php` | کلاینت Bot API (send/edit/photo/forum topic/webhook) |
| `src/core/60-state.php` | حالت گفتگو و پشتهٔ ناوبری |
| `src/services/70-domain.php` | کاربر، کیف پول، سفارش، محصول، اسلات |
| `src/services/80-pasarguard.php` | کلاینت PasarGuard/Marzban (admin و user، HWID) |
| `src/screens/90-user-screens.php` | صفحه‌های خریدار |
| `src/services/100-payments-delivery.php` | تسویه، تحویل، پنل‌سازی |
| `src/handlers/110-receipts.php` | بررسی رسید کارت‌به‌کارت |
| `src/handlers/120-wizard.php` | موتور ویزارد |
| `src/handlers/130-router-callbacks.php` | روتر کال‌بک‌ها |
| `src/handlers/140-router-messages.php` | روتر پیام‌ها و `/start` |
| `src/services/150-meter.php` | مصرف هر گیگ + اجارهٔ ساعتی + توقف خودکار |
| `src/cli/160-cli.php` | `--check/--poll/--meter/--migrate` |
| `src/services/170-gateways.php` | زرین‌پال، آقای پرداخت، واریزا، NowPayments، Plisio، SwapWallet |
| `src/web/180-web.php` | وب‌هوک درگاه‌ها |
| `src/screens/2xx-*.php` | پنل مدیریت، کاربران، دعوت، جستجوی سفارشات |

## چرا یک فایل خروجی؟

آپدیت درجا (`sibil update`) باید فایل اجرایی را عوض کند، نه یک درخت فایل را.
پس سورس ماژولار است و `build/assemble.php` دقیقاً همان ماژول‌ها را به یک
`sibil.php` می‌چسباند. تست `build/assemble.php --check` تضمین می‌کند فایل
کامیت‌شده همان سورس است.

## پول و محاسبه

- نمایش پول با `money()` (تومان، جداکنندهٔ هزارگان).
- مصرف: هر **۱ گیگ کامل** × قیمت گیگ همان پنل؛ هیچ گیگی دو بار کسر نمی‌شود
  (مبنا: ستون `billed_bytes` با CAS).
- اجارهٔ ساعتی: ساعت کامل؛ ۲۴ ساعت اول در لحظهٔ ساخت.
- توقف خودکار: اگر موجودی از هزینهٔ یک ساعت کمتر شود و `suspend_on_zero` روشن
  باشد، پنل غیرفعال می‌شود (حذف نمی‌شود).

## مدل داده (مهم‌ترین جدول‌ها)

`users` · `panels` · `products` · `categories` · `orders` · `payments` · `receipts`
· `wallet_tx` · `usage_charges` · `slots` · `discounts` · `discount_uses` ·
`kyc_cards` · `kyc_requests` · `pg_panels` · `settings` · `tg_state` · `nav_stack`
· `temp_restricted_users`

## مسیرهای جریان پول

1. **کیف پول:** کاربر مبلغ می‌فرستد → `create_order(kind=topup)` →
   درگاه/کارت‌به‌کارت → وب‌هوک یا تأیید رسید → `settle_payment()` →
   `add_balance(kind=topup)` → شمارش واریزی و پورسانت معرف.
2. **خرید پنل:** `start_panel_order()` → فاکتور با تخفیف اختصاصی →
   پرداخت از کیف پول یا درگاه → `settle_payment()` → ساخت ادمین روی پاسارگارد →
   ردیف `panels` → ۲۴ ساعت اول اجاره.
3. **مصرف:** حلقهٔ متر (`panels_meter`) هر دقیقه: خواندن مصرف → کسر گیگ کامل →
   ثبت `usage_charges` → در صورت نیاز تعلیق.

## سیاست خطا

- هیچ خطای شبکه‌ای باعث کرش ربات نمی‌شود؛ همه در `log_line()` ثبت می‌شوند.
- پیام خطا به کاربر همیشه **دلیل** را می‌گوید (مثلاً «کاربر ربات را بلاک کرده»).
- در داشبورد پنل، اگر API جواب ندهد آمار جعلی نشان داده نمی‌شود؛ پیام خطای صریح
  و دکمهٔ حذف پنل نمایش داده می‌شود.
