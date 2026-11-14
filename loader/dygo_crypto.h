/*
 * DYGO Loader — kripto self-contained (tanpa dependensi OpenSSL).
 *   - SHA-256 + HMAC-SHA256
 *   - AES-256 CBC decrypt (+ strip PKCS7)
 *   - Base64 decode
 *   - Perbandingan constant-time
 *
 * Harus kompatibel byte-per-byte dengan shared/dygo-codec.php.
 */
#ifndef DYGO_CRYPTO_H
#define DYGO_CRYPTO_H

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

void dygo_sha256(const uint8_t *data, size_t len, uint8_t out[32]);

void dygo_hmac_sha256(const uint8_t *key, size_t key_len,
                      const uint8_t *msg, size_t msg_len,
                      uint8_t out[32]);

/* Decode base64 standar. Return 1 sukses, 0 gagal. *out_len di-set. */
int dygo_base64_decode(const char *in, size_t in_len,
                       uint8_t *out, size_t *out_len);

/* AES-256-CBC decrypt. in_len harus kelipatan 16. out menampung in_len byte. */
void dygo_aes256_cbc_decrypt(const uint8_t key[32], const uint8_t iv[16],
                             const uint8_t *in, size_t in_len, uint8_t *out);

/* Strip padding PKCS7. Return panjang plaintext, atau -1 bila padding invalid. */
int dygo_pkcs7_strip(const uint8_t *buf, size_t len);

/* Perbandingan constant-time. Return 1 bila sama. */
int dygo_ct_equal(const uint8_t *a, const uint8_t *b, size_t len);

#ifdef __cplusplus
}
#endif

#endif /* DYGO_CRYPTO_H */
