// Argon2id key derivation and its parameter floors (docs/PLAN.md §4.2, ADR-003).
import { argon2id } from 'hash-wasm';

import { CryptoFormatError, randomBytes } from './encoding';

export interface KdfParameters {
  readonly algorithm: 'argon2id';
  readonly memoryKib: number;
  readonly iterations: number;
  readonly parallelism: number;
}

// Same values as App\Model\KdfParameters on the server.
export const KDF_FLOOR = { memoryKib: 65_536, iterations: 3, parallelism: 1 } as const;
export const KDF_CEILING = { memoryKib: 1_048_576, iterations: 64, parallelism: 16 } as const;

export const DEFAULT_KDF: KdfParameters = {
  algorithm: 'argon2id',
  memoryKib: KDF_FLOOR.memoryKib,
  iterations: KDF_FLOOR.iterations,
  parallelism: KDF_FLOOR.parallelism,
};

export const SALT_LENGTH = 16;

export function generateSalt(): Uint8Array<ArrayBuffer> {
  return randomBytes(SALT_LENGTH);
}

export class WeakKdfParametersError extends Error {
  override name = 'WeakKdfParametersError';
}

/**
 * Refuses parameters below the floor (a malicious server could lower them to get a cheaply
 * crackable proof) or above the ceiling (a hostile value could freeze the client).
 */
export function assertKdfParameters(params: unknown): asserts params is KdfParameters {
  if (typeof params !== 'object' || params === null) {
    throw new WeakKdfParametersError('Missing KDF parameters.');
  }
  const { algorithm, memoryKib, iterations, parallelism } = params as Record<string, unknown>;
  const inRange = (value: unknown, min: number, max: number): boolean =>
    typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max;

  if (
    algorithm !== 'argon2id' ||
    !inRange(memoryKib, KDF_FLOOR.memoryKib, KDF_CEILING.memoryKib) ||
    !inRange(iterations, KDF_FLOOR.iterations, KDF_CEILING.iterations) ||
    !inRange(parallelism, KDF_FLOOR.parallelism, KDF_CEILING.parallelism)
  ) {
    throw new WeakKdfParametersError('KDF parameters are outside the accepted range.');
  }
}

/**
 * Returns true when `next` is weaker than parameters seen before for the same account
 * (used by the extension to alert on a downgrade).
 */
export function isKdfDowngrade(previous: KdfParameters, next: KdfParameters): boolean {
  return next.memoryKib < previous.memoryKib || next.iterations < previous.iterations;
}

/** Raw Argon2id, without the parameter floor. Only for test vectors and internal use. */
export async function argon2idRaw(
  password: Uint8Array,
  salt: Uint8Array,
  params: { memoryKib: number; iterations: number; parallelism: number; hashLength: number },
): Promise<Uint8Array<ArrayBuffer>> {
  const output = await argon2id({
    password,
    salt,
    parallelism: params.parallelism,
    iterations: params.iterations,
    memorySize: params.memoryKib,
    hashLength: params.hashLength,
    outputType: 'binary',
  });
  return new Uint8Array(output);
}

export async function deriveArgon2id(
  password: Uint8Array,
  salt: Uint8Array,
  params: KdfParameters,
): Promise<Uint8Array<ArrayBuffer>> {
  assertKdfParameters(params);
  if (salt.length !== SALT_LENGTH) {
    throw new CryptoFormatError('The KDF salt must be 16 bytes long.');
  }
  return argon2idRaw(password, salt, { ...params, hashLength: 32 });
}
