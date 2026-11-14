/*
 * DYGO Loader — ekstensi Zend.
 *
 * Mekanisme (seperti ionCube loader):
 *   1. Saat MINIT, kita ambil-alih pointer global zend_compile_file.
 *   2. Setiap kali PHP meng-compile sebuah file, hook kita membaca byte mentah
 *      file tersebut. Bila mengandung marker "DYGO0001", kita:
 *         - base64-decode + parse payload,
 *         - verifikasi HMAC-SHA256 (anti-tamper),
 *         - cek lisensi (expiry + domain),
 *         - AES-256-CBC decrypt -> source PHP asli,
 *         - serahkan source ke compiler asli (zend_compile_string).
 *      File non-DYGO diteruskan apa adanya ke compiler asli.
 *
 * Target: PHP 8.1 – 8.4.
 */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_compile.h"
#include "zend_exceptions.h"
#include "php_dygo_loader.h"
#include "dygo_crypto.h"

#include <string.h>
#include <time.h>
#include <unistd.h>

#define DYGO_MAGIC "DYGO0001"
#define DYGO_MAGIC_LEN 8

/* Kunci turunan, dihitung sekali di MINIT. */
static uint8_t dygo_enc_key[32];
static uint8_t dygo_mac_key[32];

static zend_op_array *(*dygo_orig_compile_file)(zend_file_handle *, int);

/* ---------- util ---------- */

static int dygo_hex2bin32(const char *hex, uint8_t out[32]) {
    for (int i = 0; i < 32; i++) {
        unsigned v;
        char c1 = hex[i*2], c2 = hex[i*2+1];
        if (!c1 || !c2) return 0;
        unsigned hi = (c1>='0'&&c1<='9')?c1-'0':(c1|32)>='a'&&(c1|32)<='f'?(c1|32)-'a'+10:255;
        unsigned lo = (c2>='0'&&c2<='9')?c2-'0':(c2|32)>='a'&&(c2|32)<='f'?(c2|32)-'a'+10:255;
        if (hi>15||lo>15) return 0;
        v = (hi<<4)|lo; out[i]=(uint8_t)v;
    }
    return 1;
}

/* Baca seluruh isi file ke buffer baru (emalloc). Caller harus efree. */
static char *dygo_read_file(const char *path, size_t *out_len) {
    FILE *f = fopen(path, "rb");
    if (!f) return NULL;
    fseek(f, 0, SEEK_END);
    long sz = ftell(f);
    fseek(f, 0, SEEK_SET);
    if (sz < 0) { fclose(f); return NULL; }
    char *buf = emalloc((size_t)sz + 1);
    size_t rd = fread(buf, 1, (size_t)sz, f);
    fclose(f);
    buf[rd] = 0;
    *out_len = rd;
    return buf;
}

/* --- JSON mini: ambil integer "exp" --- */
static long dygo_json_exp(const char *meta, size_t len) {
    const char *key = "\"exp\":";
    char *p = (char *) php_memnstr((char*)meta, (char*)key, strlen(key), (char*)meta + len);
    if (!p) return 0;
    p += strlen(key);
    while (p < meta + len && (*p == ' ')) p++;
    return strtol(p, NULL, 10);
}

/* --- JSON mini: cek host terhadap "domains":[...] ---
 * Return: 1 diizinkan, 0 ditolak, -1 tidak ada pembatasan domain. */
static int dygo_json_domain_ok(const char *meta, size_t len, const char *host) {
    const char *key = "\"domains\":[";
    char *p = (char *) php_memnstr((char*)meta, (char*)key, strlen(key), (char*)meta + len);
    if (!p) return -1;
    p += strlen(key);
    const char *end = meta + len;
    if (p < end && *p == ']') return -1; /* array kosong = semua domain */

    size_t hlen = strlen(host);
    while (p < end && *p != ']') {
        if (*p == '"') {
            p++;
            const char *start = p;
            while (p < end && *p != '"') p++;
            size_t dl = p - start;
            /* bandingkan host dgn pola [start, dl) */
            if (dl >= 2 && start[0] == '*' && start[1] == '.') {
                const char *base = start + 2; size_t bl = dl - 2;
                if (hlen == bl && strncasecmp(host, base, bl) == 0) return 1;
                if (hlen > bl && host[hlen-bl-1] == '.' &&
                    strncasecmp(host + hlen - bl, base, bl) == 0) return 1;
            } else if (dl == hlen && strncasecmp(host, start, dl) == 0) {
                return 1;
            }
        }
        p++;
    }
    return 0;
}

