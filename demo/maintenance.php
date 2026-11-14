<?php
/**
 * Server Maintenance — Landing Page
 * Satu file PHP mandiri. Menyajikan halaman "sedang pemeliharaan" yang profesional
 * dengan status HTTP 503 + Retry-After (praktik yang benar untuk SEO & crawler),
 * hitung mundur dinamis, dan progress pemeliharaan.
 *
 * Cara pakai di server: arahkan rewrite semua request ke file ini selama maintenance,
 * atau sertakan (include) di awal front controller saat MAINTENANCE_MODE aktif.
 */

declare(strict_types=1);

/* ----------------------------------------------------------------------------
 * KONFIGURASI  (ubah sesuai kebutuhan)
 * ------------------------------------------------------------------------- */
$config = [
    'brand'        => 'DYGO Cloud',
    'title'        => 'Sedang Pemeliharaan',
    'message'      => 'Kami sedang meningkatkan sistem agar lebih cepat dan andal. '
                    . 'Layanan akan segera kembali normal. Terima kasih atas kesabaran Anda.',
    'started_at'   => '2026-11-14 22:00:00', // mulai maintenance
    'back_at'      => '2026-11-14 23:30:00', // perkiraan selesai
    'contact'      => 'support@example.com',
    'status_url'   => 'https://status.example.com',
    'progress'     => 65,                     // persen (0-100), tampil di progress bar
    'timezone'     => 'Asia/Jakarta',
];

date_default_timezone_set($config['timezone']);

$now      = new DateTimeImmutable('now');
$backAt   = new DateTimeImmutable($config['back_at']);
$remaining = max(0, $backAt->getTimestamp() - $now->getTimestamp());
$isBack    = $remaining === 0;

/* Header maintenance yang benar (diabaikan otomatis saat dijalankan via CLI). */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Status: 503 Service Unavailable');
    header('Retry-After: ' . ($isBack ? 60 : $remaining));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

/** Helper escape. */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$etaText = $isBack
    ? 'Layanan seharusnya sudah kembali — silakan muat ulang.'
    : 'Perkiraan kembali normal pukul ' . $backAt->format('H:i') . ' WIB';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($config['title']) ?> — <?= e($config['brand']) ?></title>
