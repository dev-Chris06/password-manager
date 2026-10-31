// Export and import of self-contained encrypted backups (docs/PLAN.md §4.3).
import { buildBackup, type EntryData, openBackup, parseBackup, parseSecretKey } from '../../packages/crypto/src';
import type { ApiClient } from './api';
import { MissingSecretKeyError, reauthenticate, type VaultResponse } from './auth';
import { loadSecretKey } from './secretKeyStore';
import type { VaultStore } from './vaultStore';

/** Builds the backup file. The master password is asked again before anything is exported. */
export async function exportBackup(api: ApiClient, masterPassword: string): Promise<{ fileName: string; content: string }> {
  const vault = await api.get<VaultResponse>('/api/vault');
  await reauthenticate(vault, masterPassword);
  const backup = buildBackup(
    { kdf: vault.kdf, salt: vault.salt, wrappedVaultKey: vault.wrappedVaultKey },
    vault.entries.map(({ uuid, ciphertext }) => ({ uuid, ciphertext })),
  );
  const date = new Date().toISOString().slice(0, 10);
  return { fileName: `coffre-${date}.json`, content: `${JSON.stringify(backup, null, 2)}\n` };
}

export function downloadFile(fileName: string, content: string, type = 'application/json'): void {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  URL.revokeObjectURL(url);
}

/**
 * Decrypts a backup with the secrets valid when it was made, then re-encrypts its entries with
 * the current vault key. Entries already present (same site and username) are skipped.
 */
export async function importBackup(
  store: VaultStore,
  json: string,
  masterPassword: string,
  secretKeyText: string | undefined,
  email: string,
): Promise<{ imported: number; skipped: number }> {
  const text = secretKeyText ?? loadSecretKey(email);
  if (text === null || text === '') {
    throw new MissingSecretKeyError();
  }
  const opened = await openBackup(parseBackup(json), masterPassword, parseSecretKey(text));
  return store.importEntries(opened.entries.map((entry): EntryData => entry.data));
}
