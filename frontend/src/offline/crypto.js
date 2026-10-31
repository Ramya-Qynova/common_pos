/**
 * section 9: "Encrypt sensitive fields with a device key (Web Crypto)."
 * AES-GCM, a fresh random IV per field (never reused - GCM's security
 * depends on that). The device key itself lives only in this device's
 * own IndexedDB (`sync_state`), never sent to the server and never
 * derived from anything server-side - losing it means losing access to
 * locally-encrypted data, which is correct: a wiped/revoked device
 * (section 9 "wipe local data at next contact") should not be able to
 * read what it cached before revocation either.
 */

export async function generateDeviceKey() {
  const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, [
    'encrypt',
    'decrypt',
  ]);
  const raw = await crypto.subtle.exportKey('raw', key);

  return arrayBufferToBase64(raw);
}

async function importKey(base64Key) {
  const raw = base64ToArrayBuffer(base64Key);

  return crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

/**
 * @returns {Promise<{iv: string, ciphertext: string}>}
 */
export async function encryptField(base64Key, plaintext) {
  const key = await importKey(base64Key);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const encoded = new TextEncoder().encode(plaintext);

  const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, encoded);

  return { iv: arrayBufferToBase64(iv), ciphertext: arrayBufferToBase64(ciphertext) };
}

export async function decryptField(base64Key, encrypted) {
  const key = await importKey(base64Key);
  const iv = base64ToArrayBuffer(encrypted.iv);
  const ciphertext = base64ToArrayBuffer(encrypted.ciphertext);

  const plaintext = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);

  return new TextDecoder().decode(plaintext);
}

function arrayBufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary);
}

function base64ToArrayBuffer(base64) {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }

  return bytes.buffer;
}
