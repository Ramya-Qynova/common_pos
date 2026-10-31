# Phase 0 report — Foundation

## 1. Scope completed

- New, isolated repository at `C:\xampp\htdocs\common_pos` (separate from the unrelated pre-existing `pos` project on this machine), cloned from `https://github.com/Ramya-Qynova/common_pos.git` — D-0003.
- Dev environment verified and two gaps fixed: `intl`/`sodium`/`opcache` enabled in the shared `php.ini` (D-0004); PHP 8.2.12 accepted as the local floor instead of the spec's 8.3 minimum, since replacing the shared XAMPP PHP risks the other project on this machine (D-0001).
- `scripts/check-env.ps1` written and executed — passes, with two informational warnings (PHP 8.2 vs 8.3 target, Node 22 vs 24 target), both already covered by Decisions.
- Backend scaffolded: Laravel 12.70.2 on PHP 8.2, Pest 3 + pest-plugin-laravel, Larastan 3 (`phpstan.neon`, level 5), Pint. Connected to a real local MySQL/MariaDB instance (actually MySQL Server 9.4.0 — exceeds the "MySQL 8" target, no workaround needed) in a dedicated `common_pos` database, migrated successfully.
- Frontend scaffolded: Vite + React 19 (JS/JSX, no TypeScript), `vite-plugin-pwa`, `react-router-dom`, `@tanstack/react-query`, `dexie`, `react-hook-form`, `zod`, `decimal.js` installed per section 5. ESLint 9 + `eslint-plugin-react`/`react-hooks`/`react-refresh`, Prettier, Vitest 2 + React Testing Library configured (replacing the default scaffold's `oxlint`, to match the spec's explicit "ESLint, Prettier" requirement). Default Vite/React template boilerplate (demo counter, template assets) removed and replaced with a minimal, translation-key-driven boot screen.
- `scripts/check-dynamic-only.js` written: a Node static check that greps `backend/app` and `frontend/src` for role-name literals/comparisons, business-type literals, currency literals, and literal JSX UI text.
- `shared/golden-vectors/{tax,discounts,rounding}.json` + schema `README.md`: 8 vectors covering inclusive/exclusive tax, multi-component GST (CGST+SGST), line and invoice discounts, and cash rounding (half-up and half-even boundary cases). Not yet consumed by a pricing engine — that's Phase 4.
- Docs written: `docs/MASTER_PROMPT.md` (verbatim), `docs/DECISIONS.md`, `docs/PROGRESS.md`, `docs/OPEN_QUESTIONS.md`, `docs/ERD.md`, `docs/data-dictionary.md`, `docs/threat-model.md`, `docs/sync-design.md`, `docs/openapi.yaml` (skeleton), `docs/TRACEABILITY.md`, `docs/RUNBOOK.md` (local-dev section only), root `README.md`.
- `.github/workflows/ci.yml`: runs the dynamic-only check, backend (Pint/Larastan/Pest against a real MySQL 8 service container), and frontend (ESLint/Prettier/Vitest/build) on every push. Not yet executed by GitHub Actions itself (first push happens at the end of this phase) — the equivalent commands were run locally and their real output is below.
- `.gitattributes`, `.gitignore` for the Laravel + Vite layout.

## 2. Files changed and migrations added

No business migrations yet (none are required by Phase 0; Phase 1 adds the first real tables). Laravel's own default migrations (`users`, `cache`, `jobs`) exist unmodified from the `laravel/laravel` skeleton and ran successfully against MySQL.

Full file list is the initial commit's diff (see `git log`/`git show` on the `phase-0-complete` tag) — not reproduced here to avoid duplicating what git already records.

## 3. Endpoints, permission codes, setting keys, translation keys added

None yet — Phase 0 has no business logic by design (section 12). The only "translation keys" so far are the two placeholder dev-dictionary entries in `frontend/src/i18n/index.js` (`app.boot.title`, `app.boot.subtitle`), explicitly marked in that file's comment as a stand-in for the real `GET /tenant/config` translations flow built in Phase 1.

## 4. Tests added, with real executed results

**Backend** (`cd backend; php artisan test`):
```
PASS  Tests\Unit\ExampleTest
  ✓ that true is true                                    0.07s
PASS  Tests\Feature\ExampleTest
  ✓ the application returns a successful response         2.99s

Tests:    2 passed (2 assertions)
Duration: 4.78s
```
(Laravel's own default tests — no application tests exist yet since there's no application code yet. Pest is wired and confirmed working; the first real backend tests land in Phase 1 per the work order in section 1.11: migrations → tests → services → API.)

