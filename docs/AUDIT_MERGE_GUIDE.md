# Audit changes and how to merge a new vendor version (e.g. 5.9)

Every audit fix follows one rule: **new logic lives in its own class, the vendor controller only gets a small hook.**
All hooks are marked with a comment containing `Audit Batch` / `Audit fix (Batch` so they can be found with:

    git grep -n "Audit Batch\|Audit fix (Batch\|Audit B[0-9]" -- app routes

## New files (cannot conflict with the vendor)

| File | What it does |
|---|---|
| `app/Support/StockGuard.php`, `StockDocumentRules.php`, `LiveDocument.php` | stock and document-state rules (no negative stock, edit/delete guards) |
| `app/Support/SaleReturnLimits.php`, `PurchaseReturnLimits.php` | a return can never exceed what was sold / bought |
| `app/Support/SaleTotalsVerifier.php` | server-side check of sale totals |
| `app/Support/PaymentReconciler.php` | paid amount and status always recomputed from payment rows; overpayment refused; an edit cannot shrink a document below what is already paid |
| `app/Support/LoyaltyRedemption.php` | loyalty points redemption rules |
| `app/Support/CashRegisterCash.php` | cash register expected-cash formula |
| `app/Support/Reporting/SalesFigures.php` | ONE definition of sales / tax / shipping / discount / net used by Dashboard, Profit & Loss, Analytics, Tax and Discount summaries |
| `app/Support/Reporting/DashboardHourly.php` | Dashboard one-day range: hourly Sales/Purchases and Payment data |
| `app/Support/Reporting/CashFlowFigures.php` | Cash Flow: one set of entries feeds both table and chart; includes return refunds |
| `database/migrations/2026_09_24_000001_add_report_and_stock_indexes.php` | report / date / stock indexes (additive, idempotent) |
| `tests/Regression/audit_*.php`, `tests/run_regression.sh` | regression tests, incl. seeded stock and money stress tests |
| `app/Services/Costing/MovingAverageEngine.php`, `MovementSource.php`, `InventoryCostingService.php`, `CostingReader.php` | Inventory Costing (Moving Average) — see `CUSTOMIZATIONS.md`; no-op while `costing_method = legacy` |
| `app/Console/Commands/CostingRebuild.php` | `php artisan costing:rebuild` — dry-run compare, apply, enable, verify |
| `database/migrations/2026_09_26_000001_create_inventory_costing_tables.php` | `inventory_cost_*` tables (additive, idempotent) |
| `tests/Regression/unit_costing_engine.php`, `build_costing_scenario.php`, `build_costing_realworld.php`, `build_costing_stress.php`, `build_costing_large.php` | costing regression + large-data tests |
| `app/Support/Reporting/InventoryWriteOffFigures.php` | Damage + Adjustment-decrease cost expensed in profit (master cost / legacy, or ledger cost when costing is on) |
| `tests/Regression/build_writeoff_expense.php` | write-off regression test (both costing modes) |
| `app/Http/Controllers/Settings/CostingSettingsController.php` | Costing Method settings endpoint (web wrapper around `costing:rebuild --apply --enable`, no new costing logic) |
| `resources/src/pages/settings/CostingSettings.vue` | Costing Method System Settings UI (own GET/POST, same pattern as `FeatureToggles.vue`) |
| `tests/Regression/build_costing_settings_ui.php` | Costing Method settings UI regression test |
| `app/Support/Reporting/HistoricalCostAtDate.php` | date-anchored, WAREHOUSE-SPECIFIC average cost per product/variant/warehouse, purchases + adjustments up to a date (legacy Profit Report fix) |
| `tests/Regression/audit_fix_profit_report_legacy_cost.php` | Profit Report legacy historical-cost regression test |
| `app/Support/Reporting/LegacyProfitLines.php` | normalized legacy profit lines: completed sales + received returns (netted), base-unit quantities |
| `tests/Regression/audit_fix_profit_report_legacy_correctness.php` | Legacy Profit Report correctness regression test (status/returns/base-units/warehouse) |
| `app/Support/SaleReturnStock.php` | Sale Return pack/unit resolution + locked stock mutation — ONE implementation used by `store()`/`update()`/`destroy()`/`delete_by_selection()` |
| `tests/Regression/audit_fix_sale_return_pack_unit.php` | Sale Return pack/unit integrity regression test |
| `app/Support/Reporting/AdjustmentLineCost.php` | per-adjustment-detail ledger cost (Moving Average) for the Adjustment PDF, matching the on-screen report |
| `tests/Regression/audit_fix_adjustment_pdf_costing_parity.php` | Adjustment PDF / on-screen report costing-parity regression test |
| `tests/Regression/audit_fix_mf05_pos_product_type_spoof.php` | MF-05: POS/Sales canonical product type regression test |
| `tests/Regression/audit_fix_mf03_damage_validation.php` | MF-03: Damage quantity validation + authoritative availability check regression test |
| `tests/Regression/audit_fix_mf07_sale_null_unit_reversal.php` | MF-07: SalesController legacy null-unit stock reversal regression test |
| `tests/Regression/audit_fix_mf14_writeoff_historical_cost.php` | MF-14: write-off historical-cost regression test |
| `database/migrations/2026_09_27_000001_create_bd_geography_and_zone_division_link.php` | Bangladesh Divisions/Districts reference tables (seeded), `sale_zones.division_id`, one-time backfill (additive, idempotent) |
| `app/Models/BdDivision.php`, `app/Models/BdDistrict.php` | Bangladesh geography reference-data models |
| `app/Support/BdDistrictMatcher.php` | exact, alias-aware Zone/Area name → Division id resolver (never fuzzy; `null` on no match) |
| `tests/Regression/build_zone_division_linking.php` | Zone/Area → Division linking regression test (suggest, create/update auto-resolve vs explicit choice, delete guard) |
| `resources/src/pages/sales/DivisionManager.vue` | Divisions management page (create/rename/delete — Divisions are no longer hardcoded) |
| `tests/Regression/build_division_crud.php` | Divisions management regression test |

