import { beforeAll, describe, expect, it } from 'vitest';

import {
  assertKdfParameters,
  buildBackup,
  CryptoFormatError,
  decryptEntry,
  DecryptionError,
  DEFAULT_KDF,
  deriveAccountKeys,
  encryptEntry,
  type EntryData,
  generateSalt,
  generateSecretKey,
  generateVaultKey,
  isKdfDowngrade,
  newEntryUuid,
  openBackup,
  parseBackup,
  unwrapVaultKey,
  WeakKdfParametersError,
  wrapVaultKey,
  bytesToBase64,
} from '../src';

const PASSWORD = 'correct horse battery staple';
const ENTRY: EntryData = {
  site: 'example.com',
  username: 'alice',
  password: 'S3cr3t!-pässwörd',
  category: 'email',
  notes: 'Recovery questions: none',
  includeSubdomains: false,
};

// One Argon2id derivation takes a few hundred milliseconds: derive the shared account once.
const secretKey = generateSecretKey();
const salt = generateSalt();
let wrapKey: CryptoKey;
let authProof: string;

beforeAll(async () => {
  ({ wrapKey, authProof } = await deriveAccountKeys(PASSWORD, secretKey, salt, DEFAULT_KDF));
}, 30_000);

describe('account key derivation', () => {
  it('is deterministic and never exposes the wrapping key', async () => {
    const again = await deriveAccountKeys(PASSWORD, secretKey, salt, DEFAULT_KDF);
    expect(again.authProof).toBe(authProof);
    expect(wrapKey.extractable).toBe(false);
    await expect(crypto.subtle.exportKey('raw', wrapKey)).rejects.toThrow();
  }, 30_000);

  it('depends on the password, the Secret Key and the salt', async () => {
    const otherPassword = await deriveAccountKeys(`${PASSWORD}!`, secretKey, salt, DEFAULT_KDF);
    const otherSecretKey = await deriveAccountKeys(PASSWORD, generateSecretKey(), salt, DEFAULT_KDF);
    const otherSalt = await deriveAccountKeys(PASSWORD, secretKey, generateSalt(), DEFAULT_KDF);
    const proofs = new Set([authProof, otherPassword.authProof, otherSecretKey.authProof, otherSalt.authProof]);
    expect(proofs.size).toBe(4);
  }, 60_000);

  it('normalizes the password (NFKC) so the same text always unlocks the vault', async () => {
    const composed = await deriveAccountKeys('pässwörd-correct-horse', secretKey, salt, DEFAULT_KDF);
    const decomposed = await deriveAccountKeys('pässwörd-correct-horse', secretKey, salt, DEFAULT_KDF);
    expect(decomposed.authProof).toBe(composed.authProof);
  }, 60_000);

  it('refuses KDF parameters below the floor or above the ceiling', async () => {
    const weak = { ...DEFAULT_KDF, iterations: 1 };
    await expect(deriveAccountKeys(PASSWORD, secretKey, salt, weak)).rejects.toThrow(WeakKdfParametersError);
    expect(() => {
      assertKdfParameters({ ...DEFAULT_KDF, memoryKib: 1024 });
    }).toThrow(WeakKdfParametersError);
    expect(() => {
      assertKdfParameters({ ...DEFAULT_KDF, memoryKib: 4_194_304 });
    }).toThrow(WeakKdfParametersError);
    expect(() => {
      assertKdfParameters({ ...DEFAULT_KDF, algorithm: 'pbkdf2' });
    }).toThrow(WeakKdfParametersError);
    expect(isKdfDowngrade(DEFAULT_KDF, { ...DEFAULT_KDF, iterations: 4 })).toBe(false);
    expect(isKdfDowngrade({ ...DEFAULT_KDF, iterations: 4 }, DEFAULT_KDF)).toBe(true);
  });
});

describe('vault key', () => {
  it('is wrapped and unwrapped as a non-extractable key by default', async () => {
    const vaultKey = await generateVaultKey();
    const wrapped = await wrapVaultKey(wrapKey, vaultKey);
    const unwrapped = await unwrapVaultKey(wrapKey, wrapped);

    expect(unwrapped.extractable).toBe(false);
    const uuid = newEntryUuid();
    const ciphertext = await encryptEntry(vaultKey, uuid, ENTRY);
    expect(await decryptEntry(unwrapped, uuid, ciphertext)).toEqual(ENTRY);
  });

  it('can be unwrapped as extractable for a re-wrap', async () => {
    const wrapped = await wrapVaultKey(wrapKey, await generateVaultKey());
    const unwrapped = await unwrapVaultKey(wrapKey, wrapped, { extractable: true });
    expect(unwrapped.extractable).toBe(true);
  });

  it('cannot be unwrapped with another account key', async () => {
    const wrapped = await wrapVaultKey(wrapKey, await generateVaultKey());
    const other = await deriveAccountKeys(PASSWORD, generateSecretKey(), salt, DEFAULT_KDF);
    await expect(unwrapVaultKey(other.wrapKey, wrapped)).rejects.toThrow(DecryptionError);
  }, 30_000);
});