/* --- machine-id mentah per platform --- */
static int dygo_machine_id(char *out, size_t cap) {
    out[0] = 0;
#if defined(__linux__)
    const char *files[2] = {"/etc/machine-id", "/var/lib/dbus/machine-id"};
    for (int i = 0; i < 2; i++) {
        FILE *f = fopen(files[i], "rb");
        if (f) {
            size_t n = fread(out, 1, cap - 1, f);
            fclose(f);
            out[n] = 0;
            while (n && (out[n-1] == '\n' || out[n-1] == '\r' || out[n-1] == ' ')) out[--n] = 0;
            if (out[0]) return 1;
        }
    }
    return 0;
#elif defined(__APPLE__)
    FILE *p = popen("ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null", "r");
    if (!p) return 0;
    char line[512]; int found = 0;
    while (fgets(line, sizeof(line), p)) {
        char *q = strstr(line, "IOPlatformUUID");
        if (q) {
            char *s = strchr(q, '=');
            if (s) { s = strchr(s, '"'); }
            if (s) {
                s++;
                char *e = strchr(s, '"');
                if (e) { size_t n = e - s; if (n >= cap) n = cap - 1; memcpy(out, s, n); out[n] = 0; found = 1; }
            }
            break;
        }
    }
    pclose(p);
    return found;
#elif defined(_WIN32)
    FILE *p = _popen("reg query \"HKLM\\SOFTWARE\\Microsoft\\Cryptography\" /v MachineGuid 2>NUL", "r");
    if (!p) return 0;
    char line[512]; int found = 0;
    while (fgets(line, sizeof(line), p)) {
        char *q = strstr(line, "MachineGuid");
        if (q) {
            q = strstr(q, "REG_SZ");
            if (q) {
                q += 6;
                while (*q == ' ' || *q == '\t') q++;
                size_t n = strlen(q);
                while (n && (q[n-1]=='\n'||q[n-1]=='\r'||q[n-1]==' ')) q[--n]=0;
                if (n >= cap) n = cap - 1;
                memcpy(out, q, n); out[n] = 0; found = 1;
            }
            break;
        }
    }
    _pclose(p);
    return found;
#else
    return 0;
#endif
}

/* Node-hash server ini: sha256("DYGO-NODE:" + lower(id)) -> hex[64]. */
static int dygo_current_node_hash(char out_hex[65]) {
    char id[256];
    if (!dygo_machine_id(id, sizeof(id)) || id[0] == 0) return 0;
    for (char *c = id; *c; c++) { if (*c >= 'A' && *c <= 'Z') *c += 32; }
    char buf[300];
    int n = snprintf(buf, sizeof(buf), "DYGO-NODE:%s", id);
    uint8_t h[32];
    dygo_sha256((const uint8_t *) buf, (size_t) n, h);
    static const char *hx = "0123456789abcdef";
    for (int i = 0; i < 32; i++) { out_hex[i*2] = hx[h[i] >> 4]; out_hex[i*2+1] = hx[h[i] & 15]; }
    out_hex[64] = 0;
    return 1;
}

/* Cek node-hash terhadap "nodes":[...]. 1=izin, 0=tolak, -1=tak dibatasi. */
static int dygo_json_node_ok(const char *meta, size_t len, const char *node_hex) {
    const char *key = "\"nodes\":[";
    char *p = (char *) php_memnstr((char*)meta, (char*)key, strlen(key), (char*)meta + len);
    if (!p) return -1;
    p += strlen(key);
    const char *end = meta + len;
    if (p < end && *p == ']') return -1; /* kosong = tak dibatasi */
    while (p < end && *p != ']') {
        if (*p == '"') {
            p++;
            const char *start = p;
            while (p < end && *p != '"') p++;
            size_t dl = p - start;
            if (dl == 64 && strncasecmp(node_hex, start, 64) == 0) return 1;
        }
        p++;
    }
    return 0;
}

