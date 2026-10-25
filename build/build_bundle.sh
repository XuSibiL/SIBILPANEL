#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  SIBIL PANEL — release builder
#
#  1) src/**  →  sibil.php           (assemble.php، فایل تک‌فایلیِ نصب)
#  2) sibil.php + sibil + migration.php  →  dist/sibil-installer.sh
#
#  Run:  bash build/build_bundle.sh
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail
cd "$(dirname "$0")/.."

php build/assemble.php
VERSION="$(grep -m1 "const PANEL_VERSION" sibil.php | sed "s/.*'\(.*\)'.*/\1/" || true)"
[[ -n "$VERSION" ]] || VERSION="$(tr -d ' \n\r' < VERSION)"
mkdir -p dist
OUT="dist/sibil-installer.sh"

cp install.sh "$OUT"
python3 - "$OUT" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read()
begin = "# @@SIBIL_PAYLOAD_BEGIN@@"
end = "# @@SIBIL_PAYLOAD_END@@"
head = text.split(begin)[0]
open(path, "w", encoding="utf-8").write(head + begin + "\n" + end + "\n")
PY

python3 - "$OUT" "$VERSION" <<'PY'
import base64, sys
out, version = sys.argv[1], sys.argv[2]
files = ["sibil.php", "bin/sibil", "migration.php"]
lines = ["# @@SIBIL_PAYLOAD_BEGIN@@"]
for name in files:
    blob = base64.b64encode(open(name, "rb").read()).decode()
    base = name.split("/")[-1]
    lines.append(f"#SIBIL|{base}|{blob}")
lines.append("# @@SIBIL_PAYLOAD_END@@")
text = open(out, encoding="utf-8").read()
begin = "# @@SIBIL_PAYLOAD_BEGIN@@"
end = "# @@SIBIL_PAYLOAD_END@@"
head = text.split(begin)[0]
tail = text.split(end)[1] if end in text else ""
open(out, "w", encoding="utf-8").write(head + "\n".join(lines) + "\n" + end + tail)
print("bundle :", out)
print("version:", version)
print("size   :", len(open(out, "rb").read()), "bytes")
PY

MD5=$(md5sum "$OUT" | cut -d' ' -f1)
echo "md5    : $MD5"
