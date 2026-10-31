// Self-contained backup file (docs/PLAN.md §4.3): it can be decrypted without any server,
// with the master password and the Secret Key valid at export time.
//
// The KDF parameters are not authenticated separately: they are inputs of the derivation,
// so altering them yields a different wrapping key and the vault key fails to decrypt.
import { base64ToBytes, CryptoFormatError } from './encoding';
import { deriveAccountKeys } from './derive';
import { decryptEntry, type EntryData, isEntryUuid } from './entry';
import { assertKdfParameters, type KdfParameters, SALT_LENGTH } from './kdf';
import { unwrapVaultKey } from './vaultKey';

export const BACKUP_FORMAT = 'pm-backup-v2';
export const MAX_BACKUP_ENTRIES = 5_000;

export interface BackupEntry {
  readonly uuid: string;
  readonly ciphertext: string;
}

export interface BackupFile {
  readonly format: typeof BACKUP_FORMAT;
  readonly exportedAt: string;
  readonly kdf: KdfParameters;
  readonly salt: string;
  readonly wrappedVaultKey: string;
  readonly entries: readonly BackupEntry[];
}

export interface OpenedBackup {
  readonly entries: readonly { readonly uuid: string; readonly data: EntryData }[];
}

export function buildBackup(
  account: { kdf: KdfParameters; salt: string; wrappedVaultKey: string },
  entries: readonly BackupEntry[],
  now: Date = new Date(),
): BackupFile {
  return {
    format: BACKUP_FORMAT,
    exportedAt: now.toISOString(),
    kdf: account.kdf,
    salt: account.salt,
    wrappedVaultKey: account.wrappedVaultKey,
    entries: entries.map(({ uuid, ciphertext }) => ({ uuid, ciphertext })),
  };
}

export function parseBackup(json: string): BackupFile {
  let value: unknown;
  try {
    value = JSON.parse(json);
  } catch {
    throw new CryptoFormatError('The backup file is not valid JSON.');
  }
  if (typeof value !== 'object' || value === null) {
    throw new CryptoFormatError('Invalid backup file.');
  }
  const { format, exportedAt, kdf, salt, wrappedVaultKey, entries } = value as Record<string, unknown>;
  if (format !== BACKUP_FORMAT) {
    throw new CryptoFormatError('Unsupported backup format.');
  }
  assertKdfParameters(kdf);
  if (
    typeof exportedAt !== 'string' ||
    typeof salt !== 'string' ||
    base64ToBytes(salt).length !== SALT_LENGTH ||
    typeof wrappedVaultKey !== 'string' ||
    !Array.isArray(entries) ||
    entries.length > MAX_BACKUP_ENTRIES
  ) {
    throw new CryptoFormatError('Invalid backup file.');
  }
  const parsedEntries = entries.map((entry: unknown): BackupEntry => {
    const { uuid, ciphertext } = (entry ?? {}) as Record<string, unknown>;
    if (typeof uuid !== 'string' || !isEntryUuid(uuid) || typeof ciphertext !== 'string') {
      throw new CryptoFormatError('Invalid backup entry.');
    }
    return { uuid, ciphertext };
  });

  return { format, exportedAt, kdf, salt, wrappedVaultKey, entries: parsedEntries };
}

export async function openBackup(
  backup: BackupFile,
  masterPassword: string,
  secretKey: Uint8Array<ArrayBuffer>,
): Promise<OpenedBackup> {
  const { wrapKey } = await deriveAccountKeys(masterPassword, secretKey, base64ToBytes(backup.salt), backup.kdf);
  const vaultKey = await unwrapVaultKey(wrapKey, backup.wrappedVaultKey);
  const entries = [];
  for (const entry of backup.entries) {
    entries.push({ uuid: entry.uuid, data: await decryptEntry(vaultKey, entry.uuid, entry.ciphertext) });
  }
  return { entries };
}
