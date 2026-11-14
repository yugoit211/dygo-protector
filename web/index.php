<?php
/**
 * DYGO Encoder — Web UI
 *
 * Jalankan lokal:  php -S 127.0.0.1:8080 -t web
 * Buka:            http://127.0.0.1:8080
 *
 * PENTING: UI ini memegang master key. Jangan ekspos ke internet publik tanpa
 * autentikasi. Idealnya hanya untuk jaringan internal / localhost.
 */

require_once __DIR__ . '/../encoder/src/Encoder.php';

use DYGO\Codec;
use DYGO\Encoder;

$keyFile = getenv('DYGO_KEY_FILE') ?: (__DIR__ . '/../keys/master.key.hex');
$error = null;

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $masterKey = Codec::loadMasterKey($keyFile);

        $domains = array_values(array_filter(array_map('trim',
            preg_split('/[\s,]+/', (string) ($_POST['domains'] ?? '')))));

        $expire = 0;
        if (!empty($_POST['expire'])) {
            $ts = strtotime((string) $_POST['expire']);
            if ($ts === false) { throw new RuntimeException('Format tanggal expiry tidak valid.'); }
            $expire = $ts;
        }

        if (empty($_FILES['files']['name'][0])) {
            throw new RuntimeException('Tidak ada file diunggah.');
        }

        $loaderOnly = !empty($_POST['loader_only']);

        $exclude = array_values(array_filter(array_map('trim',
            preg_split('/[\s,]+/', (string) ($_POST['exclude'] ?? '')))));

        $encoder = new Encoder($masterKey, [
            'exp'        => $expire,
            'domains'    => $domains,
            'renameVars' => !empty($_POST['rename_vars']),
            'loaderOnly' => $loaderOnly,
        ]);

        // Encode semua file dulu ke memori (yang cocok --exclude disalin apa adanya).
        $encodedFiles = []; // nama => isi
        $excludedCount = 0;
        foreach ($_FILES['files']['name'] as $i => $name) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) { continue; }
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php') {
                throw new RuntimeException("Hanya file .php yang diterima: " . h($name));
            }
            $base = basename($name);
            $src  = file_get_contents($_FILES['files']['tmp_name'][$i]);
            $skip = false;
            foreach ($exclude as $pat) { if (fnmatch($pat, $base)) { $skip = true; break; } }
            if ($skip) {
                $encodedFiles[$base] = $src;       // disalin apa adanya (tak di-encode)
                $excludedCount++;
            } else {
                $encodedFiles[$base] = $encoder->encodeSource($src, $base);
            }
        }

        if (!$encodedFiles) { throw new RuntimeException('Tidak ada file .php valid.'); }

        // Mode loader-only + 1 file → kirim SATU file .php langsung (bukan ZIP).
        if ($loaderOnly && count($encodedFiles) === 1) {
            $name = array_key_first($encodedFiles);
            $data = $encodedFiles[$name];
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($data));
            echo $data;
            exit;
        }

        // Selain itu → ZIP.
        $tmpZip = tempnam(sys_get_temp_dir(), 'dygo') . '.zip';
        $zip = new ZipArchive();
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($encodedFiles as $name => $content) {
            $zip->addFromString($name, $content);
        }

        if ($loaderOnly) {
            // Tanpa bundel fallback. Cukup file ter-encode saja.
            $zip->addFromString('README.txt',
                "Bundle DYGO (mode loader-only)\n" .
                "- Hanya file .php ter-encode. TIDAK ada runtime fallback.\n" .
                "- Butuh ekstensi DYGO Loader (dygo_loader) terpasang di server.\n" .
                "- Pasang loader: https://github.com/yugoit211/dygo-loader\n");
        } else {
            // Sertakan runtime fallback + codec agar bisa jalan tanpa ekstensi C.
            $zip->addFile(__DIR__ . '/../runtime/dygo-runtime.php', 'dygo-runtime.php');
            $zip->addFile(__DIR__ . '/../shared/dygo-codec.php', 'dygo-codec.php');
            $zip->addFromString('README.txt',
                "Bundle DYGO\n" .
                "- File .php di sini sudah ter-encode.\n" .
                "- Agar jalan TANPA ekstensi C: set env DYGO_MASTER_KEY (hex 64 char),\n" .
                "  atau taruh file 'dygo.key' berisi master key hex di folder ini.\n" .
                "- Dengan ekstensi loader C terpasang, file jalan otomatis.\n");
        }
        $zip->close();

        $data = file_get_contents($tmpZip);
        unlink($tmpZip);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="dygo-encoded.zip"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$keyExists = is_file($keyFile) && preg_match('/^[0-9a-f]{64}$/i', trim((string) @file_get_contents($keyFile)));
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DYGO Encoder</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>🔒 DYGO Encoder</h1>
    <p class="sub">Proteksi source PHP — AES-256 + HMAC, gaya ionCube.</p>

    <?php if (!$keyExists): ?>
        <div class="alert err">
            Master key belum ada / tidak valid di <code><?= h($keyFile) ?></code>.<br>
            Buat dengan: <code>php -r "echo bin2hex(random_bytes(32));" &gt; keys/master.key.hex</code>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert err"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label class="field">
            <span>File PHP (boleh banyak)</span>
            <input type="file" name="files[]" accept=".php" multiple required>
        </label>

        <label class="field">
            <span>Kunci domain <small>(opsional, pisah koma/spasi — dukung <code>*.domain.com</code>)</small></span>
            <input type="text" name="domains" placeholder="example.com, *.example.com">
        </label>

        <label class="field">
            <span>Kedaluwarsa <small>(opsional)</small></span>
            <input type="date" name="expire">
        </label>

        <label class="field">
            <span>Kecualikan <small>(opsional, pola pisah koma — file .php ini TIDAK di-encode, mis. <code>config.php</code>, <code>*.conf.php</code>)</small></span>
            <input type="text" name="exclude" placeholder="config.php, *.config.php">
        </label>

        <label class="check">
            <input type="checkbox" name="rename_vars" value="1">
            <span>Rename variabel lokal <small>(hati-hati: compact/extract/variable-variable)</small></span>
        </label>

        <label class="check">
            <input type="checkbox" name="loader_only" value="1" checked>
            <span>Hanya file loader <small>(1 file per PHP, tanpa fallback — butuh ekstensi loader C. Upload 1 file = unduh 1 file .php langsung)</small></span>
        </label>

        <button type="submit" <?= $keyExists ? '' : 'disabled' ?>>Encode &amp; Download</button>
    </form>

    <details class="help">
        <summary>Cara pakai hasil</summary>
        <ol>
            <li>Ekstrak ZIP di server tujuan.</li>
            <li><b>Dengan ekstensi loader C</b>: file <code>.php</code> jalan otomatis.</li>
            <li><b>Tanpa ekstensi</b>: set <code>DYGO_MASTER_KEY</code> (env) atau taruh <code>dygo.key</code> di folder bundle — <code>dygo-runtime.php</code> akan menangani dekripsi.</li>
        </ol>
    </details>
</main>
</body>
</html>
