#!/usr/bin/env sh
# DYGO Loader — uninstaller (Linux & macOS)
#   sh uninstall.sh [--php /path/to/php]
set -eu

PHP_BIN="php"
[ "${1:-}" = "--php" ] && { PHP_BIN="$2"; shift 2; }

say() { printf '\033[36m==>\033[0m %s\n' "$1"; }
ok()  { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn(){ printf '\033[33m•\033[0m %s\n' "$1"; }

command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "PHP tidak ditemukan: $PHP_BIN"; exit 1; }

EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
INI="$("$PHP_BIN" -r 'echo php_ini_loaded_file() ?: "";')"

# hapus .so
if [ -n "$EXT_DIR" ] && [ -f "$EXT_DIR/dygo_loader.so" ]; then
    rm -f "$EXT_DIR/dygo_loader.so" 2>/dev/null || sudo rm -f "$EXT_DIR/dygo_loader.so"
    ok "Dihapus: $EXT_DIR/dygo_loader.so"
else
    warn "dygo_loader.so tidak ditemukan di $EXT_DIR"
fi

# hapus baris di ini
if [ -n "$INI" ] && grep -q 'dygo_loader' "$INI" 2>/dev/null; then
    tmp="$(mktemp)"
    grep -v 'DYGO Loader' "$INI" | grep -v 'extension=dygo_loader' > "$tmp"
    cp "$tmp" "$INI" 2>/dev/null || sudo cp "$tmp" "$INI"
    rm -f "$tmp"
    ok "Baris loader dihapus dari $INI"
else
    warn "Tidak ada baris loader di php.ini ($INI)"
fi

# scan dir file
SCAN_FILE=""
for d in /etc/php*/conf.d /etc/php/*/*/conf.d /opt/homebrew/etc/php/*/conf.d /usr/local/etc/php/*/conf.d; do
    [ -f "$d/99-dygo-loader.ini" ] && SCAN_FILE="$d/99-dygo-loader.ini"
done
[ -n "$SCAN_FILE" ] && { rm -f "$SCAN_FILE" 2>/dev/null || sudo rm -f "$SCAN_FILE"; ok "Dihapus: $SCAN_FILE"; }

say "Selesai. Restart php-fpm/apache bila dipakai untuk web."
