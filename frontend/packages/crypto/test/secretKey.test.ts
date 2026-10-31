import { describe, expect, it } from 'vitest';

import { formatSecretKey, generateSecretKey, InvalidSecretKeyError, parseSecretKey } from '../src/secretKey';

describe('Secret Key', () => {
  it('round-trips through its text form', () => {
    for (let i = 0; i < 50; i++) {
      const key = generateSecretKey();
      const text = formatSecretKey(key);
      expect(text).toMatch(/^A1-[0-9A-Z]{6}-[0-9A-Z]{6}-[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{4}[0-9A-Z*~$=]$/);
      expect(parseSecretKey(text)).toEqual(key);
    }
  });

  it('accepts lowercase, spaces and the usual Crockford confusions', () => {
    const key = new Uint8Array(16).fill(0);
    const text = formatSecretKey(key);
    const sloppy = text.toLowerCase().replace(/-/g, ' ').replace(/0/g, 'o');
    expect(parseSecretKey(sloppy)).toEqual(key);
  });

  it('detects a mistyped digit with the check symbol', () => {
    const text = formatSecretKey(generateSecretKey());
    const position = 5;
    const replacement = text.charAt(position) === '7' ? '8' : '7';
    const typo = text.slice(0, position) + replacement + text.slice(position + 1);
    expect(() => parseSecretKey(typo)).toThrow(InvalidSecretKeyError);
  });

  it('rejects a wrong version, length or character', () => {
    const text = formatSecretKey(generateSecretKey());
    expect(() => parseSecretKey(text.replace('A1', 'A2'))).toThrow(InvalidSecretKeyError);
    expect(() => parseSecretKey(text.slice(0, -2))).toThrow(InvalidSecretKeyError);
    expect(() => parseSecretKey(text.slice(0, -2) + 'U' + text.slice(-1))).toThrow(InvalidSecretKeyError);
  });
});
