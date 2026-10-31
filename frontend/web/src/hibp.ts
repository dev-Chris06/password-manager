// Have I Been Pwned "Pwned Passwords" with k-anonymity: only the first 5 characters of the
// SHA-1 of a password leave the browser; the comparison happens locally (ADR-006).
import type { VaultItem } from './vaultStore';

export const HIBP_RANGE_URL = 'https://api.pwnedpasswords.com/range/';

export interface BreachedEntry {
  readonly site: string;
  readonly username: string;
  /** Number of times the password appears in known breaches. */
  readonly count: number;
}

export async function sha1Hex(text: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(text));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('').toUpperCase();
}

export async function findBreachedEntries(items: readonly VaultItem[], fetchImpl: typeof fetch = fetch): Promise<BreachedEntry[]> {
  const ranges = new Map<string, Map<string, number>>();
  const counts = new Map<string, number>();

  for (const password of new Set(items.map((item) => item.data.password))) {
    if (password === '') {
      continue;
    }
    const hash = await sha1Hex(password);
    const prefix = hash.slice(0, 5);
    let range = ranges.get(prefix);
    if (range === undefined) {
      // Padding hides the real number of matching suffixes from network observers.
      const response = await fetchImpl(HIBP_RANGE_URL + prefix, { headers: { 'Add-Padding': 'true' }, credentials: 'omit', referrerPolicy: 'no-referrer' });
      if (!response.ok) {
        throw new Error(`HIBP answered ${String(response.status)}.`);
      }
      range = parseRange(await response.text());
      ranges.set(prefix, range);
    }
    counts.set(password, range.get(hash.slice(5)) ?? 0);
  }

  return items
    .filter((item) => (counts.get(item.data.password) ?? 0) > 0)
    .map((item) => ({ site: item.data.site, username: item.data.username, count: counts.get(item.data.password) ?? 0 }));
}

function parseRange(body: string): Map<string, number> {
  const range = new Map<string, number>();
  for (const line of body.split('\n')) {
    const [suffix, count] = line.trim().split(':');
    const value = Number.parseInt(count ?? '', 10);
    // Padding lines have a count of 0.
    if (suffix !== undefined && suffix.length === 35 && value > 0) {
      range.set(suffix.toUpperCase(), value);
    }
  }
  return range;
}
