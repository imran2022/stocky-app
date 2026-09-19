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

## PO+GRN registration hotfix smoke test

- Run `php tests/Regression/build_po_grn.php`; it must print PASS.
- Run `php artisan route:list --path=purchase_orders` and confirm resource,
  supplier lookup, GRN lines, document, PDF, and email routes are present.
- A role with `purchase_orders` sees the PO menu and can open list/create/view;
  a role without it cannot.
- Confirm the existing active Vite entry resolves both PO page chunks.
- Create no test transaction merely to verify this wiring hotfix; PO/GRN data
  behavior is unchanged from the already-applied feature.

Rollback by restoring the three registration files and prior regression test,
then running `php artisan optimize:clear`. No migration rollback is required for
this registration-only hotfix.

## PO+GRN Phase 1.4 smoke test (supplier match, over-receipt lock, unsafe-edit block)

Changed files: `app/Services/Custom/PurchaseOrderReceiptService.php`,
`app/Http/Controllers/PurchasesController.php`. No migration, no compiled
asset change (backend-only).

- Run `php tests/Regression/build_po_grn.php`,
  `php tests/Regression/build_po_phase1_3.php`, and
  `php tests/Regression/build_po_grn_phase1_4.php`; all three must print PASS.
- Then run every scenario in
  `tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md` against a real test
  database — these were NOT run by the engineering pass that wrote this code
  (no `vendor/`/database access in that environment). Do not deploy to
  production until they have been run and pass.
- Spot-check that ordinary, non-PO-linked GRN create/edit/delete still works
  exactly as before — Phase 1.4 only adds checks on the PO-linked path.

### Rollback trigger

Roll back if: any of the three regression gates fail; a legitimate GRN
within the PO's remaining quantity is rejected; a same-supplier PO is
rejected as a supplier mismatch; editing a non-received or non-linked GRN is
newly (incorrectly) blocked; or any ordinary non-PO-linked purchase
create/edit/delete behavior changes.

### Rollback procedure

1. Restore the pre-Phase-1.4 versions of the two changed files listed above.
2. Run `php artisan optimize:clear`.
3. Re-run `php tests/Regression/build_po_grn.php` and
   `php tests/Regression/build_po_phase1_3.php` to confirm the Phase 1.3
   baseline is intact.
4. Record the failing scenario and data IDs without copying secrets or
   customer data into tickets or AI chats.
