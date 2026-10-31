// Random vault key, stored on the server only encrypted with the wrapping key.
import { open, seal } from './aead';
import { CryptoFormatError, wipe } from './encoding';

const VAULT_KEY_AAD = 'pm-vault-key-v1';

/** Generates a new vault key. It is extractable only so that it can be wrapped once. */
export async function generateVaultKey(): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
}

export async function wrapVaultKey(wrapKey: CryptoKey, vaultKey: CryptoKey): Promise<string> {
  const raw = new Uint8Array(await crypto.subtle.exportKey('raw', vaultKey));
  try {
    return await seal(wrapKey, raw, VAULT_KEY_AAD);
  } finally {
    wipe(raw);
  }
}

/**
 * Decrypts the vault key. It is not extractable unless explicitly requested (master password
 * change, key rotation): script running in the page can then use it but not exfiltrate it.
 */
export async function unwrapVaultKey(
  wrapKey: CryptoKey,
  wrappedVaultKey: string,
  options: { extractable: boolean } = { extractable: false },
): Promise<CryptoKey> {
  const raw = await open(wrapKey, wrappedVaultKey, VAULT_KEY_AAD);
  try {
    if (raw.length !== 32) {
      throw new CryptoFormatError('Invalid vault key.');
    }
    return await crypto.subtle.importKey('raw', raw, { name: 'AES-GCM', length: 256 }, options.extractable, [
      'encrypt',
      'decrypt',
    ]);
  } finally {
    wipe(raw);
  }
}
