// Registration and login flows: everything secret is derived here, only the proof leaves the browser.
import {
  assertKdfParameters,
  base64ToBytes,
  bytesToBase64,
  DEFAULT_KDF,
  DecryptionError,
  deriveAccountKeys,
  formatSecretKey,
  generateSalt,
  generateSecretKey,
  generateVaultKey,
  isKdfDowngrade,
  type KdfParameters,
  parseSecretKey,
  unwrapVaultKey,
  wrapVaultKey,
} from '../../packages/crypto/src';
import { ApiError, type ApiClient } from './api';
import { forgetPendingSecretKey, loadKnownKdf, loadPendingSecretKey, loadSecretKey, saveKnownKdf, saveSecretKey } from './secretKeyStore';

export interface EncryptedEntry {
  readonly uuid: string;
  readonly ciphertext: string;
  readonly version: number;
  readonly updatedAt: string;
}

export interface VaultResponse {
  readonly email: string;
  readonly kdf: KdfParameters;
  readonly salt: string;
  readonly wrappedVaultKey: string;
  readonly entries: EncryptedEntry[];
  readonly backupCodesRemaining: number;
}

export interface UnlockedVault {
  readonly email: string;
  readonly vaultKey: CryptoKey;
  readonly vault: VaultResponse;
}

export type LoginResult = { readonly step: 'done' } | { readonly step: '2fa' } | { readonly step: '2fa-setup' };

export class KdfDowngradeError extends Error {
  override name = 'KdfDowngradeError';
}

/**
 * Creates the account. Returns the Secret Key in its text form: it must be shown to the user
 * (emergency kit) and is never sent to the server.
 */
export async function register(api: ApiClient, email: string, masterPassword: string, registrationCode?: string): Promise<string> {
  const salt = generateSalt();
  const secretKey = generateSecretKey();
  const { wrapKey, authProof } = await deriveAccountKeys(masterPassword, secretKey, salt, DEFAULT_KDF);
  const vaultKey = await generateVaultKey();

  await api.post('/api/auth/register', {
    email,
    kdf: DEFAULT_KDF,
    salt: bytesToBase64(salt),
    authProof,
    wrappedVaultKey: await wrapVaultKey(wrapKey, vaultKey),
    ...(registrationCode === undefined ? {} : { registrationCode }),
  });

  return formatSecretKey(secretKey);
}

export interface Credentials {
  readonly email: string;
  readonly masterPassword: string;
  /** Text form typed by the user; when absent, the one remembered by this device is used. */
  readonly secretKeyText?: string | undefined;
}

export interface DerivedLogin {
  readonly email: string;
  readonly kdf: KdfParameters;
  readonly secretKeyText: string;
  readonly wrapKey: CryptoKey;
  /** Kept in memory for the two-factor enrollment, which asks for a fresh proof. */
  readonly authProof: string;
}

function assertNoKdfDowngrade(email: string, kdf: KdfParameters): void {
  const known = loadKnownKdf(email);
  if (known !== null && isKdfDowngrade(known, kdf)) {
    throw new KdfDowngradeError('The server sent weaker key derivation parameters than before.');
  }
}

/** First login step: derive the keys from the prelogin parameters and send the proof. */
export async function login(api: ApiClient, credentials: Credentials): Promise<{ result: LoginResult; derived: DerivedLogin }> {
  const email = credentials.email.trim().toLowerCase();
  const secretKeyText = credentials.secretKeyText ?? loadSecretKey(email);
  if (secretKeyText === null || secretKeyText === '') {
    throw new MissingSecretKeyError();
  }

  const prelogin = await api.post<{ kdf: unknown; salt: string }>('/api/auth/prelogin', { email });
  assertKdfParameters(prelogin.kdf);
  assertNoKdfDowngrade(email, prelogin.kdf);
  const kdf = prelogin.kdf;

  const attempt = async (text: string): Promise<{ result: LoginResult; derived: DerivedLogin }> => {
    const secretKey = parseSecretKey(text);
    const { wrapKey, authProof } = await deriveAccountKeys(credentials.masterPassword, secretKey, base64ToBytes(prelogin.salt), kdf);
    const result = await api.post<LoginResult>('/api/auth/login', { email, authProof });
    // Logging in renews the session and its CSRF token.
    await api.session();
    return { result, derived: { email, kdf, secretKeyText: formatSecretKey(secretKey), wrapKey, authProof } };
  };

  try {
    return await attempt(secretKeyText);
  } catch (error) {
    // A Secret Key change whose confirmation was lost: the pending key may be the right one.
    const pending = loadPendingSecretKey(email);
    if (credentials.secretKeyText === undefined && pending !== null && error instanceof ApiError && error.code === 'invalid_credentials') {
      return attempt(pending);
    }
    throw error;
  }
}