/* Host saat ini: $_SERVER['HTTP_HOST'] -> SERVER_NAME -> hostname. */
static void dygo_current_host(char *out, size_t cap) {
    out[0] = 0;
    zend_string *k;
    zval *arr, *v;

    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    arr = zend_hash_str_find(&EG(symbol_table), ZEND_STRL("_SERVER"));
    if (arr && Z_TYPE_P(arr) == IS_ARRAY) {
        const char *keys[2] = {"HTTP_HOST", "SERVER_NAME"};
        for (int i = 0; i < 2; i++) {
            k = zend_string_init(keys[i], strlen(keys[i]), 0);
            v = zend_hash_find(Z_ARRVAL_P(arr), k);
            zend_string_release(k);
            if (v && Z_TYPE_P(v) == IS_STRING && Z_STRLEN_P(v) > 0) {
                strncpy(out, Z_STRVAL_P(v), cap - 1);
                out[cap - 1] = 0;
                /* buang port */
                char *colon = strchr(out, ':');
                if (colon) *colon = 0;
                return;
            }
        }
    }
    gethostname(out, cap - 1);
    out[cap - 1] = 0;
}

/* Kompilasi string source menjadi op_array, lintas versi PHP. */
static zend_op_array *dygo_compile_source(const char *src, size_t len, const char *filename) {
    zend_op_array *oa;
#if PHP_VERSION_ID >= 80200
    /* PHP 8.2+: zend_compile_string(zend_string*, const char*, zend_compile_position) */
    zend_string *code = zend_string_init(src, len, 0);
    oa = zend_compile_string(code, filename, ZEND_COMPILE_POSITION_AT_SHEBANG);
    zend_string_release(code);
#elif PHP_VERSION_ID >= 80100
    /* PHP 8.1: zend_compile_string(zend_string*, const char*) meng-compile
       gaya eval (mode PHP, TANPA tag pembuka). Buang <?php di awal. */
    {
        const char *s = src; size_t l = len;
        if (l >= 5 && strncasecmp(s, "<?php", 5) == 0) { s += 5; l -= 5; }
        else if (l >= 3 && strncasecmp(s, "<?=", 3) == 0) { s += 3; l -= 3; }
        else if (l >= 2 && s[0] == '<' && s[1] == '?') { s += 2; l -= 2; }
        zend_string *code = zend_string_init(s, l, 0);
        oa = zend_compile_string(code, filename);
        zend_string_release(code);
    }
#else /* PHP 8.0: zend_compile_string(zval*, const char*) */
    zval code;
    ZVAL_STRINGL(&code, src, len);
    oa = zend_compile_string(&code, filename);
    zval_ptr_dtor(&code);
#endif
    return oa;
}

/* ---------- hook compile_file ---------- */

