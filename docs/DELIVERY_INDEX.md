# Latest Delivery Index

## Current: Inventory Costing update 5 — Legacy Profit Report: completed-only, returns netted, base units, per-warehouse cost (2026-09-25)

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
