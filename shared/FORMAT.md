# DYGO Encoded File Format (`.dygo` payload)

Versi: `DYGO0001`

Format ini dipakai bersama oleh tiga komponen agar selalu kompatibel:

- **Encoder** (PHP, CLI + Web) — menghasilkan file ter-encode.
- **Loader PHP murni** (`runtime/dygo-runtime.php`) — fallback yang langsung jalan.
- **Loader C** (`loader/`) — ekstensi Zend ala ionCube.

## Struktur file di disk

File ter-encode tetap berekstensi `.php` dan berisi stub yang terbaca PHP biasa,
lalu `__halt_compiler();`, lalu marker + payload base64:

```php
<?php
/* DYGO Encoded File - https://... */
if (!function_exists('dygo_bootstrap')) { require __DIR__.'/dygo-runtime.php'; }
return dygo_bootstrap(__FILE__, __COMPILER_HALT_OFFSET__);
__halt_compiler();
DYGO0001<base64(payload)>
```

- Jika **loader C terpasang**: `zend_compile_file` di-hook. Loader membaca byte mentah
  file, menemukan marker `DYGO0001`, mendekripsi, lalu meng-compile *source asli*.
  Seluruh stub di atas tidak pernah dieksekusi.
- Jika **loader C tidak ada**: PHP menjalankan stub. `dygo_bootstrap()` (dari loader
  PHP murni) membaca payload lewat `__COMPILER_HALT_OFFSET__`, mendekripsi, cek lisensi,
  lalu mengeksekusi source.

## Struktur payload (setelah base64-decode)

Semua integer little-endian.

| Offset        | Ukuran | Isi                                                        |
|---------------|--------|------------------------------------------------------------|
| 0             | 4      | `uint32 metaLen` = panjang JSON metadata (M)               |
| 4             | M      | Metadata JSON (UTF-8)                                       |
| 4 + M         | 16     | IV (AES-CBC)                                                |
| 20 + M        | 32     | Tag HMAC-SHA256                                             |
| 52 + M        | sisa   | Ciphertext = AES-256-CBC(PKCS7(source terobfuscate))       |

### Metadata JSON

```json
{
  "v": 1,
  "created": 1731542400,
  "exp": 0,                       // unix ts; 0 = tanpa kedaluwarsa
  "domains": ["example.com", "*.example.com"],  // [] = semua domain
  "nodes": ["<sha256 hex machine-id>"],         // [] = semua server
  "name": "app/Helper.php"
}
```

### Node-lock (machine-id)

`nodes` berisi daftar hash: `SHA256("DYGO-NODE:" + lower(machine_id))` (hex).
`machine_id` diambil per platform:
- Linux: `/etc/machine-id` (atau `/var/lib/dbus/machine-id`)
- macOS: `IOPlatformUUID` (dari `ioreg`)
- Windows: `HKLM\SOFTWARE\Microsoft\Cryptography\MachineGuid`

Bila `nodes` tidak kosong, loader menolak eksekusi di server yang hash-nya tidak
terdaftar (fail-closed bila machine-id tak terdeteksi).

## Derivasi kunci

Master key = 32 byte rahasia (disimpan sebagai hex di `keys/master.key.hex`
untuk encoder, dan di-`#define` di loader C).

```
encKey = SHA256( masterKey || "DYGO-ENC-v1" )   // 32 byte, kunci AES-256
macKey = SHA256( masterKey || "DYGO-MAC-v1" )   // 32 byte, kunci HMAC
```

## HMAC (anti-tamper)

```
tag = HMAC-SHA256( macKey, metaJson || IV || ciphertext )
```

Loader WAJIB memverifikasi tag dengan perbandingan constant-time sebelum
mendekripsi/menjalankan. Perubahan 1 byte pun pada file ter-encode => tag gagal
=> eksekusi ditolak.

## Pemeriksaan lisensi (oleh loader)

1. **Expiry**: jika `exp > 0` dan `now > exp` => tolak.
2. **Domain**: jika `domains` tidak kosong, host saat ini (`HTTP_HOST`, lalu
   `SERVER_NAME`, lalu `gethostname()`) harus cocok salah satu entri.
   Wildcard `*.example.com` cocok untuk sub-domain mana pun dari `example.com`.
