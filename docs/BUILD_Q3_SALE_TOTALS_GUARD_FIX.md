# Build Q3 — Sale totals guard correction

Date: 2026-09-24

## Failure and root cause

Create/Edit Sale calculates the document total as:

`line totals - manual discount - points discount + order tax + shipping`

The Build N1 backend security guard omitted order tax and points discount from
its Grand Total formula. Consequently, a legitimate sale such as
`2997 - 299.70 + 269.73 + 10 = 2977.03` was rejected with HTTP 422 even though
the UI values were correct.

## Correction

- `SaleTotalsGuard::checkGrandTotal()` now follows the same operation order and
  two-decimal discount behavior as `resources/src/lib/lineCalc.js`.
- Both `SalesController::store()` and `SalesController::update()` pass `TaxNet`
  and `discount_from_points` into that validation.
- Existing per-line consistency validation, TaxNet plausibility protection and
  rejection of tampered totals remain active.

## Scope and risk

No schema, stored data, stock movement, payments, COGS, POS, returns,
quotations, purchases or UI component was changed. The fix affects only the
pre-save validation formula for normal Sale create/update. No `PosPage.vue`
change was made, so the service-worker version remains `stocky-pwa-v14`.

## Verification

Automated coverage includes the reported percentage-discount/order-tax/
shipping example, points discount before order tax, tampered-total rejection,
and static confirmation that both controller paths supply the required values.

Run:

```bash
node tests/Regression/build_q3_sale_totals_guard.cjs
php artisan test --filter=SaleTotalsGuardTest
npm run build:admin
```

The PHP test and live API/browser smoke tests require the deployment/staging PHP
environment. Compiled assets are intentionally deferred to the deployer.
