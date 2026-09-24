# Build Q2 — Sale Zone/Area & Courier Management

Date: 2026-09-24
Baseline: `a6bba0b8d0800ab2560e083d8a828e7beea769f8` plus the restored Build Q1 Sale header

## Outcome

Two responsive management routes now sit under the Sales menu:

- `/next/sales/zones` — Zones / Areas
- `/next/sales/couriers` — Couriers

Each page supports server-side search, pagination, allowlisted sorting, create,
edit/update, last-updated display and an active-sale usage count. The existing
Create/Edit Sale, POS Sales and Shipment inline `CreatableSelect` flows remain
available and use the same records.

## Single source of truth

`App\Services\SaleLookupService` owns lookup-name behavior for both the inline
and management-page entry points:

- trims outer whitespace and collapses repeated inner whitespace;
- validates required/max-191 names;
- resolves duplicates case-insensitively;
- restores a matching soft-deleted value on create;
- rejects an update that would collide with another active or archived row;
- preserves the first canonical spelling entered by staff;
- handles normal unique-key create races without swallowing unrelated DB errors;
- allowlists list sort columns and sort direction.

Do not reimplement Zone/Courier creation directly in a page or controller.
Future entry points must call this service so duplicate behavior cannot drift.

## API contracts

| Method | Endpoint | Purpose | Authorization |
|---|---|---|---|
| GET | `sale_meta` | Lightweight active options for existing workflows | Existing Sale/POS/Shipment read workflow |
| GET | `sale_zones` | Paginated management list | Existing Sale/POS/Shipment read workflow |
| POST | `sale_zones` | Inline/page create or restore | Sale create/edit, POS or Shipment create/edit |
| PUT | `sale_zones/{zone}` | Rename | `Sales_edit` through `SalePolicy::update` |
| GET | `sale_couriers` | Paginated management list | Existing Sale/POS/Shipment read workflow |
| POST | `sale_couriers` | Inline/page create or restore | Sale create/edit, POS or Shipment create/edit |
| PUT | `sale_couriers/{courier}` | Rename | `Sales_edit` through `SalePolicy::update` |

No delete/archive action was added in Q2. Removing a lookup that historical
sales reference needs an explicit product decision; rename is safe because the
sale stores the lookup ID and therefore retains its relationship.

## Customer search on Create/Edit Sale

The Sale form bootstrap now returns `id`, `name`, `phone`, `email` and `code`
for customers in create, edit and quotation-conversion modes. The selector:

- searches locally by name, phone, email or customer code;
- shows phone/email/code beneath the customer name in the dropdown;
- keeps only the customer name as the selected label;
- preserves the existing `onClientChange()` side effects for loyalty points,
  credit limit, previous dues and customer payment terms.

This does not add a query per keystroke or an N+1 query. The existing one-time
customer bootstrap query simply selects four additional scalar columns. Very
large installations may later replace the full list with a debounced,
permission-protected remote customer-search endpoint.

## Files

- `app/Services/SaleLookupService.php`
- `app/Http/Controllers/SaleMetaController.php`
- `app/Models/SaleZone.php`
- `app/Models/SaleCourier.php`
- `app/Http/Controllers/SalesController.php`
- `routes/api.php`
- `resources/src/pages/sales/SaleLookupManager.vue`
- `resources/src/pages/sales/SaleForm.vue`
- `resources/src/router/index.js`
- `resources/src/config/menu.js`
- `tests/Regression/build_q2_sale_lookup_management.cjs`
- generated `public/js/**`

## Verification

- `npm run build:admin` — passed; 6,978 modules transformed.
- `node tests/Regression/build_q2_sale_lookup_management.cjs` — passed.
- `node tests/Regression/build_q1_sale_form_payment_terms_header.cjs` — passed.
- `node tests/Regression/build_m2_payment_terms_fixes_and_due_date_display.cjs` — passed.
- `git diff --check` — passed.
- PHP CLI and a live/test database are unavailable in this workspace, so PHP
  lint, route execution, DB-backed PHPUnit and browser E2E are not claimed.

## Mandatory staging/live smoke test

1. Open Sales → Zones / Areas and Sales → Couriers on desktop and mobile.
2. Search, sort and paginate both lists.
3. Create a new Zone and Courier from their pages; verify each appears in a
   fresh Create Sale without a cache clear.
4. Create another value inline from Create Sale; verify it appears on its
   management page after refresh.
5. Rename both values; verify old sales still show the new canonical name and
   the Create Sale lists update on its next load.
6. Confirm blank, over-191 and case/space-equivalent duplicate names are blocked.
7. With a user lacking `Sales_edit`, confirm Edit is hidden and PUT returns 403.
8. Search the Create Sale customer select by name, phone, email and code; select
   each result and confirm dues, loyalty/credit and payment terms still load.

## Deferred enhancements

These are intentionally not part of Q2:

- active/inactive archive with reference-aware safeguards;
- Zone delivery charge, district/thana mapping or courier-specific rate;
- courier contact/API credentials and shipment-booking integration;
- zone/courier performance KPI page beyond the existing report;
- remote customer search for very large customer datasets.
