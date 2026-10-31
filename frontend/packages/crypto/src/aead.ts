// Authenticated encryption format: base64(0x01 ‖ iv[12] ‖ ciphertext ‖ tag[16]) with AES-256-GCM.
import { base64ToBytes, bytesToBase64, concatBytes, CryptoFormatError, DecryptionError, randomBytes, utf8Encode } from './encoding';

const FORMAT_VERSION = 0x01;
const IV_LENGTH = 12;
const TAG_LENGTH = 16;

export async function seal(key: CryptoKey, plaintext: Uint8Array<ArrayBuffer>, associatedData: string): Promise<string> {
  const iv = randomBytes(IV_LENGTH);
  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv, additionalData: utf8Encode(associatedData), tagLength: TAG_LENGTH * 8 },
      key,
      plaintext,
    ),
  );
  return bytesToBase64(concatBytes(Uint8Array.of(FORMAT_VERSION), iv, ciphertext));
}

export async function open(key: CryptoKey, sealed: string, associatedData: string): Promise<Uint8Array<ArrayBuffer>> {
  const bytes = base64ToBytes(sealed);
  if (bytes.length < 1 + IV_LENGTH + TAG_LENGTH || bytes[0] !== FORMAT_VERSION) {
    throw new CryptoFormatError('Unsupported encrypted data format.');
  }
  try {
    return new Uint8Array(
      await crypto.subtle.decrypt(
        {
          name: 'AES-GCM',
          iv: bytes.slice(1, 1 + IV_LENGTH),
          additionalData: utf8Encode(associatedData),
          tagLength: TAG_LENGTH * 8,
        },
        key,
        bytes.slice(1 + IV_LENGTH),
      ),
    );
  } catch {
    throw new DecryptionError();
  }
}
