// @vitest-environment node
/**
 * jsdom replaces ArrayBuffer/Uint8Array/etc. with its own realm's copies,
 * which crypto.subtle's native instanceof-style checks reject even though
 * the code is 100% standard Web Crypto. This module has no DOM dependency
 * at all, so it runs in the plain Node environment instead - the same
 * standard crypto.subtle API a real browser provides, just without the
 * realm mismatch jsdom introduces.
 */
import { describe, expect, it } from 'vitest';
import { decryptField, encryptField, generateDeviceKey } from './crypto';

describe('device-key encryption (section 9: "Encrypt sensitive fields with a device key")', () => {
  it('round-trips a plaintext field through encrypt then decrypt', async () => {
    const key = await generateDeviceKey();
    const encrypted = await encryptField(key, '+91 98765 43210');

    expect(encrypted.ciphertext).not.toContain('98765');

    const decrypted = await decryptField(key, encrypted);
    expect(decrypted).toBe('+91 98765 43210');
  });

  it('uses a fresh IV every time, even for the same plaintext and key', async () => {
    const key = await generateDeviceKey();
    const first = await encryptField(key, 'same value');
    const second = await encryptField(key, 'same value');

    expect(first.iv).not.toBe(second.iv);
    expect(first.ciphertext).not.toBe(second.ciphertext);
  });

  it('cannot be decrypted with the wrong device key', async () => {
    const keyA = await generateDeviceKey();
    const keyB = await generateDeviceKey();
    const encrypted = await encryptField(keyA, 'secret');

    await expect(decryptField(keyB, encrypted)).rejects.toThrow();
  });
});
