// Master password change and vault key rotation, entirely prepared in the browser.
import {
  bytesToBase64,
  DEFAULT_KDF,
  deriveAccountKeys,
  encryptEntry,
  generateSalt,
  generateVaultKey,
  type KdfParameters,
  parseSecretKey,
  unwrapVaultKey,
  wrapVaultKey,
} from '../../packages/crypto/src';
import { ApiError, type ApiClient } from './api';
import { MissingSecretKeyError, reauthenticate, type UnlockedVault, type VaultResponse } from './auth';
import { forgetPendingSecretKey, loadSecretKey, saveKnownKdf, savePendingSecretKey, saveSecretKey } from './secretKeyStore';
import type { VaultStore } from './vaultStore';

export interface PasswordChange {
  readonly currentPassword: string;
  readonly newPassword: string;
  /** Also replace the vault key and re-encrypt every entry. */
  readonly rotateVaultKey: boolean;
  /**
   * New Secret Key, already shown to the user in a new emergency kit and confirmed as saved.
   * Undefined to keep the current one.
   */
  readonly newSecretKeyText?: string | undefined;
}

/**
 * The request may have been applied by the server although no answer came back: the new
 * credentials (and Secret Key) must be tried at the next login.
 */
export class PossiblyAppliedError extends Error {
  override name = 'PossiblyAppliedError';
}

export class UnreadableEntriesError extends Error {
  override name = 'UnreadableEntriesError';
}

/** Never weaker than what the account already uses. */
function strongestKdf(current: KdfParameters): KdfParameters {
  return {
    algorithm: 'argon2id',
    memoryKib: Math.max(current.memoryKib, DEFAULT_KDF.memoryKib),
    iterations: Math.max(current.iterations, DEFAULT_KDF.iterations),
    parallelism: Math.max(current.parallelism, DEFAULT_KDF.parallelism),
  };
}

export async function changeMasterPassword(
  api: ApiClient,
  store: VaultStore,
  email: string,
  change: PasswordChange,
): Promise<{ unlocked: UnlockedVault; secretKeyText: string }> {
  const vault = await api.get<VaultResponse>('/api/vault');
  const current = await reauthenticate(vault, change.currentPassword);

  const storedSecretKey = loadSecretKey(email);
  if (change.newSecretKeyText === undefined && storedSecretKey === null) {
    throw new MissingSecretKeyError();
  }
  const secretKeyText = change.newSecretKeyText ?? storedSecretKey ?? '';
  const kdf = strongestKdf(vault.kdf);
  const salt = generateSalt();
  const next = await deriveAccountKeys(change.newPassword, parseSecretKey(secretKeyText), salt, kdf);

  let vaultKey: CryptoKey;
  let entries: { uuid: string; ciphertext: string }[] | undefined;
  if (change.rotateVaultKey) {
    if (store.unreadableCount() > 0) {
      throw new UnreadableEntriesError('Unreadable entries cannot be re-encrypted.');
    }
    vaultKey = await generateVaultKey();
    entries = [];
    for (const item of store.list()) {
      entries.push({ uuid: item.uuid, ciphertext: await encryptEntry(vaultKey, item.uuid, item.data) });
    }
  } else {
    // The same vault key, wrapped with the new wrapping key.
    vaultKey = await unwrapVaultKey(current.wrapKey, vault.wrappedVaultKey, { extractable: true });
  }

  const payload = {
    currentAuthProof: current.authProof,
    kdf,
    salt: bytesToBase64(salt),
    authProof: next.authProof,
    wrappedVaultKey: await wrapVaultKey(next.wrapKey, vaultKey),
    ...(entries === undefined ? {} : { entries }),
  };
  if (change.newSecretKeyText !== undefined) {
    savePendingSecretKey(email, secretKeyText);
  }
  try {
    await api.post('/api/account/password', payload);
  } catch (error) {
    // A 4xx answer means the server refused the change: nothing was applied.
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
      forgetPendingSecretKey(email);
      throw error;
    }
    throw new PossiblyAppliedError('The server did not confirm the change.');
  }
  saveSecretKey(email, secretKeyText);
  forgetPendingSecretKey(email);
  saveKnownKdf(email, kdf);
  await api.session();

  const updated = await api.get<VaultResponse>('/api/vault');
  return {
    unlocked: { email, vaultKey: await unwrapVaultKey(next.wrapKey, updated.wrappedVaultKey), vault: updated },
    secretKeyText,
  };
}
