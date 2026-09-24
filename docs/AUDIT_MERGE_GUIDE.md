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
| `Traits/CalculatesCogsAndAverageCost.php` | +118 / -57 | COGS in base units, sale returns netted |
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
