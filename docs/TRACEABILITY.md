# TRACEABILITY.md

Maps each requirement to the code that implements it and the test that proves it, per `docs/MASTER_PROMPT.md` section 15. Populated incrementally as each phase lands — a row is added in the same commit as the code/test it documents, never retrofitted later from memory.

## Phase 0

| Requirement | Code | Test / evidence |
|---|---|---|
| `scripts/check-env.ps1` verifies PHP/Composer/MySQL/Node per section 5 | [scripts/check-env.ps1](../scripts/check-env.ps1) | Executed; see `docs/reports/phase-0.md` for real output |
| Dynamic-Only Rule CI/static check (section 4) fails on role-name literals, currency/tax literals, literal UI text | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed against a deliberately injected violation (both backend and frontend), confirmed fail, violation removed, confirmed pass — real output in `docs/reports/phase-0.md` |
| Golden vectors: tax inclusive/exclusive, multi-component, line/invoice discount, cash rounding (section 0, section 9) | [shared/golden-vectors/tax.json](../shared/golden-vectors/tax.json), [discounts.json](../shared/golden-vectors/discounts.json), [rounding.json](../shared/golden-vectors/rounding.json) | Defined only in Phase 0; consumed by both pricing engines starting Phase 4 |
| ERD / data dictionary / threat model / sync design skeleton | [ERD.md](ERD.md), [data-dictionary.md](data-dictionary.md), [threat-model.md](threat-model.md), [sync-design.md](sync-design.md) | Reviewed manually; revisited at end of each phase that adds the tables/flows they describe |
| OpenAPI skeleton | [openapi.yaml](openapi.yaml) | Structurally valid YAML; full per-endpoint schemas land with their owning phase |
| CI pipeline runs backend + frontend tests and the Dynamic-Only check on every push | [.github/workflows/ci.yml](../.github/workflows/ci.yml) | Will execute on first push to `common_pos`; local-equivalent runs documented in `docs/reports/phase-0.md` |

## Phase 1 onward

_(empty — filled in as Phase 1 lands tenancy, auth, settings, modules, templates, etc.)_

## Screen-ID mapping (section 11)

_(empty at Phase 0 — section 11's screen catalogue (AUTH-xx, POS-xx, SET-xx, etc.) is mapped to actual routes/components starting in Phase 1, as each screen is built. No screens exist yet.)_
