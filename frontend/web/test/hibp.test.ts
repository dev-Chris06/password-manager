import { describe, expect, it } from 'vitest';

import { findBreachedEntries, HIBP_RANGE_URL, sha1Hex } from '../src/hibp';
import type { VaultItem } from '../src/vaultStore';

const item = (site: string, password: string): VaultItem => ({
  uuid: crypto.randomUUID(),
  version: 1,
  updatedAt: '2026-10-31T00:00:00+00:00',
  data: { site, username: 'alice', password, category: 'other', notes: '', includeSubdomains: false },
});

describe('Have I Been Pwned check', () => {
  it('computes SHA-1 like the service', async () => {
    expect(await sha1Hex('password')).toBe('5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8');
  });

  it('sends only a 5-character prefix and compares the suffixes locally', async () => {
    const requested: string[] = [];
    const fakeFetch = (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const url = input instanceof Request ? input.url : input.toString();
      requested.push(url);
      expect(new Headers(init?.headers).get('Add-Padding')).toBe('true');
      const body = url.endsWith('5BAA6')
        ? '1E4C9B93F3F0682250B6CF8331B7EE68FD8:9659365\r\n0000000000000000000000000000000000A:0\r\n'
        : 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:0\r\n';
      return Promise.resolve(new Response(body));
    };

    const breached = await findBreachedEntries(
      [item('weak.example', 'password'), item('strong.example', 'violet-tramway-cerf-volant-42'), item('weak-too.example', 'password')],
      fakeFetch,
    );

    expect(breached).toEqual([
      { site: 'weak.example', username: 'alice', count: 9659365 },
      { site: 'weak-too.example', username: 'alice', count: 9659365 },
    ]);
    // One request per distinct prefix; no full hash and no password in any URL.
    expect(requested).toHaveLength(2);
    for (const url of requested) {
      expect(url.startsWith(HIBP_RANGE_URL)).toBe(true);
      expect(url.slice(HIBP_RANGE_URL.length)).toMatch(/^[0-9A-F]{5}$/);
    }
  });

  it('ignores padding lines', async () => {
    const fakeFetch = (): Promise<Response> => Promise.resolve(new Response('1E4C9B93F3F0682250B6CF8331B7EE68FD8:0\n'));
    expect(await findBreachedEntries([item('x', 'password')], fakeFetch)).toEqual([]);
  });
});
