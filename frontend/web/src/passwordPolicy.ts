// Master password rules, checked on the client: the server never sees the password (ADR-001).
import commonPasswordsText from '../data/common-passwords.txt';

export const MIN_MASTER_PASSWORD_LENGTH = 12;

let commonPasswords: Set<string> | null = null;

function common(): Set<string> {
  commonPasswords ??= new Set(commonPasswordsText.split('\n').filter((line) => line !== ''));
  return commonPasswords;
}

/** True when the password, or its alphabetic core, is one of the 10,000 most common passwords. */
export function isCommonPassword(password: string): boolean {
  const lower = password.toLowerCase();
  const core = lower.replace(/[^a-z]+$/u, '').replace(/^[^a-z]+/u, '');
  return common().has(lower) || (core.length >= 4 && common().has(core));
}

/** Returns the reasons (in French, for the interface) why a master password is refused. */
export function masterPasswordProblems(password: string, email: string): string[] {
  const problems: string[] = [];
  if (Array.from(password).length < MIN_MASTER_PASSWORD_LENGTH) {
    problems.push(`Au moins ${String(MIN_MASTER_PASSWORD_LENGTH)} caractères.`);
  }
  if (isCommonPassword(password)) {
    problems.push('Ce mot de passe fait partie des plus courants.');
  }
  const localPart = email.split('@')[0]?.toLowerCase() ?? '';
  if (localPart.length >= 4 && password.toLowerCase().includes(localPart)) {
    problems.push('Le mot de passe ne doit pas contenir votre adresse email.');
  }
  if (/^(.)\1+$/u.test(password)) {
    problems.push('Un seul caractère répété.');
  }
  if (/^[0-9\s]+$/u.test(password)) {
    problems.push('Uniquement des chiffres : trop facile à deviner.');
  }
  return problems;
}

export interface Strength {
  /** 0 (very weak) to 4 (very strong). */
  readonly score: 0 | 1 | 2 | 3 | 4;
  readonly label: string;
  /** Rough entropy estimate in bits, assuming random characters from the classes used. */
  readonly bits: number;
}

const LABELS = ['Très faible', 'Faible', 'Moyen', 'Fort', 'Très fort'] as const;

export function estimateStrength(password: string): Strength {
  if (password === '') {
    return { score: 0, label: LABELS[0], bits: 0 };
  }
  let pool = 0;
  if (/[a-z]/u.test(password)) pool += 26;
  if (/[A-Z]/u.test(password)) pool += 26;
  if (/[0-9]/u.test(password)) pool += 10;
  if (/[^a-zA-Z0-9]/u.test(password)) pool += 33;
  const distinct = new Set(password).size;
  const length = Array.from(password).length;
  // Repeated characters add little: weight the length by the share of distinct characters.
  const effectiveLength = Math.min(length, distinct * 2);
  let bits = Math.round(effectiveLength * Math.log2(Math.max(pool, 2)));
  if (isCommonPassword(password)) {
    bits = Math.min(bits, 14);
  }
  const score = bits < 28 ? 0 : bits < 45 ? 1 : bits < 60 ? 2 : bits < 80 ? 3 : 4;
  return { score, label: LABELS[score], bits };
}
