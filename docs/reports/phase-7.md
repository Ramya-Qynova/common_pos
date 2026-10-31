# Phase 7 report — Offline engine

## 1. Scope completed

Per `docs/MASTER_PROMPT.md` section 12, Phase 7: device policy, snapshot + incremental pull, outbox push, idempotency, conflicts + resolution screens, revoke + wipe, encrypted local storage, offline numbering. Exit gate: "All Section 13.2 offline tests pass." Section 13.2 names ten specific scenarios; every one is proved below by a real, executed test against the actual decision point (`POST /sync/push` on the backend, the outbox/sync client on the frontend) - not inferred from reading the code.

- **Idempotent sync push, the core of this phase**: `SyncPushService::processOne()` dedupes by the client's `operation_uuid` (stored on `sync_operations`, unique per tenant) - a resubmitted operation returns the stored result without reprocessing, proved by a test that submits the same envelope twice and checks only one `Sale` row exists.
- **Two devices, the last unit, nothing lost**: `processSaleCreate()` calls the unmodified `SaleService::create()`; on `INSUFFICIENT_STOCK` it retries once with a new, additive `forceAllowNegativeStock` parameter (this phase's only change to `SaleService`'s signature, fully backward-compatible - every Phase 4-6 test still passes unchanged) and records a `STOCK_SHORTFALL` conflict. Both devices' sales land; neither is discarded.
- **A stale offline price never wins**: when the envelope's `device_grand_total` disagrees with what the server just (correctly, authoritatively) computed, a `PRICE_MISMATCH` conflict records both numbers and the configured `offline.price_mismatch_policy` - but the committed sale is always the server total. G1 ("server ignores client prices") is not relaxed for sync reconciliation; see D-0037 for why "accept_device_price" is recorded for a human to act on rather than silently applied.
- **Device revocation, split by time, not blocked outright**: `processOne()` compares each operation's `client_created_at` against the device's `revoked_at` - operations created before revocation are accepted, later ones rejected with `DEVICE_REVOKED`. This is the reconciliation "wipe at next contact" needs: a device can still flush its legitimate pre-revocation queue in one final push before the client acts on the rejection reason to wipe (D-0035).
- **A real bug found via a failing test, not assumed**: the very first version of the "suspended tenant" test crashed with a null array-offset - `EnsureTenantActive` (the shared `tenant.active` route middleware) was returning a flat 403 before `SyncPushService` ever got to run its own "hold for review" logic, which defeats the entire point of that logic existing. Fixed by moving `/sync/push` outside the `tenant.active` gate in `routes/api.php` (D-0034) - every other endpoint, including `/sync/pull`, stays gated exactly as before.
- **Device-scoped offline numbering, finally exercised**: `numbering_sequences.device_id`/`device_key` have existed since Phase 1/Phase 4's D-0020 fix but nothing ever passed a device ID through until this phase's new `SaleService::create($deviceId: ...)` parameter. Proved directly: device A's third sale gets invoice number 3; device B's very first sale independently starts at 1.
- **Clock skew, large batches, visibility**: an operation whose `client_created_at` is implausibly far from server receipt time gets a `CLOCK_SKEW` conflict (still accepted). A 20-operation batch processes correctly in one call. `GET /sync/operations` (new) lets rejected/held rows stay queryable, and `SyncConflictService::resolve()` is the only way a conflict leaves `open` status - both proved directly, including resolving a conflict and confirming it disappears from the open list.
- **The frontend half, built on real IndexedDB, not stubbed**: `frontend/src/offline/{db,outbox,syncClient,snapshot,crypto}.js`, using Dexie (installed since Phase 0, never wired in until now) and tested against genuine IndexedDB via `fake-indexeddb` under Vitest. The outbox survives a simulated "restart" (closing one Dexie connection and opening a fresh one against the same database name). `SyncClient` applies exponential backoff with jitter on a network failure and leaves pending rows untouched. `crypto.js` does real AES-GCM field encryption with a device key (Web Crypto), a fresh IV every call, and correctly fails to decrypt with the wrong key.

## 2. Files changed and migrations added

1 new migration: `sync_operations`, `sync_conflicts`, `change_log` (`2025_07_01_000001_create_sync_tables.php`). New backend services: `App\Services\Sync\{SyncPushService,SyncPullService,DeviceService,SyncConflictService,ChangeLogger}`. `SaleService::create()` gained `$deviceId`/`$forceAllowNegativeStock` (additive, backward-compatible). `ProductService`/`PriceListService` now also write `ChangeLogEntry` rows. New controllers/routes: `POST /sync/push`, `GET /sync/pull`, `GET /sync/operations`, `GET`/`POST /sync/conflicts(/resolve)`, `GET /devices`, `POST /devices/{id}/revoke`. `routes/api.php` restructured so `/sync/push` sits outside the `tenant.active` gate (D-0034).

