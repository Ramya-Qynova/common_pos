import { computeBackoffDelayMs, getPending, markFailed, markSynced } from './outbox';

/**
 * section 9 "Sync push": flushes the outbox against `POST /sync/push` in
 * one batch, per-operation, and applies each result. Section 9 "Connection
 * badge (Online / Offline / Syncing / Sync Error) with pending count on
 * every screen" - `onStatusChange` is how a screen subscribes to that
 * without this module knowing anything about UI.
 */
export class SyncClient {
  /**
   * @param {import('dexie').Dexie} db
   * @param {(operations: object[]) => Promise<{results: object[]}>} pushFn
   * @param {(status: {state: 'idle'|'syncing'|'error', pending: number}) => void} [onStatusChange]
   * @param {{baseMs?: number, maxMs?: number}} [backoff] - overridable only for tests; production uses the real defaults.
   */
  constructor(db, pushFn, onStatusChange, backoff = {}) {
    this.db = db;
    this.pushFn = pushFn;
    this.onStatusChange = onStatusChange ?? (() => {});
    this.retryTimer = null;
    this.backoffBaseMs = backoff.baseMs ?? 1000;
    this.backoffMaxMs = backoff.maxMs ?? 60000;
  }

  async flush() {
    const pending = await getPending(this.db);
    if (pending.length === 0) {
      this.onStatusChange({ state: 'idle', pending: 0 });
      return { synced: 0, failed: 0 };
    }

    this.onStatusChange({ state: 'syncing', pending: pending.length });

    let response;
    try {
      response = await this.pushFn(pending.map(toEnvelope));
    } catch (networkError) {
      // Transient (offline, timeout, 5xx) - nothing here is terminal, just
      // scheduled for another attempt with backoff. The outbox rows are
      // untouched - still visible as pending.
      this.onStatusChange({ state: 'error', pending: pending.length });
      this.scheduleRetry(pending[0].attempts ?? 0);

      return { synced: 0, failed: pending.length, networkError };
    }

    let synced = 0;
    let failed = 0;
    for (const result of response.results) {
      if (result.status === 'accepted') {
        await markSynced(this.db, result.operation_uuid, result.result);
        synced++;
      } else {
        // rejected -> terminal (stop retrying, keep visible); held -> not
        // terminal (tenant may be unsuspended later, worth retrying).
        await markFailed(
          this.db,
          result.operation_uuid,
          result.message ?? result.reject_reason,
          result.status === 'rejected',
        );
        failed++;
      }
    }

    this.onStatusChange({ state: 'idle', pending: failed });

    return { synced, failed };
  }

  scheduleRetry(attempt) {
    if (this.retryTimer) {
      clearTimeout(this.retryTimer);
    }
    this.retryTimer = setTimeout(
      () => this.flush(),
      computeBackoffDelayMs(attempt, this.backoffBaseMs, this.backoffMaxMs),
    );
  }

  stop() {
    if (this.retryTimer) {
      clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
  }
}

function toEnvelope(row) {
  return {
    operation_uuid: row.operation_uuid,
    type: row.type,
    device_id: row.device_id,
    user_id: row.user_id,
    client_created_at: row.client_created_at,
    payload: row.payload,
  };
}