## Vendor files that carry hooks (re-check after taking a new vendor version)

Size = lines added / removed against the pre-audit version.

| File | Size | Nature of the change |
|---|---|---|
| `Http/Controllers/ReportController.php` | +329 / -775 | reports moved onto `SalesFigures`; completed-only rules; warehouse scope via `filterWarehouseId`; cash flow moved to `CashFlowFigures`; unit lookups cached. **Largest conflict risk - re-apply by hand.** |
| `Http/Controllers/DashboardController.php` | +31 / -1 | status filters and `SalesFigures` profit |
| `Http/Controllers/SalesController.php`, `PurchasesController.php`, `SalesReturnController.php`, `PurchasesReturnController.php` | 20-65 lines each | one-line guards (`StockGuard`, `*ReturnLimits`, `PaymentReconciler::assertTotalCoversPayments`) around store/update/destroy |
| `Http/Controllers/Payment{Sales,Purchases,SaleReturns,PurchaseReturns}Controller.php` | 20-40 lines each | `PaymentReconciler::sync*` / `assertWithinDue` calls |
| `Http/Controllers/PosController.php`, `AdjustmentController.php`, `TransferController.php`, `CashRegisterController.php`, `QuotationsController.php` | 2-45 lines | stock and cash guards |
| `Http/Controllers/ModuleSettingsController.php`, `SettingsController.php`, `QuickBooksController.php`, `CustomFieldController.php`, `Api/Store/PagesApiController.php`, `WooCommerceSyncController.php` | 1-13 lines | permission checks that were missing (module upload, cache clear, integration secrets) |
| `Http/Controllers/ClientController.php`, `ProvidersController.php`, `PublicInvoiceController.php`, `TodaySummaryController.php`, `SalesController.php` (customer figures) | 1-60 lines | balances count only received sale returns / completed purchase returns; Today's summary rebuilt on `SalesFigures` + shared COGS |
| `Http/Controllers/DashboardController.php` (Batch 6) | +8 | extra `hourly` key from `Support/Reporting/DashboardHourly`; deleted expenses ignored in the payment chart |
| `Http/Controllers/DashboardController.php` (Modern Dashboard) | ~+35 / -25 | four additive numeric keys in `report_dashboard.report` (`today_net_revenue`, `today_cogs`, `today_expenses`, `return_purchases_amount`); `TopProductsMonth()` extracted unchanged from `report_dashboard`; `skip=products` / `only=products` query flags (Classic never sends them) |
| `resources/src/router/index.js` (Modern Dashboard) | 1 line | `Dashboard` route now loads `pages/dashboard/DashboardSwitch.vue` (which mounts the unchanged Classic `Dashboard.vue`) |
| `resources/src/pages/Dashboard.vue` (Modern Dashboard) | 1 line | `return_purchases` uses `return_purchases_amount` (fixes NaN for >= 1,000) |
| `Traits/CalculatesCogsAndAverageCost.php` | +118 / -57 | COGS in base units, sale returns netted |
| `Traits/CalculatesCogsAndAverageCost.php` (Costing) | +6 | when Moving Average is on, returns the stored per-line COGS instead of recomputing; no-op while off |
| `Http/Controllers/ReportController.php` (Costing) | ~+70 | `inventory_valuation_summary`, `stock_inventory_valuation`, `Warhouse_Count_Stock`, `analyticsSummary`, `negative_stock_report`, `deadStock`, Adjustment report cost — all branch on `CostingReader::active()`, legacy branch unchanged |
| `Http/Controllers/DashboardController.php`, `TodaySummaryController.php` (Costing) | few lines each | stock value at cost through `CostingReader`, no-op while off |
| `Support/Reporting/DashboardInsights.php` (Costing) | few lines | slow-stock value through `CostingReader`, no-op while off |
| `Http/Controllers/ProfitReportController.php` (Costing) | ~+40 | base query, cost expression and dimensions swap to the ledger when on (via `CostingReader::profitLinesTemp`, one materialized temp table per request instead of re-joining per dimension query); legacy branch unchanged |
| `Http/Controllers/ProfitReportController.php` (Inventory Costing update 4) | ~+10 | legacy branch's cost expression now prefers a date-anchored average cost (`App\Support\Reporting\HistoricalCostAtDate`, joined via one temp table) over today's master/variant cost, falling back to it only when a product/variant has no purchase/adjustment history; Moving Average branch untouched |
| `Http/Controllers/ProfitReportController.php` (Inventory Costing update 5) | rewritten | legacy branch now builds on `App\Support\Reporting\LegacyProfitLines` (completed sales + received returns, base-unit quantities) instead of raw `sale_details`; historical cost join now also matches `warehouse_id`; the dimension/label array is now SHARED between both costing modes (previously duplicated) |
| `Services/ReportQuestionService.php` (Costing) | few lines | by-product profit cost through the ledger when on |
| `Http/Controllers/ReportController.php`, `DashboardController.php`, `TodaySummaryController.php` (Inventory write-off) | few lines each | Damage + Adjustment-decrease cost expensed in profit, via `App\Support\Reporting\InventoryWriteOffFigures` — NOT gated behind costing, applies always |
| `resources/src/pages/reports/ProfitAndLossReport.vue`, `resources/src/pages/dashboard/modern/sections/InsightsSection.vue` (Inventory write-off) | small | new write-off row/segment (needs `npm run build:admin`) |
| `database/seeders/translations/en.php` (Inventory write-off) | 1 line | new `Inventory_writeoff` key (needs re-seeding `TranslationSeeder`) |
| `resources/src/pages/settings/SystemSettings.vue` (Costing Method) | few lines | new sidebar item + `embeddedPages`/`currentSection` entries for `CostingSettings.vue` |
| `routes/api.php` (Costing Method) | 2 lines | `GET`/`POST costing_settings` |
| `database/seeders/translations/en.php` (Costing Method) | 6 lines | new `Costing_*` keys (needs re-seeding `TranslationSeeder`); wording reworded to plain shop-owner language in update 4 (same keys, values only); 3 more `Costing_*` keys + relabeled Legacy option in update 5 |
| `resources/src/pages/settings/CostingSettings.vue` (Inventory Costing update 5) | few lines | warning banner when Legacy is selected; confirmation dialog before switching from Moving Average back to Legacy (needs `npm run build:admin`) |
| `Http/Controllers/SalesReturnController.php` (Sale Return pack/unit integrity) | rewritten | `create_sell_return()`/`edit_sell_return()` prefill now carry the pack snapshot + a resolved unit; `store()`/`update()`/`destroy()`/`delete_by_selection()` rebuilt on `App\Support\SaleReturnStock` — re-derives pack/unit server-side, one locked stock mutation, a clean 422 instead of an uncaught SQL error |
| `Http/Controllers/AdjustmentController.php` (costing parity) | few lines | `adjustment_pdf()` prefers `AdjustmentLineCost::forDetails()` (ledger cost) over live master cost when Moving Average is on |
| `Services/Costing/CostingReader.php`, `Support/Reporting/LegacyProfitLines.php` (costing parity) | few lines each | `profitLinesBase()`/`profitLinesTemp()`/`temp()` gain optional `$viewRecords`/`$userId` params, default unchanged |
| `Services/ReportQuestionService.php` (costing parity) | rewritten | `salesByProduct()` rebuilt on `LegacyProfitLines`/`CostingReader::profitLinesTemp()` instead of a raw `SaleDetail` join |
| `Http/Controllers/PosController.php`, `SalesController.php` (MF-05) | few lines each | `product_type` for stock-check/oversell-guard decisions always resolved from the database, never from the request; `CreatePOS()`'s stock deduction uses `StockMutator::lockOrCreate()` |
| `Support/StockGuard.php` (MF-05) | few lines | `needsFromLines()` resolves product type from the database before including a line, closing the same client-trust gap at the shared layer |
| `Http/Controllers/DamageController.php` (MF-03) | ~+30 | quantity validation before any write; `$clampFloor` removed, replaced by `StockGuard::assertAvailable()`; `store()` gained warehouse authorization |
| `Http/Controllers/SalesController.php` (MF-07) | 8 one-line fixes | a legacy line with no `sale_unit_id` no longer has its resolved fallback unit discarded — fixes a stock-reversal leak in `update()`/`delete_by_selection()` and a missing unit label in 6 display/PDF/prefill methods |
| `Support/Reporting/HistoricalCostAtDate.php`, `InventoryWriteOffFigures.php` (MF-14) | few lines / rewritten | `temp()` gains `$includeAdjustments` (default true, unchanged for existing callers); write-off cost now uses a purchases-only historical average as of the report's `to` date instead of today's master cost |
| `Services/Custom/PurchaseOrderReceiptService.php`, `Support/UniqueRefGenerator.php` | small | GRN line checks, reference collision retry |
| `routes/api.php` | -5 | dead routes and the public `products_clean_names` route removed |
| `resources/src/pages/Dashboard.vue` (Batch 6) | ~+45 | hourly line/bars when `hourly` is present (needs `npm run build:admin`) |
| `resources/src/pages/reports/ProfitAndLossReport.vue` | +16 / -6 | new rows/components (needs `npm run build:admin`) |
| `database/seeders/translations/en.php` | 1 line | Dashboard Sales tooltip text |

## Procedure for a new vendor version

1. Merge the vendor code on a branch. Conflicts will only appear in the files listed above.
2. For every conflict keep the vendor code, then re-apply the hook found with the `git grep` above.
3. `composer install` / `npm ci && npm run build:admin`, run the migrations (the index migration is safe to run twice).
4. Run `tests/run_regression.sh` against a throw-away database (`RESET_CMD` restores a clean copy before each script).
   A red `audit_*` test names exactly which hook was lost.
