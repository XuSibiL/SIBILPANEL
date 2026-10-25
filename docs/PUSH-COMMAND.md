# پوش به گیت‌هاب — نسخهٔ بدون گیر کردن

## چرا گیر کرد

`git push` روی سرور، وقتی توکن نداشته باشد، منتظر می‌ماند و از ترمینال
`Username for 'https://github.com':` می‌پرسد. چون آن پیام را نمی‌بینی،
دستور «گیر کرده» به نظر می‌رسد. در نسخهٔ تازه:

- git با `GIT_TERMINAL_PROMPT=0` اجرا می‌شود، پس هیچ‌وقت منتظر ورودی نمی‌ماند،
- کل پوش زیر `timeout 180` است،
- هر مرحله با `[1/5] … [5/5]` چاپ می‌شود تا دقیقاً بدانی کجا ایستاده،
- توکن را می‌شود مستقیم در دستور داد: `--token ghp_xxx`.

## دستور کامل (توکن را جای ghp_xxx بگذار)

```bash
cd /root && rm -rf panel-pkg SIBILPANEL && mkdir -p panel-pkg && cd panel-pkg && curl -fL --connect-timeout 15 --max-time 300 -o p.tar.gz https://litter.catbox.moe/so8u1r.gz && tar -xzf p.tar.gz && cd panel-service-3.14.1-src && bash deploy.sh XuSibiL/SIBILPANEL --token ghp_xxx
```

output مورد انتظار:

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

## راه دستی (بدون اسکریپت، هر خط جدا)

اگر خواستی خودت ببینی چه می‌شود:

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

اگر خط آخر پرسید، یعنی توکن نداده‌ای. با توکن:

```bash
git push https://ghp_xxx@github.com/XuSibiL/SIBILPANEL.git main
```

## اگر باز ایستاد

بگو در کدام شمارهٔ `[N/5]` ایستاد (یا آخرین خطی که چاپ شد). معنی هر توقف:

| کجا | معنی | کار |
|---|---|---|
| `[1/5]` | `git` نصب نیست یا پوشهٔ `SIBILPANEL` از قبل هست | `apt-get install -y git` · `rm -rf /root/SIBILPANEL` |
| `[3/5]` + `repository not found` | مخزن ساخته نشده | github.com/new → نام `SIBILPANEL` → بدون README |
| `[4/5]` | منتظر توکن | همان توکن را با `--token` در دستور بگذار |
| `[5/5]` + `token was rejected` | توکن بدون اسکوپ `repo` | توکن تازه با تیک `repo` |
| `[5/5]` + `timed out` | سرور به github.com دسترسی ندارد | `curl -I https://github.com` را چک کن / فایروال |
| هیچ‌کدام (قبل از `[1/5]`) | دانلود گیر کرده | لینک را با `curl -I` تست کن یا فایل را با `scp` بفرست |
