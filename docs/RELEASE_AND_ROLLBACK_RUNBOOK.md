# Release and Rollback Runbook

## SAFE overlay deployment

1. Confirm the required base build in the overlay README.
2. Take a full application-file and database backup.
3. Record the current versions/checksums of every runtime file in the manifest.
4. Extract the overlay at the application root; overwrite only included paths.
5. Do not replace `.env`, uploads, `vendor/`, `node_modules/`, modules, or any
   unrelated public asset tree unless the release manifest explicitly requires it.
6. Run `php artisan optimize:clear`.
7. Run every cumulative regression command listed in the release README.
8. Smoke-test affected screens with representative roles, warehouses, products,
   variants, currencies, and statuses.
9. Reload clients; use one hard refresh when the release changes a PWA asset.
10. Keep the backup until functional and data reconciliation checks pass.

## Build F smoke test

- Simple product with unit `Pcs`: quantities show Pcs and normal price.
- Simple product with a non-Pcs unit: every badge/row/summary shows that unit.
- Variant product with one common price: header shows one price.
- Variant product with different prices: header/search shows min–max range.
- Soft-delete one variant: it cannot be found by its code/GTIN, does not appear
  expanded, and its stock row is excluded from the total.
- Restricted user: only assigned warehouses appear; unrestricted user sees all.
- Sales, POS Sales, Shipments, Product Insights, PDFs, and normal product pages
  remain unchanged.

## Rollback trigger

Rollback if a regression gate fails, the Stock Lookup API errors, warehouse scope
widens, active variants disappear incorrectly, totals differ beyond the deleted
variant exclusion, or unrelated workflows change.

## Rollback procedure

1. Stop further deployment/copy operations.
2. Restore the pre-release versions of runtime files listed in the exact manifest.
3. Run `php artisan optimize:clear`.
4. Reload/hard-refresh affected clients.
5. Re-run the previous build's cumulative regression sequence.
6. Record the failing scenario and data IDs without copying secrets or customer
   data into tickets or AI chats.

Build F has no migration and no data rewrite, so file restoration is sufficient.