<style>
  :root{
    --bg:#0b1020; --card:#141b31; --fg:#e8edf7; --muted:#9aa7c2;
    --accent:#5b8cff; --accent2:#49d6a8; --line:#273352; --warn:#ffb020;
    --radius:20px;
  }
  *{box-sizing:border-box}
  html,body{margin:0;height:100%}
  body{
    font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    color:var(--fg);
    background:
      radial-gradient(1200px 600px at 15% -10%, rgba(91,140,255,.25), transparent 60%),
      radial-gradient(1000px 500px at 110% 10%, rgba(73,214,168,.18), transparent 55%),
      var(--bg);
    display:flex;align-items:center;justify-content:center;padding:28px;
  }
  .card{
    width:100%;max-width:640px;background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,0));
    border:1px solid var(--line);border-radius:var(--radius);
    padding:40px 36px;box-shadow:0 30px 80px rgba(0,0,0,.45);backdrop-filter:blur(8px);
  }
  .logo{display:flex;align-items:center;gap:12px;margin-bottom:26px}
  .logo .dot{width:38px;height:38px;border-radius:11px;
    background:linear-gradient(135deg,var(--accent),var(--accent2));
    display:grid;place-items:center;box-shadow:0 8px 24px rgba(91,140,255,.4)}
  .logo .dot svg{width:22px;height:22px}
  .logo b{font-size:1.05rem;letter-spacing:.2px}
  .badge{display:inline-flex;align-items:center;gap:8px;font-size:.8rem;font-weight:600;
    color:var(--warn);background:rgba(255,176,32,.12);border:1px solid rgba(255,176,32,.35);
    padding:6px 12px;border-radius:999px;margin-bottom:20px}
  .badge .pulse{width:8px;height:8px;border-radius:50%;background:var(--warn);
    box-shadow:0 0 0 0 rgba(255,176,32,.7);animation:pulse 1.8s infinite}
  @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(255,176,32,.6)}70%{box-shadow:0 0 0 10px rgba(255,176,32,0)}100%{box-shadow:0 0 0 0 rgba(255,176,32,0)}}
  h1{margin:0 0 12px;font-size:2rem;line-height:1.2}
  p.lead{color:var(--muted);margin:0 0 28px}
  .count{display:flex;gap:12px;margin:0 0 26px}
  .count .u{flex:1;text-align:center;background:rgba(255,255,255,.03);
    border:1px solid var(--line);border-radius:14px;padding:16px 8px}
  .count .u .n{font-size:2rem;font-weight:800;font-variant-numeric:tabular-nums}
  .count .u .l{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
  .bar{height:10px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden;margin:6px 0 8px}
  .bar > i{display:block;height:100%;border-radius:999px;
    background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .6s}
  .meta{display:flex;justify-content:space-between;font-size:.85rem;color:var(--muted);margin-bottom:28px}
  .actions{display:flex;flex-wrap:wrap;gap:12px}
  .btn{flex:1;min-width:150px;text-align:center;text-decoration:none;font-weight:600;
    padding:13px 16px;border-radius:12px;border:1px solid var(--line);color:var(--fg)}
  .btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent2));
    color:#06111f;border:0}
  .foot{margin-top:26px;padding-top:18px;border-top:1px solid var(--line);
    font-size:.82rem;color:var(--muted);display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}
  a{color:#bcd0ff}
  @media(max-width:520px){h1{font-size:1.6rem}.card{padding:30px 22px}}
</style>
</head>
<body>
  <main class="card" role="main">
    <div class="logo">
      <span class="dot">
        <svg viewBox="0 0 24 24" fill="none" stroke="#06111f" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 7h18M3 12h18M3 17h18"/>
        </svg>
      </span>
      <b><?= e($config['brand']) ?></b>
    </div>

    <span class="badge"><span class="pulse"></span> Pemeliharaan Terjadwal</span>

    <h1><?= e($config['title']) ?></h1>
    <p class="lead"><?= e($config['message']) ?></p>

    <div class="count" id="countdown" data-remaining="<?= (int)$remaining ?>">
      <div class="u"><div class="n" data-h>00</div><div class="l">Jam</div></div>
      <div class="u"><div class="n" data-m>00</div><div class="l">Menit</div></div>
      <div class="u"><div class="n" data-s>00</div><div class="l">Detik</div></div>
    </div>

    <div class="bar"><i style="width:<?= max(0,min(100,(int)$config['progress'])) ?>%"></i></div>
    <div class="meta">
      <span>Progres pemeliharaan</span>
      <span><?= (int)$config['progress'] ?>%</span>
    </div>

    <div class="actions">
      <a class="btn primary" href="" onclick="location.reload();return false;">Muat Ulang</a>
      <a class="btn" href="<?= e($config['status_url']) ?>" target="_blank" rel="noopener">Halaman Status</a>
      <a class="btn" href="mailto:<?= e($config['contact']) ?>">Hubungi Dukungan</a>
    </div>

    <div class="foot">
      <span><?= e($etaText) ?></span>
      <span>Waktu server: <?= e($now->format('d M Y H:i')) ?> WIB</span>
    </div>
  </main>

<script>
  (function(){
    var box = document.getElementById('countdown');
    var left = parseInt(box.getAttribute('data-remaining'),10) || 0;
    var h = box.querySelector('[data-h]'), m = box.querySelector('[data-m]'), s = box.querySelector('[data-s]');
    function pad(n){ return (n<10?'0':'')+n; }
    function tick(){
      if(left<=0){ h.textContent=m.textContent=s.textContent='00'; setTimeout(function(){location.reload();},3000); return; }
      var hh=Math.floor(left/3600), mm=Math.floor((left%3600)/60), ss=left%60;
      h.textContent=pad(hh); m.textContent=pad(mm); s.textContent=pad(ss);
      left--; setTimeout(tick,1000);
    }
    tick();
  })();
</script>
</body>
</html>
