// Vault entries: every field is encrypted together, bound to the entry UUID (docs/PLAN.md §4.3).
import { open, seal } from './aead';
import { CryptoFormatError, utf8Decode, utf8Encode, wipe } from './encoding';

export const ENTRY_CATEGORIES = ['social', 'email', 'bank', 'school', 'work', 'shopping', 'other'] as const;
export type EntryCategory = (typeof ENTRY_CATEGORIES)[number];

export interface EntryData {
  readonly site: string;
  readonly username: string;
  readonly password: string;
  readonly category: EntryCategory;
  readonly notes: string;
  /** When true, the extension also fills this entry on subdomains of `site`. */
  readonly includeSubdomains: boolean;
}

/** Same limit as the server (≈ 64 KiB of ciphertext once encoded). */
export const MAX_ENTRY_PLAINTEXT_BYTES = 60_000;

const ENTRY_FORMAT = 1;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

export function newEntryUuid(): string {
  return crypto.randomUUID();
}

export function isEntryUuid(value: string): boolean {
  return UUID_PATTERN.test(value);
}

export async function encryptEntry(vaultKey: CryptoKey, uuid: string, data: EntryData): Promise<string> {
  assertUuid(uuid);
  const payload = utf8Encode(JSON.stringify({ v: ENTRY_FORMAT, ...normalizeEntry(data) }));
  try {
    if (payload.length > MAX_ENTRY_PLAINTEXT_BYTES) {
      throw new CryptoFormatError('Entry is too large.');
    }
    return await seal(vaultKey, payload, associatedData(uuid));
  } finally {
    wipe(payload);
  }
}

export async function decryptEntry(vaultKey: CryptoKey, uuid: string, ciphertext: string): Promise<EntryData> {
  assertUuid(uuid);
  const payload = await open(vaultKey, ciphertext, associatedData(uuid));
  try {
    const decoded: unknown = JSON.parse(utf8Decode(payload));
    return parseEntry(decoded);
  } finally {
    wipe(payload);
  }
}

export function normalizeEntry(data: EntryData): EntryData {
  return {
    site: data.site.trim(),
    username: data.username.trim(),
    password: data.password,
    category: ENTRY_CATEGORIES.includes(data.category) ? data.category : 'other',
    notes: data.notes,
    includeSubdomains: data.includeSubdomains,
  };
}

function parseEntry(value: unknown): EntryData {
  if (typeof value !== 'object' || value === null) {
    throw new CryptoFormatError('Invalid entry.');
  }
  const record = value as Record<string, unknown>;
  const { v, site, username, password, category, notes, includeSubdomains } = record;
  if (
    v !== ENTRY_FORMAT ||
    typeof site !== 'string' ||
    typeof username !== 'string' ||
    typeof password !== 'string' ||
    typeof notes !== 'string' ||
    typeof includeSubdomains !== 'boolean' ||
    !ENTRY_CATEGORIES.includes(category as EntryCategory)
  ) {
    throw new CryptoFormatError('Invalid entry.');
  }
  return { site, username, password, category: category as EntryCategory, notes, includeSubdomains };
}

function associatedData(uuid: string): string {
  return `pm-entry-v1:${uuid}`;
}

function assertUuid(uuid: string): void {
  if (!isEntryUuid(uuid)) {
    throw new CryptoFormatError('Invalid entry UUID.');
  }
}
