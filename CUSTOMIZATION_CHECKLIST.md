# StockyUltimate — Customization Checklist

## 0. Purpose of this document

This is a **single standing inventory** of every customization ever made
to this app on top of the vendor (CodeCanyon) baseline. Unlike
`CUSTOMIZATIONS.md` (which is a chronological *story* of how and why each
piece was built), this file is meant to be **checked off**, item by item,
in two situations:

1. **After any fresh install** — `php artisan migrate:fresh --seed` — to
   confirm every customization actually survived the install and is
   visible/working, not just present in source code.
2. **Before or after adding any new feature** — as a checklist of things
   a new feature might silently break (a shared component, a permission
   pattern, a PWA cache) even though it doesn't touch that feature's own
   files.

This document exists because of **two bug classes that have already each
happened twice** in this codebase's history (see the dedicated section
below, "Two known bug classes — check these every time"):

- a permission granted only by a migration's own inline logic, which
  silently grants to nobody the moment the app is freshly installed
  (`migrate:fresh --seed`), because seeders run *after* migrations and the
  role/permission links don't exist yet when the migration runs;
- a new permission that is never added to the static, hand-maintained
  `resources/src/config/permissions.js` catalogue, so it can never be
  assigned to any role from the Roles & Permissions screen in the UI —
  even though the permission exists correctly in the database and the
  backend enforces it correctly everywhere else.

Every entry below gives: what the customization does, exactly which files
it touches, and a "How to verify" checklist written so a **non-coder** (or
a future AI with no memory of this project) can run it mechanically —
open a page, click something, or run a `grep`/artisan command and check
the output — without needing to read or understand the code.

---

## 1. Two known bug classes — check these every time

These are not tied to one feature. Run both checks against **every**
future permission or feature addition, not just the ones listed in this
document.

### (a) Migration-granted permissions silently drop on a fresh install

**The pattern:** a migration creates a new `permissions` row and, in the
same migration, grants it to whatever role(s) already hold some other
permission (e.g. "grant `activity_log_report` to whoever has
`report_device_management`"). This works fine on an **already-running**
site (`php artisan migrate` alone), because roles and `permission_role`
links already exist. It silently does nothing on a **fresh install**
(`php artisan migrate:fresh --seed`), because Laravel always runs all
migrations before any seeder — so at the moment that migration runs, the
`permission_role` table is completely empty. The permission row gets
created, but is granted to **zero** roles — invisible to everyone,
including a brand-new Owner account.

This exact bug has already been found and fixed **twice**: for
`purchase_orders` (fixed by `PurchaseOrdersPermissionSeeder`) and for
`activity_log_report` (Build K.4, fixed by `ActivityLogPermissionSeeder`).

- [ ] For every permission created by a migration's own inline grant
      logic, confirm there is a matching seeder class that re-runs the
      *same* grant rule.
- [ ] Confirm that seeder is called in
      `database/seeders/DatabaseSeeder.php`, **after** `PermissionRoleSeeder`
      in the `$this->call([...])` list (not before — order matters).
- [ ] Run `php artisan migrate:fresh --seed` on a disposable/test database,
      log in as a fresh Owner account, and confirm the new
      permission's feature (its menu link, its checkbox in Roles &
      Permissions) is visible — not just present in the `permissions`
      table.

### (b) New permission missing from the static `permissions.js` catalogue

**The pattern:** `resources/src/config/permissions.js` is a static,
hand-maintained file (not a live read of the `permissions` database
table) that drives what checkboxes appear on the Roles & Permissions
screen. Any permission added to the database — by a migration, a seeder,
or any other means — that is **not** also added as an entry in this file
can never be assigned to any role from the UI, even though it exists
correctly in the database and works correctly everywhere the backend
checks it.

This is Bug 2 of Build K.4. It was found by diffing all 309 canonical
`permissions` database rows against the file's (then) 305 entries.

- [ ] For every new permission, confirm it has a matching object in
      `resources/src/config/permissions.js`, in the appropriate group,
      using the file's existing `{"v": "...", "l": "...", "f": "..."}`
      pattern (`v` = the permission's real name, `l`/`f` = its label —
      plain English text is fine, no translation-table row needed).
- [ ] `grep` the exact permission string against the file and confirm a
      match, e.g.:
      `grep -n "activity_log_report" resources/src/config/permissions.js`
- [ ] As Owner (or any role with permission-editing rights), open
      **Roles & Permissions**, pick any role, and confirm the new
      permission's checkbox is visible in its group — not just that the
      feature itself works.

### (c) Related pattern: `PosPage.vue` changes need a service-worker version bump

Any edit to `resources/src/pages/pos/PosPage.vue` must be paired with
bumping `const VERSION = 'stocky-pwa-vNN'` in `public/sw.js` to a higher
number. This app is installed as a PWA; without a version bump, an
already-installed client keeps serving its old cached JS bundle and never
picks up the change, silently running stale POS code indefinitely. This
has already been done correctly for every prior `PosPage.vue` change
(Builds E1, and the still-pending Build K.3) — keep doing it.

- [ ] Whenever `PosPage.vue` changes, check `public/sw.js`'s `VERSION`
      constant was bumped in the same delivery.
- [ ] `grep -n "const VERSION" public/sw.js` and confirm the number is
      higher than the previous known value (currently `stocky-pwa-v12` as
      of Build F — see item 27 below; K.3 needs to bump this further, to
      at least v13).

---

## 2. Sales / POS / Shipments customizations

### 2.1 Tracking Ref / Zone / Courier on Sales

One-line description: adds a courier tracking number and reusable
Zone/Courier dropdown lists to every sale.

**Files:**
- `database/migrations/2026_09_06_000001_add_tracking_zone_courier_to_sales.php`
- `app/Models/SaleZone.php`, `app/Models/SaleCourier.php`, `app/Models/Sale.php`
- `app/Http/Controllers/SaleMetaController.php`
- `app/Http/Controllers/SalesController.php` (index/store/update/create/edit/show/PDF methods)
- `app/Http/Controllers/ReportController.php` (`Report_Sales`)
- `routes/api.php` (`sale_meta`, `sale_zones`, `sale_couriers`)
- `resources/src/components/CreatableSelect.vue`
- `resources/src/pages/sales/Sales.vue`, `SaleForm.vue`, `SaleDetails.vue`
- `resources/src/pages/reports/SalesReport.vue`

**How to verify:**
- [ ] Open a Sale (create or edit) — Tracking Ref field, and Zone/Courier
      dropdowns should be present next to Sales Agent.
- [ ] Type a brand-new Zone or Courier name and press Enter — it should
      be added and selected immediately (the "quick add" feature).
- [ ] Open the Sales list — Tracking Ref, Zone, Courier columns should
      appear with the values you just set.
- [ ] Open Reports > Sales Report — the same 3 filters/columns should be
      present.

**Regression test:** no dedicated automated regression test file exists
specifically for this (predates the `tests/Regression/build_*` test
series, which started with Build A). Covered only by manual verification
described in `CUSTOMIZATIONS.md` section 1.

### 2.2 Zone / Courier Report (new report page)

One-line description: a dedicated report showing sales volume/balance
grouped by Zone and by Courier.

**Files:**
- `app/Http/Controllers/ReportController.php@zoneWiseReport`
- `routes/api.php` (`report/zone_wise`)
- `resources/src/pages/reports/ZoneWiseReport.vue`
- `resources/src/router/index.js`, `resources/src/config/menu.js`

**How to verify:**
- [ ] Log in as Owner, go to Reports — a "Zone / Courier Report" (or, if
      the translation row is still missing, the literal text
      `Zone_Courier_Report`) menu entry should be present.
- [ ] Open it — two tables (Zone totals, Courier totals) should load,
      each with its own Excel/PDF export button.
- [ ] Sales with no zone/courier assigned should appear grouped under
      "Unassigned", not be missing from totals.

**Regression test:** no dedicated automated regression test file exists.
Manual check only.

### 2.3 Box quantity per sale line

One-line description: records "this line was packed as N boxes" —
informational only, never used in stock/cost math.

**Files:**
- `database/migrations/2026_09_07_000001_add_box_qty_to_sale_details.php`
- `app/Models/SaleDetail.php`, `app/Http/Controllers/SalesController.php`
- `resources/src/pages/sales/SaleForm.vue`, `SaleDetails.vue`
- `resources/views/pdf/sale_pdf.blade.php`

**How to verify:**
- [ ] Open Create Sale — a "Box" number input should appear in the line
      items table, between Net Unit Price and Stock.
- [ ] Save a sale with a Box value, open its PDF — a "Box" column should
      appear next to Qty (unless "Enable 'Box' Quantity" is turned off in
      System Settings — see 2.4 below).

**Regression test:** none dedicated. Manual check only.

### 2.4 Consignment ID, Return column, Shipping/Agent columns, Box qty toggle

One-line description: adds the courier's own shipment number, a Return
column, Shipping Charge/Sales Agent columns, and a setting to turn the
Box feature off.

**Files:**
- `database/migrations/2026_09_09_000001_add_consignment_id_and_box_qty_toggle.php`
- `app/Models/Sale.php`, `app/Models/Setting.php`
- `app/Http/Controllers/SalesController.php`, `SettingsController.php`
- `resources/src/pages/sales/Sales.vue`, `SaleForm.vue`, `SaleDetails.vue`
- `resources/src/pages/settings/SystemSettings.vue`

**How to verify:**
- [ ] Open System Settings > Sales > Features — "Enable 'Box' Quantity on
      Sales" toggle should be present, default ON.
- [ ] Turn it off, open Create Sale — the Box column should disappear
      from the line items table entirely (not just hidden with CSS).
- [ ] Turn it back on. Open the Sales list Columns picker (gear icon) —
      Return, Shipping Charge, Consignment ID, Sales Agent should be
      available (some default-hidden, see section 2.5 for the current
      visible/hidden layout after later changes).

**Regression test:** none dedicated. Manual check only.

### 2.5 Column reorder, fixed-column stripe bug fix, Sales Agent on Sale Detail, Consignment ID moved to bulk-only

One-line description: reorders Sales list columns, fixes a real visual
bug in the shared `DataTable` component, shows Sales Agent on Sale
Detail, and moves Consignment ID entry to the bulk-update modal only.

**Files:**
- `resources/src/pages/sales/Sales.vue`
- `resources/src/components/DataTable.vue`
- `app/Http/Controllers/SalesController.php` (`@show`)
- `resources/src/pages/sales/SaleDetails.vue`, `SaleForm.vue`

**How to verify:**
- [ ] Sales list column order should read: Action, Date, Reference,
      Tracking Ref, Zone, Customer, Warehouse, Status, Qty, Total, Paid,
      Due, Return, Payment Status, Shipping Status, Shipping Charge,
      Courier, Consignment ID, Sales Agent, Added by.
- [ ] On any DataTable-backed list with a fixed/sticky column (e.g. the
      "Action" column) and zebra striping on, scroll the table
      horizontally — the fixed column should stay a solid, theme-correct
      background color, never show a translucent "see-through" strip.
- [ ] Open a Sale's Create/Edit form — there should be **no** Consignment
      ID input field (it was intentionally removed from this form).
- [ ] Open the Sales list, select one or more rows, click "Update
      Selected" — the bulk-update modal should have a Consignment ID
      input (this is now the only place to set it).
- [ ] Open Sale Detail for a sale with a Sales Agent set — the agent's
      name should show in the top info block.

**Regression test:** none dedicated. Manual check only.

### 2.6 Sale Detail page — compact info layout

One-line description: pure CSS layout change, no data/logic.

**Files:** `resources/src/pages/sales/SaleDetails.vue`

**How to verify:**
- [ ] Open any Sale Detail page on a desktop-width screen — the info
      block (Warehouse/Tracking Ref/Zone/Courier/etc.) should render as a
      2-column grid, not a tall single-column table.
- [ ] Shrink the browser below ~640px width — it should collapse back to
      one column.

**Regression test:** none. Purely cosmetic, manual check only.

### 2.7 Shipping Label & Packing List (new PDFs)

One-line description: two new printable documents distinct from the full
invoice.

**Files:**
- `app/Http/Controllers/SalesController.php@Sale_Shipping_Label`, `@Sale_Packing_List`
- `routes/api.php` (`sale_shipping_label/{id}`, `sale_packing_list/{id}`)
- `resources/views/pdf/shipping_label.blade.php`, `packing_list.blade.php`
- `resources/src/pages/sales/SaleDetails.vue`

**How to verify:**
- [ ] Open a Sale Detail page — "Shipping Label" and "Packing List"
      toolbar buttons should be present next to the PDF button and should
      each download a distinct PDF.
- [ ] The Shipping Label PDF should show a COD amount banner only when
      the sale isn't fully paid, and (after Build A, see 3.2) that amount
      should be the outstanding balance, not the full invoice total.
- [ ] The Packing List PDF should show product/code/qty/box, with **no
      prices**.

**Regression test:** COD-amount correctness covered by
`tests/Regression/build_a_stability.php` (Build A item 16.2). The base
existence of these two PDFs has no dedicated automated test — manual
check only.

### 2.8 Sales list — bulk actions + Qty column

One-line description: bulk-print invoices/labels, bulk-update
status/zone/courier/tracking for multiple selected sales; a Qty column.

**Files:**
- `app/Http/Controllers/SalesController.php` (`Sale_PDF_Bulk`,
  `Sale_Shipping_Label_Bulk`, `bulkUpdate`, `renderSaleInvoiceHtml`,
  `splitHtmlDocument`, `combineHtmlDocuments`)
- `resources/src/pages/sales/Sales.vue`

**How to verify:**
- [ ] Sales list should show a "Qty" column (total quantity per sale).
- [ ] Select 2+ sales via the checkboxes — "Print Invoices", "Print
      Labels", and "Update Selected (N)" buttons should appear in the
      toolbar.
- [ ] "Print Invoices" on 2+ sales should produce **one PDF with exactly
      N pages** (one per sale) — not 2N pages and not a blank page after
      each invoice.
