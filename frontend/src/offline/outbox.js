/**
 * section 9 "Build the POS client on the outbox abstraction from the
 * start, so online mode is the same pipeline flushed immediately." Every
 * write - online or offline - becomes an outbox row first; nothing talks
 * to the API directly. A row is removed only after the server
 * acknowledges it (G5-style: append, never silently drop). Section 13.2:
 * "outbox survives restarts and crashes" - this is just IndexedDB's own
 * durability, exercised through Dexie, not anything bespoke.
 */

/**
 * @param {import('dexie').Dexie} db
 * @param {{type: string, deviceId: string, userId: number, branchId?: number, registerId?: number, payload: object}} operation
 * @returns {Promise<object>} the stored outbox row, including its operation_uuid
 */
export async function enqueue(db, operation) {
  const row = {
    operation_uuid: crypto.randomUUID(),
    type: operation.type,
    device_id: operation.deviceId,
    user_id: operation.userId,
    branch_id: operation.branchId ?? null,
    register_id: operation.registerId ?? null,
    client_created_at: new Date().toISOString(),
    payload: operation.payload,
    status: 'pending',
    attempts: 0,
    last_error: null,
    result: null,
    created_at: Date.now(),
  };

  const localId = await db.outbox.add(row);

  return { ...row, localId };
}

/**
 * Operations are sent in the order they were created (section 9: "Operations
 * from a device are sent in order").
 */
export async function getPending(db) {
  return db.outbox.where('status').anyOf('pending', 'failed').sortBy('created_at');
}

export async function markSynced(db, operationUuid, result) {
  await db.outbox
    .where('operation_uuid')
    .equals(operationUuid)
    .modify({ status: 'synced', result, last_error: null });
}

/**
 * A rejected/held operation (section 13.2: "rejected items stay visible
 * until resolved") is NOT the same as a transient network failure - both
 * land here, but only a genuine rejection carries `terminal: true` so the
 * client stops retrying it while still showing it to the user.
 */
export async function markFailed(db, operationUuid, errorMessage, terminal = false) {
  const row = await db.outbox.where('operation_uuid').equals(operationUuid).first();
  const attempts = (row?.attempts ?? 0) + 1;

  await db.outbox
    .where('operation_uuid')
    .equals(operationUuid)
    .modify({
      status: terminal ? 'rejected' : 'failed',
      attempts,
      last_error: errorMessage,
    });
}

export async function clearSynced(db) {
  await db.outbox.where('status').equals('synced').delete();
}

/**
 * section 9 "Reliability: exponential backoff with jitter." Doubles each
 * attempt starting from `baseMs`, capped at `maxMs`, with up to 30% random
 * jitter so many devices reconnecting at once don't all retry in lockstep.
 */
export function computeBackoffDelayMs(attempt, baseMs = 1000, maxMs = 60000) {
  const exponential = Math.min(maxMs, baseMs * 2 ** Math.max(0, attempt));
  const jitter = exponential * 0.3 * Math.random();

  return Math.round(exponential + jitter);
}
