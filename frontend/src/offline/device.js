/**
 * section 9's whole offline model is device-bound (device-scoped offline
 * numbering, per-device revocation, a device key for field encryption) -
 * every one of those needs the SAME device id on every visit, so it is
 * generated once and persisted in localStorage rather than per-session.
 */
const STORAGE_KEY = 'pos.device.id';

export function getOrCreateDeviceId() {
  try {
    const existing = window.localStorage.getItem(STORAGE_KEY);
    if (existing) {
      return existing;
    }
    const created = crypto.randomUUID();
    window.localStorage.setItem(STORAGE_KEY, created);
    return created;
  } catch {
    // Storage unavailable (private mode, etc.) - fall back to a session-only
    // id rather than throwing; offline persistence just won't survive a reload.
    return crypto.randomUUID();
  }
}
