// Secret Key: 128 random bits generated on the device and never sent to the server (ADR-004).
//
// Text form: "A1-XXXXXX-XXXXXX-XXXXX-XXXXX-XXXXX" — version prefix, 26 Crockford base32 digits
// and one Crockford check symbol (value mod 37), grouped for readability.
import { randomBytes } from './encoding';

export const SECRET_KEY_LENGTH = 16;
export const SECRET_KEY_VERSION = 'A1';

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const CHECK_SYMBOLS = ALPHABET + '*~$=U';
const DATA_DIGITS = 26;
const GROUPS = [6, 6, 5, 5, 5];

export class InvalidSecretKeyError extends Error {
  override name = 'InvalidSecretKeyError';

  constructor() {
    super('Invalid Secret Key.');
  }
}

export function generateSecretKey(): Uint8Array<ArrayBuffer> {
  return randomBytes(SECRET_KEY_LENGTH);
}

export function formatSecretKey(bytes: Uint8Array): string {
  if (bytes.length !== SECRET_KEY_LENGTH) {
    throw new InvalidSecretKeyError();
  }
  const value = bytesToBigInt(bytes);
  let digits = '';
  let rest = value;
  for (let i = 0; i < DATA_DIGITS; i++) {
    digits = ALPHABET.charAt(Number(rest % 32n)) + digits;
    rest /= 32n;
  }
  const symbols = digits + CHECK_SYMBOLS.charAt(Number(value % 37n));

  const groups: string[] = [];
  let offset = 0;
  for (const size of GROUPS) {
    groups.push(symbols.slice(offset, offset + size));
    offset += size;
  }
  return [SECRET_KEY_VERSION, ...groups].join('-');
}

export function parseSecretKey(text: string): Uint8Array<ArrayBuffer> {
  const compact = text.toUpperCase().replace(/[\s-]/g, '');
  if (!compact.startsWith(SECRET_KEY_VERSION) || compact.length !== SECRET_KEY_VERSION.length + DATA_DIGITS + 1) {
    throw new InvalidSecretKeyError();
  }
  // Crockford decoding tolerates the usual confusions (O for 0, I and L for 1).
  const symbols = compact.slice(SECRET_KEY_VERSION.length).replace(/O/g, '0').replace(/[IL]/g, '1');
  const data = symbols.slice(0, -1);
  const check = symbols.slice(-1);

  let value = 0n;
  for (const char of data) {
    const digit = ALPHABET.indexOf(char);
    if (digit < 0) {
      throw new InvalidSecretKeyError();
    }
    value = value * 32n + BigInt(digit);
  }
  if (value >> 128n !== 0n || CHECK_SYMBOLS.charAt(Number(value % 37n)) !== check) {
    throw new InvalidSecretKeyError();
  }
  return bigIntToBytes(value);
}

function bytesToBigInt(bytes: Uint8Array): bigint {
  let value = 0n;
  for (const byte of bytes) {
    value = (value << 8n) | BigInt(byte);
  }
  return value;
}

function bigIntToBytes(value: bigint): Uint8Array<ArrayBuffer> {
  const bytes = new Uint8Array(SECRET_KEY_LENGTH);
  let rest = value;
  for (let i = SECRET_KEY_LENGTH - 1; i >= 0; i--) {
    bytes[i] = Number(rest & 0xffn);
    rest >>= 8n;
  }
  return bytes;
}
