<?php
/**
 * DYGO Obfuscator — mengurangi keterbacaan source SEBELUM dienkripsi.
 *
 * Tujuan: andai suatu saat payload berhasil didekripsi, source yang muncul
 * tetap sulit dibaca. Obfuscation berbasis token (token_get_all) agar aman
 * terhadap sintaks PHP.
 *
 * Level:
 *   - strip   : buang komentar + rapatkan whitespace (SELALU aman).
 *   - rename  : (opsional) acak nama variabel lokal. Dinonaktifkan default
 *               karna bisa memengaruhi compact()/extract()/variable-variables.
 */

namespace DYGO;

final class Obfuscator
{
    private bool $renameVars;

    public function __construct(bool $renameVars = false)
    {
        $this->renameVars = $renameVars;
    }

    public function process(string $source): string
    {
        $tokens = token_get_all($source);

        // Peta nama variabel -> nama acak (konsisten dalam satu file).
        $map = [];
        $reserved = [
            '$this', '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_SERVER',
            '$_SESSION', '$_COOKIE', '$_FILES', '$_ENV', '$php_errormsg',
            '$http_response_header', '$argc', '$argv',
        ];

        $out = '';
        $prevSignificant = null;

        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                [$id, $text] = $tok;

                // Buang semua komentar & docblock.
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $out .= ' ';
                    continue;
                }

                // Rapatkan whitespace menjadi satu spasi.
                if ($id === T_WHITESPACE) {
                    $out .= ' ';
                    continue;
                }

                // Rename variabel lokal (opsional).
                if ($this->renameVars && $id === T_VARIABLE && !in_array($text, $reserved, true)) {
                    // Jangan sentuh bila didahului '$' (variable-variable $$x) atau '->' / '::'
                    if ($prevSignificant !== '$' && $prevSignificant !== '->' && $prevSignificant !== '::') {
                        if (!isset($map[$text])) {
                            $map[$text] = '$' . $this->randName(count($map));
                        }
                        $text = $map[$text];
                    }
                }

                $out .= $text;
                $prevSignificant = $text;
            } else {
                $out .= $tok;
                $prevSignificant = $tok;
            }
        }

        return $out;
    }

    private function randName(int $i): string
    {
        // Nama deterministik-acak yang valid & sulit dibaca.
        $seed = substr(hash('sha1', 'dygo_v' . $i . microtime()), 0, 8);
        return '_' . $seed;
    }
}
