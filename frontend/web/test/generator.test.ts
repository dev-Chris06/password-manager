import { describe, expect, it } from 'vitest';

import { generatePassword, MAX_LENGTH, MIN_LENGTH, randomIndex } from '../src/generator';

describe('password generator', () => {
  it('honours the length and always includes every selected character class', () => {
    for (let i = 0; i < 200; i++) {
      const password = generatePassword({ length: 12, lowercase: true, uppercase: true, digits: true, symbols: true });
      expect(password).toHaveLength(12);
      expect(password).toMatch(/[a-z]/);
      expect(password).toMatch(/[A-Z]/);
      expect(password).toMatch(/[0-9]/);
      expect(password).toMatch(/[^a-zA-Z0-9]/);
    }
  });

  it('only uses the selected classes and avoids ambiguous characters', () => {
    const password = generatePassword({ length: 64, lowercase: false, uppercase: false, digits: true, symbols: false });
    expect(password).toMatch(/^[2-9]+$/);
    expect(generatePassword({ length: 64, lowercase: true, uppercase: true, digits: true, symbols: false })).not.toMatch(/[0O1lI]/);
  });

  it('clamps the length and refuses an empty selection', () => {
    expect(generatePassword({ length: 2, lowercase: true, uppercase: false, digits: false, symbols: false })).toHaveLength(MIN_LENGTH);
    expect(generatePassword({ length: 1000, lowercase: true, uppercase: false, digits: false, symbols: false })).toHaveLength(MAX_LENGTH);
    expect(() => generatePassword({ length: 20, lowercase: false, uppercase: false, digits: false, symbols: false })).toThrow(RangeError);
  });

  it('draws indexes uniformly', () => {
    const counts = new Array<number>(7).fill(0);
    const draws = 70_000;
    for (let i = 0; i < draws; i++) {
      const index = randomIndex(7);
      counts[index] = (counts[index] ?? 0) + 1;
    }
    for (const count of counts) {
      expect(Math.abs(count - draws / 7)).toBeLessThan(draws / 7 * 0.05);
    }
  });

  it('does not repeat itself', () => {
    const passwords = new Set(Array.from({ length: 1000 }, () => generatePassword()));
    expect(passwords.size).toBe(1000);
  });
});
