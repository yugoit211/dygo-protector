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
        $stats = ['encoded' => 0, 'copied' => 0];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $rel  = substr($file->getPathname(), strlen($srcDir) + 1);
            $dest = $destDir . '/' . $rel;

            if (strtolower($file->getExtension()) === 'php') {
                $this->encodeFile($file->getPathname(), $dest);
                $stats['encoded']++;
            } else {
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0775, true);
                }
                copy($file->getPathname(), $dest);
                $stats['copied']++;
            }
        }

        // Sertakan runtime + codec agar bundle bisa jalan tanpa ekstensi C.
        $this->bundleRuntime($destDir);

        return $stats;
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