describe('entries', () => {
  it('round-trip and use a fresh IV every time', async () => {
    const vaultKey = await generateVaultKey();
    const uuid = newEntryUuid();
    const first = await encryptEntry(vaultKey, uuid, ENTRY);
    const second = await encryptEntry(vaultKey, uuid, ENTRY);

    expect(first).not.toBe(second);
    expect(await decryptEntry(vaultKey, uuid, first)).toEqual(ENTRY);
    expect(first).not.toContain('example');
  });

  it('cannot be moved under another UUID', async () => {
    const vaultKey = await generateVaultKey();
    const ciphertext = await encryptEntry(vaultKey, newEntryUuid(), ENTRY);
    await expect(decryptEntry(vaultKey, newEntryUuid(), ciphertext)).rejects.toThrow(DecryptionError);
  });

  it('detects any modified bit', async () => {
    const vaultKey = await generateVaultKey();
    const uuid = newEntryUuid();
    const bytes = Uint8Array.from(atob(await encryptEntry(vaultKey, uuid, ENTRY)), (c) => c.charCodeAt(0));
    bytes[20] = (bytes[20] ?? 0) ^ 0x01;
    await expect(decryptEntry(vaultKey, uuid, bytesToBase64(bytes))).rejects.toThrow(DecryptionError);
  });

  it('rejects an unknown format version and malformed input', async () => {
    const vaultKey = await generateVaultKey();
    const uuid = newEntryUuid();
    const bytes = Uint8Array.from(atob(await encryptEntry(vaultKey, uuid, ENTRY)), (c) => c.charCodeAt(0));
    bytes[0] = 0x02;
    await expect(decryptEntry(vaultKey, uuid, bytesToBase64(bytes))).rejects.toThrow(CryptoFormatError);
    await expect(decryptEntry(vaultKey, uuid, 'not base64!')).rejects.toThrow(CryptoFormatError);
    await expect(encryptEntry(vaultKey, 'not-a-uuid', ENTRY)).rejects.toThrow(CryptoFormatError);
  });

  it('refuses oversized entries', async () => {
    const vaultKey = await generateVaultKey();
    await expect(encryptEntry(vaultKey, newEntryUuid(), { ...ENTRY, notes: 'x'.repeat(70_000) })).rejects.toThrow(
      CryptoFormatError,
    );
  });
});

describe('backup', () => {
  it('opens with the account secrets only, without any server', async () => {
    const vaultKey = await generateVaultKey();
    const uuid = newEntryUuid();
    const backup = buildBackup(
      { kdf: DEFAULT_KDF, salt: bytesToBase64(salt), wrappedVaultKey: await wrapVaultKey(wrapKey, vaultKey) },
      [{ uuid, ciphertext: await encryptEntry(vaultKey, uuid, ENTRY) }],
    );

    const parsed = parseBackup(JSON.stringify(backup));
    const opened = await openBackup(parsed, PASSWORD, secretKey);
    expect(opened.entries).toEqual([{ uuid, data: ENTRY }]);

    await expect(openBackup(parsed, 'wrong password', secretKey)).rejects.toThrow(DecryptionError);
    await expect(openBackup(parsed, PASSWORD, generateSecretKey())).rejects.toThrow(DecryptionError);
  }, 60_000);

  it('refuses tampered or weakened files', async () => {
    const backup = buildBackup(
      { kdf: DEFAULT_KDF, salt: bytesToBase64(salt), wrappedVaultKey: await wrapVaultKey(wrapKey, await generateVaultKey()) },
      [],
    );
    expect(() => parseBackup(JSON.stringify({ ...backup, kdf: { ...DEFAULT_KDF, iterations: 1 } }))).toThrow(
      WeakKdfParametersError,
    );
    expect(() => parseBackup(JSON.stringify({ ...backup, format: 'pm-backup-v1' }))).toThrow(CryptoFormatError);
    expect(() => parseBackup(JSON.stringify({ ...backup, entries: [{ uuid: 'x', ciphertext: '' }] }))).toThrow(
      CryptoFormatError,
    );
    expect(() => parseBackup('{')).toThrow(CryptoFormatError);

    // A changed (still valid) iteration count gives another wrapping key: decryption fails.
    const altered = parseBackup(JSON.stringify({ ...backup, kdf: { ...DEFAULT_KDF, iterations: 4 } }));
    await expect(openBackup(altered, PASSWORD, secretKey)).rejects.toThrow(DecryptionError);
  }, 60_000);
});
