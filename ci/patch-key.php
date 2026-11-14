<?php
/**
 * CI helper — menanamkan master key ke loader/php_dygo_loader.h sebelum build.
 *
 * Ambil key dari env DYGO_MASTER_KEY (hex 64 char). Bila tidak ada, buat key
 * acak EPHEMERAL (hanya agar build terkompilasi di CI untuk uji kompilasi —
 * biner hasilnya TIDAK berguna untuk produksi).
 *
 * Jalankan dari root repo:  php ci/patch-key.php
 */

$header = __DIR__ . '/../loader/php_dygo_loader.h';
if (!is_file($header)) {
    fwrite(STDERR, "header tidak ditemukan: $header\n");
    exit(1);
}

$key = getenv('DYGO_MASTER_KEY');
$ephemeral = false;
if (!$key) {
    // Fallback: pakai keys/master.key.hex lokal bila ada (untuk build lokal).
    $localKey = __DIR__ . '/../keys/master.key.hex';
    if (is_file($localKey)) {
        $key = trim((string) file_get_contents($localKey));
    }
}
if (!$key) {
    $key = bin2hex(random_bytes(32));
    $ephemeral = true;
}
$key = trim($key);
if (!preg_match('/^[0-9a-fA-F]{64}$/', $key)) {
    fwrite(STDERR, "DYGO_MASTER_KEY harus 64 karakter hex.\n");
    exit(1);
}

$src = file_get_contents($header);
$patched = preg_replace(
    '/#define\s+DYGO_MASTER_KEY_HEX\s+"[0-9a-fA-F]*"/',
    '#define DYGO_MASTER_KEY_HEX "' . $key . '"',
    $src,
    1,
    $count
);
if ($count !== 1) {
    fwrite(STDERR, "Gagal menemukan #define DYGO_MASTER_KEY_HEX di header.\n");
    exit(1);
}
file_put_contents($header, $patched);

// Tulis juga ke keys/master.key.hex agar langkah uji di CI bisa meng-encode
// dengan key yang sama (hanya di runner CI; tidak dikomit).
@file_put_contents(__DIR__ . '/../keys/master.key.hex', $key);

fwrite(STDERR, $ephemeral
    ? "[warn] DYGO_MASTER_KEY tidak diset — memakai key EPHEMERAL (uji kompilasi saja).\n"
    : "[ok] master key dari secret ditanamkan ke loader.\n");
