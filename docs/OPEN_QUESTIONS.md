# OPEN_QUESTIONS.md

Per the Autonomy Charter, this is **not** a stopping point — it is a list of low-risk items a human may want to review later. All items below have already been decided (see `docs/DECISIONS.md`) and do not block progress.

- D-0001: PHP 8.2.12 used locally instead of the 8.3 minimum / 8.5 target — review when the dev machine's PHP is upgradeable without risking the unrelated `pos` project that shares it.
- D-0003: Project lives at `C:\xampp\htdocs\common_pos`, a new sibling directory to the existing `C:\xampp\htdocs\pos` — confirm this is where the operator wants it long-term (e.g. local vhost name, deployment path).
- D-0008: Tamil and Hindi platform translations are machine-drafted, flagged `needs_review` — have a native speaker check them before go-live.
- D-0009: The platform guard still uses `Auth::viaRequest` (unlike the tenant guard, fixed to avoid stale-cache reauthentication after revocation) because no platform login/revocation endpoint exists yet to exercise the same bug — apply the `AuthenticateTenant`-style fix when platform auth endpoints are built.
- D-0011: Frontend stores auth tokens in plain `localStorage` for now (Phase 1, online-only) instead of the Phase 7 encrypted-IndexedDB device-key mechanism — route through that instead once Phase 7 builds it.
- D-0015: Phase 2 built catalogue/inventory backend only, no frontend screens (PRD-xx, INV-xx from section 11) — confirm this reading of the phase boundary, or ask for the screens to be added before Phase 4.
- D-0019: Phase 3 built customers/suppliers/shifts/expenses backend only, no frontend screens (CUS-xx, FIN-xx) — same reading as D-0015; confirm or ask for them to be added.
