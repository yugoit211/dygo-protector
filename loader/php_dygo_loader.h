/*
 * DYGO Loader — ekstensi Zend yang mendekripsi & menjalankan file ter-encode
 * secara transparan (gaya ionCube loader).
 *
 * Target: PHP 8.1 – 8.4 (NTS/ZTS). Lihat loader/README.md untuk build.
 */
#ifndef PHP_DYGO_LOADER_H
#define PHP_DYGO_LOADER_H

extern zend_module_entry dygo_loader_module_entry;
#define phpext_dygo_loader_ptr &dygo_loader_module_entry

#define PHP_DYGO_LOADER_VERSION "1.0.0"

#ifdef ZTS
#include "TSRM.h"
#endif

/*
 * MASTER KEY (hex 64 char / 32 byte).
 *
 * JANGAN taruh key asli di file ini (ia ter-track git). Nilai di bawah hanyalah
 * PLACEHOLDER. Key asli disuntik saat build:
 *   - install.sh / build.sh  -> lewat CFLAGS=-DDYGO_MASTER_KEY_HEX="..."
 *   - GitHub Actions          -> lewat ci/patch-key.php dari secret DYGO_MASTER_KEY
 * Guard #ifndef membuat nilai dari CFLAGS menang atas placeholder ini.
 */
#ifndef DYGO_MASTER_KEY_HEX
#define DYGO_MASTER_KEY_HEX "0000000000000000000000000000000000000000000000000000000000000000"
#endif

#endif /* PHP_DYGO_LOADER_H */
