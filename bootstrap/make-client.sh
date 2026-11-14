#!/usr/bin/env bash
# =============================================================================
#  DYGO — Pembuat paket CLIENT EDITION (hanya-baca)
# -----------------------------------------------------------------------------
#  Dijalankan oleh VENDOR (Anda). Menghasilkan paket yang bisa dikirim ke
#  server klien: HANYA bisa MENJALANKAN file ter-encode — tidak bisa
#  meng-encode (tanpa encoder, tanpa keygen, tanpa Web UI).
#
#  Isi paket:
#    bin/dygo                 -> CLI klien (hanya: install/doctor/machineid/uninstall)
#    loader/dygo_loader*.so   -> biner loader PREBUILT (key vendor sudah tertanam di biner)
#    runtime/, shared/        -> fallback PHP-murni (opsional; butuh key bila --with-key)
#    install-client.sh        -> pemasang satu-baris di server klien
#
#  Pemakaian:
#    bootstrap/make-client.sh                 # paket loader-C (kuat, TANPA key hex)
#    bootstrap/make-client.sh --with-key      # sertakan fallback + master key (LEBIH LEMAH)
#    bootstrap/make-client.sh --name acme     # nama paket kustom
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$HERE"

NAME="dygo-client"
WITH_KEY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --with-key) WITH_KEY=1 ;;
    --name) shift; NAME="${1:-dygo-client}" ;;
    -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Argumen tak dikenal: $1" >&2; exit 1 ;;
  esac
  shift
done

OUT="dist/${NAME}"
echo "==> Membuat paket klien di: ${OUT}"

# -- 0) pastikan biner loader keyed sudah dibangun -----------------------------
SO=""
for cand in loader/modules/dygo_loader.so loader/.libs/dygo_loader.so; do
  [ -f "$cand" ] && SO="$cand" && break
done
if [ -z "$SO" ]; then
  echo "!! Biner loader belum ada. Build dulu loader ber-key Anda:" >&2
  echo "     php bin/dygo install     (atau: sh install.sh)" >&2
  echo "   Lalu jalankan ulang skrip ini." >&2
  exit 1
fi

# Nomor ABI ekstensi PHP (Zend Module API) — untuk menamai biner per-ABI,
# seperti ioncube_loader_lin_8.x.so. Ambil dari "php -i" (baris "PHP API").
API="$(php -i 2>/dev/null | awk -F'=> ' '/^PHP API/{print $2}' | head -n1 | tr -dc '0-9' || true)"
OSFAM="$(php -r 'echo strtolower(PHP_OS_FAMILY);' 2>/dev/null || echo 'unknown')"

# -- 1) kerangka folder --------------------------------------------------------
rm -rf "$OUT"
mkdir -p "$OUT/bin" "$OUT/loader" "$OUT/runtime" "$OUT/shared" "$OUT/docs"

# -- 2) CLI klien (hanya-baca) -------------------------------------------------
cp bin/dygo-client "$OUT/bin/dygo"
chmod +x "$OUT/bin/dygo"

# -- 3) biner loader prebuilt (key tertanam di biner, BUKAN hex) ---------------
cp "$SO" "$OUT/loader/dygo_loader.so"
if [ -n "$API" ]; then
  cp "$SO" "$OUT/loader/dygo_loader-${API}.so"
fi
echo "   biner loader : $(basename "$SO")  (PHP API ${API:-?}, ${OSFAM})"

# -- 4) fallback PHP-murni (codec diperlukan utk machineid; runtime utk fallback)
cp shared/dygo-codec.php "$OUT/shared/"
cp runtime/dygo-runtime.php "$OUT/runtime/"

# -- 5) master key? (HANYA untuk fallback; memperlemah proteksi) ---------------
if [ "$WITH_KEY" -eq 1 ]; then
  if [ -f keys/master.key.hex ]; then
    mkdir -p "$OUT/keys"
    cp keys/master.key.hex "$OUT/keys/master.key.hex"
    chmod 600 "$OUT/keys/master.key.hex" || true
    echo "   !! master key DISERTAKAN (fallback aktif, proteksi lebih lemah)"
  else
    echo "   !! --with-key diminta tapi keys/master.key.hex tak ada; dilewati." >&2
  fi
fi

# -- 6) installer satu-baris di sisi klien -------------------------------------
cat > "$OUT/install-client.sh" <<'EOS'
#!/usr/bin/env sh
# Pemasang DYGO Client Edition (hanya-baca) di server klien.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"
echo "==> DYGO Client — memasang loader (hanya-baca) ..."
"$PHP" "$HERE/bin/dygo" install "$@"
echo "==> Selesai. Cek: $PHP $HERE/bin/dygo doctor"
EOS
chmod +x "$OUT/install-client.sh"

# -- 7) README klien -----------------------------------------------------------
cat > "$OUT/README.txt" <<EOS
DYGO — Client Edition (hanya-baca)
==================================

Paket ini HANYA bisa MENJALANKAN file PHP yang sudah di-encode oleh vendor.
Paket ini TIDAK bisa membuat enkripsi (tidak ada encoder / keygen / Web UI).
Analoginya: ini "ionCube Loader", bukan "ionCube Encoder".

Prasyarat: PHP ${API:+(API $API) }terpasang di server.

PEMASANGAN (1 langkah)
----------------------
  sh install-client.sh
    atau:  php bin/dygo install

Ini menyalin biner loader ke extension_dir PHP dan mengaktifkannya.
Setelah itu, file ter-encode dari vendor JALAN OTOMATIS — tanpa file pendukung.

PERINTAH
--------
  php bin/dygo doctor       Cek status (loader aktif?)
  php bin/dygo machineid     Node-hash server ini (kirim ke vendor utk node-lock)
  php bin/dygo uninstall     Lepas loader

Untuk web (Apache/Nginx+php-fpm): restart layanannya setelah install.

CATATAN KEAMANAN
----------------
- Biner loader ini membawa kunci vendor DI DALAM biner (bukan teks). Jaga file ini.
- Jika paket disertai keys/master.key.hex, itu untuk mode fallback PHP-murni dan
  proteksinya lebih lemah — gunakan loader C bila memungkinkan.
EOS

# -- 8) arsip -----------------------------------------------------------------
TARBALL="dist/${NAME}.tar.gz"
( cd dist && tar -czf "${NAME}.tar.gz" "${NAME}" )
echo ""
echo "==> SELESAI."
echo "    Folder : ${OUT}/"
echo "    Arsip  : ${TARBALL}"
echo ""
echo "    Isi (yang SENGAJA TIDAK ada: encoder/, web/, keygen, encode):"
( cd "$OUT" && find . -type f | sed 's|^\./|      |' )
echo ""
echo "    Kirim arsip ini ke klien. Mereka cukup: tar -xzf ${NAME}.tar.gz && sh ${NAME}/install-client.sh"
