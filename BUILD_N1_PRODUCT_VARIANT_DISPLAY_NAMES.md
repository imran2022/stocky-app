# Build N1 — Readable Product + Variant Display Names

## Result

Variable products now use one canonical user-facing label everywhere:

```text
Apple Macbook 2026 - Variant: Grey
```

The previous format is retired:

```text
[Grey]Apple Macbook 2026
```

Simple products remain unchanged. Product names, variant names, SKUs, IDs,
stock keys, prices, and document calculations are not migrated or rewritten.
This build changes presentation only.

## Architecture

`app/Support/ProductDisplayName.php` owns the display rule:

```php
ProductDisplayName::format($productName, $variantName);
```

Rules:

- product + variant: `Product - Variant: Variant name`;
- product without a variant: `Product`;
- blank variant: no suffix;
- missing product fallback: `Variant: Variant name`;
- surrounding whitespace is removed; multi-option names such as
  `Blue / XL` are preserved.

Keeping this rule in one formatter prevents product lists, POS, reports, and
documents from drifting into different naming styles again.

## Covered surfaces

- Products/item lists, stock lookup results, low-stock output, and suggestions.
- POS search/history/cart loaders.
- Sales, purchases, purchase orders, quotations, returns, transfers,
  adjustments, and damage documents.
- Reports and exported/rendered report rows.
- Sale invoice, purchase/quotation/return PDFs, packing/print flows, public
  invoice, and client portal invoice PDF.
- Online-order invoices and store order APIs.
- Kitchen order item labels and Xero line descriptions.

## Apply the overlay

1. Back up the application and database.
2. Extract the overlay into the Stocky project root, preserving directories.
3. No database migration or seeder is required.
4. Refresh autoload and clear application caches:

   ```bash
   composer dump-autoload
   php artisan optimize:clear
   ```

5. Rebuild production assets as part of the normal release process:

   ```bash
   npm ci
   npm run build
   ```

6. Run the focused tests:

   ```bash
   php vendor/bin/phpunit tests/Unit/ProductDisplayNameTest.php
   php tests/Regression/build_n1_product_variant_display_name.php
   ```

## Acceptance checks

- A variable product named `Apple Macbook 2026`, variant `Grey`, displays as
  `Apple Macbook 2026 - Variant: Grey`.
- The same label appears in Product list/suggestions, POS, Sale Create, saved
  sale details, invoice/PDF, Purchase/PO, returns, and reports.
- A simple product still displays only its product name.
- Searching by product name, variant name, and variant code still works.
- SKU/code and `product_variant_id` remain unchanged.
- No `[Variant]Product` label remains in covered application code.

## Rollback

Restore the files listed in the overlay manifest from the pre-deployment
backup, or revert the Build N1 Git commit. No database rollback is required.
