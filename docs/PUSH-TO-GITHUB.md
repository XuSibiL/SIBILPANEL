# آماده‌سازی و ارسال به GitHub

مخزن محلی در این پوشه با `git init` آماده شده است. مراحل زیر برای ایجاد یا به‌روزرسانی
مخزن GitHub استفاده می‌شوند.

## روش ساده

```bash
bash deploy.sh <OWNER>/<REPO>            # پوشهٔ SIBILPANEL را می‌سازد و پوش می‌کند
GITHUB_TOKEN=ghp_xxx bash deploy.sh <OWNER>/<REPO>   # اگر مخزن خصوصی است
```

`deploy.sh` فایل‌های حساس شامل `tests/`، پایگاه داده، `.env` و گزارش‌ها را کنار
می‌گذارد و توکن را فقط برای همان `git push` به‌کار می‌برد.

## روش دستی: ساخت مخزن و ارسال

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

برای ارسال با SSH:

```bash
git remote set-url origin git@github.com:<OWNER>/<REPO>.git
```

## بررسی امنیتی پیش از ارسال

```bash
# ۱) هیچ فایل محیطی واقعی نباید باشد
git ls-files | grep -iE '\.env($|\.)' | grep -v example   # باید خالی باشد

# ۲) دیتابیس/لاگ/بکاپ نباید باشد
git ls-files | grep -iE '(\.sqlite3|\.log|backups/|uploads/)'   # باید خالی باشد

# ۳) جستجوی سریع سکرت‌ها
grep -rnE "([0-9]{8,10}:[A-Za-z0-9_-]{30,}|pg_key_[a-f0-9]{8}|apikey-[A-Za-z0-9]{8}|6037[0-9]{12})" . \
  | grep -v '^./tests/' | grep -v '^./dist/'
```

اگر هر سه دستور خروجی نداشتند، مورد شناخته‌شده‌ای از فایل حساس در فهرست بررسی‌شده
وجود ندارد.

## اقدام پس از انتشار ناخواستهٔ کد

اگر کد پیش‌تر در محل عمومی قرار گرفته است، این موارد را باطل و جایگزین کنید:

- توکن ربات از طریق `@BotFather` و `/revoke`؛
- کلید API پنل PasarGuard؛
- رمز مدیر پنل PasarGuard؛
- کلید SwapWallet، NowPayments، ZarinPal و Plisio؛
- شمارهٔ کارت مقصد، در صورت افشای عمومی.

## انتشار نسخه

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

فایل `dist/sibil-installer.sh` را می‌توان به‌عنوان دارایی همان انتشار در GitHub قرار داد.

## قالب پیشنهادی Issue و PR

- گزارش اشکال: نسخه، خروجی `sibil doctor` و مراحل بازتولید؛
- درخواست قابلیت: مسئلهٔ مورد نظر، نه فقط راه‌حل پیشنهادی؛
- دادهٔ حساس، توکن یا شمارهٔ کارت واقعی را در Issue یا PR ثبت نکنید.