- [ ] "Update Selected" should open a modal where you can set only
      Shipping Status / Zone / Courier / Tracking Ref, and only fields
      you actually touch should be written (test: set only Zone, confirm
      Shipping Status on those rows is unchanged).

**Regression test:** none dedicated by name, but the bulk-authorization
scoping added on top of this (Build B) is covered by
`tests/Regression/build_b_authorization.php`.

### 2.9 Stock Lookup — multi-warehouse "where is it" tool

One-line description: search-first tool to see a product's stock split
by warehouse, from both the SPA and the legacy POS screen.

**Files:**
- `app/Http/Controllers/ProductsController.php` (`stockLookupSearch`, `stockLookupDetail`)
- `routes/api.php`
- `resources/src/pages/products/StockLookup.vue`
- `resources/src/pages/pos/PosPage.vue` (StockLookupModal)
- `resources/src/router/index.js`, `resources/src/config/menu.js`

**How to verify:**
- [ ] Products menu should have a "Stock Lookup" entry; opening it and
      typing a known SKU should show a per-warehouse quantity breakdown.
- [ ] In POS, click the "Stock Lookup" button next to "Scan" — the same
      search/detail flow should work inside a modal.
- [ ] For a variant product, each warehouse row should expand to show
      per-variant stock (see 2.10 below for the variant work specifically).
- [ ] A warehouse-restricted test user should only see their assigned
      warehouse(s) in the results, never other warehouses' stock.

**Regression test:** covered by `tests/Regression/build_f_stock_lookup_cleanup.php`
(Build F, unit-label/active-variant/price-range correctness — see 2.14 below).
No dedicated test for the original section 9 feature itself.

### 2.10 Stock Lookup: variant support + polish; POS Recent Invoices

One-line description: extends Stock Lookup to variant products; visual
refresh; new "Recent Invoices" POS tool to reprint/relabel a recent sale.

**Files:**
- `app/Http/Controllers/ProductsController.php@stockLookupDetail` (rewritten)
- `app/Http/Controllers/SalesController.php@posRecentSales`
- `resources/src/pages/products/StockLookup.vue`
- `resources/src/pages/pos/PosPage.vue` (RecentInvoicesModal)

**How to verify:**
- [ ] Stock Lookup on a variant product (e.g. 2 variants × 2 warehouses)
      should show each warehouse's summed qty, expandable to each
      variant's own qty at that warehouse, and a correct grand total.
- [ ] In POS, click "Recent Invoices" next to Hold — a list of the most
      recent sales should appear with per-row PDF and Label download
      buttons.

**Regression test:** POS Recent Invoices visibility/currency correctness
is covered by `tests/Regression/build_e1_pos_recent.php` (Build E1, see
2.17). The original feature itself has no dedicated earlier test.

### 2.11 POS Sales view, Shipments enhanced, Recent Invoices polish

One-line description: a Sales-list view scoped to POS-only sales; adds
courier/tracking to the existing Shipments feature; spacing/Edit-action
polish on Recent Invoices.

**Files:**
- `app/Http/Controllers/SalesController.php@index` (`is_pos` filter)
- `resources/src/pages/sales/PosSales.vue` (deliberate full copy of `Sales.vue`)
- `database/migrations/...` (adds `shipments.phone_number`)
- `app/Http/Controllers/ShipmentController.php`
- `resources/src/pages/sales/Shipments.vue`
- `resources/src/pages/pos/PosPage.vue`

**How to verify:**
- [ ] Sales submenu should have a "POS Sales" entry showing only
      `is_pos = 1` sales, with no "Add Sale" button.
- [ ] Shipments list should show Courier, Consignment ID, Tracking Ref
      columns; its Edit modal should have Delivered To + Phone Number,
      Courier Partner + Tracking ID, and a "Same as invoice address"
      checkbox.
- [ ] Creating a new Shipment and setting a phone number should persist
      it (confirmed further hardened in Build A item 16.5 — see 3.2).
- [ ] In POS Recent Invoices, an Edit button per row should navigate to
      that sale's edit page — but only when the current POS cart is
      empty; otherwise it should show a warning and not navigate.

**Regression test:** the authorization hardening of Shipments (Build B)
is in `tests/Regression/build_b_authorization.php`. `phone_number` on
create specifically is covered by `tests/Regression/build_a_stability.php`
(item 16.5). No dedicated test for the base PosSales/Shipments-columns
feature itself.

**Known drift risk:** `PosSales.vue` is a deliberate hand-maintained copy
of `Sales.vue`, not a shared component — if `Sales.vue` changes, check
whether `PosSales.vue` needs the same edit.

### 2.12 / 2.13 Recent Invoices modal cropping + scroll fixes

One-line description: two follow-up CSS-only fixes so the Recent
Invoices modal's action buttons and table are never clipped.

**Files:** `resources/src/pages/pos/PosPage.vue`

**How to verify:**
- [ ] Open POS > Recent Invoices at a typical desktop window width — the
      PDF/Label/Edit icon buttons on the right of each row should be
      fully visible, not cut off at the modal's edge.
- [ ] The modal itself should be wide enough (up to 900px) that the table
      doesn't need horizontal scrolling under normal conditions; if the
      viewport is narrow, the table area should scroll horizontally
      rather than clip.

**Regression test:** none. Purely cosmetic, manual check only.

### 2.14 Stock Lookup unit, active-variant, and price-display cleanup (Build F)

One-line description: shows the product's real stock unit (not always
"Pcs"), excludes soft-deleted variants from totals, and shows a
variant-aware price/price-range instead of the parent product's raw
price.

**Files:**
- `app/Http/Controllers/ProductsController.php`
- `resources/src/pages/products/StockLookup.vue`
- `public/js/chunks/StockLookup.D-UahGfI.js` (surgical compiled-asset sync)
- `public/sw.js` (bumped to `stocky-pwa-v12`)

**How to verify:**
- [ ] Search a product whose base unit is Kg/Box/Litre (not Pcs) in
      Stock Lookup — quantities should show that unit's short name, not
      "Pcs".
- [ ] A product with a soft-deleted variant should not show that
      variant's stock in the totals.
- [ ] A variant product with differing active-variant prices should show
      a price range ("X – Y"), not one arbitrary/zero price.
- [ ] `grep -n "const VERSION" public/sw.js` should show `v12` or higher.

**Regression test:** `tests/Regression/build_f_stock_lookup_cleanup.php`.

---

## 3. Build A stabilization series (post-5.8-merge fixes)

### 3.1 Overview

**Status:** ACTIVE. Six low-risk fixes found in a post-merge audit after
the vendor's Stocky 5.8 was merged in. Deliberately small in scope — no
stock rules, FIFO/COGS, schema, or permission architecture changed.

**How to verify (all of Build A at once):**
- [ ] Run `php tests/Regression/build_a_stability.php` — should pass with
      no failures (no PHP/Composer dependencies needed to run it).

### 3.2 Individual Build A fixes

| # | Fix | File(s) | How to verify |
|---|-----|---------|----------------|
| 16.1 | Bulk A4 invoice now matches 5.8 document-currency behavior | `SalesController.php`, `app/Support/SaleDocumentMath.php` | Create a foreign-currency sale, download its single invoice PDF and its bulk invoice PDF (via multi-select bulk print) — totals/currency code should match exactly between the two. |
| 16.2 | Shipping Label COD prints outstanding balance, not full total | `SalesController.php`, `resources/views/pdf/shipping_label.blade.php`, `SaleDocumentMath.php` | Part-pay an order, print its Shipping Label — the COD banner amount should equal `GrandTotal − paid_amount`, not the full `GrandTotal`. |
| 16.3 | Product Insight sorting no longer sends fake columns to SQL | `ProductsController.php` | Sort the Products list by "Sold (30d)" and by "Last Sold" — both should sort correctly with no error, no crash. |
| 16.4 | Shipment computed/joined sorting can no longer crash SQL | `ShipmentController.php` | Sort the Shipments list by Reference, Sale Ref, Customer, or Warehouse — all should sort without error. |
| 16.5 | Shipment creation now persists `phone_number` | `ShipmentController.php` | Create a **new** shipment (not edit) with a phone number — reopen it and confirm the phone number was saved. |
| 16.6 | Sale update no longer clears metadata on omitted fields | `SalesController.php` | Set Tracking Ref/Zone/Courier on a sale via the full edit form, then update the sale through a path that omits those fields (e.g. bulk-update with only one field) — the untouched fields must remain, not be wiped to null. |

**Regression test:** all six covered by `tests/Regression/build_a_stability.php`,
plus PHPUnit tests `tests/Unit/SaleDocumentMathTest.php` and
`tests/Unit/BuildAIntegrationContractTest.php`.

### 3.3 Build A.1 — Sales Currency column fallback + default-hidden UX

One-line description: base-currency sales no longer show a blank
Currency cell; the Currency column is available but hidden by default.

**Files:**
- `app/Http/Controllers/SalesController.php`
- `resources/src/pages/sales/Sales.vue`

**How to verify:**
- [ ] Open the Sales list Columns picker — "Currency" should be present
      but unchecked (hidden) by default.
- [ ] Enable it — a base-currency (legacy) sale should show the
      configured base currency code, not a blank cell.
- [ ] `grep -n "const VERSION" public/sw.js` should be `v10` or higher
      (this build first bumped the PWA cache to purge a stale
      always-visible Currency column).

**Regression test:** `tests/Regression/build_a1_currency_backend.php`,
`tests/Regression/build_a1_currency_column.php`,
`tests/Unit/BuildA1CurrencyColumnContractTest.php`.

**Delivery note (Section 20):** the Currency-column "hidden by default"
frontend change was initially deferred in a "safe overlay" delivery that
intentionally did not touch `public/js`/`Sales.vue`, to avoid replacing
the whole compiled frontend for one small change. Confirm the current
live `Sales.vue` actually has the `defaultHidden: true` flag on the
Currency column — don't assume it shipped just because A.1 is
"documented as done."

### 3.4 Build B — narrow authorization hardening

One-line description: fixes two IDOR-style data-isolation bugs in Bulk
Sales update and Shipments.

**Files:**
- `app/Http/Controllers/SalesController.php@bulkUpdate`
- `app/Http/Controllers/ShipmentController.php`

**How to verify:**
- [ ] As a warehouse-restricted user with `record_view` off, attempt (via
      a crafted request or by testing with two such users) to bulk-update
      a Sale that is NOT in your warehouse or NOT your own record — it
      must be silently ignored (no error, no change), not applied.
- [ ] As the same kind of restricted user, open a Shipment belonging to a
      Sale outside your visibility — it should be denied, not shown.
- [ ] Attempt to update a Shipment while submitting a different Sale's id
      in the payload — it must be rejected, and the Shipment's own
      `sale_id` must never be silently overwritten.
- [ ] Normal, in-scope Sales/Shipments bulk-update and edit should
      continue to work exactly as before for authorized users.

**Regression test:** `tests/Regression/build_b_authorization.php`,
`tests/Unit/BuildBAuthorizationContractTest.php`.

**Combined fast-check for the whole Build A/A.1/B chain:**
```
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
```

---

## 4. Product Insights series (Builds C, D1, D2)

### 4.1 Build C — set-based query optimization

One-line description: turns ~7 per-row insight queries per Product row
into 4 total set-based queries for the whole result page — performance
only, no formula change.

**Files:**
- `app/Http/Controllers/ProductsController.php`
- `app/Services/Custom/ProductInsightService.php` (new)

**How to verify:**
- [ ] Products list with insight columns enabled (Last Purchase, Sold
      30d, etc.) should load noticeably faster on a large catalog than
      before, with the same numbers as before for any given product.
- [ ] Run `php tests/Regression/build_c_product_insights.php` — should
      confirm the Product render loop makes no per-row insight query and
      the service executes exactly 4 metric queries.

**Regression test:** `tests/Regression/build_c_product_insights.php`,
`tests/Unit/BuildCProductInsightContractTest.php`.

### 4.2 Build D1 — warehouse visibility + soft-delete correctness

One-line description: Product Insight numbers (Sold/Return/Last
Purchase) now respect the logged-in user's warehouse visibility and
exclude soft-deleted parent Sales/Purchases — a real data-isolation and
correctness fix, not just performance.

**Files:**
- `app/Http/Controllers/ProductsController.php`
- `app/Services/Custom/ProductInsightService.php`

**How to verify:**
- [ ] As a warehouse-restricted user (no `is_all_warehouses`), open the
      Products list with insight columns on — Sold(30d)/Last
      Sold/Return-related numbers should reflect ONLY that user's
      assigned warehouse(s), not global totals. (Values may legitimately
      be lower than what an all-warehouse user sees — that is correct,
      not a bug.)
- [ ] A deleted (soft-deleted) Sale or Purchase must not contribute to
      any Product Insight number.
- [ ] Sorting the Products list by Sold(30d)/Last Sold must match the
      same scoped numbers actually displayed (no mismatch between sort
      order and shown value).

**Regression test:** `tests/Regression/build_d1_product_insight_scope.php`,
`tests/Unit/BuildD1ProductInsightScopeContractTest.php`.

### 4.3 Build D2 — exact 30-day windows + finalized Return Rate population

One-line description: fixes Sold(30d) to be exactly 30 calendar dates
(was 31), makes the current/previous windows non-overlapping, and
restricts Return Rate to only `received` (finalized) Sale Returns.

**Files:**
- `app/Http/Controllers/ProductsController.php`
- `app/Services/Custom/ProductInsightService.php`

**How to verify:**
- [ ] For a product with known sales on specific dates, confirm
      Sold(30d) counts exactly the last 30 calendar dates (today minus 29
      through today), not 31.
- [ ] A `pending` Sale Return (not yet `received`) must not count toward
      Return Rate; a `received` one must.
- [ ] Run `php tests/Regression/build_d2_product_analytics.php`.

**Regression test:** `tests/Regression/build_d2_product_analytics.php`,
`tests/Unit/BuildD2ProductAnalyticsContractTest.php`.

**Combined fast-check for the whole Product Insights chain:**
```
php tests/Regression/build_c_product_insights.php
php tests/Regression/build_d1_product_insight_scope.php
php tests/Regression/build_d2_product_analytics.php
```

