# StockyUltimate AI/Human Developer Handoff

## Start here

This codebase is a customized Stocky 5.8 application. The received active
baseline already contained safe overlays A through E3. Builds F, G1, Phase 0,
and PO+GRN continue that chain; the PO+GRN registration hotfix is the latest
layer. Do not restart from a clean vendor ZIP and do not replace the
application with an older cumulative archive.

Read in this order before changing code:

1. `docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`
2. `CUSTOMIZATIONS.md` — chronological customization contracts and reasons
3. The latest `BUILD_*_SAFE_OVERLAY_README.md` and file manifest
4. `README_VENDOR_UPDATE_BN.md` — deployment history and Bangla operator notes
5. Relevant no-dependency tests in `tests/Regression/`
6. Relevant source, route, model, migration, and compiled-asset references

`README.md` is the vendor changelog. `documentation.zip` contains the vendor's
end-user documentation and screenshots; it is not the authoritative record of
custom behavior.

## Active release chain

| Layer | Purpose | Status |
| --- | --- | --- |
| Stocky 5.8 merged baseline | Vendor 5.8 plus historical custom modules | Active base |
| A/A.1/B | Stabilization, currency fallback, authorization hardening | Active |
| C/D1/D2 | Product Insights performance, scope, and analytics correctness | Active |
| E1 | POS Recent visibility and historical currency | Active |
| E2 Option B | Metadata validation; authorized operational inline Zone/Courier creation | Active |
| E3 | Sales/POS Seller/Currency parity; Currency default-hidden | Active |
| F | Stock Lookup unit, active-variant, and variant-price cleanup | Active |
| G1 | Product Insight base-quantity readiness | Active |
| Phase 0 | Purchase Form quick wins | Active |
| PO+GRN | Purchase Order workflow linked to GRN receipts | Active |
| PO+GRN registration hotfix | API/router/menu registrations packaged, not manual | Latest |

## Non-negotiable business/technical contracts

- Use small SAFE overlays. Never delete the existing application tree for a
  routine patch.
- Preserve the current customized 5.8 baseline. Full vendor replacement can
  erase working customizations.
- Schema changes require migrations; never edit production schema manually.
- Money, stock, totals, FIFO/COGS, warehouse scope, and permission changes need
  real test-database verification before production.
- Keep POS Sales separate and permanently POS-only (`is_pos: 1`).
- Currency is available but default-hidden on Sales and POS Sales.
- E2 Option B remains active: authorized operational users may create Zone and
  Courier inline, with centralized validation. No Zone/Courier edit/delete UI
  was added.
- `box_qty` is informational and must not affect stock/cost calculations unless
  the business explicitly approves a new design.
- Stock Lookup reports existing base-stock quantities; Build F changes labels,
  active-variant filtering, and display pricing only.
- PO+GRN schema and business rules are documented in
  `PO_GRN_SAFE_OVERLAY_README.md`. The registration hotfix changes only
  API/router/menu wiring; it does not alter received quantities or stock.

## Working areas

- Backend: `app/`, especially controllers, models, services, and support code.
- API: `routes/api.php`; portal routes are separated in `routes/portal.php`.
- Vue 3 admin source: `resources/src/`.
- Server-rendered/PDF views: `resources/views/`.
- Database evolution: `database/migrations/`.
- Deployable frontend assets: `public/js/`; active hashes are resolved through
  `public/js/.vite/manifest.json`.
- PWA cache: `public/sw.js`; bump monotonically when a deployed same-name chunk
  is surgically synchronized.
- Module packages: `Modules/`; preserve unless the relevant change explicitly
  targets a module.
- Regression gates: `tests/Regression/`; most are readable source-contract tests
  that can run without Composer dependencies, but they still require a PHP CLI.

## Before every change

1. Confirm the live baseline and last applied overlay.
2. Write a one-sentence scope boundary and list excluded areas.
3. Locate every write/read entry point for the same business field.
4. Inspect warehouse/ownership/permission and soft-delete behavior explicitly.
5. Prefer additive/backward-compatible API fields.
6. Update source-of-truth, deployed assets if required, tests, customization log,
   release note, and exact manifest together.
7. Test on staging/realistic data, then deploy with backup and rollback ready.

## Release artifacts expected from a developer

- `*_SAFE_OVERLAY.zip`: only exact files to copy over the active application.
- `*_FILE_MANIFEST.txt`: complete included and intentionally excluded paths.
- Release README: prerequisites, apply steps, verification, and rollback.
- Updated `CUSTOMIZATIONS.md` and architecture/handoff notes.
- Regression tests and their results, including any environment limitation.
- A sanitized full source snapshot for developer continuity when requested. It
  must exclude `.env`, OAuth/private keys, session/cache/log files, live database
  dumps, `vendor/`, and `node_modules/`.

## Known handoff limitation

The uploaded source snapshot did not include `.git`, so commit history could not
be verified from this package. The detailed append-only customization log and
build manifests are therefore the available continuity record. If an external
Git repository exists, keep it as the canonical versioned source and commit each
overlay there with the matching release name.

## Current PO/GRN state (read before touching PurchasesController.php or PurchaseOrderController.php)

As of PO+GRN Phase 1.4, the full lifecycle audit and required next steps are in
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`. In short: Phase
1.4 added supplier-match validation, a row-locked over-receipt check inside
`PurchasesController::store()`'s transaction, and an explicit block on editing
a received PO-linked GRN (see `CUSTOMIZATIONS.md`'s "PO+GRN Phase 1.4"
section for exactly what changed and why). Full linked-GRN edit
*reconciliation* (letting such an edit succeed safely) is still not built —
edit remains blocked, not fixed. The engineering pass that made these changes
had no `vendor/` directory and no network access to Composer/Packagist, so
only static source-contract checks (`tests/Regression/build_po_grn_phase1_4.php`)
were run; `tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md` lists the
real-database scenarios that must still be run before this is production-verified.
