#!/usr/bin/env sh
# =============================================================================
# DYGO — Bootstrap installer (Linux & macOS)
#
# Pemakaian (one-liner):
#   # repo privat (pakai gh yang sudah login):
#   gh api repos/yugoit211/dygo-protector/contents/bootstrap/dygo-install.sh \
#     -H "Accept: application/vnd.github.raw" | sh
#
#   # repo publik:
#   curl -fsSL https://raw.githubusercontent.com/yugoit211/dygo-protector/master/bootstrap/dygo-install.sh | sh
#
# Opsi (tambahkan setelah `| sh -s --`):
#   --with-loader       build + pasang ekstensi loader C sekaligus
#   --fallback-only     siapkan loader PHP murni (tanpa kompilasi)
#   --ref <branch|tag>  ambil versi tertentu (default: master)
#
# Variabel lingkungan:
#   DYGO_REPO   (default: yugoit211/dygo-protector)
#   DYGO_REF    (default: master)
#   DYGO_HOME   (default: ~/.dygo)        -> lokasi instalasi
#   DYGO_BIN    (default: ~/.local/bin)   -> lokasi perintah `dygo`
#   DYGO_TOKEN  token GitHub (opsi, bila tanpa gh dan repo privat)
# =============================================================================
set -eu

REPO="${DYGO_REPO:-yugoit211/dygo-protector}"
REF="${DYGO_REF:-master}"
DEST="${DYGO_HOME:-$HOME/.dygo}"
BIN="${DYGO_BIN:-$HOME/.local/bin}"
WITH_LOADER=0
FALLBACK_ONLY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --with-loader)   WITH_LOADER=1; shift ;;
        --fallback-only) FALLBACK_ONLY=1; shift ;;
        --ref)           REF="$2"; shift 2 ;;
        *) echo "Argumen tak dikenal: $1"; exit 1 ;;
    esac
done

info() { printf '\033[36m==>\033[0m %s\n' "$1"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '\033[33m•\033[0m %s\n' "$1"; }
die()  { printf '\033[31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

command -v php >/dev/null 2>&1 || die "PHP tidak ditemukan. Pasang PHP 8.1+ dulu (mis. apt install php-cli / brew install php)."
command -v tar >/dev/null 2>&1 || die "perintah 'tar' dibutuhkan."

info "Mengunduh DYGO ($REPO@$REF) ..."
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
tarball="$tmp/dygo.tar.gz"

if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
    gh api "repos/$REPO/tarball/$REF" > "$tarball"
elif [ -n "${DYGO_TOKEN:-}" ]; then
    curl -fsSL -H "Authorization: Bearer $DYGO_TOKEN" \
        "https://api.github.com/repos/$REPO/tarball/$REF" -o "$tarball"
else
    curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$REF" -o "$tarball" 2>/dev/null \
      || curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/tags/$REF" -o "$tarball" 2>/dev/null \
      || die "Gagal mengunduh. Repo privat? Pasang & login 'gh', atau set DYGO_TOKEN."
fi
[ -s "$tarball" ] || die "Unduhan kosong."

info "Memasang ke $DEST ..."
rm -rf "$DEST"
mkdir -p "$DEST"
tar -xzf "$tarball" -C "$DEST" --strip-components=1
[ -f "$DEST/bin/dygo" ] || die "Struktur paket tidak sesuai (bin/dygo tidak ada)."

# --- perintah `dygo` ---
mkdir -p "$BIN"
cat > "$BIN/dygo" <<EOF
#!/usr/bin/env sh
exec php "$DEST/bin/dygo" "\$@"
EOF
chmod +x "$BIN/dygo"
ok "Perintah terpasang: $BIN/dygo"

# --- loader (opsional) ---
if [ "$FALLBACK_ONLY" -eq 1 ]; then
    sh "$DEST/install.sh" --fallback-only || warn "install.sh (fallback) melaporkan masalah."
elif [ "$WITH_LOADER" -eq 1 ]; then
    info "Membangun & memasang ekstensi loader C ..."
    sh "$DEST/install.sh" || warn "install.sh melaporkan masalah (lihat docs/INSTALL.md)."
fi

ok "DYGO terpasang."
echo

# --- PATH check ---
case ":$PATH:" in
    *":$BIN:"*) : ;;
    *)
        warn "$BIN belum ada di PATH. Tambahkan baris ini ke ~/.bashrc / ~/.zshrc:"
        echo "      export PATH=\"$BIN:\$PATH\""
        echo "   lalu buka terminal baru, atau jalankan: export PATH=\"$BIN:\$PATH\""
        ;;
esac

echo
echo "Langkah berikut:"
echo "  dygo keygen           # buat master key (sekali, di mesin encoder)"
echo "  dygo encode src/ -o dist/"
echo "  dygo doctor           # cek lingkungan"
[ "$WITH_LOADER" -eq 0 ] && [ "$FALLBACK_ONLY" -eq 0 ] && \
echo "  (untuk pasang loader C: jalankan ulang dengan --with-loader)"
