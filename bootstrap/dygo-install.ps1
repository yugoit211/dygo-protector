<#
=============================================================================
 DYGO — Bootstrap installer (Windows / PowerShell)

 Pemakaian (one-liner):
   # repo privat (pakai gh yang sudah login):
   gh api repos/yugoit211/dygo-protector/contents/bootstrap/dygo-install.ps1 `
     -H "Accept: application/vnd.github.raw" | iex

   # repo publik:
   irm https://raw.githubusercontent.com/yugoit211/dygo-protector/master/bootstrap/dygo-install.ps1 | iex

 Opsi lewat variabel lingkungan (karena di-pipe ke iex):
   $env:DYGO_WITH_LOADER   = "1"   # build + pasang DLL loader (perlu toolchain)
   $env:DYGO_FALLBACK_ONLY = "1"   # siapkan loader PHP murni (tanpa compile)
   $env:DYGO_REPO  = "yugoit211/dygo-protector"
   $env:DYGO_REF   = "master"
   $env:DYGO_HOME  = "$env:USERPROFILE\.dygo"
   $env:DYGO_TOKEN = "<github token>"   # bila tanpa gh dan repo privat
=============================================================================
#>
param(
    [switch]$WithLoader,
    [switch]$FallbackOnly,
    [string]$Ref
)

$ErrorActionPreference = "Stop"

function Info($m) { Write-Host "==> $m" -ForegroundColor Cyan }
function OK($m)   { Write-Host "OK  $m" -ForegroundColor Green }
function Warn($m) { Write-Host " .  $m" -ForegroundColor Yellow }
function Die($m)  { Write-Host "ERROR: $m" -ForegroundColor Red; exit 1 }

$Repo = if ($env:DYGO_REPO) { $env:DYGO_REPO } else { "yugoit211/dygo-protector" }
if (-not $Ref) { $Ref = if ($env:DYGO_REF) { $env:DYGO_REF } else { "master" } }
$Dest = if ($env:DYGO_HOME) { $env:DYGO_HOME } else { "$env:USERPROFILE\.dygo" }
$BinDir = "$Dest\bin-cmd"
if ($env:DYGO_WITH_LOADER -eq "1")   { $WithLoader = $true }
if ($env:DYGO_FALLBACK_ONLY -eq "1") { $FallbackOnly = $true }

# --- prasyarat ---
try { $null = & php -v } catch { Die "PHP tidak ditemukan. Pasang PHP 8.1+ dan masukkan ke PATH." }

# --- token (opsional) ---
$token = $env:DYGO_TOKEN
if (-not $token) { try { $token = (& gh auth token) 2>$null } catch {} }

$headers = @{ "User-Agent" = "dygo-install"; "Accept" = "application/vnd.github+json" }
if ($token) { $headers["Authorization"] = "Bearer $token" }

Info "Mengunduh DYGO ($Repo@$Ref) ..."
$tmp = Join-Path $env:TEMP ("dygo-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
$zip = Join-Path $tmp "dygo.zip"
try {
    Invoke-WebRequest -Uri "https://api.github.com/repos/$Repo/zipball/$Ref" -Headers $headers -OutFile $zip
} catch {
    Die "Gagal mengunduh. Repo privat? Login 'gh' dulu, atau set `$env:DYGO_TOKEN. ($_)"
}
if (-not (Test-Path $zip)) { Die "Unduhan gagal." }

Info "Memasang ke $Dest ..."
if (Test-Path $Dest) { Remove-Item -Recurse -Force $Dest }
New-Item -ItemType Directory -Force -Path $Dest | Out-Null
$extract = Join-Path $tmp "x"
Expand-Archive -Path $zip -DestinationPath $extract -Force
# zipball membungkus dalam satu folder owner-repo-<sha>
$inner = Get-ChildItem -Directory $extract | Select-Object -First 1
Copy-Item -Recurse -Force (Join-Path $inner.FullName "*") $Dest
if (-not (Test-Path "$Dest\bin\dygo")) { Die "Struktur paket tidak sesuai (bin/dygo tidak ada)." }

# --- perintah `dygo` (shim .cmd) ---
New-Item -ItemType Directory -Force -Path $BinDir | Out-Null
$shim = @"
@echo off
php "$Dest\bin\dygo" %*
"@
Set-Content -Path "$BinDir\dygo.cmd" -Value $shim -Encoding ASCII
OK "Perintah terpasang: $BinDir\dygo.cmd"

# --- tambahkan ke PATH user ---
$userPath = [Environment]::GetEnvironmentVariable("Path", "User")
if (-not ($userPath -split ";" | Where-Object { $_ -eq $BinDir })) {
    [Environment]::SetEnvironmentVariable("Path", "$userPath;$BinDir", "User")
    $env:Path = "$env:Path;$BinDir"
    OK "$BinDir ditambahkan ke PATH user (buka terminal baru agar permanen)."
} else {
    OK "$BinDir sudah ada di PATH."
}

# --- loader (opsional) ---
if ($FallbackOnly) {
    powershell -ExecutionPolicy Bypass -File "$Dest\install.ps1" -FallbackOnly
} elseif ($WithLoader) {
    powershell -ExecutionPolicy Bypass -File "$Dest\install.ps1"
}

Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue

OK "DYGO terpasang."
Write-Host ""
Write-Host "Langkah berikut (buka terminal baru dulu agar 'dygo' dikenali):" -ForegroundColor Cyan
Write-Host "  dygo keygen"
Write-Host "  dygo encode src\ -o dist\"
Write-Host "  dygo doctor"
if (-not $WithLoader -and -not $FallbackOnly) {
    Write-Host "  (loader C Windows: lihat docs/INSTALL.md, atau set `$env:DYGO_FALLBACK_ONLY=1 lalu ulangi)"
}
