#!/usr/bin/env sh
# =============================================================================
# DYGO Loader — Installer untuk Linux & macOS
#
#   sh install.sh                 # build + pasang loader C untuk `php` di PATH
#   sh install.sh --php /usr/bin/php8.3
#   sh install.sh --fallback-only # lewati build C, siapkan loader PHP murni saja
#
# Membutuhkan (untuk loader C): paket dev PHP => menyediakan `phpize` & `php-config`.
#   Debian/Ubuntu : sudo apt install php-dev
#   RHEL/Alma     : sudo dnf install php-devel
#   macOS (brew)  : sudo? -> brew install php  (sudah termasuk phpize)
# =============================================================================
set -eu

DYGO_HOME="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="php"
FALLBACK_ONLY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --php) PHP_BIN="$2"; shift 2 ;;
        --fallback-only) FALLBACK_ONLY=1; shift ;;
        -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Argumen tak dikenal: $1"; exit 1 ;;
    esac
done

say()  { printf '\033[36m==>\033[0m %s\n' "$1"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '\033[33m•\033[0m %s\n' "$1"; }
die()  { printf '\033[31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP tidak ditemukan: $PHP_BIN"
PHP_VER="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
say "PHP terdeteksi: $PHP_VER ($PHP_BIN)"

# --- master key ---
KEY_FILE="$DYGO_HOME/keys/master.key.hex"
if [ ! -f "$KEY_FILE" ]; then
    say "Membuat master key baru..."
    mkdir -p "$DYGO_HOME/keys"
    "$PHP_BIN" -r 'echo bin2hex(random_bytes(32));' > "$KEY_FILE"
    chmod 600 "$KEY_FILE"
    ok "Master key: $KEY_FILE"
fi
KEY="$(cat "$KEY_FILE")"
case "$KEY" in
    *[!0-9a-fA-F]*|"") die "Master key tidak valid di $KEY_FILE" ;;
esac
[ "${#KEY}" -eq 64 ] || die "Master key harus 64 hex char."

# =============================================================================
# Fallback-only: cukup pastikan runtime bisa dipakai.
# =============================================================================
if [ "$FALLBACK_ONLY" -eq 1 ]; then
    ok "Mode fallback: tidak membangun loader C."
    say "Saat encode folder, dygo-runtime.php & dygo-codec.php otomatis disertakan."
    say "Di server tujuan set: export DYGO_MASTER_KEY=$KEY  (atau taruh file dygo.key)."
    exit 0
fi

# =============================================================================
# Build & pasang loader C
# =============================================================================
PHPIZE="${PHPIZE:-phpize}"
PHP_CONFIG="${PHP_CONFIG:-php-config}"

if ! command -v "$PHPIZE" >/dev/null 2>&1; then
    warn "phpize tidak ada → tidak bisa build loader C."
    warn "Install paket dev PHP (php-dev / php-devel), atau jalankan: sh install.sh --fallback-only"
    die  "phpize dibutuhkan."
fi

say "Membangun ekstensi (menyuntik master key saat kompilasi)..."
cd "$DYGO_HOME/loader"
make clean >/dev/null 2>&1 || true
"$PHPIZE" >/dev/null
CFLAGS="-DDYGO_MASTER_KEY_HEX='\"$KEY\"'" ./configure --enable-dygo-loader ${PHP_CONFIG:+--with-php-config="$PHP_CONFIG"} >/dev/null
make >/dev/null
SO="$DYGO_HOME/loader/modules/dygo_loader.so"
[ -f "$SO" ] || die "Build gagal: $SO tidak ada."
ok "Terbangun: $SO"

# --- lokasi extension_dir ---
EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
[ -n "$EXT_DIR" ] || EXT_DIR="$("$PHP_CONFIG" --extension-dir 2>/dev/null || true)"
[ -n "$EXT_DIR" ] || die "Tidak bisa menentukan extension_dir."
say "extension_dir: $EXT_DIR"

copy_so() {
    # extension_dir kadang belum dibuat (mis. Homebrew pecl dir) — buat dulu.
    if [ ! -d "$EXT_DIR" ]; then
        mkdir -p "$EXT_DIR" 2>/dev/null || sudo mkdir -p "$EXT_DIR"
    fi
    if cp "$SO" "$EXT_DIR/dygo_loader.so" 2>/dev/null; then return 0; fi
    warn "Perlu hak akses untuk menulis ke $EXT_DIR — mencoba sudo..."
    sudo cp "$SO" "$EXT_DIR/dygo_loader.so"
}
copy_so
ok "Disalin ke $EXT_DIR/dygo_loader.so"

# --- aktifkan di php.ini ---
INI="$("$PHP_BIN" -r 'echo php_ini_loaded_file() ?: "";')"
SCAN_DIR="$("$PHP_BIN" -r '$s=ini_get("scan_dir"); echo $s?:"";' 2>/dev/null || true)"

enable_in_ini() {
    target="$1"
    if [ -f "$target" ] && grep -q '^\s*extension\s*=\s*dygo_loader' "$target" 2>/dev/null; then
        ok "Sudah aktif di $target"
        return 0
    fi
    if printf '\n; DYGO Loader\nextension=dygo_loader.so\n' >> "$target" 2>/dev/null; then
        ok "Ditambahkan ke $target"
    else
        warn "Perlu sudo untuk menulis $target"
        printf '\n; DYGO Loader\nextension=dygo_loader.so\n' | sudo tee -a "$target" >/dev/null
        ok "Ditambahkan ke $target (sudo)"
    fi
}

if [ -n "$INI" ]; then
    enable_in_ini "$INI"
elif [ -n "$SCAN_DIR" ] && [ -d "$SCAN_DIR" ]; then
    enable_in_ini "$SCAN_DIR/99-dygo-loader.ini"
else
    warn "php.ini tidak ditemukan. Tambahkan manual: extension=dygo_loader.so"
fi

# --- verifikasi ---
if "$PHP_BIN" -m 2>/dev/null | grep -qi '^dygo_loader$'; then
    ok "Loader AKTIF untuk $PHP_BIN."
else
    warn "Loader belum terbaca oleh $PHP_BIN. Cek php.ini / restart layanan."
fi

say "Selesai."
echo
echo "Langkah berikut:"
echo "  • Web (php-fpm/apache): restart layanannya agar php.ini terbaca ulang."
echo "      sudo systemctl restart php*-fpm   # atau: sudo service apache2 restart"
echo "  • Encode kode Anda:   $PHP_BIN $DYGO_HOME/bin/dygo encode src/ -o dist/"
echo "  • Cek status:         $PHP_BIN $DYGO_HOME/bin/dygo doctor"
