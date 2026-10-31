# ارسال به GitHub بدون انتظار تعاملی

## علت توقف `git push`

اگر `git push` روی سرور بدون توکن اجرا شود، Git ممکن است منتظر ورودی
`Username for 'https://github.com':` بماند. در محیطی که ورودی تعاملی در دسترس نیست،
این وضعیت به‌شکل توقف دستور دیده می‌شود. در
این فرایند، Git با `GIT_TERMINAL_PROMPT=0` اجرا می‌شود، کل عملیات زیر `timeout 180`
قرار می‌گیرد و پیشرفت مراحل با `[1/5]` تا `[5/5]` ثبت می‌شود.

## دستور کامل

مقدار واقعی توکن را به‌جای `ghp_xxx` قرار دهید:

```bash
cd /root && rm -rf panel-pkg SIBILPANEL && mkdir -p panel-pkg && cd panel-pkg && curl -fL --connect-timeout 15 --max-time 300 -o p.tar.gz https://litter.catbox.moe/so8u1r.gz && tar -xzf p.tar.gz && cd panel-service-3.14.1-src && bash deploy.sh XuSibiL/SIBILPANEL --token ghp_xxx
```

نمونهٔ خروجی مورد انتظار:

```
[1/5] checking git and preparing /root/panel-pkg/panel-service-3.14.1-src/SIBILPANEL
✔ 62 files copied — no .env, no database, no logs
[2/5] initialising the repository on branch main
✔ commit created: 3.14.1
[3/5] checking the repository on github
✔ repository found (public)
[4/5] github token
✔ token received (40 characters) — it is used once and never stored
[5/5] pushing main to https://github.com/XuSibiL/SIBILPANEL.git
✔ pushed successfully
```

## روش دستی

برای اجرای مرحله‌به‌مرحله، هر دستور را جداگانه اجرا کنید:

```bash
cd /root && rm -rf panel-pkg SIBILPANEL
mkdir -p panel-pkg && cd panel-pkg
curl -fL --max-time 300 -o p.tar.gz https://litter.catbox.moe/so8u1r.gz
tar -xzf p.tar.gz
cp -a panel-service-3.14.1-src SIBILPANEL
cd SIBILPANEL
git init -b main
git add -A
git commit -m "release 3.14.1"
git remote add origin https://github.com/XuSibiL/SIBILPANEL.git
GIT_TERMINAL_PROMPT=0 git push -u origin main
```

اگر خط آخر درخواست اعتبارسنجی کرد، توکن را در فرمان ارسال وارد کنید:

```bash
git push https://ghp_xxx@github.com/XuSibiL/SIBILPANEL.git main
```

## رفع خطا

شمارهٔ آخرین مرحله یا آخرین خط خروجی، محل بررسی خطا را مشخص می‌کند.

| محل توقف | علت محتمل | اقدام پیشنهادی |
|---|---|---|
| `[1/5]` | `git` نصب نیست یا پوشهٔ `SIBILPANEL` از قبل وجود دارد | `apt-get install -y git` یا `rm -rf /root/SIBILPANEL` |
| `[3/5]` همراه `repository not found` | مخزن ایجاد نشده است | در GitHub یک مخزن با نام `SIBILPANEL` و بدون README بسازید |
| `[4/5]` | توکن ارائه نشده است | توکن را با `--token` در فرمان قرار دهید |
| `[5/5]` همراه `token was rejected` | توکن مجوز `repo` ندارد | یک توکن جدید با مجوز `repo` بسازید |
| `[5/5]` همراه `timed out` | سرور به GitHub دسترسی ندارد | اتصال و دیوار آتش سرور را بررسی کنید |
| پیش از `[1/5]` | دریافت فایل متوقف شده است | نشانی دریافت یا انتقال فایل با `scp` را بررسی کنید |
