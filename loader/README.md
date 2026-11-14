# DYGO Loader (ekstensi Zend)

Loader transparan ala ionCube: meng-hook `zend_compile_file`, mendekripsi file
`.php` ter-encode di memori, memverifikasi integritas (HMAC) + lisensi
(expiry/domain), lalu menjalankannya. **Plaintext tidak pernah ditulis ke disk.**

- Target: **PHP 8.1 – 8.5** (NTS & ZTS).
- Cipher: **AES-256-CBC + HMAC-SHA256** (self-contained, tanpa OpenSSL).
- Kompatibel byte-per-byte dengan encoder PHP (`shared/dygo-codec.php`).

## 1. Samakan master key

`DYGO_MASTER_KEY_HEX` di `php_dygo_loader.h` **harus identik** dengan isi
`keys/master.key.hex` milik encoder. Ganti sebelum produksi:

```bash
php -r 'echo bin2hex(random_bytes(32));' > ../keys/master.key.hex
# lalu salin nilainya ke #define DYGO_MASTER_KEY_HEX
```

Atau suntikkan saat build tanpa mengedit header:

```bash
export CFLAGS="-DDYGO_MASTER_KEY_HEX='\"'$(cat ../keys/master.key.hex)'\"'"
```

## 2. Build (Linux / macOS)

Butuh paket dev PHP (`php-dev` / `php-devel`, menyediakan `phpize`).

```bash
cd loader
phpize
./configure --enable-dygo-loader
make
# hasil: modules/dygo_loader.so
```

Install & aktifkan:

```bash
make install            # menyalin .so ke extension_dir
# tambahkan ke php.ini:
#   zend_extension=dygo_loader      (disarankan — hook compiler lebih awal)
# atau:
#   extension=dygo_loader
php -m | grep dygo_loader
```

> Catatan: karena loader mengganti `zend_compile_file`, memuatnya sebagai
> `zend_extension` memastikan ia aktif sebelum file apa pun di-compile.

## 3. Build (Windows)

Gunakan toolchain php-sdk resmi, letakkan folder ini di `ext/dygo_loader`, lalu:

```bat
buildconf
configure --enable-dygo-loader
nmake
```

## 4. Uji

```bash
# file hello.php sudah di-encode oleh encoder
php -d zend_extension=$(pwd)/modules/dygo_loader.so -r 'require "../dist/hello.php"; echo Acme\Secret::greet("Yugo");'
```

Dengan loader aktif, stub + `dygo-runtime.php` tidak diperlukan lagi — loader
menangani dekripsi langsung di compiler.

## 5. Verifikasi kripto tanpa PHP-dev

Jika belum ada `phpize`, Anda tetap bisa membuktikan inti kripto cocok dengan
encoder:

```bash
cc -O2 -o /tmp/dygo_test test_crypto.c dygo_crypto.c
/tmp/dygo_test ../dist/hello.php "$(cat ../keys/master.key.hex)"
```

## Catatan versi

Signature `zend_compile_string` berbeda antar versi; sudah ditangani dengan
`#if PHP_VERSION_ID` di `dygo_loader.c`:

| PHP   | Pemanggilan                                                    |
|-------|----------------------------------------------------------------|
| 8.1   | `compile_string(zval*, char*)`                                 |
| 8.2   | `zend_compile_string(zend_string*, const char*)`               |
| 8.3+  | `zend_compile_string(zend_string*, const char*, position)`     |

Jika menargetkan PHP 7.x atau 8.0, sesuaikan pembacaan `file_handle->filename`
(dulu `const char*`, sejak 8.1 `zend_string*`) dan signature compile-string.
