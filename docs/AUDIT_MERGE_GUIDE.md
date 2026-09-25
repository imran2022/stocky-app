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
| `Services/ReportQuestionService.php` (Costing) | few lines | by-product profit cost through the ledger when on |
| `Http/Controllers/ReportController.php`, `DashboardController.php`, `TodaySummaryController.php` (Inventory write-off) | few lines each | Damage + Adjustment-decrease cost expensed in profit, via `App\Support\Reporting\InventoryWriteOffFigures` — NOT gated behind costing, applies always |
| `resources/src/pages/reports/ProfitAndLossReport.vue`, `resources/src/pages/dashboard/modern/sections/InsightsSection.vue` (Inventory write-off) | small | new write-off row/segment (needs `npm run build:admin`) |
| `database/seeders/translations/en.php` (Inventory write-off) | 1 line | new `Inventory_writeoff` key (needs re-seeding `TranslationSeeder`) |
| `resources/src/pages/settings/SystemSettings.vue` (Costing Method) | few lines | new sidebar item + `embeddedPages`/`currentSection` entries for `CostingSettings.vue` |
| `routes/api.php` (Costing Method) | 2 lines | `GET`/`POST costing_settings` |
| `database/seeders/translations/en.php` (Costing Method) | 6 lines | new `Costing_*` keys (needs re-seeding `TranslationSeeder`) |
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
