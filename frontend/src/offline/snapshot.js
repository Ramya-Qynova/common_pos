/**
 * section 9 "Snapshot and pull": applies a `GET /sync/pull` response to
 * the local stores. A full pull (`full: true`) replaces the product/price
 * tables wholesale; an incremental one only touches the rows it names -
 * either way `sync_state.version` is what the next pull's `since` uses.
 */
export async function applySnapshot(db, pullResult) {
  await db.transaction(
    'rw',
    db.snapshot_products,
    db.snapshot_barcodes,
    db.snapshot_prices,
    db.snapshot_customers,
    db.sync_state,
    async () => {
      if (pullResult.full) {
        await db.snapshot_products.clear();
        await db.snapshot_barcodes.clear();
        await db.snapshot_prices.clear();
      }

      for (const product of pullResult.products) {
        await db.snapshot_products.put({
          id: product.id,
          sku: product.sku,
          name: product.name,
          base_unit_id: product.base_unit_id,
          is_active: product.is_active,
        });

        await db.snapshot_barcodes.where('product_id').equals(product.id).delete();
        for (const barcode of product.barcodes) {
          await db.snapshot_barcodes.put({ ...barcode, product_id: product.id });
        }

        await db.snapshot_prices.where('product_id').equals(product.id).delete();
        for (const price of product.prices) {
          await db.snapshot_prices.put({ ...price, product_id: product.id });
        }
      }

      if (pullResult.full) {
        for (const customer of pullResult.customers) {
          await db.snapshot_customers.put(customer);
        }
      }

      await db.sync_state.put({
        key: 'version',
        value: pullResult.version,
        updated_at: Date.now(),
      });
    },
  );
}

export async function getSnapshotVersion(db) {
  const row = await db.sync_state.get('version');

  return row?.value ?? 0;
}

/**
 * section 9: "On device revocation, wipe local data at next contact."
 * Unconditional - the caller flushes any still-pending outbox rows FIRST
 * (section 13.2's "pre-revocation ops accepted"), then calls this once
 * that flush settles.
 */
export async function wipeLocalData(db) {
  await db.transaction(
    'rw',
    [
      db.snapshot_products,
      db.snapshot_barcodes,
      db.snapshot_prices,
      db.snapshot_price_lists,
      db.snapshot_customers,
      db.snapshot_config,
      db.outbox,
      db.held_bills,
      db.local_receipts,
      db.sync_state,
    ],
    async () => {
      await Promise.all([
        db.snapshot_products.clear(),
        db.snapshot_barcodes.clear(),
        db.snapshot_prices.clear(),
        db.snapshot_price_lists.clear(),
        db.snapshot_customers.clear(),
        db.snapshot_config.clear(),
        db.outbox.clear(),
        db.held_bills.clear(),
        db.local_receipts.clear(),
        db.sync_state.clear(),
      ]);
    },
  );
}
