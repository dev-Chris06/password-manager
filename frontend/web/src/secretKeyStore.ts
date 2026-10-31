// Per-device memory of the Secret Key and of the KDF parameters already seen (docs/PLAN.md §4.2).
// Storage may be unavailable (private browsing, blocked site data): every access is guarded.
import type { KdfParameters } from '../../packages/crypto/src';

const SECRET_KEY_PREFIX = 'pm:secret-key:';
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
