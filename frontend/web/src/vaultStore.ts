// Decrypted vault held in memory only while unlocked. Every change is encrypted before it is sent.
import {
  decryptEntry,
  encryptEntry,
  type EntryData,
  newEntryUuid,
  normalizeEntry,
} from '../../packages/crypto/src';
import { ApiError, type ApiClient } from './api';
import type { EncryptedEntry, VaultResponse } from './auth';

const IMPORT_BATCH_SIZE = 200;

export interface VaultItem {
  readonly uuid: string;
  readonly data: EntryData;
  readonly version: number;
  readonly updatedAt: string;
}

export class VersionConflictError extends Error {
  override name = 'VersionConflictError';
}

export class VaultStore {
  private items = new Map<string, VaultItem>();
  /** Entries that could not be decrypted (tampered or foreign data): kept apart, never shown. */
  private unreadable: string[] = [];

  constructor(
    private readonly api: ApiClient,
    private readonly vaultKey: CryptoKey,
  ) {}

  async load(vault: VaultResponse): Promise<void> {
    const items = new Map<string, VaultItem>();
    const unreadable: string[] = [];
    for (const entry of vault.entries) {
      try {
        items.set(entry.uuid, await this.decrypt(entry));
      } catch {
        unreadable.push(entry.uuid);
      }
    }
    this.items = items;
    this.unreadable = unreadable;
  }

  async reload(): Promise<void> {
    await this.load(await this.api.get<VaultResponse>('/api/vault'));
  }

  list(): VaultItem[] {
    return [...this.items.values()].sort((a, b) => a.data.site.localeCompare(b.data.site, 'fr', { sensitivity: 'base' }));
  }

  get(uuid: string): VaultItem | undefined {
    return this.items.get(uuid);
  }

  unreadableCount(): number {
    return this.unreadable.length;
  }

  /** Sites (other than `exceptUuid`) where this password is already used. */
  reusedOn(password: string, exceptUuid?: string): string[] {
    if (password === '') {
      return [];
    }
    return this.list()
      .filter((item) => item.uuid !== exceptUuid && item.data.password === password)
      .map((item) => item.data.site);
  }

  async add(data: EntryData): Promise<VaultItem> {
    const uuid = newEntryUuid();
    const normalized = normalizeEntry(data);
    const ciphertext = await encryptEntry(this.vaultKey, uuid, normalized);
    const summary = await this.api.post<{ version: number; updatedAt: string }>('/api/entries', { uuid, ciphertext });
    const item = { uuid, data: normalized, version: summary.version, updatedAt: summary.updatedAt };
    this.items.set(uuid, item);
    return item;
  }

  async update(uuid: string, data: EntryData): Promise<VaultItem> {
    const current = this.items.get(uuid);
    if (current === undefined) {
      throw new VersionConflictError();
    }
    const normalized = normalizeEntry(data);
    const ciphertext = await encryptEntry(this.vaultKey, uuid, normalized);
    try {
      const summary = await this.api.put<{ version: number; updatedAt: string }>(`/api/entries/${uuid}`, {
        ciphertext,
        version: current.version,
      });
      const item = { uuid, data: normalized, version: summary.version, updatedAt: summary.updatedAt };
      this.items.set(uuid, item);
      return item;
    } catch (error) {
      if (error instanceof ApiError && (error.code === 'version_conflict' || error.status === 404)) {
        await this.reload();
        throw new VersionConflictError();
      }
      throw error;
    }
  }

  async remove(uuid: string): Promise<void> {
    try {
      await this.api.delete(`/api/entries/${uuid}`);
    } catch (error) {
      if (!(error instanceof ApiError && error.status === 404)) {
        throw error;
      }
    }
    this.items.delete(uuid);
  }

  /**
   * Adds entries coming from a backup: re-encrypted with the current vault key under new UUIDs,
   * sent in batches. Entries with the same site and username as an existing one are skipped.
   */
  async importEntries(entries: readonly EntryData[]): Promise<{ imported: number; skipped: number }> {
    const key = (data: EntryData): string => `${data.site.toLowerCase()}\u0000${data.username.toLowerCase()}`;
    const known = new Set(this.list().map((item) => key(item.data)));
    const pending: { uuid: string; ciphertext: string }[] = [];
    let skipped = 0;
    for (const data of entries) {
      const normalized = normalizeEntry(data);
      if (known.has(key(normalized))) {
        skipped++;
        continue;
      }
      known.add(key(normalized));
      const uuid = newEntryUuid();
      pending.push({ uuid, ciphertext: await encryptEntry(this.vaultKey, uuid, normalized) });
    }
    for (let i = 0; i < pending.length; i += IMPORT_BATCH_SIZE) {
      await this.api.post('/api/entries/batch', { entries: pending.slice(i, i + IMPORT_BATCH_SIZE) });
    }
    await this.reload();
    return { imported: pending.length, skipped };
  }

  /** Forgets every decrypted value (lock, logout). */
  clear(): void {
    this.items.clear();
    this.unreadable = [];
  }

  private async decrypt(entry: EncryptedEntry): Promise<VaultItem> {
    return {
      uuid: entry.uuid,
      data: await decryptEntry(this.vaultKey, entry.uuid, entry.ciphertext),
      version: entry.version,
      updatedAt: entry.updatedAt,
    };
  }
}
