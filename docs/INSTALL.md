# Panduan Lengkap DYGO — Instalasi & Penggunaan

Panduan ini mencakup **Linux**, **macOS**, dan **Windows**, dari nol sampai bisa
meng-encode kode dan menjalankannya di server lain — mirip alur ionCube.

Daftar isi:
1. [Konsep: 2 cara menjalankan hasil encode](#1-konsep)
2. [Prasyarat per OS](#2-prasyarat)
3. [Langkah 0 — Master key](#3-master-key)
4. [Instalasi di Linux](#4-linux)
5. [Instalasi di macOS](#5-macos)
6. [Instalasi di Windows](#6-windows)
7. [Meng-encode kode Anda](#7-encode)
8. [Deploy ke server klien](#8-deploy)
9. [Verifikasi & doctor](#9-verifikasi)
10. [Troubleshooting](#10-troubleshooting)
11. [Uninstall](#11-uninstall)

---

## 1. Konsep

Satu file hasil encode bisa dijalankan dengan **salah satu** dari dua loader:

| Loader | Perlu kompilasi? | Keamanan | Cocok untuk |
|--------|------------------|----------|-------------|
| **Ekstensi C** (`dygo_loader`) | Ya (per versi PHP/OS) | Kuat — master key tertanam di biner | Produksi / distribusi produk |
| **PHP murni** (`dygo-runtime.php`) | Tidak | Lebih lemah — key harus ada di server | Setup cepat / hosting tanpa akses root |

> **Master key** adalah rahasia inti. Encoder dan loader harus memakai key yang
> **sama**. Simpan baik-baik (`keys/master.key.hex`), jangan commit, jangan sebar.

File hasil encode tetap `.php`: berisi stub kecil + `__halt_compiler();` + payload
terenkripsi. Dengan ekstensi C aktif, stub tak pernah dijalankan — loader
mencegat compiler, mendekripsi di memori, cek lisensi (expiry/domain/node),
lalu menjalankan kode asli. Tanpa ekstensi, stub memanggil `dygo-runtime.php`
yang melakukan hal sama di PHP murni.

---

## 2. Prasyarat

**Semua OS (untuk encoder):** PHP 8.1+ dengan ekstensi `openssl`, `hash`,
`tokenizer` (biasanya bawaan), dan `zip` (hanya untuk Web UI).

**Untuk membangun ekstensi C:**

| OS | Yang dibutuhkan |
|----|-----------------|
| Linux | `php-dev`/`php-devel` (menyediakan `phpize`, `php-config`), `gcc`/`make` |
| macOS | PHP dari Homebrew (sudah termasuk `phpize`), Xcode Command Line Tools |
| Windows | PHP SDK + Visual Studio (build dari source) — **atau** DLL prebuilt |

Cek kesiapan kapan saja:

```bash
php bin/dygo doctor
```

---

## 3. Master key

Lakukan **sekali**. Key ini dipakai selamanya untuk proyek Anda.

```bash
php bin/dygo keygen
# -> keys/master.key.hex
```

> Jika suatu saat key hilang/diganti, semua file yang sudah di-encode dengan key
> lama **tidak bisa** dibuka lagi. Backup key di tempat aman.

---

## 4. Linux

### 4.1 Pasang dependensi

Debian / Ubuntu:
```bash
sudo apt update
sudo apt install php php-cli php-dev php-zip build-essential
```

RHEL / AlmaLinux / Rocky:
```bash
sudo dnf install php php-cli php-devel php-pear gcc make
```

### 4.2 Jalankan installer

```bash
cd /path/ke/DYGO-PROJECT
sh install.sh
```

Installer akan: membuat key (bila belum ada) → build ekstensi dengan key
tertanam → menyalin `dygo_loader.so` ke `extension_dir` → menambahkan
`extension=dygo_loader.so` ke `php.ini` → verifikasi.

> Jika PHP Anda punya beberapa versi, arahkan ke yang benar:
> ```bash
> sh install.sh --php /usr/bin/php8.3
> ```

### 4.3 Aktifkan untuk web (FPM/Apache)

`php.ini` CLI dan web sering berbeda. Pastikan baris `extension=dygo_loader.so`
ada di ini web, lalu restart:

```bash
php -i | grep "Loaded Configuration File"          # ini CLI
php-fpm -i | grep "Loaded Configuration File"       # ini FPM (jika ada)
sudo systemctl restart php8.3-fpm        # sesuaikan versi
# atau Apache mod_php:
sudo systemctl restart apache2
```

### 4.4 Tanpa akses root / tanpa php-dev

Pakai jalur fallback (tanpa kompilasi):
```bash
sh install.sh --fallback-only
```
Lalu lihat [Deploy](#8-deploy) untuk set master key di server.

---

## 5. macOS

### 5.1 Pasang PHP (Homebrew)

```bash
brew install php
xcode-select --install     # bila CLT belum ada
```

### 5.2 Jalankan installer

```bash
cd /path/ke/DYGO-PROJECT
sh install.sh
```

Pada macOS, `extension_dir` Homebrew mis.
`/opt/homebrew/lib/php/pecl/<api>`; installer mendeteksinya otomatis dan
mengedit `php.ini` yang benar (cek dengan `php --ini`).

### 5.3 Catatan Apple Silicon

Loader dibangun untuk arsitektur PHP Anda (arm64/x86_64) secara otomatis oleh
`phpize`. Jangan campur PHP Intel dengan loader arm64.

---

## 6. Windows

Membangun ekstensi dari source di Windows relatif rumit. Ada dua jalur:

### 6.1 Jalur TERMUDAH — loader PHP murni (tanpa compile)

```powershell
powershell -ExecutionPolicy Bypass -File install.ps1 -FallbackOnly
```

Lalu saat deploy, set master key (lihat [Deploy](#8-deploy)):
```powershell
setx DYGO_MASTER_KEY <isi keys\master.key.hex>
```
Ini sudah cukup untuk menjalankan kode ter-encode di Windows.

### 6.2 Jalur ekstensi C — DLL prebuilt

Jika Anda punya `php_dygo_loader.dll` yang cocok (versi PHP, TS/NTS, x64/x86),
letakkan di:
```
loader\prebuilt\php8.3-nts-x64\php_dygo_loader.dll
```
(sesuaikan `8.3`, `nts`/`ts`, `x64`/`x86` dengan `php -i`), lalu:
```powershell
powershell -ExecutionPolicy Bypass -File install.ps1
```
Installer menyalin DLL ke `ext\` dan menambahkan `extension=php_dygo_loader`
ke `php.ini`.

### 6.3 Jalur ekstensi C — build DLL dari source

Ringkas (detail resmi: wiki "Building PHP" & php-sdk-binary-tools):

1. Pasang **Visual Studio** (dengan C++ build tools) sesuai versi PHP
   (PHP 8.1/8.2 → VS2019; 8.3/8.4 → VS2022).
2. Unduh **php-sdk-binary-tools** dan **php-dev pack** untuk versi PHP persis
   Anda (`php-devel-pack-8.x.x-...`).
3. Buka *Developer Command Prompt*, masuk folder dev pack, jalankan
   `phpize.bat`. Salin isi `loader\` ke folder `ext\dygo_loader` di sumber.
4. Set master key saat build:
   ```bat
   set CFLAGS=/DDYGO_MASTER_KEY_HEX=\"ISI_KEY_HEX\"
   configure --enable-dygo-loader
   nmake
   ```
5. Hasil `php_dygo_loader.dll` → taruh di `loader\prebuilt\php<ver>-<ts|nts>-<arch>\`
   dan jalankan `install.ps1`.

> Pastikan ABI cocok: DLL untuk PHP 8.3 NTS x64 TIDAK akan dimuat oleh PHP 8.2
> atau build TS. Cek: `php -i | findstr "Thread Safety Architecture Version"`.

### 6.4 Build otomatis lintas-versi via GitHub Actions

Agar tidak perlu compile manual di tiap server, repo ini menyertakan workflow
`.github/workflows/build-loader.yml` yang membangun loader untuk
**Linux, macOS, Windows × PHP 8.1–8.5 (NTS, plus TS untuk Windows)**.

Cara pakai:

1. Push repo ini ke GitHub (repo **privat** — biner akan memuat master key).
2. Buat **Secret** repo: *Settings → Secrets and variables → Actions → New
   repository secret*, nama `DYGO_MASTER_KEY`, isi = isi `keys/master.key.hex`.
3. Jalankan workflow: tab **Actions → Build DYGO Loader → Run workflow**
   (atau push tag `v1.0.0` untuk sekaligus membuat **Release** berisi semua biner).
4. Unduh artifact/Release, lalu di server target:
   - Linux/macOS: taruh `dygo_loader.so` di `extension_dir`, tambah
     `extension=dygo_loader.so` ke `php.ini`.
   - Windows: taruh di `loader\prebuilt\php<ver>-<ts>-<arch>\` lalu jalankan
     `install.ps1`, atau salin DLL ke `ext\` dan tambah `extension=php_dygo_loader`.

> ⚠️ Biner hasil CI **menanam master key Anda**. Perlakukan sebagai rahasia —
> jangan publikasikan. Tanpa secret, workflow tetap jalan untuk sekadar menguji
> kompilasi (memakai key acak sekali-pakai).

---

## 7. Encode

Perintah utama: `php bin/dygo encode <input> -o <output> [opsi]`.

### Per file
```bash
php bin/dygo encode app/Secret.php -o dist/Secret.php
```

### Satu folder penuh (rekursif)
```bash
php bin/dygo encode src/ -o dist/
```
File `.php` di-encode; file lain (gambar, .json, .env, dll) disalin apa adanya.
`dygo-runtime.php` + `dygo-codec.php` otomatis ikut disertakan agar bundle bisa
jalan via fallback.

### Dengan lisensi (opsional, bisa digabung)
```bash
php bin/dygo encode src/ -o dist/ \
    --domain example.com --domain "*.example.com" \
    --expire 2027-12-31 \
    --node <node-hash-server-klien> \
    --rename-vars
```

- `--domain` : hanya jalan di domain tsb (dukung wildcard subdomain).
- `--expire` : berhenti jalan setelah tanggal tsb.
- `--node`   : hanya jalan di server dengan machine-id tsb. Ambil hash-nya dengan
  menjalankan `php bin/dygo machineid` **di server klien**.
- `--rename-vars` : obfuscate nama variabel lokal (hati-hati bila kode memakai
  `compact()`, `extract()`, atau `$$var`).

---

## 8. Deploy

### 8.1 Dengan ekstensi C (disarankan)

1. Install loader di server klien (lihat bagian OS masing-masing) dengan
   **master key yang sama**.
2. Upload **hanya file hasil encode** (folder `dist/`). File pendukung PHP
   (`dygo-runtime.php`, `dygo-codec.php`) tidak wajib bila ekstensi aktif, tapi
   aman untuk tetap disertakan sebagai cadangan.
3. Tidak perlu menaruh master key di server klien — key sudah tertanam di `.so`.

### 8.2 Tanpa ekstensi (fallback PHP murni)

1. Upload folder `dist/` **beserta** `dygo-runtime.php` + `dygo-codec.php`
   (otomatis ada bila Anda `encode` satu folder).
2. Beri master key ke runtime, salah satu:
   - Env var (paling aman, taruh di config web server / `.htaccess`/pool fpm):
     ```
     DYGO_MASTER_KEY=<isi keys/master.key.hex>
     ```
   - Atau file `dygo.key` berisi key hex di folder bundle (lindungi dari akses web!).

### 8.3 Node-lock lintas server (opsional)

Agar kode hanya jalan di server tertentu:
```bash
# di server klien:
php bin/dygo machineid          # catat "Node hash"
# di mesin Anda saat encode:
php bin/dygo encode src/ -o dist/ --node <node-hash-itu>
```

---

## 9. Verifikasi

```bash
php bin/dygo doctor
```
Menampilkan: versi PHP, ekstensi wajib, keberadaan master key, apakah loader C
aktif, ketersediaan `phpize`, dan deteksi machine-id.

Uji jalan:
```bash
php -r 'require "dist/NamaFile.php"; /* panggil kelas/fungsi Anda */'
```

---

## 10. Troubleshooting

**`doesn't appear to be a valid Zend extension`**
→ Muat sebagai modul biasa, bukan zend_extension:
`extension=dygo_loader.so` (bukan `zend_extension=...`).

**`Unable to load dynamic library ... (mach-o, wrong architecture)` / `wrong ABI`**
→ `.so`/`.dll` dibangun untuk versi/ABI PHP berbeda. Rebuild dengan PHP target,
pastikan TS/NTS & arsitektur cocok.

**Loader aktif di CLI tapi tidak di web**
→ `php.ini` web (FPM/Apache) berbeda dari CLI. Tambahkan baris `extension=` di
ini web lalu restart layanan.

**`verifikasi integritas gagal`**
→ File hasil encode termodifikasi (walau 1 byte), atau master key loader ≠ key
encoder. Pastikan key sama dan file tidak diedit.

**`domain tidak diizinkan` / `kedaluwarsa` / `machine-id tidak diizinkan`**
→ Lisensi ditegakkan. Encode ulang dengan domain/expire/node yang sesuai.

**`DYGO master key tidak terkonfigurasi` (fallback)**
→ Set env `DYGO_MASTER_KEY` atau taruh file `dygo.key`.

**`Permission denied` saat menyalin ke extension_dir**
→ Jalankan installer dengan hak cukup; installer akan otomatis mencoba `sudo`.

**Obfuscation `--rename-vars` membuat kode error**
→ Kode memakai `compact()/extract()/$$var`. Encode tanpa `--rename-vars`.

---

## 11. Uninstall

Linux/macOS:
```bash
sh uninstall.sh            # atau manual:
# 1) hapus baris 'extension=dygo_loader.so' dari php.ini
# 2) hapus <extension_dir>/dygo_loader.so
# 3) restart php-fpm/apache
```

Windows: hapus baris `extension=php_dygo_loader` dari `php.ini`, hapus
`ext\php_dygo_loader.dll`, restart layanan web.
