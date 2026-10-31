# DECISIONS.md

Append-only log of assumptions, deviations, improvements and workarounds made while building the Advanced Universal POS, per the Autonomy Charter (`docs/MASTER_PROMPT.md` §1).

---

## D-0001 | Phase 0 | Type: Workaround
Context: The dev machine (shared XAMPP install at `C:\xampp`) has PHP 8.2.12 (NTS) installed. The master prompt targets PHP 8.5.x with an 8.3 minimum.
Options considered:
A. Install a second PHP 8.3+ runtime system-wide (via Chocolatey) and repoint PATH/XAMPP.
B. Leave the system PHP untouched and build against PHP 8.2.
C. Use Docker for local dev PHP only.
Decision: B. Build the backend against PHP 8.2.12 locally; `composer.json` declares `"php": "^8.2"`. The production `Dockerfile` (Phase 8) targets PHP 8.3+ as originally specified.
Reason: `C:\xampp\htdocs\pos` is a separate, unrelated, already-working project (AJ Agency POS) that depends on this exact shared XAMPP PHP installation. Replacing or shadowing it system-wide (option A) risks breaking that project and is a "modify system settings"-class action on a shared machine, which is out of scope for this task's authorization. Docker Desktop is not confirmed available on this machine at this time, so option C is deferred to Phase 8 (hosting) where Docker is specified anyway. Laravel's latest stable release (12.x, used here) only requires PHP ^8.2, so this does not block any functional requirement — only the aspirational runtime-version ceiling.
Reversibility: Bump `composer.json`'s `php` constraint and re-test whenever PHP 8.3+ becomes available on the dev machine; no application code depends on an 8.2-only vs 8.3+ behaviour difference.

## D-0002 | Phase 0 | Type: Workaround
Context: `gh` (GitHub CLI) is not installed on this machine. The task requires pushing the finished code to `https://github.com/Ramya-Qynova/common_pos.git`.
Decision: Use plain `git` (clone/add/commit/push) against the HTTPS remote. Git's credential helper is already configured system-wide as `manager` (Git Credential Manager for Windows), so authenticated push reuses the operator's existing GitHub credentials without needing `gh`.
Reversibility: None needed; `gh` was only ever a convenience, not a requirement.

## D-0003 | Phase 0 | Type: Deviation
Context: The task requires a "completely fresh and independent" project that must not reuse any code/structure from the existing POS project at `C:\xampp\htdocs\pos` (a different, unrelated application — "AJ Agency POS" — with its own git history of invoices, van stock, commission bills, etc.), yet also work from "an empty repository" per the master prompt.
Decision: Build this project in a new, separate directory, `C:\xampp\htdocs\common_pos`, cloned directly from the empty target repo `Ramya-Qynova/common_pos`, rather than inside `C:\xampp\htdocs\pos`. No files, migrations, or code are copied from the old project; everything here is authored fresh against the master prompt only.
Reason: Keeps the fresh build fully isolated from the unrelated existing codebase and its git history, satisfying the "do not reuse/mix" instruction literally, while still matching the master prompt's repo layout (`docs/`, `backend/`, `frontend/`, `shared/`, `scripts/`) inside the new repo root.
Reversibility: n/a (directory choice only; both projects can continue to run side by side under XAMPP on different vhost/ports).

## D-0004 | Phase 0 | Type: Workaround
Context: The shared XAMPP `php.ini` had the `intl`, `sodium` extensions and the `opcache` zend_extension commented out, all required by `scripts/check-env.ps1` (§5) and by Laravel (`intl` for translations/number formatting, `sodium` for Argon2id-adjacent crypto primitives and Laravel's encryption, `opcache` for performance).
Decision: Uncommented `extension=intl`, `extension=sodium` and `zend_extension=opcache` in `C:\xampp\php\php.ini`. This is additive (enables previously-unused, already-installed DLLs) and does not change any existing behaviour, so it carries no risk to the other project sharing this PHP install.
Reversibility: Re-comment the three lines in `C:\xampp\php\php.ini` to revert.

## D-0007 | Phase 0 | Type: Workaround
Context: `npm create vite@latest` installed Vite 8.3.5 and the matching bleeding-edge majors of Vitest 5 / ESLint 10 / eslint-plugin-react / @testing-library/react by default. On this machine (Node 22.14.0, below several packages' new engine floors) this produced three separate failures: (1) ESLint 10 crashed inside `eslint-plugin-react`'s React-version detection (`contextOrFilename.getFilename is not a function`), (2) Vitest 5 failed to even start its worker pool (`undici`'s `decompress.js` threw on an unsupported Node API), (3) after fixing those two, component-render tests under Vitest still threw `React is not defined` because the Vite 8 + `@vitejs/plugin-react` 6 + Vitest 2 combination was not applying the automatic JSX runtime during the Vitest transform pipeline speciifically (production `vite build` was unaffected - this was a test-pipeline-only incompatibility).
Options considered:
A. Chase individual config flags (explicit `jsxRuntime: 'automatic'`, cache clears) indefinitely.
B. Pin the whole frontend toolchain down to the most recent *mature* majors known compatible with each other and with Node 22.14: `vite@^6`, `@vitejs/plugin-react@^4`, `vitest@^2`, `eslint@^9` (+ matching `@eslint/js@^9`), `@testing-library/react@^16` / `jest-dom@^6` / `jsdom@^25`.
C. Upgrade the dev machine's Node to 24.x/26.x first.
Decision: B. After pinning these five package majors (and adding the missing `@testing-library/dom` peer dependency, which npm 8 does not auto-install), `npm run build`, `npm run lint`, and `npm run test` all pass with real output (see `docs/reports/phase-0.md`).
Reason: Option A was already tried (three targeted attempts: explicit automatic-runtime config, cache clear, explicit `React` import) without success before concluding the incompatibility was at the dependency-version level, not the config level (per the "try at least three different fixes" rule). Option C touches the shared machine's Node install, used by other local tooling, for an uncertain payoff — out of proportion to the fix actually needed.
Reversibility: Re-run `npm install -D vite@latest vitest@latest eslint@latest ...` and re-verify the three commands whenever the dev machine's Node version is updated past each package's new floor; nothing in application code depends on these exact versions.

## D-0005 | Phase 0 | Type: Assumption
Context: §1.2 pre-resolves "Regional preset: India preset as seed data" and "Login: Shop code + username by default."
Decision: Treated as given; no further decision needed. Noted here only for traceability back to the pre-resolved table.
Reversibility: n/a.