### 4.4 Products list: business-insight columns (original feature, pre-C/D1/D2)

One-line description: the original 4 metrics — Last Purchase (date +
cost), Sold(30d), Last Sold, Warehouse Count — added as default-hidden
Products-list columns.

**Files:** `app/Http/Controllers/ProductsController.php@index`,
`resources/src/pages/products/Products.vue`

**How to verify:**
- [ ] Products list Columns picker should offer Last Purchase, Sold
      (30d), Last Sold, Warehouse Count (Last Purchase/Sold 30d are
      **visible by default** as of the follow-up in 4.5 below).
- [ ] These 4 fields should also appear in Excel/PDF export.

**Regression test:** none dedicated to the original feature; formula
correctness now covered indirectly by 4.1–4.3's tests.

### 4.5 Products list: Sales Trend, Revenue Contribution, Return Rate

One-line description: 3 more derived metrics — a 30d-vs-prior-30d trend
arrow, an estimated 30-day revenue figure, and a Return Rate percentage.

**Files:** `app/Http/Controllers/ProductsController.php@index`,
`resources/src/pages/products/Products.vue`

**How to verify:**
- [ ] Products list Columns picker should offer Trend, Revenue (30d), and
      Return Rate (all default-hidden).
- [ ] Trend should show a dash (not 0%/∞%) when the prior 30-day window
      was zero.
- [ ] A variant product with multiple prices should show "—" for Revenue
      (30d), not a nonsensical number.
- [ ] Return Rate ≥10% should render highlighted (red/bold).
- [ ] These 3 fields should also appear in Excel/PDF export.

**Regression test:** none dedicated to the original display feature; the
underlying Return Rate formula's date/status correctness is covered by
4.3's `build_d2_product_analytics.php`.

---

## 5. POS Recent Invoices / Sales parity (Builds E1, E2, E3)

### 5.1 Build E1 — POS Recent Invoices visibility + historical currency

One-line description: Recent Invoices in POS now follows the same
ownership/`record_view` rule as the main Sales list, is restricted to
POS-origin completed sales only, and shows each sale's own historical
document currency instead of whatever currency is currently selected in
the register.

**Files:**
- `app/Http/Controllers/SalesController.php`
- `resources/src/pages/pos/PosPage.vue`
- `public/js/chunks/PosPage.LolIxUV8.js` (surgical sync)
- `public/sw.js` (bumped to `stocky-pwa-v10`)

**How to verify:**
- [ ] As a restricted (non-`record_view`) user, Recent Invoices in POS
      should show only that user's own sales, never another user's.
- [ ] Recent Invoices should only ever list `is_pos = 1`, completed
      sales — never a non-POS sale.
- [ ] Create a foreign-currency sale, change the register's active
      currency afterward, reopen Recent Invoices — the old sale's amount
      must still show in **its own** stored currency/rate, not
      recalculated at the new rate.
- [ ] `grep -n "const VERSION" public/sw.js` should be `v10` or higher.

**Regression test:** `tests/Regression/build_e1_pos_recent.php`,
`tests/Unit/BuildE1PosRecentContractTest.php`.

### 5.2 Build E2 — Sale metadata validation + centralized rules

One-line description: centralizes and hardens validation for
zone_id/courier_id/tracking_ref/consignment_id/box_qty and Shipment
status across every entry point that can write them (Sale create/edit,
bulk-update, Shipment edit).

**Files:**
- `app/Http/Controllers/SalesController.php`, `ShipmentController.php`,
  `SaleMetaController.php`
- `app/Support/SaleMetadataRules.php` (new)

**How to verify:**
- [ ] Attempt to submit a `zone_id`/`courier_id` that doesn't exist (or
      is soft-deleted) through Sale create/edit, bulk-update, AND
      Shipment edit — all three should reject it consistently, not just
      one of them.
- [ ] Attempt a bulk-update selecting more than 1000 sale IDs — it should
      be rejected.
- [ ] Type the same zone/courier name with different casing/whitespace
      (e.g. "Pathao", " pathao ", "PATHAO") via the quick-add — all three
      should resolve to the **same** existing lookup row, not create 3
      duplicates.

**Regression test:** `tests/Regression/build_e2_metadata_validation.php`,
`tests/Unit/BuildE2MetadataValidationContractTest.php`.

### 5.3 Build E3 — POS Sales Seller/Currency parity + default-hidden Currency

One-line description: brings `PosSales.vue` up to the same
Seller/Currency display the normal Sales list already has, and hides
Currency by default on both.

**Files:**
- `resources/src/pages/sales/Sales.vue`, `PosSales.vue`
- `public/js/chunks/Sales.DrRmbclp.js`, `PosSales.Tm8D__HT.js`
- `public/sw.js` (bumped to `stocky-pwa-v11`)

**How to verify:**
- [ ] POS Sales list should show a Seller column matching the normal
      Sales list's Seller values.
- [ ] Currency column should be present (when multi-currency is enabled)
      but hidden by default on both Sales and POS Sales lists.
- [ ] `grep -n "const VERSION" public/sw.js` should be `v11` or higher.

**Regression test:** `tests/Regression/build_e3_pos_sales_parity.php`,
`tests/Unit/BuildE3PosSalesParityContractTest.php`.

**Combined fast-check for E1–E3:**
```
php tests/Regression/build_e1_pos_recent.php
php tests/Regression/build_e2_metadata_validation.php
php tests/Regression/build_e3_pos_sales_parity.php
```

---

## 6. Purchase Orders / GRN series

### 6.1 Post-F continuity — G1, Phase 0, PO+GRN linkage + registration hotfix

One-line description: Product Insight base-quantity readiness (G1);
Purchase Form quick wins (Phase 0); the core Purchase Order → GRN
(Goods Received Note) linkage feature; and a hotfix that registered
routes/menu entries the first PO+GRN delivery had left out.

**Files:** not individually itemized in `CUSTOMIZATIONS.md` (full detail
lives in `PO_GRN_SAFE_OVERLAY_README.md` and `PO_GRN_FILE_MANIFEST.txt`
per that section) — known touched files include `routes/api.php`,
`resources/src/router/index.js`, `resources/src/config/menu.js`, plus PO
controller/model/migration files and PO SPA pages/chunks.

**How to verify:**
- [ ] Log in as Owner — a "Purchase Orders" menu entry should be present
      and functional (list/create/edit).
- [ ] Create a PO, then create a Purchase (GRN) that references it — the
      PO's received quantity should update.
- [ ] Run `php tests/Regression/build_g1_unit_quantity_resolver.php`,
      `php tests/Regression/build_phase0_purchase_form.php`,
      `php tests/Regression/build_po_grn.php`, and
      `php tests/Regression/po_grn_runtime.php` — all should pass.

**Regression test:** `tests/Regression/build_g1_unit_quantity_resolver.php`,
`tests/Regression/build_phase0_purchase_form.php`,
`tests/Regression/build_po_grn.php`,
`tests/Regression/po_grn_runtime.php` (the last one is a read-only
installation check for PO routes/tables/columns/permission row).

### 6.2 PO+GRN Phase 1.2 — labels, GRN header layout, actionable errors

One-line description: fixes raw `$t()` translation keys leaking as
literal text on PO pages; reorganizes the Create Purchase/GRN header into
one 4-column row; replaces a generic "Something went wrong" with specific
403/404/500 error messages.

**Files:** PO list/form Vue pages, English translation seeder,
`resources/src/pages/purchases/...` (GRN header layout).

