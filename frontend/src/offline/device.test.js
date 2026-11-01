import { beforeEach, describe, expect, it } from 'vitest';
import { getOrCreateDeviceId } from './device';

describe('getOrCreateDeviceId', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('creates a device id once and returns the same one on every later call', () => {
    const first = getOrCreateDeviceId();
    const second = getOrCreateDeviceId();

    expect(first).toBe(second);
    expect(window.localStorage.getItem('pos.device.id')).toBe(first);
  });

  it('different devices (no shared storage) get different ids', () => {
    const first = getOrCreateDeviceId();
    window.localStorage.clear();
    const second = getOrCreateDeviceId();

    expect(first).not.toBe(second);
  });
});
