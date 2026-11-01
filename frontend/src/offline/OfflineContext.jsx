import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { apiRequest } from '../api/client';
import { openOfflineDb } from './db';
import { enqueue } from './outbox';
import { SyncClient } from './syncClient';

const OfflineContext = createContext(null);

/**
 * section 9 "Build the POS client on the outbox abstraction from the start,
 * so online mode is the same pipeline flushed immediately" and "Connection
 * badge ... with pending count on every screen." One db/outbox/SyncClient
 * per logged-in device, shared by every screen under AppShell: the badge
 * (rendered once, in the shell) and whichever screen actually enqueues a
 * write (PosScreen today) both read/drive the same instance.
 */
export function OfflineProvider({ deviceId, userId, children }) {
  // Lazy useState initializers (not useRef) - each runs exactly once, same
  // pattern AuthContext uses for its stored session, and avoids touching a
  // ref's value during render.
  const [db] = useState(() => openOfflineDb());
  const [status, setStatus] = useState({ state: 'idle', pending: 0 });
  const [client] = useState(
    () =>
      new SyncClient(
        db,
        (operations) => apiRequest('/sync/push', { method: 'POST', body: { operations } }),
        setStatus,
      ),
  );

  useEffect(() => {
    client.flush();
    const onOnline = () => client.flush();
    window.addEventListener('online', onOnline);
    return () => {
      window.removeEventListener('online', onOnline);
      client.stop();
    };
  }, [client]);

  const value = useMemo(
    () => ({
      status,
      flush: () => client.flush(),
      enqueueSale: async (payload, { branchId, registerId }) => {
        const row = await enqueue(db, {
          type: 'sale.create',
          deviceId,
          userId,
          branchId,
          registerId,
          payload,
        });
        await client.flush();
        const settled = await db.outbox.where('operation_uuid').equals(row.operation_uuid).first();
        return settled ?? row;
      },
    }),
    [status, deviceId, userId, db, client],
  );

  return <OfflineContext.Provider value={value}>{children}</OfflineContext.Provider>;
}

export function useOffline() {
  const ctx = useContext(OfflineContext);
  if (!ctx) {
    throw new Error('useOffline must be used within OfflineProvider');
  }
  return ctx;
}
