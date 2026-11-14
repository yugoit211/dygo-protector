#!/usr/bin/env bash
# Build ekstensi loader C dari master key saat ini.
set -euo pipefail
cd "$(dirname "$0")"

KEY_FILE="keys/master.key.hex"
if [ ! -f "$KEY_FILE" ]; then
    echo "Master key belum ada. Membuat..."
    php -r 'echo bin2hex(random_bytes(32));' > "$KEY_FILE"
    echo "  -> $KEY_FILE"
fi
KEY=$(cat "$KEY_FILE")

echo "==> Build loader C dengan master key dari $KEY_FILE"
cd loader
command -v phpize >/dev/null || { echo "phpize tidak ada (install paket php-dev)."; exit 1; }

make clean >/dev/null 2>&1 || true
phpize >/dev/null
CFLAGS="-DDYGO_MASTER_KEY_HEX='\"$KEY\"'" ./configure --enable-dygo-loader >/dev/null
make >/dev/null

SO="$(pwd)/modules/dygo_loader.so"
echo "==> Selesai: $SO"
echo
echo "Uji:"
echo "  php -d extension=$SO -m | grep dygo_loader"
echo
echo "Aktifkan permanen: tambahkan ke php.ini ->  extension=$SO"
