// HKDF-SHA256 (RFC 5869) through WebCrypto.

export async function hkdfSha256(
  ikm: Uint8Array<ArrayBuffer>,
  salt: Uint8Array<ArrayBuffer>,
  info: string | Uint8Array<ArrayBuffer>,
  lengthBytes: number,
): Promise<Uint8Array<ArrayBuffer>> {
  const key = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
  const infoBytes = typeof info === 'string' ? new TextEncoder().encode(info) : info;
  const bits = await crypto.subtle.deriveBits(
    { name: 'HKDF', hash: 'SHA-256', salt, info: infoBytes },
    key,
    lengthBytes * 8,
  );
  return new Uint8Array(bits);
}
