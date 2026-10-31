# SIBILPANEL

<div align="center">

**ربات فروش پنل پاسارگارد (PasarGuard) — نسخهٔ PHP**

**نسخهٔ پایدار: `3.18.6`**

فروش پنل حجمی/ساعتی، کیف پول، درگاه‌های پرداخت ایرانی و کریپتو، احراز هویت دو سطحی،
کد تخفیف، سیستم دعوت دوستان و پنل مدیریت کامل — بدون فریم‌ورک، بدون Composer.

![version](https://img.shields.io/badge/version-3.18.6-2ea043)
![php](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![db](https://img.shields.io/badge/SQLite-PDO-003b57)
![license](https://img.shields.io/badge/license-MIT-blue)
![tests](https://img.shields.io/badge/tests-251%20passing-2ea043)

[![کانال رسمی تلگرام](https://img.shields.io/badge/Telegram-%DA%A9%D8%A7%D9%86%D8%A7%D9%84%20%D8%B1%D8%B3%D9%85%DB%8C-229ED9?logo=telegram&logoColor=white)](https://t.me/sibilpanel)
[![GitHub](https://img.shields.io/badge/GitHub-XuSibiL%2FSIBILPANEL-181717?logo=github&logoColor=white)](https://github.com/XuSibiL/SIBILPANEL)

**📢 کانال رسمی پروژه (خبر، تغییرها و بروزرسانی‌ها): [t.me/sibilpanel](https://t.me/sibilpanel)**

</div>

---

## فهرست

- [کانال رسمی](#کانال-رسمی)
- [ویژگی‌ها](#ویژگیها)
- [پیش‌نیازها](#پیشنیازها)
- [انتشار روی گیت‌هاب](#انتشار-روی-گیتهاب)
- [نصب سریع](#نصب-سریع)
- [نصب دستی از سورس](#نصب-دستی-از-سورس)
- [پیکربندی](#پیکربندی)
- [بروزرسانی خودکار](#بروزرسانی-خودکار)
- [دستورهای CLI](#دستورهای-cli)
- [ساختار پروژه](#ساختار-پروژه)
- [تست‌ها](#تستها)
- [امنیت](#امنیت)
- [سوال‌های رایج](#سوالهای-رایج)
- [لایسنس](#لایسنس)

---

## کانال رسمی

| کانال | نشانی |
|---|---|
| 📢 کانال رسمی پروژه | [t.me/sibilpanel](https://t.me/sibilpanel) |
| 🐙 مخزن سورس | [github.com/XuSibiL/SIBILPANEL](https://github.com/XuSibiL/SIBILPANEL) |

خبر انتشار نسخه‌ها، تغییرهای مهم و راهنمای بروزرسانی در کانال منتشر می‌شود.
داخل ربات هم لینک کانال از مسیر «🎓 راهنما → 📢 کانال رسمی» و
«☎️ پشتیبانی» در دسترس است.

---

## ویژگی‌ها

| بخش | توضیح |
|---|---|
| **فروش** | محصولات حجمی + اجارهٔ ساعتی، قیمت هر گیگ و هر ساعت جدا برای هر محصول، حداقل موجودی، سقف کاربر و دستگاه |
| **اسلات** | ۳ اسلات رایگان، خرید اسلات اضافه در «پنل‌های من» |
| **کیف پول** | شارژ کارت‌به‌کارت با رسید، درگاه‌های ایرانی (زرین‌پال، آقای پرداخت، واریزا)، کریپتو (NowPayments، Plisio، SwapWallet) |
| **احراز هویت** | دو سطح: کارت ملی = کارت بانکی، و سلفی با مدارک؛ تاپیک خودکار برای هر سطح در گروه گزارشات |
| **تخفیف** | کد تخفیف چندپله‌ای + تخفیف اختصاصی هر کاربر + تخفیف در حال اجرا روی هر گیگ |
| **دعوت دوستان** | لینک `?start=ref_<id>` از نام کاربری رسمی ربات + دکمهٔ اشتراک‌گذاری، ثبت دعوت فقط برای عضو جدید، پاداش نقدی (لحظهٔ عضویت یا اولین خرید) و پورسانت درصدی واریزی اول و دوم |
| **مصرف** | کسر خودکار هر گیگ کامل + اجارهٔ ساعتی، توقف خودکار با موجودی صفر |
| **کنترل دستگاه** | تشخیص دستگاه اضافه و غیرفعال‌سازی همان کانفیگ برای ۱۰ دقیقه (نه کسر پول) |
| **جستجو** | جستجوی کاربر، جستجوی پنل مشتری و جستجوی فاکتور/تراکنش با کنترل کامل پنل |
| **بکاپ** | بکاپ ۲۴ ساعتهٔ دیتابیس به گروه تلگرام + بازیابی مستقیم |
| **پیش‌پرداخت ۲۴ ساعت** | هزینهٔ روز اول در فاکتور و همان لحظه از کیف پول؛ پنجرهٔ ۲۴ ساعته بدون کسر ساعتی و بدون بازگشت در صورت حذف |
| **قفل کانال** | عضویت اجباری کانال با بررسی مدیر بودن ربات و استثنای مالک/ادمین |
| **پاسخ پشتیبانی** | زیر هر پیام مستقیمِ ادمین/پشتیبانی دکمهٔ «✍️ پاسخ به پیام / پشتیبانی» می‌آید؛ پاسخ با مشخصات کاربر، ticket امن و دکمهٔ پاسخ مجدد برای مسئول معتبر و گروه گزارشات ثبت می‌شود |
| **پیام همگانی** | ارسال پس‌زمینه، تحمل 429، رد کاربران مسدودشده و گزارش کامل |
| **امنیت** | سکرت‌ها فقط در `.env`، دیتابیس SQLite با تراکنش قفل‌شده، سخت‌سازی وب‌سرور |

---

## اتصال درگاه‌های ریالی در حالت سازگار

نسخهٔ `3.18.6` مسیرهای پرداختِ حالت سازگار را در مسیریاب SIBIL پاسخ می‌دهد؛ بنابراین نیازی به
بارگذاری دستی فایل PHP در میزبان نیست. نصب‌کنندهٔ رسمی همهٔ درخواست‌ها را به همین مسیریاب هدایت
می‌کند و مسیرهای زیر در دسترس هستند:

| سرویس | مسیر حالت سازگار | قاعدهٔ قطعی‌سازی |
|---|---|---|
| Aban Gateway | `/payment/iranpay4.php` | بازگشت فقط برای شناسایی است؛ `POST /verify` با Bearer key، تطبیق `order_id` و مبلغ، و تحویل idempotent |
| CubePay | `/payment/iranpay2.php` | اعتبارسنجی امضای HMAC و اتصال پرداخت به `order_id` پیش از تحویل |
| Variza | `/payment/variza.php` و `/payment/variza_webhook.php` | بازگشت مرورگر فقط وضعیت انتظار را نشان می‌دهد؛ تسویه فقط با وب‌هوک امضاشده انجام می‌شود |
| IranPay1 / زرین‌پال / آقای پرداخت | `/payment/iranpay1.php`، `/payment/zarinpal.php`، `/payment/aqayepardakht.php` | بررسی پرداخت در سمت سرور SIBIL |

برای **Aban Gateway** در ربات Aban، گزینهٔ «میرزا آپدیت‌شده» را انتخاب کنید و ریشهٔ دامنهٔ نصب SIBIL را
وارد کنید. برای نمونه، از `https://example.com` استفاده کنید، نه صفحهٔ پنل یا زیرشاخه.
سپس دو مقدار بازگردانده‌شده را در مسیر «مدیریت → مالی → Aban Gateway» در SIBIL ثبت کنید: `آدرس ابان‌پی`
نشانی اتصال و `کلید اصلی` کلید اتصال است. هنگام ساخت فاکتور، SIBIL نشانی بازگشت زیر را ارسال می‌کند:

```
https://your-domain/payment/iranpay4.php
```

یک درخواست ساده به این مسیر، پیش از هر سفارش، فقط پاسخ بی‌خطرِ «شناسه سفارش ارسال نشد» می‌دهد؛
هیچ پرداخت، کیف پول یا محصولی با درخواست آزمایشی یا بازگشت مرورگر تسویه نمی‌شود. پس از بازگشت، فقط
تأیید معتبر ارائه‌دهنده می‌تواند سفارش را تحویل دهد. مسیرهای قدیمی `/pay/...` نیز برای فاکتورهای
ساخته‌شده در نسخه‌های پیشین فعال می‌مانند.

> **TetraPay عمداً در این لایه قرار نگرفته است.** بازگشت Telegram، API و وب‌هوک آن بدون تغییر
> باقی می‌مانند.

---

## پیش‌نیازها

| نیاز | حداقل | توضیح |
|---|---|---|
| PHP | 8.1 یا بالاتر | نسخهٔ 8.2 و 8.3 تست شده است |
| افزونه‌های PHP | `pdo_sqlite`، `sqlite3`، `curl`، `mbstring`، `xml`، `json` | همه در بسته‌های استاندارد موجودند |
| پنل | PasarGuard یا Marzban | دسترسی با **کلید API** (بدون نام کاربری و رمز) |
| سیستم‌عامل | Debian 11+ / Ubuntu 20.04+ | Termux هم پشتیبانی می‌شود |

نصب پیش‌نیازها روی Debian و Ubuntu:

```bash
sudo apt-get update
sudo apt-get install -y php-cli php-sqlite3 php-curl php-mbstring php-xml
```

---

## انتشار در GitHub

بستهٔ تمیز را دریافت کنید، روی سرور باز کنید و نام مخزن را بدهید:

```bash
bash deploy.sh XuSibiL/SIBILPANEL
```

این اسکریپت پوشهٔ `SIBILPANEL` را می‌سازد، فایل‌های حساس
(`.env`، دیتابیس، لاگ، `tests/`) را کنار می‌گذارد، `git init -b main` اجرا می‌کند،
یک کامیت می‌سازد، ریموت گیت‌هاب را اضافه می‌کند و `git push` انجام می‌دهد.
توکن را از شما می‌گیرد و در هیچ فایلی ذخیره نمی‌کند:

```bash
GITHUB_TOKEN=ghp_xxx bash deploy.sh XuSibiL/SIBILPANEL
```

اگر مخزن از قبل ساخته شده و پکیج رویش هست، روی هر سرور دیگری با یک خط:

```bash
curl -fsSL https://github.com/XuSibiL/SIBILPANEL/archive/refs/heads/main.tar.gz \
  | tar -xz -C /tmp && bash /tmp/SIBILPANEL-main/deploy.sh XuSibiL/SIBILPANEL
```

ساخت پکیج انتشار:

```bash
bash build/package.sh     # release/panel-service-<version>-src.{tar.gz,zip}
```

---

## نصب سریع

**نصب با یک خط** (نیازی به کلون کردن نیست؛ خودش کد منبع را می‌آورد):

```bash
bash <(curl -Ls https://raw.githubusercontent.com/XuSibiL/SIBILPANEL/main/install.sh)
```

یا با آرگومان‌ها، بدون هیچ پرسشی:

```bash
bash <(curl -Ls https://raw.githubusercontent.com/XuSibiL/SIBILPANEL/main/install.sh) \
  --token 123456:ABC --admins 123456789 --domain panel.example.com
```

نصب از روی کلون (اگر مخزن را از قبل دارید):

```bash
git clone https://github.com/XuSibiL/SIBILPANEL.git
cd SIBILPANEL
sudo bash install.sh --token <BOT_TOKEN> --domain panel.example.com
```

اگر اسکریپت پایپ شده باشد و کد منبع کنارش نباشد، خودش آرشیو مخزن را از گیت‌هاب
می‌گیرد، در `/root/SIBILPANEL` می‌گذارد، پیش‌نیازها را نصب می‌کند،
`/usr/local/bin/sibil` را می‌سازد و سرویس systemd را فعال می‌کند.

نصب‌کننده پیش‌نیازها را بررسی می‌کند، فایل `.env` و دیتابیس را می‌سازد، سرویس را
راه‌اندازی می‌کند و در صورت ارائهٔ دامنه، گواهی SSL را خودکار دریافت می‌کند.

پس از نصب:

```bash
sudo sibil            # منوی CLI
sudo sibil status     # وضعیت سرویس
```

به‌روزرسانی در آینده:

```bash
sudo sibil update
```

> برای ساخت نصب‌کنندهٔ **تک‌فایلی**:
> `bash build/build_bundle.sh` فایل `dist/sibil-installer.sh` را می‌سازد؛
> همان یک فایل را می‌توان روی هر سروری اجرا کرد.

---

## نصب دستی از کد منبع

```bash
git clone https://github.com/XuSibiL/SIBILPANEL.git
cd SIBILPANEL

# ۱) فایل محیطی
cp .env.example .env

# ۲) مقدارهای لازم را پر کن (توکن، ادمین، برند، پشتیبانی)
nano .env

# ۳) دیتابیس و جداول
php sibil.php --migrate

# ۴) اجرای ربات (long polling)
php sibil.php --poll
```

اجرای وب‌هوک درگاه‌ها (در کنار ربات):

```bash
php -S 0.0.0.0:8080 src/web/180-web.php
```

> `sibil.php` در ریشهٔ پروژه یک فایل **ساخته‌شده** است: خروجی گردآوری
> ماژول‌های `src/` با `php build/assemble.php`. پس از هر تغییر مجاز در `src/`،
> دستور `php build/assemble.php` را دوباره اجرا کنید.

---

## پیکربندی

همهٔ سکرت‌ها فقط در `.env` (یا متغیر محیطی) نگه داشته می‌شوند؛ **هیچ توکن، رمز،
کلید API، شمارهٔ کارت یا آیدی ادمینی به‌صورت ثابت داخل کد منبع وجود ندارد.**

| کلید | توضیح | اجباری |
|---|---|---|
| `PANEL_BOT_TOKEN` | توکن ربات از @BotFather | ✅ |
| `PANEL_ADMINS` | آیدی عددی مدیران اولیه با کاما (برای مهاجرت نصب قدیمی) | ✅ |
| `PANEL_PRIMARY_OWNER_ID` | آیدی عددی مالک اصلیِ قفل‌شده؛ قابل حذف/تنزل نیست | ✅ |
| `PANEL_HOME` | مسیر نصب | — |
| `PANEL_DB` | مسیر فایل SQLite | — |
| `PANEL_BOT_NAME` | نام نمایشی ربات در پیام‌ها (خالی = از تنظیمات ربات) | — |
| `PANEL_BOT_USERNAME` | نام کاربری ربات (برای لینک دعوت) | — |
| `PANEL_SUPPORT_USERNAME` | نام کاربری پشتیبانی بدون @ (اگر خالی باشد از «⚙️ تنظیمات ربات → 📢 گزارش و پشتیبانی» خوانده می‌شود) | — |
| `PANEL_WEB_URL` | آدرس https برای کال‌بک درگاه‌ها | برای درگاه |
| `PANEL_WEB_PORT` | پورت وب‌هوک (پیش‌فرض 8080) | — |

تنظیمات عملیاتی ربات (کلید پنل پاسارگارد، درگاه‌ها، شمارهٔ کارت، درصد تخفیف و…)
از داخل خود ربات و در **⚙️ پنل مدیریت** انجام می‌شود و در دیتابیس ذخیره می‌شود —
نه در فایل.

---

## انتشار بستهٔ نسخه در GitHub

پس از هر تغییر، بسته ساخته و همان لحظه روی گیت‌هاب قابل دانلود می‌شود:

```bash
php build/assemble.php --check
bash build/build_bundle.sh && bash build/package.sh
git commit -am "release: <version>" && git push
GITHUB_TOKEN=<token> bash build/publish_release.sh
```

`publish_release.sh` تگ `v<version>` می‌سازد و ریلیز گیت‌هاب را با
`tar.gz` · `zip` · `sibil-installer.sh` · `sha256` منتشر می‌کند، پس
`https://github.com/XuSibiL/SIBILPANEL/releases/latest` همیشه آخرین نسخه است.

## به‌روزرسانی خودکار

نسخهٔ تازه مستقیماً از مخزن گیت‌هاب خوانده می‌شود:

```bash
sudo sibil check     # نسخهٔ نصب‌شده و نسخهٔ گیت‌هاب را نشان می‌دهد
sudo sibil update    # جایگزینی کد + مایگریشن + ری‌استارت
```

در منوی `sudo sibil`، کنار گزینهٔ Update نشانگر `● (new vX.Y.Z)` می‌آید.
همین عملیات از داخل ربات نیز در دسترس است: **⚙️ تنظیمات ربات → ⬆️ بروزرسانی ربات**.

قواعد ایمنی که همیشه برقرار است:

| دست‌نخورده می‌ماند | بازنویسی می‌شود |
|---|---|
| `.env` و همهٔ سکرت‌ها | `sibil.php` و `src/` |
| دیتابیس `sibil.sqlite3` | `bin/`، `build/`، `docs/` |
| `logs/` و `backups/` و `uploads/` | `install.sh`، `deploy.sh`، مستندات |

- نسخهٔ قبلی کد قبل از جایگزینی در `backups/code-vX.Y.Z-*.tar.gz` نگه داشته می‌شود.
- مایگریشن بعد از به‌روزرسانی خودکار اجرا می‌شود؛ جدول‌ها با کد تازه هم‌قدم می‌شوند.
- اگر دانلود یا بررسی سینتکس شکست بخورد، **هیچ فایلی تغییر نمی‌کند**.
- سرویس با پرچم خروج تمیز ری‌استارت می‌شود (`Restart=always` در systemd).

تنظیم مخزن و آینه:

```bash
PANEL_UPDATE_REPO=owner/repo        # پیش‌فرض: مخزن رسمی
PANEL_UPDATE_BRANCH=main
PANEL_UPDATE_ARCHIVE_URL=           # آینه یا فایل محلی (اختیاری)
PANEL_UPDATE_RESTART=0              # ری‌استارت خودکار خاموش (نصب دستی)
```

---

## دستورهای CLI

```bash
sudo sibil status        # وضعیت سرویس و نسخه
sudo sibil update        # آپدیت درجا (بدون دست‌زدن به .env و دیتابیس)
sudo sibil update --force # نصب دوبارهٔ همان نسخه از گیت‌هاب
sudo sibil check         # مقایسهٔ نسخهٔ نصب‌شده با گیت‌هاب (همیشه تازه)
sudo sibil backup        # بکاپ دیتابیس
sudo sibil restore       # بازیابی بکاپ
sudo sibil doctor        # بررسی سلامت نصب
sudo sibil deps          # بررسی پیش‌نیازها
sudo sibil logs          # لاگ زنده
sudo sibil restart       # ری‌استارت
sudo sibil domain        # تنظیم دامنه
sudo sibil ssl           # گواهی TLS
sudo sibil config        # ویرایش .env
sudo sibil migrate       # ساخت/ارتقای جداول
sudo sibil version       # نسخه
sudo sibil uninstall     # حذف کامل نصب (با تأیید؛ یک کپی از دیتابیس در /root می‌ماند)
```

دستورهای مستقیم خود ربات:

```bash
php sibil.php --check     # سلف‌تست بدون تلگرام
php sibil.php --poll      # اجرای ربات
php sibil.php --migrate   # جداول
php sibil.php --version   # نسخه
php sibil.php --broadcast # ادامهٔ کار پس‌زمینهٔ پیام همگانی
php sibil.php --sync-bot  # خواندن و ذخیرهٔ یوزرنیم ربات از getMe
```

---

## ساختار پروژه

```
sibil-panel/
├── sibil.php                 ← فایل تک‌فایلی خروجی build (نصب می‌شود)
├── migration.php             ← اسکریپت مهاجرت مستقل
├── install.sh                ← نصب‌کنندهٔ تعاملی
├── VERSION
├── bin/
│   └── sibil                 ← CLI مدیریت سرور (bash)
├── build/
│   ├── assemble.php          ← چسباندن src/ → sibil.php
│   ├── manifest.php          ← ترتیب ماژول‌ها
│   └── build_bundle.sh       ← ساخت نصب‌کنندهٔ تک‌فایل
├── src/
│   ├── core/                 ← محیط، هلپرها، وضعیت گفتگو، ران‌تایم
│   ├── config/               ← تنظیمات و پیش‌فرض‌ها
│   ├── db/                   ← اسکیمای SQLite و ارتقای جداول
│   ├── services/             ← تلگرام، دامنه، پاسارگارد، پرداخت، متر مصرف
│   ├── screens/              ← همهٔ صفحه‌های کاربر و مدیر
│   ├── handlers/             ← روتر کال‌بک/پیام، ویزارد، رسیدها
│   ├── cli/                  ← سلف‌تست و دستورهای خط فرمان
│   └── web/                  ← وب‌هوک درگاه‌ها
├── tests/                    ← ۸ سوئیت تست
├── docs/                     ← معماری، امنیت، یادداشت نسخه
└── .github/workflows/ci.yml  ← CI: lint + تست
```

---

## تست‌ها

```bash
php tests/test_payment_iran_controls.php
php tests/test_payment_tetrapay.php
php tests/test_mirza_payment_selector.php
php tests/test_metered_product_callbacks.php
php tests/test_hourly_unlimited.php
php tests/test_v318_unlimited.php
php tests/test_rbac_force_join.php
bash tests/test_cli_safety.sh

# یا کل ماتریس را یک‌جا اجرا کنید
bash tests/run_all.sh
```

همه بدون شبکه اجرا می‌شوند (تلگرام و پنل mock/dry هستند). هر سوئیت PHP باید با
`failed: 0` و سوئیت shell با گزارش بدون خطا پایان یابد.

---

## امنیت

- سکرت‌ها فقط در `.env`؛ `.env` در `.gitignore`.
- دیتابیس، لاگ، بکاپ و پوشهٔ `uploads/` هیچ‌وقت کامیت نمی‌شوند.
- در وب‌سرور جلوی `logs/`, `backups/`, `uploads/*.php` را بگیر.
- توکن ربات و کلید API پنل را هر چند وقت یک‌بار عوض کن.

جزئیات بیشتر در `docs/SECURITY.md` آمده است.

---

## پرسش‌های رایج

**نام کاربری پشتیبانی از کجا می‌آید؟**
هیچ نام کاربریِ ثابتی داخل کد نیست. یا `PANEL_SUPPORT_USERNAME` را در `.env` بگذار، یا در ربات، مسیر «⚙️ پنل مدیریت → ⚙️ تنظیمات ربات → 📢 گزارش و پشتیبانی» را باز کنید و
«لینک پشتیبانی» را ثبت کنید. تا وقتی تنظیم نشده، دکمهٔ پشتیبانی نمایش داده نمی‌شود.

**بدون دامنه هم کار می‌کند؟**
بله برای کارت‌به‌کارت و کیف پول. برای کال‌بک درگاه‌ها به آدرس https نیاز داری
(`PANEL_WEB_URL`).

**چرا `sibil.php` تک‌فایلی است؟**
چون به‌روزرسانی درجا با `sibil update` باید دقیقاً یک فایل را جایگزین کند و هیچ نصب
نیمه‌کاره‌ای باقی نگذارد. کد منبع ماژولار در `src/` است و با `build/assemble.php`
به همان فایل تبدیل می‌شود.

**دیتابیس MySQL پشتیبانی می‌شود؟**
خیر؛ SQLite با PDO است و برای این حجم کارایی کافی و بکاپ ساده دارد.

---

## مجوز

مجوز MIT — استفاده، تغییر و انتشار آزاد است، با حفظ متن مجوز.
