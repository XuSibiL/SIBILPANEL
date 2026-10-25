#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  SIBIL PANEL — source package builder
#
#  release/panel-service-<VERSION>-src.tar.gz  (+ .zip + .sha256)
#  محتوای بسته: کد و مستندات — بدون .env، دیتابیس، لاگ، بکاپ، تست و فایل موقت
#
#  Run:  bash build/package.sh
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="$(tr -d ' \n\r' < VERSION)"
NAME="panel-service-${VERSION}-src"
STAGE=".pack-${NAME}"
mkdir -p release
rm -rf "$STAGE"
mkdir -p "$STAGE/$NAME"

copy() {
    local item="$1"
    [[ -e "$item" ]] || return 0
    cp -a "$item" "$STAGE/$NAME/"
}

for item in install.sh sibil.php migration.php VERSION README.md LICENSE \
            .env.example .gitignore deploy.sh sibil bin build src docs; do
    copy "$item"
done

# هیچ فایل حساسی داخل بسته نمی‌رود
find "$STAGE" -name '.env' -o -name '*.sqlite3*' -o -name '*.log' \
    -o -name 'tests' -o -name 'release' -o -name 'dist' | while read -r junk; do
    rm -rf "$junk"
done
rm -rf "$STAGE/$NAME/tests" "$STAGE/$NAME/.pack-"*

# چک نهایی: هیچ راز/فایل موقتی نمانده باشد
if grep -rIlE 'ghp_[A-Za-z0-9]{20,}|BOT_TOKEN=[0-9]{6,}:' "$STAGE" 2>/dev/null | grep -v '.env.example' | head -1 | grep -q .; then
    echo "package: a secret looking string was found — aborting" >&2
    exit 1
fi

TAR="release/${NAME}.tar.gz"
ZIP="release/${NAME}.zip"
rm -f "$TAR" "$ZIP" "release/${NAME}.sha256"
tar -czf "$TAR" -C "$STAGE" "$NAME"
( cd "$STAGE" && zip -qr "../$ZIP" "$NAME" >/dev/null )
rm -rf "$STAGE"
( cd release && sha256sum "$(basename "$TAR")" > "${NAME}.sha256" )

echo "tar    : $TAR  ($(stat -c%s "$TAR") bytes)"
echo "md5    : $(md5sum "$TAR" | cut -d' ' -f1)"
echo "zip    : $ZIP  ($(stat -c%s "$ZIP") bytes)"
echo "md5    : $(md5sum "$ZIP" | cut -d' ' -f1)"
