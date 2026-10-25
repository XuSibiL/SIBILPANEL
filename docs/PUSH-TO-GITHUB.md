# آماده‌سازی و پوش به گیت‌هاب

مخزن محلی در همین پوشه از قبل `git init` شده و اولین کامیت هم زده شده است.
بقیهٔ کارها فقط چند دستور است.

## ۰) راه ساده: یک دستور

```bash
bash deploy.sh <OWNER>/<REPO>            # پوشهٔ SIBILPANEL را می‌سازد و پوش می‌کند
GITHUB_TOKEN=ghp_xxx bash deploy.sh <OWNER>/<REPO>   # اگر مخزن خصوصی است
```

`deploy.sh` خودش فایل‌های حساس را کنار می‌گذارد (`tests/`، دیتابیس، `.env`، لاگ)
و توکن را فقط برای همان `git push` استفاده می‌کند.

## ۱) راه دستی: ساخت مخزن و پوش

```bash
cd SIBILPANEL

# نام و ایمیل فقط برای همین مخزن
git config user.name  "Your Name"
git config user.email "you@example.com"

# مخزن خالی را روی گیت‌هاب بساز (بدون README و بدون .gitignore)
git remote add origin https://github.com/<OWNER>/<REPO>.git
git branch -M main
git push -u origin main
```

اگر می‌خواهی با SSH بفرستی:

```bash
git remote set-url origin git@github.com:<OWNER>/<REPO>.git
```

## ۲) قبل از پوش، این چک امنیتی را بزن

```bash
# ۱) هیچ فایل محیطی واقعی نباید باشد
git ls-files | grep -iE '\.env($|\.)' | grep -v example   # باید خالی باشد

# ۲) دیتابیس/لاگ/بکاپ نباید باشد
git ls-files | grep -iE '(\.sqlite3|\.log|backups/|uploads/)'   # باید خالی باشد

# ۳) جستجوی سریع سکرت‌ها
grep -rnE "([0-9]{8,10}:[A-Za-z0-9_-]{30,}|pg_key_[a-f0-9]{8}|apikey-[A-Za-z0-9]{8}|6037[0-9]{12})" . \
  | grep -v '^./tests/' | grep -v '^./dist/'
```

اگر خروجی هر سه دستور خالی بود، امن است.

## ۳) اگر قبلاً این کد را جایی عمومی گذاشته‌ای

این مقادیر را **باطل و از نو بساز** (چون در نسخه‌های قبلی داخل برنامه ذخیره
شده بودند، حتی اگر داخل سورس نبودند):

- توکن ربات → @BotFather → `/revoke`
- کلید API پنل پاسارگارد → از پنل، کلید جدید
- رمز ادمین پنل پاسارگارد
- کلید SwapWallet / NowPayments / ZarinPal / Plisio
- شمارهٔ کارت مقصد (اگر عمومی شده بود، عوضش کن)

## ۴) انتشار نسخه

```bash
# شمارهٔ نسخه را در سه جا بالا ببر:
#   src/core/00-preamble.php   (const PANEL_VERSION)
#   install.sh                 (VERSION=)
#   VERSION
php build/assemble.php
bash build/build_bundle.sh          # dist/sibil-installer.sh

git add -A
git commit -m "release: 3.14.0"
git tag -a v3.14.0 -m "release 3.14.0"
git push && git push --tags
```

فایل `dist/sibil-installer.sh` را می‌توانی به‌عنوان asset همان Release روی
گیت‌هاب هم بگذاری تا با یک `curl` نصب شود.

## ۵) قالب پیشنهادی Issue / PR

- باگ: نسخه، خروجی `sibil doctor`، و مراحل بازتولید
- درخواست ویژگی: مسئله‌ای که حل می‌شود، نه فقط راه‌حل
- هیچ‌وقت توکن یا شمارهٔ کارت واقعی را در Issue نگذار
