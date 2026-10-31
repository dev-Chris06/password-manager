// Known-answer tests: the primitives must match independent references.
import { describe, expect, it } from 'vitest';

import { bytesToHex, hexToBytes, utf8Encode } from '../src/encoding';
import { hkdfSha256 } from '../src/hkdf';
import { argon2idRaw } from '../src/kdf';

describe('Argon2id', () => {
  it('matches the reference implementation (phc-winner-argon2 test suite)', async () => {
    const hash = await argon2idRaw(utf8Encode('password'), utf8Encode('somesalt'), {
      memoryKib: 65_536,
      iterations: 2,
      parallelism: 1,
      hashLength: 32,
    });
    expect(bytesToHex(hash)).toBe('09316115d5cf24ed5a15a31a3ba326e5cf32edc24702987c02b6566f61913cf7');
  });

  it('matches libsodium crypto_pwhash (ARGON2ID13) with the production parameters', async () => {
    // php -r 'echo bin2hex(sodium_crypto_pwhash(32, "correct horse battery staple",
    //   str_repeat("\x02", 16), 3, 64*1024*1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13));'
    const hash = await argon2idRaw(utf8Encode('correct horse battery staple'), new Uint8Array(16).fill(2), {
      memoryKib: 65_536,
      iterations: 3,
      parallelism: 1,
      hashLength: 32,
    });
    expect(bytesToHex(hash)).toBe('1096810449d933301800a640fd5cade6dfa0a9d07987e909a9fde0235baa5309');
  });
});

describe('HKDF-SHA256', () => {
  it('matches RFC 5869 test case 1', async () => {
    const okm = await hkdfSha256(
      hexToBytes('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b'),
      hexToBytes('000102030405060708090a0b0c'),
      hexToBytes('f0f1f2f3f4f5f6f7f8f9'),
      42,
    );
    expect(bytesToHex(okm)).toBe(
      '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
    );
  });

  it('matches RFC 5869 test case 3 (empty salt and info)', async () => {
    const okm = await hkdfSha256(hexToBytes('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b'), new Uint8Array(0), '', 42);
    expect(bytesToHex(okm)).toBe(
      '8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8',
    );
  });
});
