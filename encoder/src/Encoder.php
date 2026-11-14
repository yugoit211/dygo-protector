<?php
/**
 * DYGO Encoder — mengubah satu file / folder PHP menjadi file terenkripsi.
 */

namespace DYGO;

require_once __DIR__ . '/../../shared/dygo-codec.php';
require_once __DIR__ . '/Obfuscator.php';

final class Encoder
{
    private string $masterKey;
    private Obfuscator $obf;

    /** @var array{exp:int,domains:string[],runtimeRef:string} */
    private array $opts;

    public function __construct(string $masterKeyRaw, array $opts = [])
    {
        $this->masterKey = $masterKeyRaw;
        $this->opts = [
            'exp'        => (int) ($opts['exp'] ?? 0),
            'domains'    => array_values($opts['domains'] ?? []),
            'nodes'      => array_values($opts['nodes'] ?? []),
            'renameVars' => (bool) ($opts['renameVars'] ?? false),
            // Path relatif dari file ter-encode menuju dygo-runtime.php
            'runtimeRef' => (string) ($opts['runtimeRef'] ?? 'dygo-runtime.php'),
            // Mode "loader-only": stub minimal, TANPA fallback PHP-murni.
            // Hasil = 1 file per PHP; butuh ekstensi loader C untuk jalan.
            'loaderOnly' => (bool) ($opts['loaderOnly'] ?? false),
            // Pola glob (*, ?) file .php yang TIDAK di-encode (disalin apa adanya).
            // Dicocokkan ke path relatif & nama file. Mis. "config/*.php", "*.conf.php".
            'exclude'    => array_values(array_filter((array) ($opts['exclude'] ?? []))),
        ];
        $this->obf = new Obfuscator($this->opts['renameVars']);
    }

    /** Encode isi string PHP -> string file ter-encode lengkap (dengan stub). */
    public function encodeSource(string $source, string $name = ''): string
    {
        $obfuscated = $this->obf->process($source);

        $payload = Codec::encode($obfuscated, $this->masterKey, [
            'exp'     => $this->opts['exp'],
            'domains' => $this->opts['domains'],
            'nodes'   => $this->opts['nodes'],
            'name'    => $name,
        ]);

        return $this->buildStub() . "\n" . Codec::wrap($payload);
    }

    /** Encode satu file -> tulis ke $dest. */
    public function encodeFile(string $src, string $dest): void
    {
        $source = file_get_contents($src);
        if ($source === false) {
            throw new \RuntimeException("Gagal membaca: {$src}");
        }
        $name = basename($src);
        $encoded = $this->encodeSource($source, $name);

        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        file_put_contents($dest, $encoded);
    }

    /**
     * Encode seluruh folder secara rekursif ke $destDir.
     * File .php di-encode; file lain disalin apa adanya.
     *
     * @return array{encoded:int,copied:int}
     */
    public function encodeDir(string $srcDir, string $destDir): array
    {
        $srcDir  = rtrim($srcDir, '/');
        $destDir = rtrim($destDir, '/');
        $stats = ['encoded' => 0, 'copied' => 0, 'excluded' => 0];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $rel  = str_replace('\\', '/', substr($file->getPathname(), strlen($srcDir) + 1));
            $dest = $destDir . '/' . $rel;

            $isPhp      = strtolower($file->getExtension()) === 'php';
            $isExcluded = $isPhp && $this->isExcluded($rel);

            if ($isPhp && !$isExcluded) {
                $this->encodeFile($file->getPathname(), $dest);
                $stats['encoded']++;
            } else {
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0775, true);
                }
                copy($file->getPathname(), $dest);
                $isExcluded ? $stats['excluded']++ : $stats['copied']++;
            }
        }

        // Sertakan runtime + codec agar bundle bisa jalan tanpa ekstensi C.
        // Dilewati pada mode loader-only (hasil cukup file ter-encode saja).
        if (!$this->opts['loaderOnly']) {
            $this->bundleRuntime($destDir);
        }

        return $stats;
    }

    /** Apakah path relatif ini cocok salah satu pola --exclude? (glob *,? ) */
    private function isExcluded(string $rel): bool
    {
        $base = basename($rel);
        foreach ($this->opts['exclude'] as $pat) {
            $pat = str_replace('\\', '/', (string) $pat);
            // cocokkan ke path relatif penuh ATAU ke nama file saja.
            // '*' tanpa FNM_PATHNAME ikut mencocokkan '/', jadi "vendor/*" dsb jalan.
            if (fnmatch($pat, $rel) || fnmatch($pat, $base)) {
                return true;
            }
        }
        return false;
    }

    private function bundleRuntime(string $destDir): void
    {
        $runtimeSrc = __DIR__ . '/../../runtime/dygo-runtime.php';
        $codecSrc   = __DIR__ . '/../../shared/dygo-codec.php';
        if (is_file($runtimeSrc)) {
            copy($runtimeSrc, $destDir . '/dygo-runtime.php');
        }
        if (is_file($codecSrc)) {
            copy($codecSrc, $destDir . '/dygo-codec.php');
        }
    }

    private function buildStub(): string
    {
        // Mode loader-only: stub minimal. Kalau ekstensi loader C aktif, isi stub
        // ini TIDAK pernah dijalankan (loader mengganti hasil kompilasi dengan
        // source asli). Kalau loader TIDAK ada, tampilkan pesan yang jelas.
        if ($this->opts['loaderOnly']) {
            return <<<PHP
<?php
/* DYGO Encoded File — butuh ekstensi DYGO Loader. Jangan edit. */
if (!extension_loaded('dygo_loader')) {
    http_response_code(500);
    die('File ini terenkripsi DYGO dan membutuhkan ekstensi "dygo_loader" terpasang di server ini.');
}
__halt_compiler();
PHP;
        }

        $ref = var_export($this->opts['runtimeRef'], true);
        return <<<PHP
<?php
/* DYGO Encoded File — dilindungi. Jangan edit di bawah __halt_compiler(). */
if (!function_exists('dygo_bootstrap')) {
    \$__dygo_rt = __DIR__ . '/' . {$ref};
    if (!is_file(\$__dygo_rt)) {
        \$__dygo_rt = __DIR__ . '/dygo-runtime.php';
    }
    if (!is_file(\$__dygo_rt)) {
        die('File ini membutuhkan DYGO Loader (ekstensi) atau dygo-runtime.php.');
    }
    require \$__dygo_rt;
}
return dygo_bootstrap(__FILE__, __COMPILER_HALT_OFFSET__);
__halt_compiler();
PHP;
    }
}