**How to verify:**
- [ ] Purchase Orders list/form should show real labels ("Purchase
      Orders", "Add Purchase Order", "Expected Delivery Date") — not raw
      keys like `PurchaseOrders`.
- [ ] Create Purchase/GRN header should show Date, Supplier, Select
      Purchase Order, Warehouse in one row on desktop.
- [ ] Trigger a PO-related error (e.g. an invalid PO selection) — the
      error message shown should be specific, not a generic failure
      message.

**Regression test:** `tests/Regression/po_grn_runtime.php` (installation
check). No dedicated test file named for Phase 1.2 specifically.

### 6.3 PO+GRN Phase 1.3 — real PO view, documents/columns, safe GRN deletion

One-line description: adds a real read-only PO detail/view page,
Created By/Last GRN Date/Age columns, PO attachments, and a
safety-checked GRN deletion flow that blocks any deletion that would push
stock negative.

**Files:** PO view page, PO list Vue, GRN deletion service/controller.

**How to verify:**
- [ ] Open a partially or fully received PO — it should open a read-only
      detail view showing ordered/received/remaining quantities per line,
      not the edit form.
- [ ] Edit should only be offered for Draft/Ordered POs.
- [ ] PO list should offer Created By and Last GRN Date as
      default-hidden columns, plus Age and an attachment indicator.
- [ ] Attempt to delete a GRN whose reversal would make any product's
      stock negative — it must be rejected, with no partial stock change.

**Regression test:** `tests/Regression/build_po_phase1_3.php`.

**Known limitation (documented, not a bug to "fix" yet):** a received
PO-linked GRN should be **deleted and recreated**, not edited — editing
does not yet correctly reconcile old/new PO receipt contributions. See
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`.

### 6.4 PO+GRN Phase 1.4 — supplier match, over-receipt lock, unsafe-edit block

One-line description: (1) rejects a GRN linked to a PO from a different
supplier; (2) locks PO/PO-detail rows during receipt so two concurrent
GRNs can never together over-receive a PO line; (3) blocks editing a
received, PO-linked GRN outright (rather than silently letting it
desync the PO).

**Files:**
- `app/Services/.../PurchaseOrderReceiptService.php`
  (`validateReceivablePo()`, `lockAndValidateReceiptLines()`)
- `app/Http/Controllers/PurchasesController.php` (`store()`, `update()`)

**How to verify:**
- [ ] Create a GRN against a PO belonging to a different supplier than
      the GRN's own supplier — it must be rejected (HTTP 422).
- [ ] (Requires a real concurrency test, not just a manual click test —
      see "static test" caveat in section 8 below.) Two near-simultaneous
      GRNs against the same PO line should never together receive more
      than the PO line's remaining quantity.
- [ ] Attempt to edit a GRN that is PO-linked and already `received` (or
      whose edit would set it to `received`) — it must be blocked with an
      error, not silently applied.
- [ ] Editing a PO-linked GRN that stays pending/ordered on both sides
      (never touches `received`) should continue to work normally.

**Regression test:** `tests/Regression/build_po_grn_phase1_4.php`
(**static source-contract check only** — see
`tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md` for the actual
real-database steps that must be run by hand in a real environment to
confirm runtime correctness, especially the concurrency scenario).

### 6.5 PO Reports and PO view/labels (later refinements)

One-line description: further PO reporting and PO view/label polish
tracked by their own regression files, not given a separate numbered
section in `CUSTOMIZATIONS.md`'s prose.

**Regression test:** `tests/Regression/build_po_reports.php`,
`tests/Regression/build_po_view_and_labels.php`.

**How to verify:**
- [ ] Run both test files; also manually open any PO Price Variance /
      other PO report and confirm it loads without error for Owner.

### 6.6 Purchase Orders enable/disable toggle (Build J1)

One-line description: a System Settings switch to turn the whole
PO/GRN feature off for stores that don't use formal purchase orders —
enforced server-side via middleware, not just cosmetic.

**Files:**
- `database/migrations/2026_09_18_000003_add_enable_purchase_orders_toggle.php`
- `app/Http/Middleware/CheckPurchaseOrdersEnabled.php`
- `routes/api.php` (`purchase_orders` route group wrapped in `po.enabled`)
- `resources/src/pages/settings/SystemSettings.vue`

**How to verify:**
- [ ] System Settings should have an "Enable Purchase Orders" toggle
      (under/near "Enable Box Quantity").
- [ ] Turn it off — any `purchase_orders`-prefixed API call (list,
      create, PDF, price-variance report) should be blocked (403/abort),
      not just hidden in the UI.
- [ ] **Known, accepted limitation:** the "Purchase Orders" and "Price
      Variance Report" sidebar links still show even when the toggle is
      off — clicking them should show the block, not silently succeed.
      This is documented as intentional/deferred, not a bug to report.
- [ ] Confirm normal Purchases (GRN receiving against no PO) still work
      when the PO toggle is off — the `purchases` routes themselves are
      not wrapped by this middleware.

**Regression test:** none named specifically for J1 in the test list
given; check `tests/Regression/build_po_grn.php` and
`tests/Regression/po_grn_runtime.php` for related coverage, and verify
manually per above.

---

## 7. Product Movement Ledger (Builds H1, H2)

One-line description: a single chronological, running-balance view of a
product's entire stock history across Purchases/Sales/Transfers/
Adjustments/Sale Returns/Purchase Returns/Damages, with a reconciliation
check against the live `product_warehouse.qte` — built specifically to
help find real instances of the known stock-integrity bug (see section 9
below).

**Files:**
- `app/Services/Custom/ProductMovementLedgerService.php` (new)
- `app/Http/Controllers/ProductsController.php@movement_ledger`
- `routes/api.php` (`GET products/movement-ledger`)
- `resources/src/pages/products/MovementHistoryCard.vue` (new)
- `resources/src/pages/products/ProductDetails.vue`
- `resources/src/pages/reports/StockDetailReport.vue`

**How to verify:**
- [ ] Open any Product Details page — a Movement History card/section
      should be present, showing a chronological table with a running
      balance, and a reconciliation badge (green = reconciled, orange =
      numbers disagree, red = row missing).
- [ ] The same view should be reachable via Reports > Stock Report > pick
      a product > a "Movement History" (or similarly named) tab.
- [ ] For a variant product, viewing "all variants combined" should show
      real movement rows, not "no movement" (this was a real bug found
      and fixed in H2 — worth specifically re-testing on any variant
      product).
- [ ] Filter by warehouse and by date range — results should narrow
      correctly.
- [ ] A red "row missing" badge on a real product's warehouse row is the
      exact signature of the known stock-integrity bug (section 9) — if
      you see one on live data, that is a genuine finding, not a false
      positive from this tool.

**Regression test:** `tests/Regression/build_h1_stock_movement_ledger.php`.

---

## 8. Activity Log (Builds I1, I2) and Build K.4 (fresh-install fix)

> Coverage was later extended significantly beyond what I1/I2 describe
> below — see **Section 16 (Build I3/I3b)** for the reference-number fix
> and 9 additional record types now logged (Sale Return, Purchase Return,
> Damage, Quotation, Purchase Order, Warehouse, Shipment, Settings, all
> Payment types).

### 8.1 Build I1 — Activity Log Phase 1 (Sales, Purchases, Products, Customers + logins)

One-line description: a unified "who did what" audit trail — records
create/update/delete on Sales, Purchases, Products, Customers, plus
merges in the existing (previously self-service-only) login history for
an admin-wide view.

**Files:**
- `database/migrations/2026_09_18_000001_create_activity_logs_table.php`
- `database/migrations/2026_09_18_000002_add_activity_log_report_permission.php`
- `app/Services/Custom/ActivityLogger.php`
- `app/Providers/ActivityLogServiceProvider.php` (+ `config/app.php`)
- `app/Http/Controllers/ClientController.php` (explicit log calls for bulk update/destroy)
- `app/Http/Controllers/ActivityLogController.php` (new)
- `app/Policies/SettingPolicy.php` (`activity_log_report()` method — required, a DB
  permission row alone is not enough for this app's Settings-area authorization)
- `routes/api.php` (`GET reports/activity-log`)
- `resources/src/pages/reports/ActivityLogReport.vue` (new)
- `resources/src/config/menu.js`, `resources/src/router/index.js`

**How to verify:**
- [ ] As Owner, open Reports > Activity Log Report — should load a
      filterable (Date range/User/Module) table of created/updated/
      deleted actions.
- [ ] Perform a real action (e.g. edit a Sale) and confirm a
      corresponding row appears in the Activity Log shortly after.
- [ ] Click the 👁 (view) action on an "updated" row — an old→new diff
      modal should open, showing only the fields that actually changed.
- [ ] Confirm no row ever shows a `password`, `NewPassword`,
      `remember_token`, or any `quickbooks_*` field as a "changed" value
      (these must always be excluded).
- [ ] Confirm ordinary QuickBooks background sync attempts (connected or
      not) never create a phantom "Sale updated" log row by themselves.

**Regression test:** `tests/Regression/build_i1_activity_log.php`.

### 8.2 Build I2 — Activity Log Phase 2 (Adjustments, Transfers, Users, Roles & Permissions)

One-line description: extends the Activity Log to stock Adjustments/
Transfers and to access-control changes (Users, Roles & Permissions,
including permission-list diffs on role changes).

**Files:**
- `app/Providers/ActivityLogServiceProvider.php` (extended)
- `app/Http/Controllers/UserController.php`, `PermissionsController.php`
  (explicit log calls for bulk update / role create/update/delete)
- `resources/src/pages/reports/ActivityLogReport.vue` (Module filter extended)

**How to verify:**
- [ ] Create/edit/delete a Stock Adjustment or Stock Transfer — an
      Activity Log row should appear for it.
- [ ] Edit a Role's permission list — the log row for that change should
      show the actual permission-list diff (which permissions were
      added/removed), not just "Role updated."
- [ ] Create a new User — confirm the "created" log row does NOT include
      the hashed password field.
- [ ] **Known, accepted scope limit:** the enable/disable user toggle
      (`IsActivated()`) is not logged — do not treat its absence from the
      Activity Log as a bug.
- [ ] **Known, accepted deferral:** failed login attempts are not tracked
      anywhere yet — do not expect a "Failed" entry type.

**Regression test:** covered together with I1 by
`tests/Regression/build_i1_activity_log.php` (per the test file naming
in the repo, Phase 2's contract additions are exercised alongside Phase
1's file — confirm by reading the file's assertions if precise Phase 1
vs Phase 2 coverage needs to be distinguished).

### 8.3 Build K.4 — Activity Log Report invisible on fresh install + permissions.js gap

**This is the build that is the direct reason this checklist document
exists.** Two bugs, found from a real `php artisan migrate:fresh --seed`
test run, both instances of the two bug classes in section 1 above.

**Bug 1:** `activity_log_report`'s migration granted the permission
inline, which silently granted it to zero roles on a fresh install
(fixed once before for `purchase_orders`, missed for this later
permission).

**Bug 2:** neither `activity_log_report` nor `purchase_orders` were
present in the static `resources/src/config/permissions.js` file, so
even with the correct database grant, neither could be assigned to any
role from the Roles & Permissions screen.

**Files:**
- `database/seeders/ActivityLogPermissionSeeder.php` (new — confirmed
  present, mirrors `PurchaseOrdersPermissionSeeder` exactly)
- `database/seeders/DatabaseSeeder.php` (confirmed: `ActivityLogPermissionSeeder::class`
  is called immediately after `PurchaseOrdersPermissionSeeder::class`,
  and both are after `PermissionRoleSeeder::class`)
- `resources/src/config/permissions.js` (confirmed: contains a
  `"v": "purchase_orders"` entry at line 472 in the Purchases group, and
  a `"v": "activity_log_report"` entry at line 922 in the Reports group)

**How to verify:**
- [ ] Run `php artisan migrate:fresh --seed` on a disposable/test
      database.
- [ ] Log in as a brand-new Owner account — Activity Log Report must
      appear in the Reports menu (this is the exact bug: it was invisible
      after a fresh install before this fix).
- [ ] Open Roles & Permissions, pick any role (new or existing) — both
      "Activity Log Report" (Reports group) and "Purchase Orders"
      (Purchases group) checkboxes must be visible and assignable.
- [ ] `grep -n "activity_log_report" resources/src/config/permissions.js`
      — must return a match (currently line 922–923).
- [ ] `grep -n "purchase_orders" resources/src/config/permissions.js` —
      must return a match (currently line 472–473).
- [ ] Open `database/seeders/DatabaseSeeder.php` — confirm
      `ActivityLogPermissionSeeder::class` and
      `PurchaseOrdersPermissionSeeder::class` both appear in the
      `$this->call([...])` array **after** `PermissionRoleSeeder::class`.
- [ ] For an already-seeded/live database (no fresh install), run
      `php artisan db:seed --class=ActivityLogPermissionSeeder`, then
      `npm run build`, and manually enable the Activity Log Report
      checkbox for any role besides Owner that needs it.

**Regression test:** `tests/Regression/build_k4_activity_log_permission_and_config_gap.php`
(static source-contract check — see section 9 for what that means and
does not mean).

**Known, explicitly unresolved follow-up:** a second report was reported
missing after a fresh install by the user, but its name couldn't be
recalled. "Zone / Courier Report" was checked and found structurally
fine (its permission `Reports_sales` is correctly granted to Owner; its
route mapping is correct) — so it is probably not the missing report.
**Ask the user to re-check the Reports menu after this fix and, if
something is still missing, get the exact name** — this cannot be traced
further without it.

---

## 9. Public Invoice URL (Build J2)

One-line description: a shareable, no-login link + PDF for a sale's
invoice.

**Files:**
- `database/migrations/2026_09_18_000004_add_public_token_to_sales.php`
- `app/Services/Custom/PublicInvoiceLinkService.php` (new)
- `app/Http/Controllers/PublicInvoiceController.php` (new)
- `app/Http/Controllers/SalesController.php@Sale_PDF` (one additive `inline` flag)
- `routes/api.php` (authenticated `sales/{id}/public-link[/regenerate]`;
  public `public/invoice/{token}` and `public/invoice/{token}/pdf`)
- `resources/src/pages/public/PublicInvoice.vue` (new)
- `resources/src/pages/sales/SaleDetails.vue`
- `resources/src/router/index.js` (`/invoice/:token`, `skipAuth: true`)
- `routes/web.php` (unguarded `Route::view('/next/invoice/{token}', 'next')`,
  registered **before** the auth-gated `/next/*` catch-all)

**How to verify:**
- [ ] Open a Sale Detail page — a "Public Link" button/dropdown should
      let you copy a link and, separately, "Regenerate" it (invalidating
      the old one).
- [ ] Open that link in a genuinely fresh/incognito browser window (not
      logged in) — it must load the invoice page directly, **not**
      redirect to login. (This exact failure — redirecting to login even
      in incognito — was a real bug found and fixed; specifically caused
      by `routes/web.php`'s catch-all needing its own unguarded carve-out,
      not just the Vue Router's `skipAuth` meta.)
- [ ] The public page's Download button should produce the same,
      fully currency-converted PDF as the authenticated invoice PDF.
- [ ] Copying the link should work even over plain HTTP / a non-HTTPS
      custom hostname (not just `localhost`/HTTPS) — confirm the copy
      button doesn't silently fail; if the browser API is unavailable it
      should fall back to a manual copy dialog, not do nothing.
- [ ] Regenerating the link should make the **old** link stop working.

**Regression test:** `tests/Regression/build_j2_public_invoice_url.php`.

---

## 10. Build K.1 — VAT/BIN ID + Website fields

**Status: PENDING.** This build exists on the live site but is **not**
present in this working zip/checkout — confirmed by checking that
`database/migrations/2026_09_18_000001_add_website_field_to_settings_table.php`
does not exist here. Treat this entry as a to-do to apply and verify, not
as something already confirmed working in this codebase.

One-line description: adds VAT/BIN ID and Website fields to General
Settings, flowing into roughly 17 PDF templates/pages that print company
info.

**Files (per the task description, to confirm once applied):**
- `database/migrations/2026_09_18_000001_add_website_field_to_settings_table.php`
- `app/Models/Setting.php` (fillable)
- `app/Http/Controllers/SettingsController.php`
- `resources/src/pages/settings/SystemSettings.vue`
- `resources/lang/en/pdf.php` and the Arabic equivalent
- 15 Blade PDF templates (not individually named in the task brief)
- `app/Http/Controllers/PublicInvoiceController.php`
- `app/Http/Controllers/PurchaseOrderController.php`
- `app/Http/Controllers/ReportController.php`
- `resources/src/pages/public/PublicInvoice.vue`
- `resources/src/pages/reports/ProductsSoldSummaryReport.vue`

**How to verify once applied:**
- [ ] Open Settings > General — VAT/BIN ID and Website fields should be
      present and save correctly.
- [ ] Open several different PDFs (Sale invoice, Purchase Order, Public
      Invoice, at minimum) — VAT/BIN ID and Website should print
      correctly wherever company info appears, in both English and
      Arabic language settings.
- [ ] Confirm all ~17 touched PDF/report pages were actually updated —
      a partial rollout (some PDFs show the new fields, others don't) is
      the most likely failure mode for a change this widely spread.

**Regression test:** none exists yet — this build predates any automated
test being written for it. **Add a source-contract test for this build
once it is applied**, following the pattern of the other
`tests/Regression/build_*.php` files, so future replays don't silently
drop one of the ~17 touched templates.

---

## 11. Build K.2 — Transfer stock-integrity fix

**Status: PENDING.** Not present in this working zip/checkout.

One-line description: `TransferController.php` gains a
`resolveProductWarehouseRow()` helper, used at all 36 write call sites,
so a product's very first stock movement into a warehouse via Transfer is
never silently dropped — a targeted fix for (one instance of) the
stock-integrity bug documented in "Known unresolved issues" (section 12
below).

**Files (per the task description):**
- `app/Http/Controllers/TransferController.php`

**How to verify once applied:**
- [ ] `grep -n "resolveProductWarehouseRow" app/Http/Controllers/TransferController.php`
      should return the helper's definition plus (per the task
      description) 36 call sites using it.
- [ ] Create a brand-new product in a warehouse it has never had stock in
      before (bypassing the normal auto-backfill — e.g. via whatever
      real-world path originally exposed this bug, such as a variant
      added after the fact), then Transfer stock into that
      product/warehouse combination for the first time — confirm a
      `product_warehouse` row is created/updated correctly, not silently
      dropped.
- [ ] Open that product's Movement History (section 7) after the
      transfer — it should reconcile (green badge), not show
      `row_missing: true`.

**Regression test:** none exists yet. **Add a source-contract test for
this build once applied** — e.g. asserting all 36 write call sites in
`TransferController.php` call `resolveProductWarehouseRow()` rather than
a raw conditional update, so a future edit can't accidentally reintroduce
a 37th unguarded call site.

---

## 12. Build K.3 — Quick Add Customer modal fix + required sw.js bump

**Status: PENDING.** Not present in this working zip/checkout. Confirmed
`public/sw.js`'s `VERSION` constant is currently `stocky-pwa-v12` (Build
F's value) — K.3 must bump it further, to at least `v13`.

One-line description: a CSS-only alignment/spacing fix for the Quick Add
Customer modal in POS (`.qac-*` styles), **plus** the mandatory PWA
service-worker cache-version bump that any `PosPage.vue` change requires
(see section 1(c) above).

**Files (per the task description):**
- `resources/src/pages/pos/PosPage.vue` (`.qac-*` CSS classes)
- `public/sw.js` (`VERSION` bump)

**How to verify once applied:**
- [ ] In POS, open the Quick Add Customer modal — its fields/labels
      should be visually aligned and evenly spaced (no cramped or
      misaligned rows).
- [ ] `grep -n "const VERSION" public/sw.js` — must show a version number
      **higher** than `v12` (e.g. `v13` or later). **This is the single
      most likely thing to be forgotten** for this build specifically —
      a CSS-only change to `PosPage.vue` is easy to ship without
      remembering the cache bump, and the failure mode (stale cached POS
      code on already-installed clients) is invisible until someone
      reports "my POS still looks old" days later.
- [ ] After deploying, on a device that had the PWA installed
      **before** this change, force-refresh or reopen the installed app
      and confirm the new modal styling actually appears (proves the
      cache purge worked, not just that the source file changed).

**Regression test:** none exists yet. **Add a source-contract test for
this build once applied** — at minimum, assert `public/sw.js`'s
`VERSION` string is present and matches whatever value was shipped, so a
future PosPage.vue edit that forgets to bump it again is caught by
`grep`, not by a user complaint.

---

## 13. Other standing rules and known issues (not individually "checkable" features, but worth tracking)

### 13.1 House rule: plain-English UI text (adopted mid-project)

Every page/component built from Build I1 onward (Activity Log Report
being the first) uses literal English strings directly in the source,
**not** `$t('Some_Key')` translation keys — because a brand-new key is
guaranteed not to exist in the translations table yet, and this app's
`$t()` renders the raw key text verbatim on a miss. Older features (built
before this rule) may still use hardcoded label text for the same
underlying reason (no translation row exists), which is consistent, not
inconsistent, with this rule — the point in both cases is "don't rely on
a translation-table row that doesn't exist."

- [ ] When reviewing any new page/component going forward, confirm labels
      are plain literal text, not an unresolved `$t()` key rendering as
      `Some_Key`-looking text in the UI.

### 13.2 Known unresolved issue: stock-integrity bug (pre-existing vendor pattern)

Across Purchases/Transfers/Adjustments/Sales/Sale Returns/Purchase
Returns/Damages, a `product_warehouse` row is only *updated* if it
already exists — there's no else-branch to *create* one. A product's
first-ever stock movement into a warehouse can silently do nothing. Both
"create product" and "create warehouse" already auto-backfill the full
product×warehouse grid, so this mostly only bites on bulk import (though
the current `ProductImport.php` is confirmed to be a non-functional
stub), legacy pre-backfill data, or a variant added after the fact.

- [ ] Use the Product Movement Ledger (section 7) reconciliation badges
      to find real instances on live data — a red "row missing" badge is
      the bug's exact signature.
- [ ] Build K.2 (section 11) fixes this specifically for Transfers, once
      applied — the same pattern likely still exists in the other six
      modules (Purchases, Adjustments, Sales, Sale Returns, Purchase
      Returns, Damages) and remains unfixed there as of this writing.

### 13.3 Known unresolved issue: `ReportController` `$perPage = $request->limit;` pattern (~45 instances)

An unguarded assumption repeated ~45 times across report methods in
`ReportController.php` — confirmed present in both fresh vendor 5.8 and
this customized version. Not started/fixed as of this writing.

- [ ] No specific verify step yet — flagged here so it isn't forgotten
      when report-pagination bugs are investigated in the future.

### 13.4 GitHub repository visibility / `public/js` tracking (operational, not code)

Two outstanding, **unconfirmed** operational follow-ups from an earlier
session: (1) the `imran2022/stocky-app` GitHub repo was found set to
Public (should be Private, per CodeCanyon license terms) and the owner
said they'd handle it, but follow-through was never confirmed in this
document; (2) `public/js` (build output) was previously committed to
git history despite `.gitignore` now excluding it — a `git rm -r --cached
public/js` plus a fresh commit was recommended, also never confirmed.

- [ ] Confirm with the repository owner whether the repo is Private and
      whether `public/js` has been removed from git tracking. This has no
      code-level check — it must be asked/confirmed with a human.

---

## 14. How to run a full fresh-install check

Run this sequence after any fresh install, and periodically as a general
health check, to catch regressions across **all** the items in this
document at once — not just one feature in isolation.

- [ ] **1. Rebuild the database from scratch:**
      `php artisan migrate:fresh --seed`
- [ ] **2. Rebuild the frontend:**
      `npm run build`
- [ ] **3. Clear all caches:**
      `php artisan config:clear && php artisan view:clear && php artisan cache:clear && php artisan route:clear`
      (also restart the actual web server process, e.g. Apache/Laragon —
      not just the CLI — since CLI and the web server's PHP module can
      hold separate, independently-stale OPcache state; this has
      genuinely happened before, see Build H2's notes).
- [ ] **4. Log in as a brand-new Owner account** (do not reuse an
      existing, already-configured account — the whole point is to
      confirm what a *fresh* install actually gives a *fresh* Owner).
- [ ] **5. Walk the Reports menu top to bottom** — every report listed in
      this document (Sales Report, Zone/Courier Report, Activity Log
      Report, Stock Detail Report, PO reports, etc.) should be present
      and load without error. Pay special attention to Activity Log
      Report specifically — its exact disappearance on fresh install is
      the bug that motivated this whole document.
- [ ] **6. Open Roles & Permissions, create or edit a role** — confirm
      every custom permission this document lists (at minimum
      `activity_log_report`, `purchase_orders`) is visible as an
      assignable checkbox, in the correct group.
- [ ] **7. Spot-check one item from each major section above** — Sales
      Zone/Courier fields, Stock Lookup, Product Insight columns, POS
      Recent Invoices, a Purchase Order + GRN, and the Public Invoice
      link — using each section's own "How to verify" steps.
- [ ] **8. Run every no-dependency regression script in one pass:**
      ```
      for f in tests/Regression/build_*.php tests/Regression/po_grn_runtime.php; do
        echo "== $f =="; php "$f"; echo;
      done
      ```
      Every one should report success with no failures.
- [ ] **9. Confirm the PWA cache version is current:**
      `grep -n "const VERSION" public/sw.js` — cross-check the number
      against the highest bump mentioned in this document for whatever
      builds are actually applied (currently `v12` as of Build F; must be
      `v13`+ once Build K.3 is applied).

---

## 15. Honest limitation of the automated regression tests

Every file under `tests/Regression/build_*.php` (23 files, listed at the
top of this project's task brief and cross-referenced throughout this
document) is a **static, no-dependency source-contract check**. In plain
terms: each one opens the relevant PHP/Vue/JS source files as **text**
and asserts that certain strings, method calls, or structural patterns
are present or absent — for example, confirming that
`ActivityLogPermissionSeeder::class` appears in `DatabaseSeeder.php`
after `PermissionRoleSeeder::class`, or that `permissions.js` contains
the string `activity_log_report`.

**These are not live database tests. They are not runtime tests. They do
not boot the Laravel application.** This is because the working
environment these builds were produced in has **no PHP CLI, no MySQL/
database, and no ability to actually run `php artisan migrate:fresh
--seed` or open the app in a browser.**

What this means in practice:

- These tests **can** catch "the code that is supposed to do X is
  missing, or was silently reverted, or was written wrong" — because
  that shows up as a difference in the source text itself.
- These tests **cannot** catch "the code is completely correct, but the
  actual live database or installed application is in a bad state" —
  because that requires actually running the app against a real
  database, which these tests structurally cannot do.

**This is exactly why the Build K.4 Activity Log bug reached a live
install in the first place**: the underlying code pattern (a migration
granting a permission inline, with no matching post-seeder fix) was not
something any static test was written to check for — because no one had
written that check yet. The bug was found only by a human actually
running `php artisan migrate:fresh --seed` against a real database and
noticing the menu item was missing. The static regression test for K.4
(`tests/Regression/build_k4_activity_log_permission_and_config_gap.php`)
was written **after** the live bug was already found and fixed — it
protects against this exact bug happening a third time, but it could not
have caught it the first two times (`purchase_orders`, then
`activity_log_report`), because it didn't exist yet.

**Until, or unless, a future session has real PHP CLI + database access
to write and run actual runtime/functional regression tests, section 14
above ("How to run a full fresh-install check") is the *only* way to
close this gap.** Treat every static regression test pass as necessary
but explicitly **not sufficient** — a green run of every
`tests/Regression/build_*.php` script tells you the source code is
internally consistent with what this document describes; it does not
tell you the live, installed application actually behaves correctly.
Run the fresh-install check (section 14) too, every time, especially
before or after touching permissions, migrations, or seeders.

## 16. Build I3 / I3b — Activity Log real reference numbers + full audit-trail coverage

**What it does:** Fixed the Activity Log Report showing internal database
ids ("Sale #5") instead of the actual invoice/reference number shown
everywhere else ("Sale SL-104"), for Sale, Purchase, Adjustment, Transfer.
Also extended logging to 9 record types that previously had NO audit
trail at all: Sale Return, Purchase Return, Damage, Quotation, Purchase
Order, Warehouse, Shipment, System Settings, and all four Payment types
(Sale/Purchase/Sale Return/Purchase Return payments).

**Files touched:**
- `app/Providers/ActivityLogServiceProvider.php`
- `app/Services/Custom/ActivityLogger.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/WarehouseController.php`
- `app/Http/Controllers/PaymentSalesController.php`
- `app/Http/Controllers/PaymentPurchasesController.php`
- `app/Http/Controllers/PaymentSaleReturnsController.php`
- `app/Http/Controllers/PaymentPurchaseReturnsController.php`
- `resources/src/pages/reports/ActivityLogReport.vue`

**How to verify:**
- [ ] Create a Sale — Activity Log must show "Sale <invoice-number>
      created", not "Sale #<id> created".
- [ ] Same for a Purchase, Adjustment, Transfer.
- [ ] Create a Sale Return, Purchase Return, Damage, Quotation, Purchase
      Order — each must now appear in the Activity Log using its own Ref
      number, module label matching the new filter dropdown options.
- [ ] Add or edit a Warehouse — must appear as "Warehouse ... updated"
      with the changed fields.
- [ ] Delete a Warehouse — must appear as "Warehouse ... deleted".
- [ ] Create a Shipment, then delete it — both create and delete must
      appear (delete is the one case in this app using a real hard
      Eloquent delete, not soft-delete).
- [ ] Record a Sale/Purchase/Sale Return/Purchase Return payment, then
      delete it — both must appear under their own "Payment (...)" module.
- [ ] Change something in System Settings (e.g. VAT number) — must
      appear as "System Settings updated" with an old/new diff of only
      the changed fields.
- [ ] **Security check:** if cloud backup credentials (S3 secret key,
      Google Drive/Dropbox tokens) are ever set in Settings, open that
      Activity Log entry's detail view and confirm the actual secret
      value never appears anywhere in it.
- [ ] Roles & Permissions → open any role → module filter dropdown in
      Activity Log Report must list all 9 new modules alongside the
      original ones.

**Regression tests:** `tests/Regression/build_i3_activity_log_reference_numbers.php`,
`tests/Regression/build_i3b_activity_log_coverage_extension.php`.

**Not covered yet (documented, deferred):** export/print/download
tracking, failed/permission-denied action tracking, and a full pre-delete
snapshot captured directly on the `deleted` log entry (currently
reconstructable from prior `updated` entries, but not guaranteed complete
if a record was never edited before being deleted).

---

## 17. Build L1 — Public Invoice: parity with Sale Detail page

**What it does:** The public, no-login invoice page (`/invoice/:token`,
Build J2) only showed Item/Qty/Price/Total and a short totals box. This
build brings it up to parity with what the authenticated Sale Detail page
shows: per-line Box Qty (only when the company's `enable_box_qty` setting
is on), per-line Discount and Tax, IMEI/batch numbers, pack quantity
breakdown, order-level Discount from Points, Previous Dues, and Net
Balance. Also removes the customer's own email from the page (no reason
to show it back to them) and reformats the company header block to
Name / Address / VAT/BIN / Phone / Mail / Website, one per line.

**Files touched:**
- `app/Http/Controllers/PublicInvoiceController.php`
- `resources/src/pages/public/PublicInvoice.vue`

**How to verify:**
- [ ] Open a sale's public invoice link. The item table must show a
      "Box" column (only if "Enable Box Qty" is on in Settings), Discount
      and Tax columns per line, matching the authenticated Sale Detail
      page's numbers for the same sale.
- [ ] If the sold product has an IMEI/serial number or is batch-tracked,
      it must appear under the item name, same as Sale Detail.
- [ ] Totals box must show Order Tax, Discount (with % shown when the
      sale used a percent discount), Discount from Points (if any),
      Shipping, Total, Paid, Balance due, and — if the client had any
      prior outstanding balance — Previous Dues and Net Balance, all
      matching the authenticated page's numbers exactly.
- [ ] Turn "Enable Box Qty" off in Settings — the Box column must
      disappear from the public invoice page entirely.
- [ ] The customer's email must not appear anywhere on the page.
- [ ] Company header (top-left) must read, one line each, in this order:
      Company Name, Address, VAT/BIN, Phone, Mail, Website.

**Regression test:** `tests/Regression/build_l1_public_invoice_parity.php`.

**Deliberately not added (flagged, not built without asking):** tracking
reference, consignment ID, sales agent name, zone, courier — internal
routing/ops fields shown on the authenticated page but not typically
meant for a customer-facing document. Say the word if you want any of
these on the public page too.

---

## 18. Build L2 — Previous Dues / Net Balance show/hide toggle

**What it does:** Previous Dues and Net Balance used to always print (when
a client had a balance) on Sale Detail, the Sale PDF, and the public
invoice page, with no way to turn them off. Now they're two independent
switches in Settings → Invoice PDF → Sales Invoice → Sections, defaulting
to on (no behavior change unless you touch them). Reuses the app's
existing Invoice PDF customizer rather than a new settings mechanism.

**Files touched:**
- `app/Models/PdfTemplate.php`, `app/Http/Controllers/PdfTemplateController.php`
- `resources/views/pdf/sale_pdf.blade.php`
- `app/Http/Controllers/SalesController.php`, `app/Http/Controllers/PublicInvoiceController.php`
- `resources/src/pages/sales/SaleDetails.vue`, `resources/src/pages/public/PublicInvoice.vue`
- `resources/src/pages/settings/InvoicePdfSettings.vue`

**How to verify:**
- [ ] Settings → Invoice PDF → Sales Invoice → Sections must show
      "Previous Dues line" and "Net Balance line" toggles (only for Sales
      Invoice, not Quotation/Purchase).
- [ ] Pick a client with an outstanding balance, turn "Previous Dues
      line" off, save. Open Sale Detail, the Sale PDF, and the public
      invoice link for a new sale to that client — Previous Dues must not
      appear on any of the three.
- [ ] Turn "Net Balance line" off (Previous Dues back on) — Net Balance
      must disappear from all three while Previous Dues still shows.
- [ ] Turn both back on — both must reappear on all three, matching
      numbers.

**Regression test:** `tests/Regression/build_l2_previous_dues_toggle.php`.

---

## 19. Build L3 — Sales Invoice: multiple selectable PDF templates

**What it does:** Settings → Invoice PDF → Sales Invoice now has a
**Template** picker: "Classic" (the original, fully customizable via the
Colors/Typography/etc. panels) and "Modern (with Shipping Label)" (a
user-supplied, fully self-styled design with a built-in COD/shipping
label section). Whichever is selected is used everywhere a Sales Invoice
PDF is produced — download, inline view, bulk download, and the public
invoice page's Download button.

**Files touched:**
- `app/Models/PdfTemplate.php`, `app/Http/Controllers/PdfTemplateController.php`
- New: `resources/views/pdf/sale_pdf_modern.blade.php`
- `app/Http/Controllers/SalesController.php`
- `resources/src/pages/settings/InvoicePdfSettings.vue`

**How to verify:**
- [ ] Settings → Invoice PDF → Sales Invoice must show a Template picker
      with "Classic" and "Modern (with Shipping Label)".
- [ ] Switch to Modern, save. Download any sale's PDF — it must be the
      new design, with the shipping-label/COD section at the bottom.
- [ ] The public invoice link's Download button must also produce the
      Modern PDF once selected (it shares the same rendering path).
- [ ] Previous Dues / Net Balance (Build L2) must still honor their
      on/off toggle inside the Modern layout.
- [ ] Switch back to Classic, save — everything must return to exactly
      how it looked before this build.
- [ ] Quotation and Purchase Order settings must NOT show a Template
      picker (they only have one layout).

**Regression test:** `tests/Regression/build_l3_multi_template_sale_pdf.php`.

**How to add another template later:** drop a new Blade file, add it to
`PdfTemplate::LAYOUTS['sale']` — no other code changes needed unless the
new design needs data the current templates don't already receive.

---

## 20. Build L4 — Modern Sale Invoice: bug fix, Box Qty, customization parity, matching shipping label

**What it does:**
- Fixes a real bug in the user-supplied Modern Sale Invoice template
  (Build L3): percent-based order discounts were treated as flat dollar
  amounts, understating both the Discount line and the Subtotal.
- Adds a "Box" column to the Modern template (same on/off logic as
  Classic: only shows when the `enable_box_qty` setting is on and at
  least one line item has a box quantity).
- Adds a "Discount from Points" row to the Modern template's totals.
- Wires the rest of the Sections / Text & labels customizer panels into
  Modern (Customer block, Sales Status, Notes, Thank-you line + text
  override, Footer text override, Document title override) — previously
  only Previous Dues/Net Balance (Build L2) worked there even though the
  settings page always showed all these controls.
- Restyles the separate standalone Shipping Label PDF to match the
  Modern invoice's own "DELIVERY INFORMATION" shipping-label section.

**Files touched:**
- `resources/views/pdf/sale_pdf_modern.blade.php`
- `resources/views/pdf/shipping_label.blade.php`

**How to verify:**
- [ ] Settings → Invoice PDF → Sales Invoice → Template = Modern, save.
      Create/open a sale with a **percentage** discount — the invoice PDF
      must show "Discount: - X.00% (currency amount)" with the correct
      dollar amount, and the Subtotal above it must equal the true
      pre-discount total (sum of line totals).
- [ ] A sale with a **fixed-amount** discount must still show a plain
      dollar amount (no stray "%" text) — unchanged from before.
- [ ] A sale that used loyalty points for part of the discount must show
      a separate "Discount from Points" line.
- [ ] With the box-qty feature enabled and a sale that has box quantities
      on some line items: the PDF must show a "Box" column, with a dash
      for any line that has no box quantity. With the feature disabled,
      the column must not appear at all.
- [ ] In Settings → Invoice PDF → Sales Invoice, with Modern selected,
      toggle off Customer / Sales Status / Notes / Thank-you, add a
      custom Footer text, and set a custom Document title — save, then
      download a PDF and confirm all of these are honored the same way
      they already are for Classic.
- [ ] Download the standalone "Shipping Label" for a sale with a balance
      due — it must show the "DELIVERY INFORMATION" card style (matching
      the invoice's own shipping section) with a red "CASH ON DELIVERY
      (COD)" badge and the correct amount. For a fully paid sale, it must
      show a green "PAID" badge instead.
- [ ] Switch back to Classic — its own discount display, Box Qty, and all
      customizer panels must be completely unaffected by this build.

**Regression test:** `tests/Regression/build_l4_modern_invoice_fixes_and_shipping_label.php`.

---

## 21. Build L5 — Packing List restyled to match the Modern Sale Invoice

**What it does:** The Packing List PDF (warehouse pick/pack sheet, no
prices) now uses the same colors, header layout, and product-table style
as the Modern Sale Invoice. Its Box column is now dynamic — only shows
when box quantities are actually in use for that sale, same as the
invoice.

**Files touched:**
- `resources/views/pdf/packing_list.blade.php`
- `resources/views/pdf/shipping_label.blade.php` (comment-only encoding
  fix, no visual change)
- `app/Http/Controllers/SalesController.php`

**How to verify:**
- [ ] Download the Packing List for a sale that has box quantities set on
      at least one line item — it must show a "Box" column with the
      right value per line (and a dash for any line without one), and a
      "Total Boxes" row at the bottom, styled like the Modern invoice.
- [ ] Download the Packing List for a sale with **no** box quantities on
      any line — the Box column and "Total Boxes" row must not appear at
      all.
- [ ] The header must show your company name (and logo, if you have one
      set) — it had no company info before this build.
- [ ] Text with an em-dash ("—") anywhere in the PDF (e.g. the footer
      note, or a dashed-out Box cell) must render as an actual dash, not
      garbled characters.
- [ ] Print Shipping Label must still work exactly as it did after Build
      L4 (only its documentation comment changed in this build).

**Regression test:** `tests/Regression/build_l5_packing_list_modern_style.php`.

---

## 22. Build L6 — Packing List: page-margin fix + full header parity

**What it does:** Fixes the Packing List PDF looking cut off at the page
edges (reported after Build L5) and adds the missing company phone/email
line to its header, so it now matches the Modern invoice's header
exactly.

**Files touched:**
- `resources/views/pdf/packing_list.blade.php`

**How to verify:**
- [ ] Download the Packing List for any sale — the content must have a
      clear margin on all sides (nothing flush against the page edges),
      matching how the Sale Invoice PDF looks.
- [ ] The header must show three lines on the left: Company Name,
      Address, and Phone | Email — same as the Sale Invoice PDF's header.
- [ ] For a sale with no box quantities, the Code/SKU values should not
      wrap awkwardly onto two lines.

**Regression test:** `tests/Regression/build_l6_packing_list_margin_fix.php`.

## 23. Build M1 — Payment Terms & Due Dates (Phase A of Customer Ledger plan)

**What it does:** Adds a 3-level Payment Term hierarchy (System Default →
Customer Default → Invoice Override) and a snapshotted Due Date on every
sale, so overdue invoices can be identified as
`today > due_date AND outstanding > 0`. This is Phase A of a 3-phase plan
(B: Real Admin Customer Ledger, C: Invoice-wise Payment Allocation — both
still proposed future work, not built).

**Files touched:**
- `database/migrations/2026_09_19_000001_add_payment_terms_and_due_dates.php`
- `app/Support/PaymentTerms.php`
- `app/Models/Setting.php`, `app/Models/Client.php`, `app/Models/Sale.php`
- `app/Support/SaleMetadataRules.php`
- `app/Http/Controllers/SalesController.php`
- `app/Http/Controllers/ClientController.php`
- `app/Http/Controllers/SettingsController.php`
- `resources/src/pages/settings/SystemSettings.vue`
- `resources/src/pages/people/CustomerForm.vue`
- `resources/src/pages/sales/SaleForm.vue`

**How to verify:**
- [ ] Settings → Features: set the Default Payment Term (try 15 Days).
      Create a sale for a customer with no term of their own, leave the
      Sale form's Payment Term on "Use system/customer default" — the Due
      Date preview should be Sale Date + 15 days.
- [ ] Edit a customer, set their Payment Term to 30 Days (Custom also
      works). Create a new sale for that customer, leave the invoice's
      Payment Term on default — the Due Date preview should now use 30
      days, not the system default.
- [ ] On that same sale, explicitly pick "7 Days" as the invoice's own
      Payment Term — the Due Date preview should switch to 7 days,
      overriding the customer's 30 and the system default.
- [ ] Save the sale, then change the system default and/or the customer's
      own default afterwards — reopen the already-saved sale and confirm
      its Payment Term / Due Date did **not** change (it is snapshotted,
      not recalculated live).
- [ ] Edit that sale and clear the invoice-level override back to
      "default" — the due date should re-resolve using the customer's
      (or system's) *current* default.
- [ ] Back-date a sale's due date past today with an unpaid balance and
      confirm the API's `is_overdue` flag is true (no dedicated UI column
      yet — this is available for a future list/detail-page enhancement).

**Regression test:** `tests/Regression/build_m1_payment_terms_and_due_dates.php`
(real, DB-backed — hierarchy resolution at all 3 levels, snapshot
stability, overdue detection, Settings/Client/Sale field wiring).

**Not yet done:** Due Date / Overdue display on the Sales list and Sale
Detail pages (backend-ready, frontend not started); due date on PDF
templates / Public Invoice. Phases B and C of the original spec remain
proposed future work only, pending the user's go-ahead.

## 24. Build M2/M3 — Payment Terms fixes, on/off toggle, Due Date display

**What it does:** Fixes the "Custom" Payment Term option silently
snapping back on Settings > Features; adds a master on/off switch for
the whole Payment Terms & Due Dates feature; adds a Due Date column to
the Sales list (off by default — turn on via the column picker), a Due
Date row on the Sale Detail page, and a "Due Date line" toggle on the
Invoice PDF customizer (mirrors "Previous Dues").

**Files touched:**
- `resources/src/pages/settings/SystemSettings.vue`
- `resources/src/pages/people/CustomerForm.vue`
- `resources/src/pages/sales/SaleForm.vue`
- `resources/src/pages/sales/SaleDetails.vue`
- `resources/src/pages/sales/Sales.vue`
- `resources/src/pages/settings/InvoicePdfSettings.vue`
- `resources/src/pages/public/PublicInvoice.vue`
- `database/migrations/2026_09_19_000002_add_enable_payment_terms_toggle.php`
- `app/Models/Setting.php`, `app/Models/PdfTemplate.php`
- `app/Http/Controllers/SettingsController.php`,
  `SalesController.php`, `PublicInvoiceController.php`
- `resources/views/pdf/sale_pdf.blade.php`,
  `resources/views/pdf/sale_pdf_modern.blade.php`
- `resources/lang/en/pdf.php`, `resources/lang/ar/pdf.php`

**How to verify:**
- [ ] Settings > Features: click "Custom" next to Default Payment Term
      while it's currently set to Immediate/7/15/30 Days — a "days" input
      must appear immediately and stay selected on Custom (it must NOT
      snap back to the previous preset).
- [ ] Same check on a Customer's own Payment Term field, and on a Sale's
      Payment Term field when creating/editing a sale.
- [ ] Turn off "Enable Payment Terms & Due Dates" — the Payment Term
      controls disappear from the Customer form, the Sale form, and the
      Sale Detail page. Create a sale while it's off — it should get no
      due date at all. Turn it back on — new sales resolve a due date
      again as before.
- [ ] Sales list: open the column picker — "Due Date" should be listed
      but unchecked by default; check it and confirm the column appears
      (with a red "Overdue" tag on an overdue sale).
- [ ] Sale Detail page: a sale with a due date shows a "Due Date" row
      (with the "Overdue" tag when applicable).
- [ ] Settings > Invoice PDF customizer (Sale tab) > Sections: a new
      "Due Date line" toggle exists; turning it off removes the Due Date
      line from a downloaded/printed invoice; turning it back on restores
      it. An overdue invoice's due date prints in red with "(Overdue)".

**Regression tests:**
`tests/Regression/build_m2_payment_terms_fixes_and_due_date_display.cjs`
(run with `node`) and
`tests/Regression/build_m3_due_date_pdf_and_toggle_backend.php`.

## Build M4 — Customer Statement (admin)

**Files:**
- New: `app/Services/ClientStatementService.php`,
  `app/Http/Controllers/ClientStatementController.php`,
  `app/Exports/ClientStatementExport.php`,
  `resources/views/pdf/customer_statement_modern.blade.php`,
  `resources/src/pages/people/CustomerStatement.vue`,
  `tests/Regression/build_m4_customer_statement.php`
- `app/Http/Controllers/Api/Portal/PortalStatementController.php`
- `routes/api.php`, `resources/src/router/index.js`
- `resources/src/pages/people/Customers.vue`,
  `resources/src/pages/people/CustomerDetails.vue`
- `resources/lang/en/messages.php`

**How to verify:**
- [ ] Customers list > row action menu (⋮): a new "Customer Statement"
      item opens `/customers/{id}/statement` — a page with an Opening
      Balance / Total Debit / Closing Balance card row, a date-range
      filter, and a Date/Type/Ref/Description/Debit/Credit/Balance table.
- [ ] The numbers on this page must match the SAME customer's statement
      in the client portal (Account Statement page) exactly — same
      entries, same closing balance.
- [ ] Customer Details page: "Pay Due" button is now followed by a
      "View Statement" button; the customer's Address now shows next to
      Phone (previously missing even though the data was already there).
- [ ] "Download PDF" produces a Modern-invoice-styled statement PDF;
      "Download Excel" produces an .xlsx with the same rows plus a
      header block (customer/period/opening/closing balance).
- [ ] Applying a date range (From/To) narrows the table and both
      downloads to that period; "Reset" clears it back to all-time.
- [ ] The existing "Customer Ledger" menu item/page is untouched — this
      is a new, additional item, not a replacement.

**Regression test:**
`tests/Regression/build_m4_customer_statement.php` (real DB; also
cross-checks the portal and admin statements are identical).

## Build M4.1 — Customer Statement fixes/polish

**Files (in addition to Build M4's):**
- `app/Services/ClientStatementService.php`, `app/Exports/ClientStatementExport.php`
- `resources/views/pdf/customer_statement_modern.blade.php`
- `resources/src/pages/people/CustomerStatement.vue`
- `database/seeders/translations/en.php`
- `resources/lang/en/messages.php` (reverted — see CUSTOMIZATIONS.md)

**Extra apply step:** after replacing files, run:
```
php artisan db:seed --class=Database\Seeders\TranslationSeeder
```
(Safe to re-run — preserves any translation already customized through
the Translations UI; only adds/updates the defaults.)

**How to verify:**
- [ ] Page title, buttons, KPI labels, and table headers all show
      proper human text (no raw "Some_Key"-style text anywhere).
- [ ] Hero section shows email, phone, AND address (when the customer
      has them).
- [ ] 4 KPI cards: Opening Balance, Total Debit, Total Credit, Closing
      Balance.
- [ ] Below the table, a bold "Closing Balance: $X" line.
- [ ] Downloaded Excel file has the same bold Closing Balance total row
      at the bottom.
- [ ] Downloaded PDF no longer shows the customer's code or email —
      just name, address, phone.

**Regression test:** `tests/Regression/build_m4_customer_statement.php`
(extended — now also opens the real generated `.xlsx` with PhpSpreadsheet).

## Build M5 — Company header (VAT/BIN, Website) + shipping section removed

**Files:**
- `resources/views/pdf/sale_pdf_modern.blade.php`
- `resources/views/pdf/packing_list.blade.php`
- `resources/views/pdf/customer_statement_modern.blade.php`
- `resources/views/pdf/shipping_label.blade.php`
- New: `tests/Regression/build_m5_company_header_and_shipping_removal.php`

**Prerequisite:** Settings → Company (or wherever VAT number / Website
are set — same fields the Classic invoice already reads) must have a
VAT/BIN number and Website filled in for these lines to show (both are
optional; the line just doesn't render when empty).

**How to verify:**
- [ ] Download/print a Modern-layout Sale Invoice — top-left header now
      shows Address, VAT/BIN, Phone, Mail, Website (address/phone/mail
      always show; VAT/BIN and Website only when set).
- [ ] Same Modern invoice: the old "DELIVERY INFORMATION" shipping-
      label block at the bottom is completely gone — the invoice now
      ends right after the thank-you/footer text.
- [ ] Download a Packing List — same header fields now present.
- [ ] Download a Customer Statement PDF (Build M4) — same header
      fields now present.
- [ ] Download the standalone Shipping Label (from the Sales list) —
      now also shows Mail and Website (it already had VAT/BIN and
      Phone).
- [ ] The Classic-layout Sale Invoice and other Classic PDFs are
      unchanged (they already had this).

**Regression test:**
`tests/Regression/build_m5_company_header_and_shipping_removal.php`.

---

## Build M6 — Delivery Info on Sale Invoice PDF

**How to verify:**
- [ ] Open a sale that has a Tracking Ref, Zone and Courier set (Sale
      Detail page shows these in its top-right card) → download/print
      its Invoice PDF (either layout) → a "Delivery Info" box now
      shows Warehouse, Tracking Ref, Zone and Courier.
- [ ] Open a sale that has NO Tracking Ref/Zone/Courier set → its
      Invoice PDF still shows the "Delivery Info" box, but with only
      "Warehouse: …" — no empty Tracking Ref/Zone/Courier lines.
- [ ] Settings → Invoice PDF → Sales Invoice → Sections → turn off
      "Delivery Info" → save → re-download the same invoice → the
      whole box is gone.
- [ ] Quotation and Purchase PDFs are unaffected (no Delivery Info
      section there — Warehouse/Tracking/Zone/Courier are Sale-only
      concepts).

**Regression test:**
`tests/Regression/build_m6_invoice_delivery_info.php`.

---

## Build M7 — Real-time Sales Counter fixes + Dashboard hourly/warehouse panels

**How to verify:**
- [ ] Sales menu → Real-time Sales Counter → make a new sale → the
      "Today's sales by hour" chart now shows a bar in the hour the
      sale was actually made (not stuck at 00h).
- [ ] Same page → Recent Sales table → the "Reference" column now
      shows the invoice reference (not blank) for new and existing
      sales.
- [ ] Main Dashboard → a new row appears with "Today's sales by hour"
      (bar chart) and "Sales by Warehouse" (table) — make a sale and
      confirm the hour chart updates and the warehouse table shows the
      correct warehouse with +1 invoice and the right amount.
- [ ] Dashboard's date-range filter still changes "Sales by
      Warehouse" (it respects the picked range, like the rest of the
      Dashboard); "Today's sales by hour" always stays "today"
      regardless of the range picked (same as the Real-time Sales
      Counter page).
- [ ] All other Dashboard/Real-time Sales Counter figures (stat cards,
      top selling, stock alert, etc.) are unaffected.

**Regression test:**
`tests/Regression/build_m7_dashboard_hourly_warehouse_and_realtime_fixes.php`.

---

## Build M8 — Invoice Receivables Report

**How to verify:**
- [ ] Reports → Invoice Receivables Report opens and lists one row per
      completed sale (Invoice Total, Return Amount, Net Invoice, Paid,
      Remaining, Due Date, Overdue Days, Status).
- [ ] Summary cards at the top (Total Invoice, Total Return, Net
      Invoice, Total Paid, Total Remaining, Total Overdue) match the
      sum of the rows shown for the current filter.
- [ ] A fully-paid invoice shows Status "Paid" and Remaining ৳0.
- [ ] A partially-paid invoice not yet past its due date shows
      "Partial".
- [ ] An invoice past its due date with something still owed shows
      "Overdue" (red tag) with a positive Overdue Days count.
- [ ] An untouched invoice not yet due shows "Due".
- [ ] A sale return still in "pending" status does NOT reduce that
      invoice's Net Invoice Amount; only a "received" return does.
- [ ] Date From/To, Customer and Status filters narrow the list
      correctly; "Show Outstanding Only" hides fully-paid invoices.
- [ ] A role that doesn't have the new "Invoice Receivables Report"
      permission (Roles & Permissions → find it under Reports) can't
      see the menu item or open the report directly.

**Regression test:**
`tests/Regression/build_m8_invoice_receivables_report.php`.

---

## Build N1a — Security Hardening (Critical findings from third-party audit)

**How to verify:**
- [ ] Try creating a Sale via the API/POS with a line's subtotal
      deliberately not matching its quantity × price (e.g. through
      browser dev tools) — the request should be rejected (422), not
      saved.
- [ ] A normal sale with a real discount and tax still saves correctly
      (nothing about the ordinary checkout flow changed).
- [ ] Complete a sale for a product in a warehouse it has never been
      stocked in before — the sale should complete AND a stock row
      should be created with the correct (negative) quantity, not
      silently do nothing.
- [ ] Try uploading a `.php` file as a Sale/Purchase/Purchase Order/
      Expense attachment — it should be rejected. A real PDF/image/
      Office file should still upload fine.
- [ ] Settings → Backup → Generate Backup still works; check that
      `storage/app/backups` (not `storage/app/public/backup`) now
      contains the `.sql` file.
- [ ] Log out (or open an invoice PDF link in a private/incognito
      window with no login) and try opening a `sale_pdf/{id}` or
      similar link directly — it should now require login instead of
      opening the PDF straight away.
- [ ] The public, shareable invoice link (`public/invoice/{token}`)
      still works without login, exactly as before.

**Regression test:**
`tests/Regression/build_n1_security_hardening.php`.

**Not covered by this build (see CUSTOMIZATIONS.md "Known gaps"):**
overpayment capping, Purchase/Transfer/Adjustment/Damage versions of
the C-01/C-02 fixes, DB-level unique constraints, Purchase Order
float→decimal columns, npm dependency upgrades.

---

## Build N1b — Readable Product + Variant Display Names

**Expected format:** `Apple Macbook 2026 - Variant: Grey`.

**How to verify:**

- [ ] Product/item list and product suggestions show the expected format.
- [ ] POS search, recent-sale history, and cart lines show the same format.
- [ ] Sale Create/Edit/Detail and all sale invoice/print/PDF paths match.
- [ ] Purchase, Purchase Order, Quotation, Returns, Transfer, Adjustment, and
      Damage screens/documents match.
- [ ] Reports, public invoice, customer portal, online-order invoice, and
      kitchen order labels match.
- [ ] A simple product shows only its name, with no `Variant:` suffix.
- [ ] A multi-option variant such as `Blue / XL` is not split or reordered.
- [ ] Search by product name, variant name, and variant code still returns the
      correct item.
- [ ] SKU/code, stock, price, tax, discount, and all calculations are unchanged.
- [ ] No legacy `[Grey]Apple Macbook 2026` label remains.

**Automated tests:**

```bash
php vendor/bin/phpunit tests/Unit/ProductDisplayNameTest.php
php tests/Regression/build_n1_product_variant_display_name.php
```

---

## Build N2 — High-Severity Findings (Overpayment, Stock Locking, Ref Uniqueness, PO Decimals)

**How to verify:**
- [ ] In POS/Sale checkout, tender MORE cash than the total (e.g. total
      1044.06, pay with 1245.06) — the sale should show change 201.00 as
      before, but "Paid" on the sale should read exactly the total
      (1044.06), never the full tendered amount.
- [ ] Same check on a Purchase payment (Payment Purchases → record a
      payment larger than the outstanding due) — paid amount is capped at
      the purchase's own total.
- [ ] Create a Purchase (GRN), Adjustment, or Damage for a product in a
      warehouse it has never been stocked in before — it should complete
      AND a stock row should be created with the correct quantity, not
      silently do nothing (previously: silently did nothing outside the
      Sales module).
- [ ] Create/approve a Transfer for a product that has both a plain
      (non-variant) stock row and a separate variant-specific stock row in
      the destination warehouse — the plain transfer must affect the
      PLAIN row, never the variant row.
- [ ] Add a new Purchase Order line with a fractional cost/quantity (e.g.
      10.10, 1.125) and reload it — the value must come back exactly as
      entered, no rounding drift.
- [ ] Two sales/purchases can no longer end up with the identical
      reference number, even under back-to-back rapid creation.

**Regression test:**
`tests/Regression/build_n2_high_severity_fixes.php`.

**Not covered by this build (see CUSTOMIZATIONS.md "Known gaps"):**
attachment access control (H-03), release-artifact hygiene (H-04),
regression-suite exit-code masking (H-05), npm dependency upgrades
(H-08), soft-delete audit trail depth (H-09), and the same stock-locking
pattern in PurchasesReturnController/SalesReturnController (found during
this build but outside its stated scope).

## Build N3 — User-Reported Bugs After Build N2

**How to verify:**
- [ ] Open a sale's public invoice link (Sale Detail → generate/copy
      public link) in a private/incognito browser window (no login) and
      click "Download PDF" — it should download a real PDF, not show a
      403 error.
- [ ] The direct `/sale_pdf/{id}` link should still require login (this
      must NOT have re-opened).
- [ ] On the same public invoice page: "Billed to" is bold, the item
      table header has a shaded background, and each item row shows a
      serial number (1, 2, 3, ...).
- [ ] Create or edit a Sale — the "Net Unit Price" column in the line
      table should be directly editable (like Purchase's "Net Cost"
      column already is), and discount/tax/subtotal should recompute
      when it's changed.
- [ ] Open a Sale/Purchase/Quotation/Sale Return/Purchase Return Detail
      page — the "Company" box should show Name, Address, VAT/BIN (if
      set), Phone, Email, Website (if set), in that order.
- [ ] Download a Packing List PDF for a sale whose client has an address
      and phone — both should appear, along with Warehouse, Order Status,
      and Payment Status.
- [ ] After applying this build, run `php artisan db:seed --class=Database\Seeders\TranslationSeeder`
      — the "Zone Courier Report", "Price Variance Report", and "Stock
      Lookup" menu items should read correctly. If any of the three still
      look wrong afterward, check Settings → Translations for that exact
      key — it may have been hand-customized there, which the seeder
      intentionally never overwrites.
- [ ] System Settings → Invoice PDF → template dropdown should say
      "Modern", not "Modern (with Shipping Label)".

**Regression test:** `tests/Regression/build_n3_bug_fixes.php`.

**Migration:** none — this build is code + one translation-seeder run
only. `php artisan db:seed --class=Database\Seeders\TranslationSeeder`
should still be run once to correct the 3 translation keys.

## Build N5 — POS receipt toggles + 2 new layouts, POS SKU search fix, Dashboard chart/profit clarity, Customer Statement fix (2026-09-20)

### 1. POS Receipt Show VAT/BIN + Show Website toggles

**How to verify:**
- [ ] Settings → POS Receipt — confirm "Show VAT/BIN" and "Show Website"
      appear in the toggle list, next to the existing Show Address/Show
      Email toggles.
- [ ] Toggle each on/off and confirm the live preview updates immediately
      for whichever layout is currently selected.
- [ ] Set a VAT/BIN number and a Website in Company Settings, save, then
      go make a real POS sale and print/preview the receipt — both
      should appear (or not) exactly matching the toggle state.
- [ ] Confirm existing receipts/layouts that showed VAT/BIN before this
      update still show it by default after `php artisan migrate` (the
      new column defaults to ON specifically to avoid silently hiding it
      on existing ZATCA-style receipts).

**Regression test:** `tests/Regression/build_n5_receipt_vat_website_toggles.php`.

### 2. POS Receipt Layout 6 (Roomy) + Layout 7 (Simplified Tax Invoice, English)

**How to verify:**
- [ ] Settings → POS Receipt (or Settings → POS Settings) — confirm the
      layout dropdown now offers "Layout 6 - Minimal (Roomy)" and
      "Layout 7 - Simplified Tax Invoice (English)" in addition to the
      existing 5.
- [ ] Select Layout 6 — the live preview should look like Layout 5
      (Minimal) but noticeably more spaced out / larger text, matching
      Layout 1-4's roominess.
- [ ] Select Layout 7 — the live preview should look like Layout 4
      (Bilingual) but with **no Arabic text anywhere**, English only.
- [ ] Save each layout selection, then make/print a real POS sale on
      each — confirm the printed/PDF receipt matches the live preview,
      and that on a narrow paper size (58mm/80mm) Layout 7 does not crop
      or wrap the right edge.
- [ ] With Layout 7 selected, confirm the ZATCA QR code / Invoice QR
      code (if enabled) still renders on the printed receipt — this is
      the toggle most likely to silently break if a future customization
      ever changes these layouts' QR block markup.
- [ ] Toggle Show VAT/BIN, Show Website, Show Email, etc. while Layout 6
      or 7 is selected — same toggle behavior as every other layout.

**Regression test:** `tests/Regression/build_n5_receipt_layouts_6_7.php`.

### 3. POS search by variable product's main SKU

**How to verify:**
- [ ] Open POS, search by a variable product's **main/parent SKU**
      (not a variant's own code) — matching variant rows should now
      appear, exactly as they already do in Sales > Create Sale.
- [ ] Confirm searching by a variant's own individual code still works
      exactly as before (no regression to the existing behavior).

**Regression test:** `tests/Regression/build_n5_pos_main_sku_search.php`.

### 4. Dashboard: Sales vs Purchases chart

**How to verify:**
- [ ] Open the Dashboard — the Sales vs Purchases chart should now be a
      smooth gradient area chart (not a bar chart), visually matching
      the style of the Payment Methods chart on the same page.
- [ ] Hover over the chart — tooltips should still show the correct
      Sales/Purchases figures for each point.
- [ ] Switch the dashboard's date range / warehouse filter and confirm
      the chart re-renders correctly as an area chart every time (not
      reverting to a bar chart on data refresh).

### 5. Dashboard: Profit card

**How to verify:**
- [ ] Hover the small info icon next to "Sales" and "Profit" on the
      Dashboard stat cards — each should show a tooltip explaining
      exactly what it counts (Sales = all sales incl. drafts/holds;
      Profit = completed sales only, minus FIFO cost of goods and
      expenses, plus service job profit).
- [ ] Create a draft/held sale and confirm it appears in the Sales total
      but not in the Profit calculation — this is expected, correct
      behavior (now explained by the tooltip, not a bug).

**Regression test:** `tests/Regression/build_n5_dashboard_profit_verification.php`.

### 6. Customer Statement date range + Opening Balance fix

**How to verify:**
- [ ] Open a customer's Statement page — it should now default to
      showing the last ~90 days instead of the full history.
- [ ] Click "Show All Time" — full history should load exactly as
      before this build.
- [ ] Pick a client with activity both before and after some date, set
      "From Date" to that date — the "Opening Balance" row shown should
      now correctly reflect all activity *before* that date (not just
      the client's static opening balance), and the final running
      balance at the bottom should match the all-time closing balance
      exactly.

**Regression test:** `tests/Regression/build_n5_customer_statement_range_fix.php`.

**Migration:** `php artisan migrate` (new `pos_settings.show_vat_bin` /
`show_website` columns; additive only, safe rollback via
`migrate:rollback --step=1`).

---

## 31. Consolidated September 20–23 release checklist (Builds O1–O9)

This supplements the in-depth Build O1–O9 record appended to
`CUSTOMIZATIONS.md`; nothing above was removed.

### 31.1 Git/release integrity

- [ ] Confirm the intended review base is GitHub Build N5 (`8ca9b05...`) or
      consciously integrate any newer remote work before review.
- [ ] Review branch contains all 30 post-N5 commits plus the final handoff
      commit; `git bundle verify` and a test clone both pass.
- [ ] Verify every SHA-256 in the delivery checksum manifest.
- [ ] Full-source ZIP contains no `.env`, credentials, database dump, logs,
      `vendor`, `node_modules`, `.git`, or previous delivery ZIPs.
- [ ] Changed-files overlay contains every file different from Build N5 and no
      unrelated runtime secret.

### 31.2 Client Portal

- [ ] Desktop/mobile Dashboard is compact; Total Paid remains visible.
- [ ] Invoice/Payment mobile lists are readable horizontal-scroll tables.
- [ ] Invoice Detail has one status treatment, left-side reference/status, no
      duplicate arrows, aligned amounts, and responsive totals.
- [ ] Dark theme, language, full-screen and responsive navigation work.
- [ ] Invoice download uses configured modern template; Statement PDF works.
- [ ] Appointment/Contract/Quotation flows still work.
- [ ] `npm run build:portal` passes and assets match source.

### 31.3 Customer Display

- [ ] A long cart scrolls in the item segment while calculations remain visible.
- [ ] Unit price aligns, percentage discount shows amount, no thumbnails appear.
- [ ] Date/time and desktop/mobile layouts work.
- [ ] `npm run build:customer-display` passes.

### 31.4 Real-Time Sales Displays

- [ ] Both migrations run; multiple named links survive cache clear.
- [ ] Warehouse scope, creator, created/expiry/last-seen/online data are correct.
- [ ] Copy/Open/Edit/Regenerate/Revoke/Archive affect only the selected display.
- [ ] Revoked/archived/expired public links are rejected.
- [ ] Light default/dark option, table headers, row hover, totals, warehouse last
      sale, ticker and new-sale notification are readable.
- [ ] Manager profile shows top warehouse, sales velocity, peak sales hour.
- [ ] Forced API failure increments count; later success auto-recovers and
      updates last successful sync/recovery.
- [ ] Scheduler cron is active and existing `APP_KEY` preserved.
- [ ] `npm run build:realtime-sales-display` passes.

### 31.5 POS keyboard search and variant picker

- [ ] Arrow Down/Up and Enter navigate/select suggestions.
- [ ] Simple SKU, variant SKU, barcode and mouse behavior are unchanged.
- [ ] Variable parent SKU opens picker; selected variant adds exactly once with
      correct stock/unit/price/tax/discount.
- [ ] Modal close/footer spacing works desktop/mobile.
- [ ] Verify/bump `public/sw.js` cache version against production.

### 31.6 Supplier Statement/Ledger

- [ ] Supplier Details/List expose Statement; Details exposes Download Ledger.
- [ ] Opening balance, purchase debit, payment credit, return credit and refund
      debit produce correct running balance.
- [ ] From-date carry-forward preserves final closing balance.
- [ ] Filtered PDF/Excel work; correct dedicated supplier Blade templates serve.
- [ ] Customer Ledger design/address remains correct without customer code.
- [ ] User without `Suppliers_view` is denied.

### 31.7 Dashboard

- [ ] Comparison charts use zero-based area/spline rendering; Today one-point
      series renders and all-zero series shows empty state.
- [ ] Hourly bar timeline covers 0–23 and shows empty state when appropriate.
- [ ] Settings order saves and applies immediately and after login.
- [ ] Paired cards stay together; real-time/warehouse row is configurable.
- [ ] Default date range and dashboard typography settings apply.

### 31.8 Product Insights

- [ ] Sold, Trend, Revenue, Return Rate and Last Sold are visible by default.
- [ ] Trend: previous 0/current >0 = New; equal non-zero = 0%; both zero = dash.
- [ ] Revenue equals completed line totals in the same rolling-30-day/warehouse
      scope, including variable products; server sort matches display.
- [ ] Query remains set-based with no per-product/N+1 regression.

### 31.9 Product Movement History

- [ ] Menu/route loads; typeahead matches product/variant names and SKUs.
- [ ] Product/Warehouse/Variation/Date filters combine correctly.
- [ ] Restricted out-of-scope warehouse returns 403.
- [ ] Every stock source/status, alternate unit and pack multiplier is correct.
- [ ] Customer/Supplier and Variant identify rows.
- [ ] Zero return lines disappear; real non-zero variants remain separate.
- [ ] From-date Opening Balance and historical/current reconciliation are valid.
- [ ] Summary equals filtered rows; filtered Excel/PDF contain visible columns.

### 31.10 Deployment/test gate

- [ ] Back up DB/files and record commit + `APP_KEY`.
- [ ] Locked PHP/Node dependencies install successfully.
- [ ] `php artisan migrate --force` passes on staging copy.
- [ ] `npm run build` passes all targets.
- [ ] PHP lint, full PHPUnit/regression and real-DB fixtures pass.
- [ ] Browser smoke test passes desktop/tablet/mobile.
- [ ] Only then push the reviewed branch and deploy.

### 31.11 Explicitly deferred

- [ ] COGS/average-cost/GRN costing architecture is planned, not shipped.
- [ ] Warehouse-specific document address is planned, not shipped.
- [ ] Dashboard 1/Dashboard 2 selector is planned, not shipped.
- [ ] Extra Manager target/margin/return/no-sale/high-value alerts are not in
      the approved O1–O9 scope.

## 32. Build P — live reconciliation checklist (2026-09-24)

- [ ] Live `public/js` replaced with the P build (whole folder, not a merge),
      including `public/js/storefront.css` and `public/js/storefront.min.js`
      (Online Store layout requires both; they were missing on the live copy
      reviewed).
- [ ] `public/sw.js` is `stocky-pwa-v14`; POS opened once online on every
      till/PWA install and hard-refreshed (Ctrl+F5) so the old shell is dropped.
- [ ] POS: type a partial name, wait for the list, type more and press Enter
      immediately — no wrong product is added; scanner scan + Enter adds the
      scanned product; ↑/↓ + Enter still picks the highlighted row.
- [ ] Suppliers → Statement: From date inside the history — opening line equals
      the real balance on that date, no earlier opening-payment row, closing
      balance equals the supplier's balance.
- [ ] Sale invoice (Modern), payment receipts (sale + purchase), PO PDF render
      as before; Modern invoice shows Tracking Ref/Courier when set; PO header
      shows Website when set.
- [ ] `SHOW CREATE TABLE real_time_sales_displays` — `expires_at` must NOT carry
      `ON UPDATE CURRENT_TIMESTAMP` (MariaDB/MySQL with
      `explicit_defaults_for_timestamp=OFF` adds it to the first NOT NULL
      TIMESTAMP column). If it does, `ALTER TABLE real_time_sales_displays
      MODIFY expires_at DATETIME NOT NULL;`.
- [ ] GitHub repository visibility is Private.

## 33. Build Q1 — Create Sale payment-term header

- [ ] Payment Terms enabled: Date, Customer, Payment Term, Due Date and
      Warehouse share one desktop row and stack cleanly on mobile.
- [ ] Payment Terms disabled: term/due controls are absent and Date/Customer/
      Warehouse use equal widths.
- [ ] Custom term input works; Due Date follows invoice/customer/system term.
- [ ] Positive previous due appears in base currency; zero/credit is hidden.
- [ ] Create, Edit and quotation conversion remain functional.

## 34. Build Q2 — Zone/Area, Courier and customer search

- [ ] Sales menu contains Zones / Areas and Couriers; both routes work on
      desktop/mobile and respect DataTable preferences.
- [ ] Search, sorting, pagination, usage count and last-updated display work.
- [ ] Page create immediately becomes available to Create Sale/Shipment/POS
      workflows on their next data load.
- [ ] Inline create still works and its value appears on the management page.
- [ ] Rename preserves linked historical/current sales.
- [ ] Blank, too-long and case/space-equivalent duplicate names are rejected.
- [ ] User without `Sales_edit` cannot rename; legitimate Sale/POS/Shipment
      users retain their existing read/inline-create behavior.
- [ ] Customer search matches name, phone, email and code in Create, Edit and
      quotation-conversion modes.
- [ ] Selecting a searched customer still loads previous due, payment terms,
      loyalty points and credit-limit behavior.
- [ ] `build_q2_sale_lookup_management.cjs` and existing payment-term regression
      pass; staging DB/API/browser smoke test completed before deployment.

## 35. Build Q3 — Sale totals validation correction

- [ ] Edit an existing sale with percentage order discount, non-zero Order Tax
      and shipping; the unchanged valid totals save without HTTP 422.
- [ ] Create a sale with the same combination; it saves successfully.
- [ ] Create/Edit with a valid loyalty-points discount; tax is calculated after
      both discounts and the sale saves.
- [ ] Tamper with a line subtotal or Grand Total in an API request; the guard
      still returns HTTP 422 and persists nothing.
- [ ] Run `node tests/Regression/build_q3_sale_totals_guard.cjs` and
      `php artisan test --filter=SaleTotalsGuardTest` before deployment.
- [ ] Run `npm run build:admin` on the deployment source; no prebuilt assets are
      included in this task.

## 36. Audit Batches 1-5 — calculation, payment and security hardening

- [ ] Sale/POS: tamper a line subtotal or Grand Total in the request; it is refused and nothing is stored.
- [ ] Sale return / purchase return larger than the original quantity or price is refused; refund never exceeds paid.
- [ ] A sale cannot go below zero stock; adjustment, transfer and purchase return cannot make stock negative.
- [ ] A deleted document cannot be edited, deleted again, approved or paid.
- [ ] Payment add/edit/delete on sale, purchase and returns: paid amount, status and due always equal the payment rows;
      overpayment is refused.
- [ ] Edit a sale/purchase/return so the total is below what is already paid: refused with a message, stock unchanged.
- [ ] Pending sale/purchase return does not change customer/supplier balance, client brief, public invoice or today's
      summary; received/completed returns do.
- [ ] Dashboard, Profit & Loss, Analytics, Tax summary, Discount summary and the top-bar Today's summary agree for the same
      period (completed only; net = total - tax - shipping - returns).
- [ ] Sell a product in a carton/unit: profit cost (COGS) uses base-unit quantity and nets received returns.
- [ ] Cash Flow table and chart show the same entries; return refunds appear.
- [ ] A warehouse-limited user cannot see another warehouse by changing warehouse_id.
- [ ] User without System Settings cannot upload/enable a module, clear cache, edit QuickBooks/custom fields/storefront
      pages, or receive integration API keys.
- [ ] `php artisan migrate` ran (14 `idx_b5_*` indexes exist); `php artisan optimize:clear` ran; Ctrl+F5 done.
- [ ] Run `tests/run_regression.sh`; only the 12 documented harness-only `build_*` scripts may fail.
- [ ] After a vendor upgrade, follow `docs/AUDIT_MERGE_GUIDE.md` and re-run the regression suite.
