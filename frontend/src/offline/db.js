import Dexie from 'dexie';

/**
 * section 9 "IndexedDB stores": snapshot_products, snapshot_barcodes,
 * snapshot_prices, snapshot_price_lists, snapshot_customers,
 * snapshot_config, outbox, held_bills, local_receipts, sync_state.
 *
 * One database per device (`&deviceId` scopes nothing here - a device
 * only ever has one tenant/session active locally at a time per section
 * 9's device-bound model). Schema version bumps go through Dexie's own
 * versioning, never a destructive drop-and-recreate.
 */
export function openOfflineDb(name = 'common_pos_offline') {
  const db = new Dexie(name);

  db.version(1).stores({
    snapshot_products: 'id, sku, is_active',
    snapshot_barcodes: 'barcode, product_id, type',
    snapshot_prices: '[product_id+unit_id+variant_key], product_id',
    snapshot_price_lists: 'id',
    snapshot_customers: 'id, phone',
    snapshot_config: 'key',
    outbox: '++localId, operation_uuid, status, created_at',
    held_bills: 'uuid, created_at',
    local_receipts: 'sale_uuid, created_at',
    sync_state: 'key',
  });

  return db;
}
