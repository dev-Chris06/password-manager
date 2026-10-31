// Client-side cryptography shared by the vault web app and the browser extension.
export { open, seal } from './aead';
export { buildBackup, BACKUP_FORMAT, MAX_BACKUP_ENTRIES, openBackup, parseBackup } from './backup';
export type { BackupEntry, BackupFile, OpenedBackup } from './backup';
export { deriveAccountKeys } from './derive';
export type { AccountKeys } from './derive';
export {
  base64ToBytes,
  bytesToBase64,
  CryptoFormatError,
  DecryptionError,
  randomBytes,
  wipe,
} from './encoding';
export {
  decryptEntry,
  encryptEntry,
  ENTRY_CATEGORIES,
  isEntryUuid,
  MAX_ENTRY_PLAINTEXT_BYTES,
  newEntryUuid,
  normalizeEntry,
} from './entry';
export type { EntryCategory, EntryData } from './entry';
export {
  assertKdfParameters,
  DEFAULT_KDF,
  generateSalt,
  isKdfDowngrade,
  KDF_CEILING,
  KDF_FLOOR,
  SALT_LENGTH,
  WeakKdfParametersError,
} from './kdf';
export type { KdfParameters } from './kdf';
export {
  formatSecretKey,
  generateSecretKey,
  InvalidSecretKeyError,
  parseSecretKey,
  SECRET_KEY_LENGTH,
} from './secretKey';
export { generateVaultKey, unwrapVaultKey, wrapVaultKey } from './vaultKey';
