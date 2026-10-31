// Per-device memory of the Secret Key and of the KDF parameters already seen (docs/PLAN.md §4.2).
// Storage may be unavailable (private browsing, blocked site data): every access is guarded.
import type { KdfParameters } from '../../packages/crypto/src';

const SECRET_KEY_PREFIX = 'pm:secret-key:';
const PENDING_SECRET_KEY_PREFIX = 'pm:secret-key-pending:';
const KDF_PREFIX = 'pm:kdf:';

function read(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function write(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    // Not persisted: the Secret Key will be asked again next time.
  }
}

export function loadSecretKey(email: string): string | null {
  return read(SECRET_KEY_PREFIX + email);
}

export function saveSecretKey(email: string, secretKeyText: string): void {
  write(SECRET_KEY_PREFIX + email, secretKeyText);
}

export function forgetSecretKey(email: string): void {
  try {
    localStorage.removeItem(SECRET_KEY_PREFIX + email);
  } catch {
    // Nothing to forget.
  }
}

/**
 * A new Secret Key is kept as "pending" from the moment it is sent to the server until the change
 * is confirmed: if the answer is lost, the next login falls back to it instead of locking the
 * owner out.
 */
export function savePendingSecretKey(email: string, secretKeyText: string): void {
  write(PENDING_SECRET_KEY_PREFIX + email, secretKeyText);
}

export function loadPendingSecretKey(email: string): string | null {
  return read(PENDING_SECRET_KEY_PREFIX + email);
}

export function forgetPendingSecretKey(email: string): void {
  try {
    localStorage.removeItem(PENDING_SECRET_KEY_PREFIX + email);
  } catch {
    // Nothing to forget.
  }
}

/** The pending Secret Key turned out to be the right one: it becomes the current one. */
export function promotePendingSecretKey(email: string): void {
  const pending = loadPendingSecretKey(email);
  if (pending !== null) {
    saveSecretKey(email, pending);
    forgetPendingSecretKey(email);
  }
}

export function loadKnownKdf(email: string): KdfParameters | null {
  const raw = read(KDF_PREFIX + email);
  if (raw === null) {
    return null;
  }
  try {
    return JSON.parse(raw) as KdfParameters;
  } catch {
    return null;
  }
}

export function saveKnownKdf(email: string, kdf: KdfParameters): void {
  write(KDF_PREFIX + email, JSON.stringify(kdf));
}
