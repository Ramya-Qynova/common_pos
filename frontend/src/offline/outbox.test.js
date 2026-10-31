import { beforeEach, describe, expect, it } from 'vitest';
import { openOfflineDb } from './db';
import { computeBackoffDelayMs, enqueue, getPending, markFailed, markSynced } from './outbox';

describe('outbox', () => {
  let db;

  beforeEach(() => {
    db = openOfflineDb(`test_db_${crypto.randomUUID()}`);
  });

  it('enqueues an operation with a generated UUID and pending status', async () => {
    const row = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: { lines: [] },
    });

    expect(row.operation_uuid).toBeDefined();
    expect(row.status).toBe('pending');

    const pending = await getPending(db);
    expect(pending).toHaveLength(1);
    expect(pending[0].operation_uuid).toBe(row.operation_uuid);
  });

  it('returns pending operations in the order they were created (section 9: "sent in order")', async () => {
    const first = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: { line: 1 },
    });
    await new Promise((resolve) => setTimeout(resolve, 2));
    const second = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: { line: 2 },
    });

    const pending = await getPending(db);

    expect(pending.map((p) => p.operation_uuid)).toEqual([
      first.operation_uuid,
      second.operation_uuid,
    ]);
  });

  it('marking an operation synced removes it from the pending set but keeps the row (never deleted outright)', async () => {
    const row = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: {},
    });
    await markSynced(db, row.operation_uuid, { sale_id: 42 });

    expect(await getPending(db)).toHaveLength(0);
    const stored = await db.outbox.where('operation_uuid').equals(row.operation_uuid).first();
    expect(stored.status).toBe('synced');
    expect(stored.result.sale_id).toBe(42);
  });

  it('a transient failure keeps the operation pending-for-retry and visible; a terminal rejection marks it rejected', async () => {
    const transient = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: {},
    });
    await markFailed(db, transient.operation_uuid, 'network timeout', false);
    const transientRow = await db.outbox
      .where('operation_uuid')
      .equals(transient.operation_uuid)
      .first();
    expect(transientRow.status).toBe('failed');
    expect(await getPending(db)).toHaveLength(1); // still visible/retryable

    const rejected = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: {},
    });
    await markFailed(db, rejected.operation_uuid, 'DEVICE_REVOKED', true);
    const rejectedRow = await db.outbox
      .where('operation_uuid')
      .equals(rejected.operation_uuid)
      .first();
    expect(rejectedRow.status).toBe('rejected');
  });

  it('outbox data survives a "restart" - a brand new Dexie connection to the same database sees the same rows', async () => {
    const row = await enqueue(db, {
      type: 'sale.create',
      deviceId: 'DEV-1',
      userId: 1,
      payload: { lines: [{ product_id: 1 }] },
    });
    const dbName = db.name;
    db.close();

    const reopened = openOfflineDb(dbName);
    const pending = await getPending(reopened);

    expect(pending).toHaveLength(1);
    expect(pending[0].operation_uuid).toBe(row.operation_uuid);
    expect(pending[0].payload.lines[0].product_id).toBe(1);
  });
});

describe('computeBackoffDelayMs', () => {
  it('grows exponentially and never exceeds the configured maximum', () => {
    const delay0 = computeBackoffDelayMs(0, 1000, 60000);
    const delay3 = computeBackoffDelayMs(3, 1000, 60000);
    const delay10 = computeBackoffDelayMs(10, 1000, 60000);

    expect(delay0).toBeGreaterThanOrEqual(1000);
    expect(delay0).toBeLessThan(1300); // base + up to 30% jitter
    expect(delay3).toBeGreaterThan(delay0);
    expect(delay10).toBeLessThanOrEqual(60000 * 1.3);
  });

  it('adds jitter so repeated calls at the same attempt are not identical', () => {
    const delays = Array.from({ length: 10 }, () => computeBackoffDelayMs(2));
    expect(new Set(delays).size).toBeGreaterThan(1);
  });
});
