# Threat model

Scope: the Advanced Universal POS as specified in `docs/MASTER_PROMPT.md`. STRIDE-style pass over the architecture in section 5, focused on the assets that matter most per the decision hierarchy (section 1.3): tenant isolation, then money/stock/ledger correctness.

## Assets

1. Tenant business data (sales, customers, suppliers, stock, pricing) — confidentiality + integrity across tenant boundaries.
2. Money and stock correctness (ledgers, balances, invoice numbering) — integrity, non-repudiation.
3. Credentials and tokens (access/refresh tokens, device keys, password hashes) — confidentiality.
4. Availability of billing (POS must keep working offline; see `docs/sync-design.md`).
5. Audit trail (`audit_logs`, `change_log`) — integrity, completeness.

## Trust boundaries

```
[ Browser / PWA client ] --HTTPS--> [ Laravel API /api/v1 ] --SQL--> [ MySQL ]
        |                                  |
   [ IndexedDB (Dexie) ]            [ Redis (prod) / queue workers ]
        |                                  |
 [ Payment provider webhook ] --HTTPS,signed--> [ /payments/webhooks/* ]
```

Everything left of the API is **untrusted input**, including: request bodies, headers, the client's own computed prices/totals/tax (G1), the client's claimed `tenant_id`/`branch_id` (G2), and webhook payloads (verified by signature, section 10).

## STRIDE pass

| Threat | Where it applies | Mitigation (spec reference) |
|---|---|---|
| **Spoofing** identity | Login, device registration, API tokens | Argon2id password hashing; short-lived access tokens + rotating refresh tokens stored hashed; rate limiting + lockout on repeated failures (section 8 "Login") |
| **Spoofing** tenant/branch | Any request body/header claiming a `tenant_id` or `branch_id` | G2: tenant ID is resolved only from the authenticated user/device identity server-side, never trusted from the client. Branch validated against `user_branch_access` (section 10) |
| **Tampering** with price/tax/discount/total | `POST /sales`, offline sync push | G1: backend recalculates everything server-side and ignores client-submitted totals; offline operations are recalculated and compared, with mismatches routed through `offline.price_mismatch_policy`, never trusted outright (section 9) |
| **Tampering** with offline operation payload | Sync push envelope | Each operation carries a `hash`; a tampered hash is rejected outright, not silently "corrected" (section 9) |
| **Tampering** via replay | Any mutating POS/sync request | G6: idempotency key + request hash; same key + different payload -> `IDEMPOTENCY_KEY_REUSED` rather than silently applied |
| **Repudiation** of a critical action (discount override, void, approval) | Approvals, cancellations, settings changes | G4: every critical write and its audit entry share one DB transaction — never a write that could exist without its audit row; approver identity is recorded on the approval itself (section 8 "Approvals") |
| **Information disclosure** across tenants | Any list/detail/export/report/sync endpoint, any foreign-key lookup by ID | G14: a foreign-tenant ID returns `404`, not `403` (avoids confirming the ID exists in another tenant); global scope applied at the model layer, not per-query opt-in; platform-guard users structurally cannot query tenant tables (section 5 "Identity") |
| **Information disclosure** of secrets | API responses, logs | G15: secrets only in env config or an encrypted store; never serialized into an API Resource or written to a log line — enforced by code review discipline + a `$hidden`/cast convention on any model touching credentials |
| **Information disclosure** via IDOR | `GET /sales/{id}`, `/customers/{id}`, etc. | Same tenant global scope as above; route-model binding resolves within the authenticated tenant's scope only |
| **Denial of service** against billing availability | Network outage at a branch | Not treated as an attack surface but as a reliability requirement: section 9 ("Offline-First Design") — billing never depends on the network by construction |
| **Denial of service** via brute force / credential stuffing | `/auth/login` | Rate limit per IP and per account, temporary lockout after `auth.max_failed_logins` (setting, section 6) |
| **Denial of service** via webhook flooding | `/payments/webhooks/*` | Signature verification happens before any DB write; idempotent on `provider_reference` so retries/floods don't multiply side effects |
| **Elevation of privilege** via role-name logic bugs | Any `if ($role === 'admin')`-shaped code | G11 + the Dynamic-Only Rule (section 4): authorization is permission-code-only; `scripts/check-dynamic-only.js` fails CI on a literal role-name comparison (demonstrated in `docs/reports/phase-0.md`) |
| **Elevation of privilege** via mass-assignment / over-posting | Any Eloquent `create()`/`update()` from request input | Form Requests validate and whitelist fields explicitly; models do not use blanket `$guarded = []` |
| **Elevation of privilege** via SQL/NoSQL injection | Any filter/search/sort parameter on list endpoints | Eloquent query builder with bound parameters throughout; the rule engine (section 6) evaluates a fixed, closed set of operators against JSON conditions — it never executes arbitrary code or raw user-supplied SQL fragments |
| **Elevation of privilege** via XSS | Any rendered user-supplied text (product names, custom fields, receipt templates) | React's default JSX escaping; receipt/document templates (section 6) are a constrained template language, not `eval`'d strings; CSP headers set at the nginx layer in Phase 8 |

## Support / platform-admin access

Platform users (separate guard, section 5) can be granted **explicit, time-limited, audited** support access to a tenant. This is itself a sensitive capability: the grant, its expiry, and every read/write made under it are audit-logged distinctly from the tenant's own users, so a support session is always distinguishable after the fact. Built when the approval/audit frameworks land in Phase 1.

## Out of scope for Release 1 (documented, not ignored)

- Formal penetration testing / third-party audit — recommended before accepting real payment-provider production credentials; flagged for human review in `docs/DECISIONS.md`.
- DDoS protection at the network edge (expected to sit in front of the Docker Compose stack via the hosting provider, section 1.2 "Hosting") — not application-layer code.
- Two-factor authentication — explicitly Release 2 (section 2).

This document is revisited at the end of Phase 8 ("Security tests") against what was actually built, and any gap found there is logged in `docs/DECISIONS.md` rather than silently left here unresolved.
