<?php
/**
 * DYGO Codec — single source of truth untuk format file terenkripsi.
 *
 * Dipakai oleh encoder (CLI + Web) dan oleh loader PHP murni (runtime).
 * Lihat shared/FORMAT.md untuk spesifikasi byte-level.
 *
 * TIDAK bergantung pada apa pun selain ekstensi openssl + hash (bawaan PHP).
 */

namespace DYGO;

final class Codec
{
    public const MAGIC   = 'DYGO0001';
    public const VERSION = 1;

    /** Derivasi encKey & macKey dari master key (32 byte mentah). */
    public static function deriveKeys(string $masterKeyRaw): array
    {
        return [
            'enc' => hash('sha256', $masterKeyRaw . 'DYGO-ENC-v1', true),
            'mac' => hash('sha256', $masterKeyRaw . 'DYGO-MAC-v1', true),
        ];
    }

    /** Ambil master key mentah dari file hex. */
    public static function loadMasterKey(string $hexFile): string
    {
        if (!is_file($hexFile)) {
            throw new \RuntimeException("Master key tidak ditemukan: {$hexFile}");
        }
        $hex = trim((string) file_get_contents($hexFile));
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException('Master key harus 64 karakter hex (32 byte).');
        }
        return hex2bin($hex);
    }

    /**
     * Encode source PHP mentah -> payload biner (belum base64).
     *
     * @param array{exp?:int,domains?:string[],name?:string} $meta
     */
    public static function encode(string $source, string $masterKeyRaw, array $meta = []): string
    {
        $keys = self::deriveKeys($masterKeyRaw);

        $metaJson = json_encode([
            'v'       => self::VERSION,
            'created' => time(),
            'exp'     => (int) ($meta['exp'] ?? 0),
            'domains' => array_values($meta['domains'] ?? []),
            'nodes'   => array_values($meta['nodes'] ?? []),
            'name'    => (string) ($meta['name'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $iv         = random_bytes(16);
        $ciphertext = openssl_encrypt($source, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Enkripsi gagal: ' . openssl_error_string());
        }

        $tag = hash_hmac('sha256', $metaJson . $iv . $ciphertext, $keys['mac'], true);

        return pack('V', strlen($metaJson)) . $metaJson . $iv . $tag . $ciphertext;
    }

    /** Bungkus payload biner menjadi string lengkap file (MAGIC + base64). */
    public static function wrap(string $payload): string
    {
        return self::MAGIC . base64_encode($payload);
    }

    /**
     * Decode blob lengkap (MAGIC + base64) -> ['meta'=>array, 'source'=>string].
     * Melempar exception bila tag HMAC tidak valid (anti-tamper).
     */
    public static function decode(string $blob, string $masterKeyRaw): array
    {
        $pos = strpos($blob, self::MAGIC);
        if ($pos === false) {
            throw new \RuntimeException('Marker DYGO tidak ditemukan.');
        }
        $payload = base64_decode(substr($blob, $pos + strlen(self::MAGIC)), true);
        if ($payload === false || strlen($payload) < 4 + 16 + 32) {
            throw new \RuntimeException('Payload DYGO rusak.');
        }

        $keys = self::deriveKeys($masterKeyRaw);

        $metaLen = unpack('V', substr($payload, 0, 4))[1];
        $off     = 4;
        $metaJson = substr($payload, $off, $metaLen);           $off += $metaLen;
        $iv       = substr($payload, $off, 16);                 $off += 16;
        $tag      = substr($payload, $off, 32);                 $off += 32;
        $cipher   = substr($payload, $off);

        $calc = hash_hmac('sha256', $metaJson . $iv . $cipher, $keys['mac'], true);
        if (!hash_equals($calc, $tag)) {
            throw new \RuntimeException('Verifikasi integritas gagal (file dimodifikasi?).');
        }

        $meta = json_decode($metaJson, true);
        if (!is_array($meta)) {
            throw new \RuntimeException('Metadata DYGO rusak.');
        }

        $source = openssl_decrypt($cipher, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv);
        if ($source === false) {
            throw new \RuntimeException('Dekripsi gagal.');
        }

        return ['meta' => $meta, 'source' => $source];
    }

    /** Validasi lisensi (expiry + domain). Lempar exception bila tidak lolos. */
    public static function enforceLicense(array $meta, ?string $host = null): void
    {
        $exp = (int) ($meta['exp'] ?? 0);
        if ($exp > 0 && time() > $exp) {
            throw new \RuntimeException('Lisensi kedaluwarsa (' . date('Y-m-d', $exp) . ').');
        }

        $domains = $meta['domains'] ?? [];
        if (!empty($domains)) {
            $host = $host ?? self::currentHost();
            if (!self::hostMatches($host, $domains)) {
                throw new \RuntimeException("Domain tidak diizinkan: {$host}");
            }
        }

        $nodes = $meta['nodes'] ?? [];
        if (!empty($nodes)) {
            $id = self::machineIdHash();
            if ($id === '' || !in_array($id, $nodes, true)) {
                throw new \RuntimeException('Server (machine-id) tidak diizinkan.');
            }
        }
    }

    /** Machine-id mentah per platform (Linux/Mac/Windows). */
    public static function machineId(): string
    {
        // Linux
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
            if (is_file($f) && is_readable($f)) {
                $v = trim((string) @file_get_contents($f));
                if ($v !== '') { return $v; }
            }
        }
        // macOS
        if (PHP_OS_FAMILY === 'Darwin') {
            $out = @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null');
            if ($out && preg_match('/IOPlatformUUID"\s*=\s*"([^"]+)"/', $out, $m)) {
                return $m[1];
            }
        }
        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid 2>NUL');
            if ($out && preg_match('/MachineGuid\s+REG_SZ\s+([0-9A-Fa-f\-]+)/', $out, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /** Hash machine-id (sha256 hex) — nilai inilah yang dimasukkan ke --node. */
    public static function machineIdHash(): string
    {
        $id = self::machineId();
        return $id === '' ? '' : hash('sha256', 'DYGO-NODE:' . strtolower(trim($id)));
    }

    public static function currentHost(): string
    {
        $h = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? gethostname() ?: '';
        return strtolower(preg_replace('/:\d+$/', '', (string) $h));
    }

    public static function hostMatches(string $host, array $patterns): bool
    {
        $host = strtolower(trim($host));
        foreach ($patterns as $p) {
            $p = strtolower(trim((string) $p));
            if ($p === '' ) { continue; }
            if ($p === $host) { return true; }
            if (str_starts_with($p, '*.')) {
                $base = substr($p, 2);
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return true;
                }
            }
        }
        return false;
    }
}
