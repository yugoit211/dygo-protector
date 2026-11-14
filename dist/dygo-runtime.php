<?php
/**
 * DYGO Runtime — loader PHP murni (fallback saat ekstensi C tidak terpasang).
 *
 * Fungsi global dygo_bootstrap() dipanggil oleh stub di setiap file ter-encode.
 * Mendekripsi payload (tanpa menulis plaintext ke disk), memverifikasi integritas
 * + lisensi, lalu menjalankan source di scope global via stream wrapper.
 *
 * File ini + dygo-codec.php harus disertakan dalam bundle yang didistribusikan.
 */

namespace DYGO {

    require_once __DIR__ . '/dygo-codec.php';

    final class StreamWrapper
    {
        /** @var array<string,string> key => source PHP */
        private static array $store = [];
        private int $pos = 0;
        private string $data = '';
        /** @var resource|null */
        public $context;

        public static function put(string $source): string
        {
            $key = bin2hex(random_bytes(8));
            self::$store[$key] = $source;
            return $key;
        }

        public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
        {
            $key = (string) (parse_url($path, PHP_URL_HOST) ?: '');
            if ($key === '' || !isset(self::$store[$key])) {
                return false;
            }
            $this->data = self::$store[$key];
            unset(self::$store[$key]); // sekali pakai
            $this->pos = 0;
            return true;
        }

        public function stream_read(int $count): string
        {
            $chunk = substr($this->data, $this->pos, $count);
            $this->pos += strlen($chunk);
            return $chunk;
        }

        public function stream_write(string $data): int { return 0; }

        public function stream_eof(): bool { return $this->pos >= strlen($this->data); }

        public function stream_tell(): int { return $this->pos; }

        public function stream_seek(int $offset, int $whence = SEEK_SET): bool
        {
            switch ($whence) {
                case SEEK_SET: $this->pos = $offset; break;
                case SEEK_CUR: $this->pos += $offset; break;
                case SEEK_END: $this->pos = strlen($this->data) + $offset; break;
                default: return false;
            }
            return true;
        }

        public function stream_stat(): array { return ['size' => strlen($this->data)]; }

        public function url_stat(string $path, int $flags) { return ['size' => 0]; }

        public function stream_set_option(int $option, int $arg1, int $arg2): bool { return false; }
    }

    /**
     * Master key untuk loader PHP murni. Prioritas:
     *   env DYGO_MASTER_KEY (hex) -> file dygo.key di samping runtime.
     */
    function dygo_master_key(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $hex = getenv('DYGO_MASTER_KEY');
        if (!$hex) {
            $f = __DIR__ . '/dygo.key';
            if (is_file($f)) {
                $hex = trim((string) file_get_contents($f));
            }
        }
        if (!$hex || !preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException('DYGO master key tidak terkonfigurasi (set env DYGO_MASTER_KEY atau file dygo.key).');
        }
        return $cached = hex2bin($hex);
    }
}

namespace {

    /**
     * Dipanggil oleh stub di setiap file ter-encode.
     *
     * @param string $file   __FILE__ dari file ter-encode
     * @param int    $offset __COMPILER_HALT_OFFSET__ (byte setelah __halt_compiler();)
     * @return mixed nilai return dari source asli
     */
    function dygo_bootstrap(string $file, int $offset)
    {
        static $wrapperRegistered = false;
        if (!$wrapperRegistered) {
            if (!in_array('dygo', stream_get_wrappers(), true)) {
                stream_wrapper_register('dygo', \DYGO\StreamWrapper::class);
            }
            $wrapperRegistered = true;
        }

        $raw = file_get_contents($file, false, null, $offset);
        if ($raw === false) {
            throw new \RuntimeException("DYGO: gagal membaca payload dari {$file}");
        }

        $data = \DYGO\Codec::decode($raw, \DYGO\dygo_master_key());
        \DYGO\Codec::enforceLicense($data['meta']);

        $key = \DYGO\StreamWrapper::put($data['source']);
        return include 'dygo://' . $key;
    }
}
