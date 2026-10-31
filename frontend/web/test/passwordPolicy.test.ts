import { describe, expect, it } from 'vitest';

import { estimateStrength, isCommonPassword, masterPasswordProblems } from '../src/passwordPolicy';

describe('master password policy', () => {
  it('accepts a long passphrase', () => {
    expect(masterPasswordProblems('violet-tramway-cerf-volant-42', 'owner@example.com')).toEqual([]);
  });

  it('requires 12 characters, counted as characters and not bytes', () => {
    expect(masterPasswordProblems('Kz8#pQ2!vL', 'owner@example.com')).toContain('Au moins 12 caractères.');
    expect(masterPasswordProblems('éééééééééééé'.slice(0, 11) + 'x', 'a@b.c')).not.toContain('Au moins 12 caractères.');
  });

  it('refuses common passwords, also with digits or symbols around them', () => {
    expect(isCommonPassword('password')).toBe(true);
    expect(isCommonPassword('Password1234!')).toBe(true);
    expect(isCommonPassword('12345678')).toBe(true);
    expect(isCommonPassword('violet-tramway-cerf-volant')).toBe(false);
    expect(masterPasswordProblems('qwertyuiop12', 'a@b.c')).toContain('Ce mot de passe fait partie des plus courants.');
  });

  it('refuses a password containing the email address', () => {
    expect(masterPasswordProblems('christopher-is-great-2026', 'christopher@example.com')).toContain(
      'Le mot de passe ne doit pas contenir votre adresse email.',
    );
  });

  it('refuses digit-only passwords', () => {
    expect(masterPasswordProblems('123456789012', 'a@b.c')).toContain('Uniquement des chiffres : trop facile à deviner.');
  });

  it('refuses a single repeated character', () => {
    expect(masterPasswordProblems('aaaaaaaaaaaaaaaa', 'a@b.c')).toContain('Un seul caractère répété.');
  });
});

describe('strength estimate', () => {
  it('grows with length and character classes', () => {
    expect(estimateStrength('').score).toBe(0);
    expect(estimateStrength('abc').score).toBe(0);
    expect(estimateStrength('violet-tramway-cerf-volant-42').score).toBe(4);
    expect(estimateStrength('Kz8#pQ2!vL4@').score).toBeGreaterThanOrEqual(3);
  });

  it('stays very weak for common passwords and repetitions', () => {
    expect(estimateStrength('Password1234').score).toBe(0);
    expect(estimateStrength('aaaaaaaaaaaaaaaaaaaaaaaa').score).toBe(0);
  });
});
