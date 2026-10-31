# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 0 — Foundation: **complete**, tagged `phase-0-complete`. See `docs/reports/phase-0.md` for full detail and real executed test output. Phase 1 starts next.

## Done (Phase 0)
- Dev environment verified (`scripts/check-env.ps1`) and two gaps fixed (`intl`/`sodium`/`opcache` enabled; PHP 8.2.12 accepted as the local floor — D-0001, D-0004).
- Project built in a new, isolated directory/repo `C:\xampp\htdocs\common_pos`, separate from the unrelated existing `pos` project on this machine (D-0003).
- Backend scaffolded: Laravel 12 / PHP 8.2, Pest 3, Larastan 3 (level 5), Pint — all three run clean. Connected to a real local MySQL 9.4 instance, database `common_pos`, migrated successfully.
- Frontend scaffolded: Vite + React 19 (JS/JSX), the full dependency set from section 5 (react-router-dom, @tanstack/react-query, dexie, react-hook-form, zod, decimal.js, vite-plugin-pwa), ESLint 9 + Prettier + Vitest 2 + RTL, all run clean (lint/test/build). Toolchain versions pinned off a few bleeding-edge defaults that didn't work on this Node version — see D-0007.
- `scripts/check-dynamic-only.js` written and **proven** to fail on real + injected violations (both frontend literal-UI-text and backend role-literal patterns), then pass once each violation is removed — this is the Phase 0 exit gate and it executed for real (see `docs/reports/phase-0.md` section 4 for the actual output).
- `shared/golden-vectors/{tax,discounts,rounding}.json`: 8 vectors, schema documented in that folder's `README.md`. Not yet consumed by any pricing engine (that's Phase 4).
- All Phase 0 docs written: ERD, data dictionary, threat model, sync design, OpenAPI skeleton, TRACEABILITY, RUNBOOK (local-dev section), root README, DECISIONS, OPEN_QUESTIONS.
- `.github/workflows/ci.yml` written (dynamic-only check + backend Pint/Larastan/Pest against a MySQL 8 service container + frontend ESLint/Prettier/Vitest/build), verified by running the equivalent commands locally; not yet observed running inside GitHub Actions itself.

## Next 5 steps (start of Phase 1)
1. Platform/tenant/config migrations: `platform_users`, `tenants`, `tenant_subscriptions`, `tenant_modules`, `module_definitions`, `templates`, `template_versions`, `tenant_template_snapshot`, `tenant_settings`, `plans`, `plan_modules`, `plan_limits`.
2. Two guards (tenant vs platform identity, section 5) + tenant global scope + `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branch_access`, `devices`, `device_sessions`, `security_events`.
3. `SettingsService.get(key, context)` with branch→tenant→plan→platform resolution and version-based cache invalidation (section 4) — write its behaviour tests (change a setting, assert behaviour changes with no code change) before the service itself, per the Phase 1 exit gate.
4. Auth endpoints (login/refresh/logout/devices) + onboarding backend (tenant creation transaction, setup wizard ON-01..10, go-live) per section 8.
5. Tenancy/permission/login/lockout/dynamic-configuration tests, run for real, phase-1 report, tag `phase-1-complete`.

## How to run tests
- Backend: `cd backend && php artisan test`; style: `php vendor/bin/pint --test`; static analysis: `php vendor/bin/phpstan analyse --no-progress`
- Frontend: `cd frontend && npm run test` (Vitest + RTL); `npm run lint`; `npm run build`
- Dynamic-Only static check: `node scripts/check-dynamic-only.js`
- Env check: `pwsh scripts/check-env.ps1` (or `powershell -File scripts/check-env.ps1` if `pwsh` isn't on PATH)

## Known issues
- PHP available locally is 8.2.12, below the prompt's 8.3 minimum (D-0001) — not a functional blocker.
- `gh` CLI not installed; using plain `git` via the existing Git Credential Manager (D-0002).
- Frontend toolchain pinned to Vite 6 / Vitest 2 / ESLint 9 instead of `npm create vite@latest`'s newer defaults, which broke on this machine's Node 22.14 (D-0007).
- CI workflow is written and locally-equivalent-verified but not yet observed running inside GitHub Actions.

## Notes for the next session
This is a multi-week-scale build (8 phases, ~80 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
