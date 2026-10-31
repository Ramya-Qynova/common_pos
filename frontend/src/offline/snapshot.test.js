import { beforeEach, describe, expect, it } from 'vitest';
import { openOfflineDb } from './db';
import { enqueue } from './outbox';
import { applySnapshot, getSnapshotVersion, wipeLocalData } from './snapshot';

const fullSnapshot = {
  version: 5,
  full: true,
  products: [
    {
      id: 1,
      sku: 'SKU-1',
      name: 'Product One',
      base_unit_id: 10,
      is_active: true,
      barcodes: [{ barcode: '123', type: 'fixed', unit_id: 10 }],
      prices: [{ unit_id: 10, variant_key: 0, price: '50.0000' }],
    },
  ],
  customers: [
    { id: 1, uuid: 'c-1', code: 'CUST-1', name: 'Jane', phone: '999', credit_limit: null },
  ],
};

describe('snapshot', () => {
  let db;

  beforeEach(() => {
    db = openOfflineDb(`test_db_${crypto.randomUUID()}`);
  });

  it('applies a full snapshot and records its version', async () => {
    await applySnapshot(db, fullSnapshot);

    expect(await db.snapshot_products.count()).toBe(1);
    expect(await db.snapshot_barcodes.count()).toBe(1);
    expect(await db.snapshot_customers.count()).toBe(1);
    expect(await getSnapshotVersion(db)).toBe(5);
  });

  it('an incremental snapshot only touches the products it names, leaving everything else as-is', async () => {
    await applySnapshot(db, fullSnapshot);

    await applySnapshot(db, {
      version: 6,
      full: false,
      products: [
        {
          id: 1,
          sku: 'SKU-1',
          name: 'Product One',
          base_unit_id: 10,
          is_active: true,
          barcodes: [],
          prices: [{ unit_id: 10, variant_key: 0, price: '60.0000' }],
        },
      ],
      customers: [],
    });

    expect(await db.snapshot_products.count()).toBe(1); // untouched set, not cleared
    const price = await db.snapshot_prices.where('product_id').equals(1).first();
    expect(price.price).toBe('60.0000');
    expect(await getSnapshotVersion(db)).toBe(6);
  });

  it('wiping local data clears every store, including anything still pending in the outbox', async () => {
    await applySnapshot(db, fullSnapshot);
    await enqueue(db, { type: 'sale.create', deviceId: 'DEV-1', userId: 1, payload: {} });

    await wipeLocalData(db);

    expect(await db.snapshot_products.count()).toBe(0);
    expect(await db.snapshot_customers.count()).toBe(0);
    expect(await db.outbox.count()).toBe(0);
    expect(await getSnapshotVersion(db)).toBe(0);
  });
});