export interface TwoFactorEnrollment {
  readonly secret: string;
  readonly uri: string;
  readonly qrCode: string;
}

/** Second login step: TOTP code or backup code. Completing it renews the session and its CSRF token. */
export async function completeSecondFactor(api: ApiClient, answer: { code: string } | { backupCode: string }): Promise<void> {
  await api.post('/api/auth/2fa/check', answer);
  await api.session();
}

export async function startTwoFactorEnrollment(api: ApiClient, derived: DerivedLogin): Promise<TwoFactorEnrollment> {
  return api.post<TwoFactorEnrollment>('/api/2fa/setup', { authProof: derived.authProof });
}

/** Confirms the enrollment; returns the backup codes, shown only once. */
export async function confirmTwoFactorEnrollment(api: ApiClient, code: string): Promise<string[]> {
  const response = await api.post<{ backupCodes: string[] }>('/api/2fa/enable', { code });
  await api.session();
  return response.backupCodes;
}

/** Once the server session is complete: fetch the encrypted vault and unwrap its key locally. */
export async function openVault(api: ApiClient, derived: DerivedLogin): Promise<UnlockedVault> {
  const vault = await api.get<VaultResponse>('/api/vault');
  const vaultKey = await unwrapVaultKey(derived.wrapKey, vault.wrappedVaultKey);
  saveSecretKey(derived.email, derived.secretKeyText);
  if (loadPendingSecretKey(derived.email) === derived.secretKeyText) {
    forgetPendingSecretKey(derived.email);
  }
  saveKnownKdf(derived.email, derived.kdf);
  return { email: derived.email, vaultKey, vault };
}

/** Unlocks again an already authenticated session (local operation, no second factor). */
export async function unlock(api: ApiClient, email: string, masterPassword: string, secretKeyText?: string): Promise<UnlockedVault> {
  const text = secretKeyText ?? loadSecretKey(email);
  if (text === null || text === '') {
    throw new MissingSecretKeyError();
  }
  const vault = await api.get<VaultResponse>('/api/vault');
  assertKdfParameters(vault.kdf);
  assertNoKdfDowngrade(email, vault.kdf);
  const open = async (candidate: string): Promise<CryptoKey> => {
    const { wrapKey } = await deriveAccountKeys(masterPassword, parseSecretKey(candidate), base64ToBytes(vault.salt), vault.kdf);
    return unwrapVaultKey(wrapKey, vault.wrappedVaultKey);
  };
  try {
    return { email, vaultKey: await open(text), vault };
  } catch (error) {
    const pending = loadPendingSecretKey(email);
    if (secretKeyText === undefined && pending !== null && error instanceof DecryptionError) {
      const vaultKey = await open(pending);
      saveSecretKey(email, pending);
      forgetPendingSecretKey(email);
      return { email, vaultKey, vault };
    }
    throw error;
  }
}

/**
 * Derives a fresh authentication proof from the master password, for sensitive actions
 * (backup codes, master password change). Also returns the wrapping key.
 */
export async function reauthenticate(
  vault: VaultResponse,
  masterPassword: string,
  secretKeyText?: string,
): Promise<{ authProof: string; wrapKey: CryptoKey }> {
  const text = secretKeyText ?? loadSecretKey(vault.email);
  if (text === null || text === '') {
    throw new MissingSecretKeyError();
  }
  assertKdfParameters(vault.kdf);
  assertNoKdfDowngrade(vault.email, vault.kdf);
  const { wrapKey, authProof } = await deriveAccountKeys(masterPassword, parseSecretKey(text), base64ToBytes(vault.salt), vault.kdf);
  // Fails here, before any request, if the master password is wrong.
  await unwrapVaultKey(wrapKey, vault.wrappedVaultKey);
  return { authProof, wrapKey };
}

export class MissingSecretKeyError extends Error {
  override name = 'MissingSecretKeyError';

  constructor() {
    super('The Secret Key of this account is not known on this device.');
  }
}