static zend_op_array *dygo_compile_file(zend_file_handle *file_handle, int type) {
    const char *filename = NULL;

    if (file_handle && file_handle->filename) {
        filename = ZSTR_VAL(file_handle->filename);
    }
    if (!filename) {
        return dygo_orig_compile_file(file_handle, type);
    }

    size_t raw_len = 0;
    char *raw = dygo_read_file(filename, &raw_len);
    if (!raw) {
        return dygo_orig_compile_file(file_handle, type);
    }

    /* cari marker */
    char *marker = (char *) php_memnstr(raw, DYGO_MAGIC, DYGO_MAGIC_LEN, raw + raw_len);
    if (!marker) {
        efree(raw);
        return dygo_orig_compile_file(file_handle, type);
    }

    const char *b64 = marker + DYGO_MAGIC_LEN;
    size_t b64_len = (raw + raw_len) - b64;

    uint8_t *payload = emalloc(b64_len);
    size_t plen = 0;
    if (!dygo_base64_decode(b64, b64_len, payload, &plen) || plen < 4 + 16 + 32) {
        efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: payload rusak pada %s", filename);
        return NULL;
    }

    uint32_t metaLen = payload[0] | (payload[1]<<8) | (payload[2]<<16) | ((uint32_t)payload[3]<<24);
    if (4 + metaLen + 16 + 32 > plen) {
        efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: metadata rusak pada %s", filename);
        return NULL;
    }
    size_t off = 4;
    const uint8_t *meta = payload + off;             off += metaLen;
    const uint8_t *iv   = payload + off;             off += 16;
    const uint8_t *tag  = payload + off;             off += 32;
    const uint8_t *ct   = payload + off;
    size_t ctlen = plen - off;

    /* --- HMAC verify: meta || iv || ct --- */
    size_t maclen = metaLen + 16 + ctlen;
    uint8_t *macmsg = emalloc(maclen);
    memcpy(macmsg, meta, metaLen);
    memcpy(macmsg + metaLen, iv, 16);
    memcpy(macmsg + metaLen + 16, ct, ctlen);
    uint8_t calc[32];
    dygo_hmac_sha256(dygo_mac_key, 32, macmsg, maclen, calc);
    efree(macmsg);
    if (!dygo_ct_equal(calc, tag, 32)) {
        efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: verifikasi integritas gagal pada %s (file dimodifikasi?)", filename);
        return NULL;
    }

    /* --- lisensi: expiry --- */
    long exp = dygo_json_exp((const char *) meta, metaLen);
    if (exp > 0 && (long) time(NULL) > exp) {
        efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: lisensi kedaluwarsa pada %s", filename);
        return NULL;
    }

    /* --- lisensi: domain --- */
    char host[256];
    dygo_current_host(host, sizeof(host));
    int dok = dygo_json_domain_ok((const char *) meta, metaLen, host);
    if (dok == 0) {
        efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: domain tidak diizinkan (%s) pada %s", host, filename);
        return NULL;
    }

    /* --- lisensi: node-lock (machine-id) --- */
    {
        char node_hex[65];
        int have_node = dygo_current_node_hash(node_hex);
        int nok = dygo_json_node_ok((const char *) meta, metaLen, have_node ? node_hex : "");
        if (nok == 0) {
            efree(payload); efree(raw);
            zend_error(E_ERROR, "DYGO Loader: server (machine-id) tidak diizinkan pada %s", filename);
            return NULL;
        }
    }

    /* --- decrypt --- */
    if (ctlen == 0 || (ctlen % 16) != 0) {
        efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: ciphertext tidak valid pada %s", filename);
        return NULL;
    }
    uint8_t *pt = emalloc(ctlen);
    dygo_aes256_cbc_decrypt(dygo_enc_key, iv, ct, ctlen, pt);
    int slen = dygo_pkcs7_strip(pt, ctlen);
    if (slen < 0) {
        efree(pt); efree(payload); efree(raw);
        zend_error(E_ERROR, "DYGO Loader: dekripsi gagal pada %s", filename);
        return NULL;
    }

    zend_op_array *oa = dygo_compile_source((const char *) pt, (size_t) slen, filename);

    efree(pt);
    efree(payload);
    efree(raw);
    return oa;
}

/* ---------- module ---------- */

PHP_MINIT_FUNCTION(dygo_loader) {
    uint8_t master[32];
    if (!dygo_hex2bin32(DYGO_MASTER_KEY_HEX, master)) {
        zend_error(E_WARNING, "DYGO Loader: DYGO_MASTER_KEY_HEX tidak valid");
        return FAILURE;
    }

    /* encKey = SHA256(master || "DYGO-ENC-v1") */
    uint8_t tmp[64];
    memcpy(tmp, master, 32);
    memcpy(tmp + 32, "DYGO-ENC-v1", 11);
    dygo_sha256(tmp, 32 + 11, dygo_enc_key);
    memcpy(tmp + 32, "DYGO-MAC-v1", 11);
    dygo_sha256(tmp, 32 + 11, dygo_mac_key);

    /* pasang hook */
    dygo_orig_compile_file = zend_compile_file;
    zend_compile_file = dygo_compile_file;
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(dygo_loader) {
    if (dygo_orig_compile_file) {
        zend_compile_file = dygo_orig_compile_file;
        dygo_orig_compile_file = NULL;
    }
    return SUCCESS;
}

PHP_MINFO_FUNCTION(dygo_loader) {
    php_info_print_table_start();
    php_info_print_table_row(2, "DYGO Loader", "enabled");
    php_info_print_table_row(2, "Version", PHP_DYGO_LOADER_VERSION);
    php_info_print_table_row(2, "Cipher", "AES-256-CBC + HMAC-SHA256");
    php_info_print_table_end();
}

zend_module_entry dygo_loader_module_entry = {
    STANDARD_MODULE_HEADER,
    "dygo_loader",
    NULL,                       /* functions */
    PHP_MINIT(dygo_loader),
    PHP_MSHUTDOWN(dygo_loader),
    NULL,                       /* RINIT */
    NULL,                       /* RSHUTDOWN */
    PHP_MINFO(dygo_loader),
    PHP_DYGO_LOADER_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_DYGO_LOADER
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(dygo_loader)
#endif