**Backend static analysis** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}`
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors`

**Frontend** (`cd frontend; npm run test`):
```
✓ src/App.test.jsx (1 test) 372ms
  ✓ App boot screen > renders the translated boot title 355ms

Test Files  1 passed (1)
     Tests  1 passed (1)
```

**Frontend lint** (`npm run lint`): clean, no output (pass).
**Frontend build** (`npm run build`): `✓ 31 modules transformed`, `✓ built in 3.00s`.

**Dynamic-Only Rule check — the Phase 0 exit gate, executed twice to prove both directions:**

Run 1 (against the stock Vite React template, before cleanup — a *real*, not synthetic, violation):
```
Dynamic-Only Rule violations found: 3
  [literal-ui-text-jsx] frontend\src\App.jsx:19   <h1>Get started</h1>
  [literal-ui-text-jsx] frontend\src\App.jsx:61   <h2>Connect with us</h2>
  [literal-ui-text-jsx] frontend\src\App.jsx:62   <p>Join the Vite community</p>
EXIT: 1
```
Fix: replaced the template boilerplate with the translation-key-driven boot screen described above.

Re-run after the fix: `Dynamic-Only Rule check: no violations found.` `EXIT: 0`

Additionally, to prove the backend-side rules work (no real backend code existed yet to trigger one), a scratch file was deliberately added with `if ($role === "admin") { ... }`, confirmed to fail:
```
Dynamic-Only Rule violations found: 1
  [role-name-comparison] backend\app\Scratch\Violation.php:5   if ($role === "admin") {
EXIT: 1
```
...then deleted, confirmed to pass again: `Dynamic-Only Rule check: no violations found.` `EXIT: 0`

## 5. Dynamic-Only static-check results

See section 4 above — this *is* the Phase 0 exit gate ("CI runs and fails on a deliberate rule violation, then remove the violation") and it was executed, not simulated, for both the frontend and backend rule families.

## 6. Known issues, deviations

- D-0001: PHP 8.2.12 locally vs. the spec's 8.3 minimum / 8.5 target. Not a functional blocker (Laravel 12 only requires `^8.2`); production Docker image (Phase 8) targets 8.3+.
- D-0002: `gh` CLI unavailable; pushed via plain `git` + the machine's existing Git Credential Manager.
- D-0003: Built in a new sibling directory rather than inside the unrelated existing `pos` project, to honor "completely fresh and independent."
- D-0004: Enabled `intl`/`sodium`/`opcache` in the shared `php.ini` (additive, low-risk).
- D-0005: No action needed; pre-resolved decisions table acknowledged.
- D-0007: Frontend toolchain pinned off the `npm create vite@latest` defaults (Vite 8/Vitest 5/ESLint 10) down to Vite 6/Vitest 2/ESLint 9, which are mutually compatible and compatible with this machine's Node 22.14 — the newer majors either crashed outright or silently broke the automatic JSX runtime under the test runner specifically (production build was unaffected). Full root-cause trail is in `docs/DECISIONS.md`.
- CI workflow (`.github/workflows/ci.yml`) has been written and its steps verified by running the equivalent commands locally, but has not yet executed inside GitHub Actions itself — that happens on the push that ends this phase. If it fails there for an environment reason that doesn't reproduce locally (e.g. a GitHub-hosted-runner-specific issue), that will be fixed in a follow-up commit before Phase 1 is considered to build on a green `main`.
- No real application code exists yet (correct for Phase 0 per section 12) — so most of section 13's mandatory test groups (tenancy, permissions, money/tax golden-vector consumption, idempotency, etc.) have nothing to test yet. They are not "skipped"; their subject matter doesn't exist until Phase 1 onward.

## 7. Remaining work / next phase

Phase 1 per section 12: platform/tenant/config migrations, tenancy + global scope, two guards (tenant vs platform), auth/tokens/devices/security events, roles/permissions/branch access, the `SettingsService` + cache, modules/templates/custom fields/rule engine, lookups, translations (replacing the Phase 0 placeholder dictionary), numbering, tax, payment methods, audit + approval frameworks, onboarding backend + go-live, and the matching screens. Its exit gate: tenant isolation, permission, login, lockout and dynamic-configuration tests pass; changing a setting or module changes behaviour with no deployment. Work begins immediately following this report, per the work order in section 1.11 (migrations → tests → services → API → UI → static checks → phase report).
