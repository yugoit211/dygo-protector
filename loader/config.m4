dnl DYGO Loader — build config (Unix/Linux/macOS)
PHP_ARG_ENABLE([dygo-loader],
  [whether to enable DYGO Loader],
  [AS_HELP_STRING([--enable-dygo-loader], [Enable DYGO Loader support])],
  [no])

if test "$PHP_DYGO_LOADER" != "no"; then
  AC_DEFINE(HAVE_DYGO_LOADER, 1, [DYGO Loader enabled])
  PHP_NEW_EXTENSION(dygo_loader, dygo_loader.c dygo_crypto.c, $ext_shared)
fi
