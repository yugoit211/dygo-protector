/*
 * Test mandiri: buktikan dygo_crypto.c mendekripsi file hasil encoder PHP.
 * Build: cc -o /tmp/dygo_test loader/test_crypto.c loader/dygo_crypto.c
 * Jalan: /tmp/dygo_test <file.php ter-encode> <master_key_hex>
 */
#include "dygo_crypto.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static const char MAGIC[] = "DYGO0001";

static int hex2bin(const char *hex, uint8_t *out, size_t n) {
    for (size_t i = 0; i < n; i++) {
        unsigned v;
        if (sscanf(hex + i*2, "%2x", &v) != 1) return 0;
        out[i] = (uint8_t)v;
    }
    return 1;
}

int main(int argc, char **argv) {
    if (argc < 3) { fprintf(stderr, "usage: %s <file> <masterkeyhex>\n", argv[0]); return 2; }

    FILE *f = fopen(argv[1], "rb");
    if (!f) { perror("open"); return 2; }
    fseek(f, 0, SEEK_END); long sz = ftell(f); fseek(f, 0, SEEK_SET);
    char *buf = malloc(sz + 1);
    fread(buf, 1, sz, f); buf[sz] = 0; fclose(f);

    char *m = strstr(buf, MAGIC);
    if (!m) { fprintf(stderr, "marker tidak ditemukan\n"); return 1; }
    const char *b64 = m + strlen(MAGIC);
    size_t b64len = (buf + sz) - b64;

    uint8_t *payload = malloc(b64len);
    size_t plen = 0;
    if (!dygo_base64_decode(b64, b64len, payload, &plen)) {
        fprintf(stderr, "base64 gagal\n"); return 1;
    }

    /* master key -> enc/mac keys */
    uint8_t master[32];
    if (!hex2bin(argv[2], master, 32)) { fprintf(stderr, "key hex invalid\n"); return 1; }

    uint8_t encKey[32], macKey[32];
    uint8_t tmp[64];
    memcpy(tmp, master, 32); memcpy(tmp+32, "DYGO-ENC-v1", 11);
    dygo_sha256(tmp, 32+11, encKey);
    memcpy(tmp+32, "DYGO-MAC-v1", 11);
    dygo_sha256(tmp, 32+11, macKey);

    /* parse payload */
    uint32_t metaLen = payload[0] | (payload[1]<<8) | (payload[2]<<16) | ((uint32_t)payload[3]<<24);
    size_t off = 4;
    const uint8_t *meta = payload + off; off += metaLen;
    const uint8_t *iv   = payload + off; off += 16;
    const uint8_t *tag  = payload + off; off += 32;
    const uint8_t *ct   = payload + off;
    size_t ctlen = plen - off;

    /* HMAC verify atas meta||iv||ct */
    size_t maclen = metaLen + 16 + ctlen;
    uint8_t *macmsg = malloc(maclen);
    memcpy(macmsg, meta, metaLen);
    memcpy(macmsg + metaLen, iv, 16);
    memcpy(macmsg + metaLen + 16, ct, ctlen);
    uint8_t calc[32];
    dygo_hmac_sha256(macKey, 32, macmsg, maclen, calc);
    if (!dygo_ct_equal(calc, tag, 32)) {
        fprintf(stderr, "HMAC MISMATCH (gagal)\n"); return 1;
    }
    fprintf(stderr, "[ok] HMAC cocok\n");

    /* decrypt */
    uint8_t *pt = malloc(ctlen);
    dygo_aes256_cbc_decrypt(encKey, iv, ct, ctlen, pt);
    int slen = dygo_pkcs7_strip(pt, ctlen);
    if (slen < 0) { fprintf(stderr, "padding invalid\n"); return 1; }

    fprintf(stderr, "[ok] meta: %.*s\n", (int)metaLen, meta);
    fprintf(stderr, "[ok] source terdekripsi (%d byte):\n", slen);
    fwrite(pt, 1, slen, stdout);
    printf("\n");
    return 0;
}
