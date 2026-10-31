// Two-secret key derivation (docs/PLAN.md §4.2):
//   master    = Argon2id(password, salt) XOR HKDF(secretKey, salt, "pm-secret-key-v1")
//   wrapKey   = HKDF(master, "pm-wrap-v1")  → AES-256-GCM key, never extractable
//   authProof = HKDF(master, "pm-auth-v1")  → the only value sent to the server
import { bytesToBase64, utf8Encode, wipe } from './encoding';
import { hkdfSha256 } from './hkdf';
import { deriveArgon2id, type KdfParameters } from './kdf';
import { InvalidSecretKeyError, SECRET_KEY_LENGTH } from './secretKey';

export interface AccountKeys {
  /** Encrypts and decrypts the vault key. Not extractable. */
  readonly wrapKey: CryptoKey;
  /** Base64 proof sent to the server to authenticate; reveals nothing about the wrapping key. */
  readonly authProof: string;
}

const EMPTY = new Uint8Array(0);

export async function deriveAccountKeys(
  masterPassword: string,
  secretKey: Uint8Array<ArrayBuffer>,
  salt: Uint8Array<ArrayBuffer>,
  params: KdfParameters,
): Promise<AccountKeys> {
  if (secretKey.length !== SECRET_KEY_LENGTH) {
    throw new InvalidSecretKeyError();
  }

  const passwordBytes = utf8Encode(masterPassword.normalize('NFKC'));
  const fromPassword = await deriveArgon2id(passwordBytes, salt, params);
  const fromSecretKey = await hkdfSha256(secretKey, salt, 'pm-secret-key-v1', 32);
  const master = new Uint8Array(32);
  for (let i = 0; i < master.length; i++) {
    master[i] = (fromPassword[i] ?? 0) ^ (fromSecretKey[i] ?? 0);
  }

  try {
    const masterKey = await crypto.subtle.importKey('raw', master, 'HKDF', false, ['deriveBits', 'deriveKey']);
    const wrapKey = await crypto.subtle.deriveKey(
      { name: 'HKDF', hash: 'SHA-256', salt: EMPTY, info: utf8Encode('pm-wrap-v1') },
      masterKey,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    );
    const proofBits = new Uint8Array(
      await crypto.subtle.deriveBits(
        { name: 'HKDF', hash: 'SHA-256', salt: EMPTY, info: utf8Encode('pm-auth-v1') },
        masterKey,
        256,
      ),
    );
    const authProof = bytesToBase64(proofBits);
    wipe(proofBits);

    return { wrapKey, authProof };
  } finally {
    wipe(passwordBytes);
    wipe(fromPassword);
    wipe(fromSecretKey);
    wipe(master);
  }
}
