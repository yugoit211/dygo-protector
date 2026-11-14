# DYGO — PHP Source Protector (gaya ionCube)

Sistem proteksi source PHP: meng-**encode** file PHP menjadi payload terenkripsi
(**AES-256-CBC + HMAC-SHA256**), lalu menjalankannya kembali secara transparan
lewat **ekstensi loader C** (seperti ionCube) atau **loader PHP murni** sebagai
fallback. Mendukung **expiry**, **domain-lock**, **anti-tamper**, dan
**obfuscation**.

> Lindungi hanya kode milik Anda sendiri / yang Anda punya hak atasnya.

## Komponen

| Folder      | Isi                                                                   |
|-------------|-----------------------------------------------------------------------|
| `encoder/`  | Encoder PHP: CLI (`bin/dygo-encode`) + kelas `Encoder`/`Obfuscator`.   |
| `web/`      | Web UI upload → download ZIP hasil encode.                            |
| `loader/`   | Ekstensi Zend (C) ala ionCube + kripto self-contained + test.         |
| `runtime/`  | Loader PHP murni (fallback, tanpa perlu kompilasi).                    |
| `shared/`   | `dygo-codec.php` (format bersama) + `FORMAT.md` (spesifikasi).         |
| `keys/`     | `master.key.hex` — kunci rahasia (JANGAN commit / sebar).             |
| `samples/`  | Contoh source. `dist/` — contoh hasil encode.                         |

## Cara kerja

```
         encode                         jalankan
source ─────────► file .php ter-encode ──────────► output
 (PHP)   AES+HMAC   (stub + payload)      loader      (asli)
                                         ┌─ ekstensi C (zend_compile_file hook), ATAU
                                         └─ dygo-runtime.php (fallback PHP murni)
```

File ter-encode = stub PHP terbaca + `__halt_compiler();` + `DYGO0001` +
base64(payload). Loader mendekripsi di memori (plaintext **tak pernah** ke disk),
verifikasi HMAC + lisensi, lalu compile source asli. Detail byte: `shared/FORMAT.md`.

## Mulai cepat

> 📘 **Panduan instalasi lengkap (Windows/Linux/Mac, detail): [`docs/INSTALL.md`](docs/INSTALL.md)**

CLI terpadu ada di `bin/dygo`:

```bash
php bin/dygo keygen                 # 1. buat master key (sekali)
php bin/dygo install                # 2. build & pasang loader C (Linux/Mac)
php bin/dygo encode src/ -o dist/   # 3. encode kode Anda
php bin/dygo doctor                 # cek kesiapan lingkungan kapan saja
```

### 1. Master key (sekali)

```bash
php bin/dygo keygen
```

### 2. Pasang loader

Linux/macOS (otomatis build + aktifkan di php.ini):
```bash
sh install.sh                       # atau: php bin/dygo install
sh install.sh --fallback-only       # tanpa kompilasi (loader PHP murni)
```
Windows:
```powershell
powershell -ExecutionPolicy Bypass -File install.ps1 -FallbackOnly   # termudah
powershell -ExecutionPolicy Bypass -File install.ps1                 # pakai DLL prebuilt
```

### 3. Encode (CLI) — per file atau satu folder

```bash
# satu file
php bin/dygo encode app.php -o dist/app.php

# satu folder penuh + lisensi (domain, expiry, node-lock, obfuscation)
php bin/dygo encode src/ -o dist/ \
    --domain example.com --domain "*.example.com" \
    --expire 2027-12-31 \
    --node $(php bin/dygo machineid | awk '/Node hash/{print $NF}') \
    --rename-vars
```

### Encode via Web UI

```bash
php -S 127.0.0.1:8080 -t web
# buka http://127.0.0.1:8080 — upload .php, atur domain/expiry, download ZIP
```

> Web UI memegang master key → hanya jalankan di localhost/jaringan internal.

### Menjalankan hasil

**Opsi A — ekstensi loader C (produksi, proteksi terkuat):** setelah `install.sh`,
file ter-encode jalan otomatis — tanpa file pendukung.

**Opsi B — loader PHP murni (tanpa kompilasi):** sertakan `dygo-runtime.php` +
`dygo-codec.php` (otomatis saat `encode folder`), lalu beri master key via
`DYGO_MASTER_KEY` (env) atau file `dygo.key` di folder bundle.

### Lisensi yang didukung (ditegakkan kedua loader)

| Opsi | Efek |
|------|------|
| `--domain <host>` | Hanya jalan di domain tsb (dukung `*.sub.com`) |
| `--expire <tgl>`  | Berhenti setelah tanggal tsb |
| `--node <hash>`   | Hanya jalan di server dgn machine-id tsb (`dygo machineid`) |
| `--rename-vars`   | Obfuscate nama variabel lokal |

## Status teruji (di repo ini)

- ✅ CLI terpadu `bin/dygo` (keygen/encode/machineid/doctor/install)
- ✅ Encode CLI + Web UI (per file & satu folder penuh)
- ✅ Installer `install.sh` teruji end-to-end: build → salin ke extension_dir →
  aktifkan di php.ini → file ter-encode jalan otomatis tanpa `-d extension`
- ✅ Loader PHP murni menjalankan hasil (scope global via stream wrapper)
- ✅ Ekstensi C **ter-build & teruji di PHP 8.5**, menjalankan file ter-encode
  di folder bersih (tanpa runtime/kunci) — dekripsi di memori
- ✅ Anti-tamper (HMAC) menolak file yang diubah 1 byte
- ✅ Expiry, domain-lock, **dan node-lock (machine-id)** ditegakkan loader PHP
  **dan** loader C; hash machine-id PHP ↔ C terbukti identik
- ✅ Kripto C ↔ PHP terbukti cocok byte-per-byte (`loader/test_crypto.c`)

## Keamanan — baca ini

- **Rahasia = master key.** Siapa pun yang punya master key bisa mendekripsi.
  Jaga `keys/master.key.hex` & `DYGO_MASTER_KEY_HEX`. Jangan commit.
- Loader PHP murni harus menyimpan kunci di sisi yang sama dengan kode →
  proteksinya lebih lemah dari ekstensi C (kunci tertanam dalam biner terkompilasi).
  Untuk proteksi serius, pakai **ekstensi C** dan jangan distribusikan key hex.
- Ini **bukan** jaminan anti-reverse mutlak. Seperti ionCube pun, kode yang
  akhirnya dijalankan mesin PHP secara teori bisa diintip oleh penyerang dengan
  akses penuh ke server. Tujuan realistis: menaikkan biaya reverse-engineering
  secara signifikan, bukan kemustahilan.
- Ganti master key default sebelum produksi.

## Lisensi penggunaan

Alat ini untuk melindungi kode milik Anda. Jangan gunakan untuk menyembunyikan
kode berbahaya.
