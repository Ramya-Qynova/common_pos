import { beforeEach, describe, expect, it, vi } from 'vitest';
import { openOfflineDb } from './db';
import { enqueue, getPending } from './outbox';
import { SyncClient } from './syncClient';

describe('SyncClient (section 13.2 offline gate, client side)', () => {
  let db;

  beforeEach(() => {
    db = openOfflineDb(`test_db_${crypto.randomUUID()}`);
  });

  it('reports idle with nothing pending when the outbox is empty', async () => {
    const statuses = [];
    const client = new SyncClient(
      db,
      async () => ({ results: [] }),
      (s) => statuses.push(s),
    );

    const result = await client.flush();

    expect(result).toEqual({ synced: 0, failed: 0 });
    expect(statuses).toEqual([{ state: 'idle', pending: 0 }]);
  });

  it('marks every accepted operation synced and removes it from the pending set', async () => {
    const op = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: {},
    });
    const pushFn = vi.fn().mockResolvedValue({
      results: [
        {
          operation_uuid: op.operation_uuid,
          status: 'accepted',
          result: { sale_id: 7 },
          replay: false,
        },
      ],
    });
    const client = new SyncClient(db, pushFn);

    const result = await client.flush();

    expect(result).toEqual({ synced: 1, failed: 0 });
    expect(await getPending(db)).toHaveLength(0);
    expect(pushFn).toHaveBeenCalledTimes(1);
  });

  it('a rejected operation is marked terminal and stops showing up as retryable-pending, but stays visible', async () => {
    const op = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: {},
    });
    const pushFn = vi.fn().mockResolvedValue({
      results: [
        {
          operation_uuid: op.operation_uuid,
          status: 'rejected',
          reject_reason: 'DEVICE_REVOKED',
          replay: false,
        },
      ],
    });
    const client = new SyncClient(db, pushFn);

    await client.flush();

    const stored = await db.outbox.where('operation_uuid').equals(op.operation_uuid).first();
    expect(stored.status).toBe('rejected');
    expect(stored.last_error).toBe('DEVICE_REVOKED');
  });

  it('a network failure leaves pending operations untouched and schedules a retry with backoff', async () => {
    // Real timers, but an overridden (tiny) backoff window - exercising the
    // actual setTimeout-based retry path without fighting Dexie's own
    // internal scheduling the way vi.useFakeTimers() would (fake-indexeddb/
    // Dexie rely on real microtask/timer ticks to resolve their own
    // transactions).
    await enqueue(db, { type: 'sale.create', deviceId: 'DEV-1', userId: 1, payload: {} });

    const pushFn = vi
      .fn()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce({ results: [] });
    const statuses = [];
    const client = new SyncClient(db, pushFn, (s) => statuses.push(s), { baseMs: 1, maxMs: 5 });

    await client.flush();

    expect(statuses.at(-1)).toEqual({ state: 'error', pending: 1 });
    expect(await getPending(db)).toHaveLength(1); // untouched, not discarded

    await vi.waitUntil(() => pushFn.mock.calls.length >= 2, { timeout: 2000 });

    expect(pushFn).toHaveBeenCalledTimes(2); // the scheduled retry actually fired
  });
});
