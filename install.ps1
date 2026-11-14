<#
=============================================================================
 DYGO Loader — Installer untuk Windows (PowerShell)

   powershell -ExecutionPolicy Bypass -File install.ps1
   powershell -ExecutionPolicy Bypass -File install.ps1 -PhpPath "C:\php\php.exe"
   powershell -ExecutionPolicy Bypass -File install.ps1 -FallbackOnly

 Membangun ekstensi dari source di Windows butuh php-sdk + Visual Studio
 (lihat docs/INSTALL.md). Cara termudah di Windows: pakai DLL prebuilt yang
 cocok, ATAU loader PHP murni (-FallbackOnly, tanpa kompilasi sama sekali).
=============================================================================
#>
param(
    [string]$PhpPath = "php",
    [switch]$FallbackOnly
)

$ErrorActionPreference = "Stop"
$DygoHome = Split-Path -Parent $MyInvocation.MyCommand.Path

function Say  ($m) { Write-Host "==> $m" -ForegroundColor Cyan }
function OK   ($m) { Write-Host "OK  $m" -ForegroundColor Green }
function Warn ($m) { Write-Host " .  $m" -ForegroundColor Yellow }
function Die  ($m) { Write-Host "ERROR: $m" -ForegroundColor Red; exit 1 }

# --- PHP ---
try { $phpVer = & $PhpPath -r "echo PHP_VERSION;" } catch { Die "PHP tidak ditemukan: $PhpPath" }
Say "PHP terdeteksi: $phpVer ($PhpPath)"

# --- master key ---
$keyFile = Join-Path $DygoHome "keys\master.key.hex"
if (-not (Test-Path $keyFile)) {
    Say "Membuat master key baru..."
    New-Item -ItemType Directory -Force -Path (Split-Path $keyFile) | Out-Null
    $key = & $PhpPath -r "echo bin2hex(random_bytes(32));"
    Set-Content -Path $keyFile -Value $key -NoNewline
    OK "Master key: $keyFile"
} else {
    $key = (Get-Content $keyFile -Raw).Trim()
}
if ($key.Length -ne 64) { Die "Master key tidak valid (harus 64 hex char)." }

# --- info lingkungan PHP ---
$extDir   = & $PhpPath -r "echo ini_get('extension_dir');"
$iniFile  = & $PhpPath -r "echo php_ini_loaded_file() ?: '';"
$zts      = & $PhpPath -r "echo ZEND_THREAD_SAFE ? 'ts' : 'nts';"
$arch     = & $PhpPath -r "echo PHP_INT_SIZE === 8 ? 'x64' : 'x86';"
$verShort = & $PhpPath -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;"
Say "extension_dir : $extDir"
Say "php.ini       : $(if ($iniFile) { $iniFile } else { '(tidak ada)' })"
Say "ABI           : PHP $verShort / $zts / $arch"

function Set-FallbackInfo {
    Say "Mode fallback (loader PHP murni, tanpa kompilasi)."
    OK  "Saat encode folder, dygo-runtime.php & dygo-codec.php otomatis disertakan."
    Write-Host ""
    Write-Host "Di server tujuan, set master key (salah satu):" -ForegroundColor Cyan
    Write-Host "  setx DYGO_MASTER_KEY $key" -ForegroundColor Gray
    Write-Host "  (atau taruh file 'dygo.key' berisi key di folder bundle)" -ForegroundColor Gray
}

if ($FallbackOnly) { Set-FallbackInfo; exit 0 }

# --- cari DLL prebuilt yang cocok ---
$dllName = "php_dygo_loader.dll"
$prebuilt = Join-Path $DygoHome ("loader\prebuilt\php{0}-{1}-{2}\{3}" -f $verShort, $zts, $arch, $dllName)
Say "Mencari DLL prebuilt: $prebuilt"

if (Test-Path $prebuilt) {
    Copy-Item $prebuilt (Join-Path $extDir $dllName) -Force
    OK "DLL disalin ke $extDir\$dllName"

    if ($iniFile -and -not (Select-String -Path $iniFile -Pattern "dygo_loader" -Quiet)) {
        Add-Content -Path $iniFile -Value "`r`n; DYGO Loader`r`nextension=php_dygo_loader"
        OK "Diaktifkan di $iniFile"
    } elseif ($iniFile) {
        OK "Sudah aktif di $iniFile"
    } else {
        Warn "php.ini tidak ada. Tambahkan manual:  extension=php_dygo_loader"
    }

    $loaded = & $PhpPath -m
    if ($loaded -match "dygo_loader") { OK "Loader AKTIF." } else { Warn "Belum terbaca; cek php.ini / restart layanan." }
    Say "Selesai. Restart Apache/IIS/php-cgi bila dipakai untuk web."
    exit 0
}

# --- tidak ada DLL ---
Warn "DLL prebuilt yang cocok TIDAK ditemukan untuk PHP $verShort/$zts/$arch."
Write-Host ""
Write-Host "Pilih salah satu:" -ForegroundColor Cyan
Write-Host ""
Write-Host "  [A] Termudah — pakai loader PHP murni (tanpa compile):" -ForegroundColor White
Write-Host "      powershell -ExecutionPolicy Bypass -File install.ps1 -FallbackOnly"
Write-Host ""
Write-Host "  [B] Build DLL dari source (php-sdk + Visual Studio):" -ForegroundColor White
Write-Host "      Lihat langkah detail di docs/INSTALL.md bagian Windows."
Write-Host "      Setelah jadi, letakkan hasilnya di:"
Write-Host "        loader\prebuilt\php$verShort-$zts-$arch\$dllName"
Write-Host "      lalu jalankan installer ini lagi."
Write-Host ""
Set-FallbackInfo
