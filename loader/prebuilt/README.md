# Prebuilt binaries

Letakkan biner loader hasil build di sini dengan layout:

```
prebuilt/
  php8.3-nts-x64/dygo_loader.so          # Linux/macOS
  php8.3-nts-x64/php_dygo_loader.dll     # Windows
  php8.3-ts-x64/php_dygo_loader.dll
  ...
```

Nama folder: `php<major.minor>-<nts|ts>-<arch>` (arch: `x64`, `x86`, atau `arm64`).
`install.ps1` di Windows mencari DLL sesuai folder ini secara otomatis.

Biner dibuat otomatis oleh GitHub Actions (`.github/workflows/build-loader.yml`):
unduh dari tab **Actions → artifact**, atau dari **Release** (bila dipicu tag).

> ⚠️ Biner menanam master key → RAHASIA. File `.so`/`.dll` di folder ini
> di-ignore git (lihat .gitignore) agar tidak ikut ter-commit.
