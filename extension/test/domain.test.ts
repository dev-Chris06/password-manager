import { describe, expect, it } from 'vitest';

import { entryMatchesHost, isFillablePage, normalizeServerUrl, siteHost } from '../src/domain';
import { isPopupRequest } from '../src/messages';

describe('site host', () => {
  it('extracts the host from what users type', () => {
    expect(siteHost('example.com')).toBe('example.com');
    expect(siteHost('https://www.Example.com/login?next=/')).toBe('example.com');
    expect(siteHost('accounts.example.com')).toBe('accounts.example.com');
    expect(siteHost('  ')).toBeNull();
  });
});

describe('entry matching', () => {
  it('matches the exact host, with or without www', () => {
    expect(entryMatchesHost('example.com', false, 'example.com')).toBe(true);
    expect(entryMatchesHost('example.com', false, 'www.example.com')).toBe(true);
    expect(entryMatchesHost('https://www.example.com', false, 'example.com')).toBe(true);
  });

  it('only matches subdomains when the entry allows it', () => {
    expect(entryMatchesHost('example.com', false, 'login.example.com')).toBe(false);
    expect(entryMatchesHost('example.com', true, 'login.example.com')).toBe(true);
  });

  it('never matches look-alike or parent domains', () => {
    expect(entryMatchesHost('example.com', true, 'example.com.evil.net')).toBe(false);
    expect(entryMatchesHost('example.com', true, 'notexample.com')).toBe(false);
    expect(entryMatchesHost('login.example.com', true, 'example.com')).toBe(false);
    expect(entryMatchesHost('com', true, 'example.com')).toBe(false);
  });
});

describe('fillable pages and server address', () => {
  it('only fills HTTPS pages, or local development pages', () => {
    expect(isFillablePage('https://example.com/login')).toBe(true);
    expect(isFillablePage('http://example.com/login')).toBe(false);
    expect(isFillablePage('http://localhost:8000/')).toBe(true);
    expect(isFillablePage('chrome://settings')).toBe(false);
    expect(isFillablePage('file:///etc/passwd')).toBe(false);
  });

  it('accepts an HTTPS server or a local one', () => {
    expect(normalizeServerUrl('https://coffre.exemple.fr/some/path')).toBe('https://coffre.exemple.fr');
    expect(normalizeServerUrl('http://127.0.0.1:8000')).toBe('http://127.0.0.1:8000');
    expect(normalizeServerUrl('http://coffre.exemple.fr')).toBeNull();
    expect(normalizeServerUrl('not a url')).toBeNull();
  });
});

describe('popup messages', () => {
  it('only accepts known message types', () => {
    expect(isPopupRequest({ type: 'status' })).toBe(true);
    expect(isPopupRequest({ type: 'fill', tabId: 1, uuid: 'x' })).toBe(true);
    expect(isPopupRequest({ type: 'export-vault' })).toBe(false);
    expect(isPopupRequest('status')).toBe(false);
    expect(isPopupRequest(null)).toBe(false);
  });
});
