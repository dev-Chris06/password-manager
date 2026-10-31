// Minimal TOTP generator (RFC 6238, SHA-1, 6 digits), standing in for an authenticator app.
import { createHmac } from 'node:crypto';

function base32Decode(input: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const clean = input.replace(/[\s=]/g, '').toUpperCase();
  let bits = '';
  for (const char of clean) {
    const value = alphabet.indexOf(char);
    if (value < 0) {
      throw new Error('Invalid base32.');
    }
    bits += value.toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) {
    bytes.push(Number.parseInt(bits.slice(i, i + 8), 2));
  }
  return Buffer.from(bytes);
}

/** Code for the given 30-second step offset from now (0 = current period, 1 = next one). */
export function totp(secret: string, stepOffset = 0): string {
  const counter = Math.floor(Date.now() / 1000 / 30) + stepOffset;
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(counter));
  const digest = createHmac('sha1', base32Decode(secret)).update(message).digest();
  const offset = (digest[digest.length - 1] ?? 0) & 0x0f;
  const binary = ((digest[offset] ?? 0) & 0x7f) << 24 | (digest[offset + 1] ?? 0) << 16 | (digest[offset + 2] ?? 0) << 8 | (digest[offset + 3] ?? 0);
  return String(binary % 1_000_000).padStart(6, '0');
}
