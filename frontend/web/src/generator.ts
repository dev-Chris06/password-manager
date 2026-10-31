// Password generator: uniform choice through rejection sampling on crypto.getRandomValues.

export interface GeneratorOptions {
  readonly length: number;
  readonly lowercase: boolean;
  readonly uppercase: boolean;
  readonly digits: boolean;
  readonly symbols: boolean;
}

export const DEFAULT_GENERATOR_OPTIONS: GeneratorOptions = {
  length: 20,
  lowercase: true,
  uppercase: true,
  digits: true,
  symbols: true,
};

// Ambiguous characters (0/O, 1/l/I) are left out to ease reading and typing.
const SETS = {
  lowercase: 'abcdefghijkmnopqrstuvwxyz',
  uppercase: 'ABCDEFGHJKLMNPQRSTUVWXYZ',
  digits: '23456789',
  symbols: '!#$%&*+-=?@^_~',
} as const;

export const MIN_LENGTH = 8;
export const MAX_LENGTH = 128;

/** Uniform random integer in [0, max). */
export function randomIndex(max: number): number {
  const limit = Math.floor(0x1_0000_0000 / max) * max;
  const buffer = new Uint32Array(1);
  for (;;) {
    crypto.getRandomValues(buffer);
    const value = buffer[0] ?? 0;
    if (value < limit) {
      return value % max;
    }
  }
}

export function generatePassword(options: GeneratorOptions = DEFAULT_GENERATOR_OPTIONS): string {
  const sets = (Object.keys(SETS) as (keyof typeof SETS)[]).filter((name) => options[name]).map((name) => SETS[name]);
  if (sets.length === 0) {
    throw new RangeError('At least one character set is required.');
  }
  const length = Math.min(MAX_LENGTH, Math.max(MIN_LENGTH, Math.trunc(options.length), sets.length));
  const alphabet = sets.join('');

  // One character of each selected set, the rest from the whole alphabet, then shuffled.
  const chars = sets.map((set) => set.charAt(randomIndex(set.length)));
  while (chars.length < length) {
    chars.push(alphabet.charAt(randomIndex(alphabet.length)));
  }
  for (let i = chars.length - 1; i > 0; i--) {
    const j = randomIndex(i + 1);
    [chars[i], chars[j]] = [chars[j] ?? '', chars[i] ?? ''];
  }
  return chars.join('');
}
