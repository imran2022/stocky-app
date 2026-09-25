# Latest Delivery Index

## Current: Divisions management, inline on the Zone/Area page — no longer hardcoded (2026-09-27)

Follow-up to the Zone/Division feature below: Divisions were fixed reference data with no admin UI. First
built as a separate "Divisions / States" page, then folded into the existing Zone/Area create/edit modal per
the client's request (no separate page/menu): the Division field is now a `CreatableSelect` (type an
existing name to select it, type a new one to create it — the same behavior Zone/Area's own name field
already has), with a rename/delete icon pair next to it acting on the currently-selected Division (delete
blocked while a Zone/Area still uses it, same guard pattern as Zone/Area's own delete). Custom Divisions can
be added and picked directly on the Zone/Area form (just no auto-suggest, since there's no district list
behind a custom one). New: `POST bd_divisions`, `PUT`/`DELETE bd_divisions/{division}`,
`build_division_crud.php`. No migration. Description: `CUSTOMIZATIONS.md`, checklist section 51.

## Current: Zone/Area → Bangladesh Division linking (2026-09-27)

New feature (not from the external audit), requested directly by the client: the Zone/Area list
(`sale_zones`) can now optionally be linked to one of Bangladesh's 8 Divisions, auto-detected from the
Zone's name against a seeded 64-district reference table (with common alternate spellings as aliases) but
always overridable — never a silent guess — plus a new "delete a Zone/Area once nothing uses it" capability
that didn't exist before. Groundwork for future Division-wise performance/reporting.

- New reference tables `bd_divisions` (8 rows) / `bd_districts` (64 rows, with `aliases`), seeded and
  idempotent in `database/migrations/2026_09_27_000001_create_bd_geography_and_zone_division_link.php`.
- New nullable `sale_zones.division_id`, backfilled for every pre-existing zone whose name exactly matches a
  known district/alias (case/punctuation-insensitive); left `NULL` on no match.
- `GET sale_divisions`, `GET sale_zones/suggest_division`, `DELETE sale_zones/{zone}` — new endpoints.
- The Zone/Area management page (`SaleLookupManager.vue`) gained a Division column, an editable
  Division dropdown (live-suggested while typing, always human-overridable, both at creation and on edit),
  and a delete button (disabled with a tooltip while the zone still has linked sales).
- The Sale form itself is unchanged — still only picks a Zone; Division is always derived server-side, never
  a separate field there. `SaleCourier` (the sibling lookup sharing the same service) is unaffected.

No migration risk (additive, nullable, idempotent), no change to any existing Sale/Shipment workflow's
behavior. Full regression suite re-run clean; only the same pre-existing, unrelated test-data-drift failures
remain (see `AUDIT_MERGE_GUIDE.md`/test comments — not caused by this batch). Description:
`CUSTOMIZATIONS.md` ("Zone/Area → Bangladesh Division linking"), checklist section 50, new test
`tests/Regression/build_zone_division_linking.php`.

## Current: External "Must-Fix" audit — Tier 1 (MF-05, MF-03, MF-07, MF-14) + costing-parity fixes (2026-09-25)

A separately-supplied external audit document (`STOCKY_MUST_FIX_MAJOR_ISSUES_AUDIT_2026-09-25.md`, 16 findings
MF-01–MF-16 plus RP-01) was triaged into tiers with the client; Tier 1 (the 4 most urgent, no business decision
needed) is fixed and fully regression-tested. Also delivered in the same batch: two costing-parity fixes found
independently (Adjustment PDF vs on-screen report; `ReportQuestionService::salesByProduct()` vs Profit Report).

- **MF-05** — POS/Sales could no longer be spoofed into skipping stock deduction/oversell-guard checks by
  submitting `product_type: is_service` for a genuinely physical product; type is now always resolved from the
  database. `CreatePOS()`'s stock deduction also moved onto the locked `StockMutator::lockOrCreate()`.
- **MF-03** — Damage no longer accepts a negative/zero/non-numeric quantity, and no longer silently CLAMPS an
  over-large damage to whatever stock exists (document quantity and real stock movement could disagree before);
  it is now REJECTED with a clean 422 when unavailable (unless "Allow overselling" is on). `store()` also gained
  warehouse authorization it was missing.
- **MF-07** — A "resolve a fallback unit then discard it" copy-paste bug, the same class already fixed in Sale
  Return, was found independently in 8 more places in `SalesController.php` — in `update()`/
  `delete_by_selection()` it silently leaked stock forever on an old/legacy sale line with no stored unit; in 6
  display/PDF/prefill methods it just showed a blank unit. All 8 fixed.
- **MF-14** — The Damage/Adjustment write-off expense in Profit & Loss/Dashboard/Today Summary (legacy costing
  mode) no longer values every past loss at TODAY's live master cost — it uses a date-anchored historical
  (purchase) cost as of the report's own end date, the same approach already used for legacy COGS.
- **Costing parity** — the Adjustment PDF's cost column now matches the on-screen Stock Adjustment report in
  Moving Average mode too; the "sales by product" report-question answer is rebuilt on the same normalized lines
  the Profit Report uses, so the two can no longer disagree.

No migrations, no frontend changes in any of the six fixes. Full regression suite re-run clean after each fix;
only the same 15 pre-existing, unrelated test-cleanup/display-quirk failures remain (documented in
`AUDIT_MERGE_GUIDE.md`/test comments — not caused by this batch). Description: `CUSTOMIZATIONS.md` (six new
entries), checklist sections 45–49, new tests `audit_fix_adjustment_pdf_costing_parity.php`,
`audit_fix_mf05_pos_product_type_spoof.php`, `audit_fix_mf03_damage_validation.php`,
`audit_fix_mf07_sale_null_unit_reversal.php`, `audit_fix_mf14_writeoff_historical_cost.php`.

Still open from the same external audit, pending the client's business decision (Tier 3, not blocking): MF-04
(Transfer — `sent` stage has no availability guard, and no row-lock before the completion check; the
sent/completed stock-movement timing itself was confirmed correct as-is), MF-13 (Combo products — whether a combo
should carry its own independent stock, or be purely virtual/derived from its components' stock; Sale/POS and
Damage/Adjustment currently disagree on this and cannot be safely unified without that decision).

## Earlier: Sale Return pack/unit integrity (2026-09-25)

A follow-up external review of update 5 found the Sale Return flow itself had two further,
pre-existing bugs (not caused by update 5): `create_sell_return()` (the return-form prefill
endpoint) never sent back a sale line's `product_pack_id`/`pack_multiplier`/`pack_name`, so the
frontend's own `?? 1` fallback silently turned a multi-pack sale into a "return of 1 base unit" the
moment it was returned — understating both the restocked quantity and (in Legacy mode) the reported
COGS reversal by a full pack's worth every time; separately, a sale line with no explicit
`sale_unit_id` had its resolved default unit discarded by an unconditional `$unit = null;`, repeated
identically in `store()`/`update()`/`destroy()`/`delete_by_selection()` — the real failure mode was
an uncaught FK `QueryException` (HTTP 500), not a silent skip. Both reproduced live and fixed with
one new `App\Support\SaleReturnStock`: `resolveUnit()` keeps a resolved fallback instead of
discarding it; `deriveSnapshot()` re-derives a return line's pack/unit **server-side** from the
original sale detail (a browser-submitted `pack_multiplier`/`sale_unit_id` is never trusted for a
return line again) and throws a clean, caught 422 when nothing resolvable exists; `baseQuantity()`/
`applyStock()` (through the existing `StockMutator::lockOrCreate`) are the one stock-mutation
implementation now used identically by all four call sites. Moving Average's own reversal
(`MovementSource::saleReturns()`) reads the same persisted `sale_return_details` row, so it is fixed
for free by the corrected data — no Costing-engine change was needed. No migration, no frontend
change (the existing Vue form already round-trips whatever the prefill sends; the fix is entirely in
what the backend sends/derives/trusts). Description: `CUSTOMIZATIONS.md` "Sale Return pack/unit
integrity", checklist section 44, test `audit_fix_sale_return_pack_unit.php`.

## Earlier: Inventory Costing update 5 — Legacy Profit Report: completed-only, returns netted, base units, per-warehouse cost (2026-09-25)

An external code review of update 4 found the Legacy Profit Report had several further, pre-existing bugs (not
caused by update 4): pending/draft sales counted as revenue, received sale returns never netted off, box/pack sales
undercosted (raw sale-unit quantity instead of base units), and — after update 4's fix — the new historical average
blending different warehouses' costs into one number. All four reproduced live and fixed: new
`App\Support\Reporting\LegacyProfitLines` (completed sales + received returns, base-unit quantities, mirrors
`CostingReader::profitLinesTemp()`); `HistoricalCostAtDate` is now warehouse-keyed. Two remaining Legacy limitations
are deliberately NOT engineered away and are now documented + surfaced in the UI: one flat report-window average
(not true transaction-time cost) and adjustment history still valued at today's master cost. Legacy is relabeled
"Legacy / Estimated Cost" with an on-screen warning and a confirm-before-switch-back dialog.
**Needs `npm run build:admin`** (`CostingSettings.vue` changed) and
`php artisan db:seed --class=Database\Seeders\TranslationSeeder --force` (reworded + 3 new translation keys). No
migration. Description: `CUSTOMIZATIONS.md` "Inventory Costing — update 5", checklist section 43, test
`audit_fix_profit_report_legacy_correctness.php`.

## Current: Inventory Costing update 4 — Profit Report legacy COGS is now historical (2026-09-25)

Legacy-mode Profit Report used to value every sale line at TODAY's master/variant cost instead of the cost that was
actually in effect when the sale happened — so correcting a product's cost today silently rewrote past periods'
reported profit. Found during the owner's 4-5 year historical audit. Fixed with a new
`App\Support\Reporting\HistoricalCostAtDate` (does not touch the shared `CalculatesCogsAndAverageCost` trait) —
mirrors that trait's own date-anchored average-cost math, joined into the Profit Report's legacy branch. Moving
Average mode is untouched. Also: the update-3 "Costing Method" translation strings were reworded to read as plain
shop-owner language instead of a technical spec (keys unchanged). No migration, no frontend rebuild for the report
fix; **needs `php artisan db:seed --class=Database\Seeders\TranslationSeeder --force`** for the reworded strings.
Description: `CUSTOMIZATIONS.md` "Inventory Costing — update 4", checklist section 42, test
`audit_fix_profit_report_legacy_cost.php`.

## Current: Inventory Costing update 3 — Costing Method setting in System Settings (2026-09-26)

A "Costing Method" item in System Settings lets the business owner switch Legacy/Moving Average from the UI instead
of the CLI (`costing:rebuild --apply --enable` under the hood, no new costing logic). Not a FIFO engine — only
switches between the two existing methods. No migration. **Needs `npm run build:admin`** (1 new Vue file + 1
changed) and `php artisan db:seed --class=Database\Seeders\TranslationSeeder --force` (6 new translation keys).
Description: `CUSTOMIZATIONS.md` "Inventory Costing — update 3", checklist section 41, test
`build_costing_settings_ui.php`.

## Current: Inventory Costing update 2 — Damage/Adjustment losses expensed (2026-09-26)

Damage documents and Adjustment DECREASES now reduce reported profit (Profit & Loss, Dashboard, Today Summary) —
valued at master cost when costing is off, at the Moving Average ledger cost when it's on. Adjustment INCREASES are
deliberately never counted (accounting conservatism — not income until sold). Not gated behind the costing switch —
every store gets this correctness fix. No migration. **Needs `npm run build:admin`** (2 Vue files changed) and
`php artisan db:seed --class=Database\Seeders\TranslationSeeder --force` (1 new translation key).
Description: `CUSTOMIZATIONS.md` "Inventory Costing — update 2", checklist section 40, test
`build_writeoff_expense.php`.

## Current: Inventory Costing — Moving Average (2026-09-26)

Per-sale-line COGS and historically-correct stock value, replacing the old master-cost-at-report-time math. OFF by
default (`inventory_cost_meta.costing_method = 'legacy'`) — deploying changes nothing until an admin runs
`php artisan costing:rebuild --dry-run` (compare, no change) then `--apply --enable`. New tables only
(migration `2026_09_26_000001_create_inventory_costing_tables.php`); every report hook is additive and a no-op while
off. Description: `CUSTOMIZATIONS.md` "Inventory Costing (Moving Average)", checklist section 39, tests
`unit_costing_engine.php` / `build_costing_scenario.php` / `build_costing_realworld.php` / `build_costing_stress.php`
/ `build_costing_large.php` (all in `tests/Regression/`).
Known limitations: order-level landed cost not yet allocated per line; damage/adjustment-out losses not deducted from
Profit report profit (accounting-policy decision left to the business); batch/expiry/GRN-trace (FIFO) is a Phase 2
candidate behind the same `costing_method` switch.

## Current: Modern Dashboard (2026-09-25)

Classic/Modern dashboard switch, drag-and-drop layout, business insights, recent activity and Bangladesh sales map.
Needs `php artisan migrate` (new table `dashboard_preferences` + column `allow_user_switch`), `php artisan optimize:clear` and Ctrl+F5 (new admin assets in `public/js`).
Update 5: map island fix + always-named districts, Recent activity tabs/day groups, Menu tab opens sidebar, product rankings skipped when hidden (no migration; source-only, rebuild `npm run build:admin`).
Update 4: KPI badge/In-Out overflow fix, map zoom (+/-/reset/pan), phone bottom navigation bar (no migration).
Update 3: mobile KPI amount fix, Customize hidden on phone, sales map placed by Zone, always-visible district labels, typography (no migration beyond v2).
Description: `CUSTOMIZATIONS.md` "Modern Dashboard" (update 3), checklist section 38, tests `audit_ui1_dashboard_prefs.php` / `audit_ui2_dashboard_insights.php`.

## Current: Audit Batches 1-6 (2026-09-24)

Delivered as git bundles `STOCKY_AUDIT_FIX_B1..B5_2026-09-24.bundle` (each on top of the previous) plus live-deploy zips
`LIVE_DEPLOY_AUDIT_B4/B5_2026-09-24.zip`. Batch 5 needs `php artisan migrate` (indexes) and `php artisan optimize:clear`.
Batch 6 (Dashboard hourly charts) adds rebuilt `public/js` admin assets, no migration.
Description: `CUSTOMIZATIONS.md` "Audit Batches 1-5" and "Audit Batch 6", checklist section 36, merge procedure
`docs/AUDIT_MERGE_GUIDE.md`, tests `tests/Regression/audit_*.php` (`tests/run_regression.sh`).

## Current: PO+GRN Phase 1.4 — supplier match, over-receipt lock, unsafe-edit block

Applied directly to source (no new overlay zip was packaged for this pass).
Full description, verification performed, and known remaining limitations are
in `CUSTOMIZATIONS.md`'s "PO+GRN Phase 1.4" section and
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`. Static
regression gate: `tests/Regression/build_po_grn_phase1_4.php`. Real-database
verification steps (not yet run in this engineering environment — see that
handoff doc's environment note): `tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md`.

## Earlier: PO+GRN Phase 1.2 / Phase 1.3

Phase 1.2 (real English labels, actionable list errors) and Phase 1.3 (real
read-only PO view, PO columns/attachments, transactional cumulative GRN
deletion safety) are described in `PO_GRN_PHASE1_2_UI_ERROR_FIX_SAFE_OVERLAY_README.md`,
`PO_GRN_PHASE1_3_SAFE_OVERLAY_README.md`, `PO_GRN_PHASE1_3_FILE_MANIFEST.txt`,
and `CLAUDE_PO_GRN_ZIP_AUDIT.md`, and are already reflected in the live
codebase.

## Earlier: PO+GRN Registration Hotfix

## Deployment file

`PO_GRN_REGISTRATION_HOTFIX_SAFE_OVERLAY.zip` is the latest patch. Apply it only
after the PO+GRN feature overlay. It packages the API, SPA router, and sidebar
menu registrations that the first package left as manual instructions. It has
no migration or compiled asset replacement.

The exact content and exclusions are in
`PO_GRN_REGISTRATION_HOTFIX_FILE_MANIFEST.txt`; checksums are in
`PO_GRN_REGISTRATION_HOTFIX_SHA256SUMS.txt`.

## Earlier Build F artifacts

## 1. STOCKY_BUILD_F_SAFE_OVERLAY.zip

Use this file for deployment. It contains only Build F runtime/source changes,
cumulative test maintenance, and release/handoff documentation. Extract it into
the existing active Stocky application root and overwrite matching files. Do not
delete the application first.

The exact contents and exclusions are in `BUILD_F_FILE_MANIFEST.txt`; runtime
checksums are in `BUILD_F_SHA256SUMS.txt`.

## 2. STOCKY_5.8_BUILD_F_DEVELOPER_SOURCE_SANITIZED.zip

Use this file for future AI/human development and code review. It is a complete
sanitized source snapshot of the uploaded active application after Build F. It is
not a production replacement package.

Excluded for security or reproducibility:

- `.env` and Git metadata
- OAuth/private keys
- session, cache, log, and updater runtime files
- live/local database files
- `vendor/` and `node_modules/`

Dependencies are reproducible from `composer.lock` and `package-lock.json`.
Vendor end-user documentation remains available in `documentation.zip`.

## Canonical continuity files

- `docs/AI_HUMAN_DEVELOPER_HANDOFF.md`
- `docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`
- `docs/RELEASE_AND_ROLLBACK_RUNBOOK.md`
- `docs/BASELINE_AUDIT_2026-09-12.md`
- `docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- latest `BUILD_*_SAFE_OVERLAY_README.md` and `BUILD_*_FILE_MANIFEST.txt`
