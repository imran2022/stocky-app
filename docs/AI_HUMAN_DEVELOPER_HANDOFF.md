# StockyUltimate AI/Human Developer Handoff

## Start here

This codebase is a customized Stocky 5.8 application. The received active
baseline already contained safe overlays A through E3. Build F is the current
latest layer. Do not restart from a clean vendor ZIP and do not replace the
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
| G1 | Unit/Multi-Pack base-unit normalization for Product Insights | Active |
| Phase 0 | Purchase form: Last Purchase hint, inline cost edit, Sell Price/Profit Margin % | Active |
| PO+GRN | Purchase Order lifecycle, GRN linkage, receipt tracking, security hardening | Active |
| PO Documents+Columns | PO attachment UI, Created By/Last GRN Date/Age/Attachment list columns | Active |
| PO Reports | Fulfillment stats (Open/Overdue), Price Variance Report | Active |
| Post-release fixes | Fresh-install migration/seeder ordering, Zone-Wise Report ambiguous column, `useCrudTable` params contract | Latest |

See `CUSTOMIZATIONS.md` entries 25–32+ for the detailed contract of everything
from G1 onward, including the "Hard lessons" callout in
`ARCHITECTURE_AND_CHANGE_CONTROL.md` for the specific incidents that produced
the "Post-release fixes" row above.

## Verification discipline — what "tested" means here

A change is not considered verified by any ONE of the following alone; all
that apply to the change must pass before it's called done:

- **Static/source regression** (`tests/Regression/build_*.php`) — fast,
  no database required, catches drift in the specific contracts each build
  established. Necessary but not sufficient on its own: these check that
  code *contains* the right patterns, not that the patterns *execute*
  correctly at runtime.
- **PHPUnit** (`php artisan test`) — for anything with a database-backed
  contract test.
- **Live data verification** — for backend changes, actually create the
  records, call the real endpoint (or the real controller method), and
  check the actual returned values match hand-computed expected values, not
  just "no exception was thrown." A query returning zero rows without error
  is not the same as a query returning the correct rows.
- **Frontend build success is a syntax check, not a correctness check.**
  Vite/esbuild verify the code is well-formed JavaScript; they do not verify
  that a composable is called with the shape it expects. A `computed()` ref
  passed where a plain function is expected builds cleanly and fails only at
  runtime, the first time that code path actually executes in a browser.
  When a real browser isn't available (a genuine, disclosed limitation of
  the environment this work was done in), the fallback is: (a) find and
  compare against an existing, already-working caller of the same
  composable/pattern in this codebase, and (b) when in doubt, reproduce the
  exact suspect call pattern in a minimal standalone script (Node.js shares
  the V8 engine with Chrome) rather than asserting confidence without
  either check.
- **Adversarial/security re-review for anything touching money, stock, or
  authorization** — a second pass specifically looking for what a
  malformed or malicious request could do, not just the happy path. This is
  what caught the PO/warehouse cross-linking gaps before deployment; it
  should be standard for any new write endpoint in these domains, not an
  occasional extra.

None of this replaces a human smoke-testing the actual feature in a real
browser against real data before considering a release fully done — the
regression suite and live-data checks reduce the chance of a defect, they
don't eliminate the value of that final human pass.

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
- PO status `partially_received`/`received` is computed only — never accept a
  client-supplied value for these two states (see
  `PurchaseOrderReceiptService`). GRN deletion must be checked for negative-
  stock safety BEFORE the delete's transaction opens, not inside it.

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