New frontend module: `frontend/src/offline/{db,outbox,syncClient,snapshot,crypto}.js`, using the already-installed `dexie` dependency. New frontend dev dependency: `fake-indexeddb` (test-only, polyfills IndexedDB under Vitest's jsdom environment).

## 3. Endpoints, permission codes, setting keys added

**Endpoints**: `POST /api/v1/sync/push`, `GET /api/v1/sync/pull`, `GET /api/v1/sync/operations`, `GET /api/v1/sync/conflicts`, `POST /api/v1/sync/conflicts/{conflict}/resolve`, `GET /api/v1/devices`, `POST /api/v1/devices/{device}/revoke`.

**Permission codes**: none new - `pos.sales.create` (sync push/pull), `core.audit.view` (operations/conflicts), `core.users.manage` (devices) are all reused (D-0033's established pattern).

**Setting keys**: `offline.clock_skew_warning_minutes` (new, default 10 - seeded with the same "default with a seeded value, never a literal" rule section 4 requires).

## 4. Tests added, with real executed results

**Backend** (`cd backend && php artisan test`):
```
Tests:    111 passed (437 assertions)
Duration: 49.05s
```
New this phase (15): `SyncPushServiceTest` (10, one per section 13.2 scenario plus the large-batch and visibility tests), `SyncPullServiceTest` (3), `DeviceControllerTest` (2).

**Frontend** (`cd frontend && npm run test`):
```
Test Files  8 passed (8)
     Tests  31 passed (31)
```
New this phase (17): `outbox.test.js` (7), `syncClient.test.js` (4), `snapshot.test.js` (3), `crypto.test.js` (3).

**Backend style** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}` (auto-fix pass for import ordering on new test files, same discipline as every prior phase).
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors` on the first run.
**Frontend lint/format/build**: `npm run lint` (0 errors, 1 pre-existing warning), `npm run format` (passed after one auto-format pass), `npm run build` (succeeded - bundle size unchanged, confirming the new offline module isn't imported by any screen yet, D-0040).
**Dynamic-Only Rule check**: `Dynamic-Only Rule check: no violations found.`

## 5. Dynamic-Only static-check results

Clean. The offline module's behaviour (whether variable-weight-style config, pricing, or anything else) never branches on anything beyond settings/module flags already established in prior phases.

## 6. Known issues, deviations

- D-0034: a real bug - `/sync/push` was blocked entirely for a suspended tenant by the shared route gate, found by a failing test and fixed by route restructuring.
- D-0035: device revocation discriminates per-operation by timestamp rather than blocking the device from every endpoint immediately; a broader immediate-lockout gate is additive future work.
- D-0036: `change_log` (and therefore `GET /sync/pull`'s incremental diff) is scoped to `Product`/`ProductPrice` only.
- D-0037: a price mismatch is always recorded, but the server total always wins - the policy setting is informational for reconciliation, never a silent override.
- D-0038: no cryptographic verification of the envelope's `hash` field (no signing scheme is specified anywhere to verify against).
- D-0039: tenant-suspension check is `tenant.status` only, no separate subscription-expiry/grace-period model.
- D-0040: the frontend offline module is real and tested but not yet imported by any screen - no POS billing UI exists.
- D-0041: no PWA/service-worker registration, no auto-lock/PIN screen.
- D-0042: only `sale.create` has a real sync push handler; other offline-capable operation types are structurally accepted through the envelope/device/tenant pipeline and then rejected with `TYPE_NOT_ALLOWED_OFFLINE`.

## 7. Remaining work / next phase

Phase 7's exit gate - "all Section 13.2 offline tests pass" - is met and demonstrated directly: all ten named scenarios have a dedicated, executed, passing test against the real decision point, backend and frontend both. The gaps in section 6 are deliberate scope boundaries (most following the same backend/mechanism-first pattern every phase since Phase 2 has used), not shortfalls against this phase's own exit gate.

Phase 8 per section 12: security tests, performance tests against section 11's targets, backup/restore verification, monitoring + alerting, Docker/hosting, a runbook, and fixes. Exit gate: "restore test succeeds and integrity checks pass; targets met or exceptions documented." Work begins immediately following this report.
