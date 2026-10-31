# Offline sync design

Implements `docs/MASTER_PROMPT.md` section 9. This document is the design; Phase 7 is where it is actually built and gated against section 13.2.

## Principle

Billing never depends on the network. Every offline write is an immutable, UUID-identified record in a local outbox. The server is the single source of truth for shared totals/balances, but a completed offline sale is a **fact** — sync records and reconciles it, it is never discarded or silently reversed (section 9 "Principles").

The online POS client is built on the exact same outbox abstraction, just flushed immediately instead of queued — there is one code path, not two.

## Local storage (IndexedDB via Dexie)

| Store | Contents | Written by |
|---|---|---|
| `snapshot_products`, `snapshot_barcodes`, `snapshot_prices`, `snapshot_price_lists`, `snapshot_customers` | Branch/warehouse-scoped catalogue snapshot | Initial login snapshot + `GET /sync/pull` |
| `snapshot_config` | Resolved settings, tax groups, payment methods, units, translations, document templates, permissions, offline policy for this user/device | Same as above |
| `outbox` | Every pending local operation, in creation order, as an immutable envelope (see below) | Every local POS action |
| `held_bills`, `local_receipts` | Local copies for offline resume/reprint | POS client |
| `sync_state` | Last pulled `change_log` version, last push attempt/result, pending count | Sync engine |

Sensitive fields are encrypted with a device key (Web Crypto); the app auto-locks on inactivity and requires PIN unlock; a revoked device wipes local data on next contact (section 9).

## Operation envelope

```json
{
  "operation_uuid": "uuid-v4, client-generated, globally unique per tenant",
  "type": "sale.create | sale.hold | sale.resume | customer.quick_add | cash.in | cash.out | shift.open | ...",
  "device_id": "...",
  "user_id": "...",
  "client_created_at": "ISO-8601 UTC",
  "branch_id": "...",
  "register_id": "...",
  "snapshot_version": "the change_log version this operation was computed against",
  "numbering": { "series": "...", "number": "..." },
  "payload": { "...": "operation-specific, same shape the online endpoint accepts" },
  "hash": "HMAC over the canonical payload, device-keyed; tamper-evident, not secret"
}
```

Operations from one device are pushed **in order**; an operation that references another offline-created record (e.g. a payment against an offline sale) is sent after it and refers to it by `operation_uuid`/`uuid`, never by a server-assigned ID it couldn't have known offline.

## Pull (snapshot refresh)

`GET /sync/pull?since=<version>` returns authorized `change_log` rows in version order. If the local snapshot is older than `offline.snapshot_max_age_hours`, the client shows a stale-data warning; `offline.price_mismatch_policy` and the module's own judgement (not a hardcoded rule) decide whether billing continues anyway — see "Offline operation policy" below.

## Push (`POST /sync/push`)

Batched; **each operation processed in its own DB transaction** so one bad operation in a batch never blocks or corrupts its siblings. Per operation:

1. Authenticate user + device.
2. If `operation_uuid` already processed -> return the stored result (idempotent replay, G6).
3. Recalculate the operation with the server's pricing/stock/tax engine (the same one `POST /sales` uses) and compare against what the client computed.
4. Outcome:
   | Condition | Outcome |
   |---|---|
   | Totals match | `accepted` |
   | Prices differ | `accepted`, per `offline.price_mismatch_policy` (`accept_device_price` default / `accept_server_price`), difference recorded in `sync_conflicts` |
   | Stock insufficient | `accepted` with a `STOCK_SHORTFALL` conflict row — negative stock is allowed *only* for this already-left-the-shop case (section 1.2 "Negative stock") |
   | Item archived / price list changed since snapshot | `accepted` with a `DATA_CHANGED` flag |
   | Structurally invalid, tampered `hash`, or `type` not in `offline.allowed_operations` | `rejected`, reason recorded, stays visible to the user for manual resolution |
   | Device revoked before the operation's `client_created_at` | `rejected` for review |
   | Device revoked **after** the operation's `client_created_at` | `accepted` (it was legitimate when made) |
   | Tenant suspended or subscription expired | stored, held for review — **never dropped** |
5. Write to `sync_operations` (outcome) and, where relevant, `sync_conflicts`. The client only marks its local outbox entry synced after this acknowledgement.

## Offline numbering

`numbering.offline_mode` setting, per tenant:

- `device_series` (default): each registered device owns its own prefix + counter in `numbering_sequences` keyed by `device_id`. The number printed on the receipt is final and is never renumbered on sync.
- `provisional_then_final`: a provisional number is shown/printed offline and replaced by a final one on sync, with the mapping kept for reprint/audit.

Either way, numbers never repeat and a sale's printed number is traceable end to end.

## Reliability

- Exponential backoff with jitter on push failures; outbox and `sync_state` survive app restarts and crashes (persisted in IndexedDB, not memory).
- Server stores both `client_created_at` and server receipt time, and flags clock skew beyond a setting.
- Manual "Sync Now" plus automatic background sync when connectivity returns.
- A connection badge (Online / Offline / Syncing / Sync Error) with the current pending-operation count is visible on every screen (section 9, section 11 "Schema-driven UI rules").

## Offline operation policy (data, not code — `offline_policies` table)

Defaults, editable per tenant/device:

| Operation | Offline? |
|---|---|
| Sale (cash, credit within snapshot limit, manual-confirm) | Allowed |
| Hold / resume bill | Allowed |
| Quick-add customer | Allowed (merged by phone on sync) |
| Price check | Allowed |
| Cash in / cash out | Allowed |
| Shift open | Allowed |
| Shift close | Allowed, but blocked while the device's sync queue is non-empty (`shift.close_requires_empty_sync_queue`) |
| Refund / return / cancel | Online only |
| Stock transfer / adjustment / purchase receiving | Online only |
| User / role / price / tax / settings changes | Always online only |

This table is read by both the client (to decide what to even show as available while offline) and the server (to reject, rather than silently accept, an operation type it should never have received offline) — one source of truth, not duplicated logic.

## Test gate

Section 13.2 enumerates the exact scenarios Phase 7 must pass before it is considered done (kill-mid-sale survival, two devices selling the last unit offline, revoked-device handling, clock skew, 500-operation queue, etc.). This design is written to make each of those scenarios a direct consequence of the rules above, not a special case bolted on afterward.
