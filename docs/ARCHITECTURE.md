# معماری پروژه

## نمای کلی

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

- پروژه بدون فریم‌ورک و Composer اجرا می‌شود و به PHP 8، PDO/SQLite و cURL نیاز دارد.
- حالت گفت‌وگو در جدول `tg_state` ذخیره می‌شود؛ راه‌اندازی دوبارهٔ سرویس، فرایند نیمه‌تمام
  را از بین نمی‌برد.
- پشتهٔ ناوبری در جدول `nav_stack` نگه‌داری می‌شود. دکمهٔ «🔙 بازگشت» با `nav_back()`
  فقط یک گام به عقب برمی‌گردد.

## ماژول‌ها

| مسیر | مسئولیت |
|---|---|
| `src/core/00-preamble.php` | ثابت‌ها و نسخه |
| `src/core/10-environment.php` | خواندن `.env`، توکن، شناسهٔ مدیران و مسیر اصلی ربات |
| `src/core/20-helpers.php` | `SEP`، `btn()`، اعداد فارسی، پول، تاریخ، `kb_clean()` و یکدست‌سازی دکمهٔ بازگشت |
| `src/db/30-schema.php` | طرح SQLite و ارتقای ایمن ستون‌ها |
| `src/config/40-settings.php` | تنظیمات پیش‌فرض و گروه‌های صفحهٔ تنظیمات |
| `src/services/50-telegram.php` | کلاینت Bot API برای ارسال، ویرایش، عکس، موضوع انجمن و وب‌هوک |
| `src/core/60-state.php` | حالت گفت‌وگو و پشتهٔ ناوبری |
| `src/services/70-domain.php` | کاربر، کیف پول، سفارش، محصول و اسلات |
| `src/services/80-pasarguard.php` | کلاینت PasarGuard/Marzban برای مدیر، کاربر و HWID |
| `src/screens/90-user-screens.php` | صفحه‌های کاربر |
| `src/services/100-payments-delivery.php` | تسویه، تحویل و ساخت پنل |
| `src/handlers/110-receipts.php` | بررسی رسید کارت‌به‌کارت |
| `src/handlers/120-wizard.php` | موتور ورودی‌های چندمرحله‌ای |
| `src/handlers/130-router-callbacks.php` | مسیریابی callbackها |
| `src/handlers/140-router-messages.php` | مسیریابی پیام‌ها و `/start` |
| `src/services/150-meter.php` | مصرف هر گیگ، اجارهٔ ساعتی و توقف خودکار |
| `src/cli/160-cli.php` | `--check`، `--poll`، `--meter` و `--migrate` |
| `src/services/170-gateways.php` | زرین‌پال، آقای پرداخت، واریزا، NowPayments، Plisio و SwapWallet |
| `src/web/180-web.php` | وب‌هوک درگاه‌ها |
| `src/screens/2xx-*.php` | پنل مدیریت، کاربران، دعوت و جست‌وجوی سفارش‌ها |

## فایل خروجی تک‌فایلی

به‌روزرسانی درجا با `sibil update` باید فایل اجرایی را جایگزین کند، نه یک درخت کامل از
فایل‌ها را. به همین دلیل، کد منبع ماژولار است و `build/assemble.php` همان ماژول‌ها را در
`sibil.php` گردآوری می‌کند. دستور `php build/assemble.php --check` هم‌خوانی فایل
تک‌فایلی با کد منبع را بررسی می‌کند.

## محاسبهٔ مبلغ و مصرف

- نمایش مبلغ با `money()`، بر پایهٔ تومان و با جداکنندهٔ هزارگان انجام می‌شود.
- مصرف: هر یک گیگ کامل در قیمت گیگ همان پنل ضرب می‌شود و هیچ گیگی دوباره کسر نمی‌شود؛
  مبنا ستون `billed_bytes` با CAS است.
- اجارهٔ ساعتی بر پایهٔ ساعت کامل محاسبه می‌شود و ۲۴ ساعت نخست در زمان ساخت پنل ثبت می‌شود.
- اگر موجودی از هزینهٔ یک ساعت کمتر باشد و `suspend_on_zero` فعال باشد، پنل غیرفعال، نه حذف، می‌شود.

## مدل داده

`users` · `panels` · `products` · `categories` · `orders` · `payments` · `receipts` ·
`wallet_tx` · `usage_charges` · `slots` · `discounts` · `discount_uses` · `kyc_cards` ·
`kyc_requests` · `pg_panels` · `settings` · `tg_state` · `nav_stack` ·
`temp_restricted_users`

## جریان مالی

1. **کیف پول:** کاربر مبلغ را ارسال می‌کند، `create_order(kind=topup)` ساخته می‌شود، پرداخت
   از درگاه یا کارت‌به‌کارت تأیید می‌شود و `settle_payment()` و سپس
   `add_balance(kind=topup)` اجرا می‌شود.
2. **خرید پنل:** `start_panel_order()` فاکتور را با تخفیف اختصاصی می‌سازد. پس از پرداخت از
   کیف پول یا درگاه، `settle_payment()` اجرا و پنل در PasarGuard ایجاد می‌شود.
3. **مصرف:** حلقهٔ `panels_meter` هر دقیقه مصرف را می‌خواند، گیگ کامل را کسر می‌کند،
   `usage_charges` را ثبت می‌کند و در صورت لزوم پنل را تعلیق می‌کند.

## سیاست خطا

- خطای شبکه باعث توقف ربات نمی‌شود و در `log_line()` ثبت می‌شود.
- پیام خطا برای کاربر علت را روشن بیان می‌کند؛ برای نمونه، مسدودشدن ربات توسط کاربر.
- اگر API در داشبورد پنل پاسخ ندهد، آمار ساختگی نمایش داده نمی‌شود؛ پیام خطا و دکمهٔ حذف
  پنل در دسترس خواهد بود.
