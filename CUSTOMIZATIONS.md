# StockyUltimate — Customizations Log

This file documents every customization made on top of the vendor
(CodeCanyon) codebase, in the order they were built. Read this before
touching any of the areas below — it explains *why* each piece exists,
not just what it does.

**Golden rules for anyone (human or AI) working on this codebase:**
1. Never edit the vendor baseline commit. All custom work happens in commits
   layered on top of it (see `git log`).
2. Every schema change is a migration — never hand-edit the database schema.
3. Every new/changed calculation that touches money, stock, or totals must be
   verified against a real test database before shipping (see "Testing
   approach" below). This app is the financial system of record for a real
   business — a silent rounding or logic error here costs the owner money.
4. Match existing code conventions in the file you're editing (this vendor
   codebase mixes an older Blade/jQuery admin with a newer Vue 3 + Ant
   Design Vue SPA — don't introduce a third style).
5. Keep changes additive and backward-compatible: new nullable columns, new
   optional fields, new routes — never rename or repurpose an existing
   column/route/permission that other code depends on.

---

## 1. Tracking Ref / Zone / Courier (Sales)

**Why:** The business ships orders via courier and needed to record, per
sale, a courier tracking number, a delivery zone, and which courier
company handled it — with Zone/Courier as reusable, user-manageable
dropdown lists (not free text), so reporting stays clean.

**Schema** — migration
`database/migrations/2026_09_06_000001_add_tracking_zone_courier_to_sales.php`:
- New tables `sale_zones`, `sale_couriers` (id, name, timestamps, soft
  deletes, unique name).
- New columns on `sales`: `tracking_ref` (string, nullable),
  `zone_id`, `courier_id` (nullable FKs, `ON DELETE SET NULL`).

**Backend:**
- `app/Models/SaleZone.php`, `app/Models/SaleCourier.php` — new, trivial
  models.
- `app/Models/Sale.php` — added the 3 columns to `$fillable`, `zone_id`/
  `courier_id` to `$casts`, and `zone()`/`courier()` `belongsTo` relations.
- `app/Http/Controllers/SaleMetaController.php` — new controller.
  `GET sale_meta` returns `{zones, couriers}` for the dropdowns.
  `POST sale_zones` / `POST sale_couriers` implement the "type a new name,
  press Enter" quick-add used by `CreatableSelect.vue` — `firstOrNew` +
  restore-if-trashed, so re-adding a soft-deleted name doesn't collide with
  the unique constraint.
- `app/Http/Controllers/SalesController.php` — touched in these methods
  only: `index` (list + zone/courier filters + zone_name/courier_name per
  row), `store`, `update` (persist the 3 fields), `create`, `edit` (return
  the zone/courier option lists + current values), `show` (Sale Detail
  page data), `Sale_PDF` / `Sale_PDF_Inline` (kept in step with `show`).
- `app/Http/Controllers/ReportController.php` — Sales Report
  (`Report_Sales`) gained the same 3 columns/filters and returns
  `zones`/`couriers` for its filter dropdowns.
- Routes added in `routes/api.php`, inside the existing authenticated
  `sales` route group: `sale_meta`, `sale_zones`, `sale_couriers`.

**Frontend:**
- `resources/src/components/CreatableSelect.vue` — new, generic. A
  searchable `<a-select>` where typing a new name and pressing Enter (or
  the `+` button) POSTs to `createEndpoint`, adds the result to the local
  options list, and selects it. Reused for both Zone and Courier — don't
  fork it, extend props if a third lookup like this is ever needed.
- `resources/src/pages/sales/Sales.vue` — 3 new list columns
  (`tracking_ref`, `zone_name`, `courier_name`), plain `dataIndex` columns
  so the existing Excel/PDF export (which just serializes the columns
  array) picked them up with no extra work.
- `resources/src/pages/sales/SaleForm.vue` — 3 new fields next to Sales
  Agent (Tracking Ref text input, Zone/Courier `CreatableSelect`).
  `zoneOptions`/`courierOptions` refs are populated from whichever
  bootstrap endpoint loaded (`sales/create`, `sales/{id}/edit`, or the
  quotation-conversion endpoint).
- `resources/src/pages/sales/SaleDetails.vue` — top info block shows
  Warehouse/Tracking Ref/Zone/Courier when present (`v-if`, so it never
  shows an empty row).
- `resources/src/pages/reports/SalesReport.vue` — same 3 columns +
  Zone/Courier filter selects, same `opts()` pattern as the existing
  Warehouse/Customer filters.

## 2. Zone / Courier Report (new report page)

**Why:** A dedicated view answering "which zone/courier carries the most
volume and outstanding balance" — the Sales Report's row-per-sale view
doesn't answer that at a glance.

- `app/Http/Controllers/ReportController.php@zoneWiseReport` — new method.
  Groups `sales` (same date-range/warehouse filters as `Report_Sales`) by
  `zone_id` and, separately, by `courier_id`, via `leftJoin` +
  `COALESCE(..., 'Unassigned')` so sales with no zone/courier still show
  up in one bucket instead of vanishing from the totals. Permission reuses
  `Reports_sales` (it's a variant of the Sales Report, not a new
  permission).
- Route: `GET report/zone_wise` (`routes/api.php`, same auth group as
  `report/sales`).
- `resources/src/pages/reports/ZoneWiseReport.vue` — new page, two
  independent `a-table`s (Zone, Courier) with their own Excel/PDF export
  buttons (uses `lib/exporters.js` directly rather than the full
  `ReportPage`/`useCrudTable` machinery, since this is a small aggregated
  dataset, not a paginated row list).
- Registered in `resources/src/router/index.js`
  (`/reports/zone-wise`) and `resources/src/config/menu.js` (legacy-path
  mapping `/app/reports/zone_wise_report` + a Reports submenu entry,
  label key `Zone_Courier_Report` — **not yet in the translations table**,
  so it currently renders as that literal string; add a proper translation
  row if/when polishing labels).

## 3. Box quantity per sale line

**Why:** The business packs orders into physical boxes and wants to record
"this line's quantity was N boxes" (e.g. quantity 12, box_qty 1) —
informational only, not used in any stock/costing calculation.

**Schema** — migration
`database/migrations/2026_09_07_000001_add_box_qty_to_sale_details.php`:
new nullable `decimal(10,2)` column `box_qty` on `sale_details`.

**Backend** (`app/Models/SaleDetail.php`, `SalesController.php`):
- Added to `SaleDetail`'s `$fillable`/`$casts`.
- Threaded through every place a sale line is read or written: `store`,
  `update` (both save it from the incoming line payload), `edit` (Edit
  Sale form prefill), `show` (Sale Detail page), `Sale_PDF` /
  `Sale_PDF_Inline` (PDF/print), and the new `Sale_Packing_List`.
- **This is intentionally NOT part of any cost/inventory calculation** —
  it never appears in `PurchaseDetail`, `get_average_cost_by_product`, or
  `CalculatesCogsAndAverageCost`. If a future feature wants box_qty to
  affect stock math, that's a deliberate new decision, not something this
  column already assumes.

**Frontend:**
- `resources/src/pages/sales/SaleForm.vue` — new "Box" column in the
  product line-items table (an `a-input-number`), between Net Unit Price
  and Stock. Defaults to `null` for a newly added line; flows through
  automatically for Edit Sale via the `...d` spread when mapping backend
  line data onto `lines.value`.
- `resources/src/pages/sales/SaleDetails.vue` — "Box" column in the items
  table (shows `—` when null).
- `resources/views/pdf/sale_pdf.blade.php` — "Box" column in the invoice
  PDF, next to Qty.

## 4. Sale Detail page — compact info layout

**Why:** Adding Warehouse/Tracking Ref/Zone/Courier as more rows in a
single-column `<table>` pushed the page height way down and left a lot of
dead white space next to the (short) logo column.

- `resources/src/pages/sales/SaleDetails.vue` — the `.inv-meta` block
  changed from a `<table>` (one row per fact) to a CSS Grid
  (`grid-template-columns: repeat(2, auto)`), so the same facts lay out
  in ~half the vertical space on desktop. Below 640px width it collapses
  back to a single column (unchanged mobile behavior) via a media query.
  This is pure layout — no data or logic changed, and `window.print()`
  automatically reflects it since Print renders this same page.

## 5. Shipping Label & Packing List (new PDFs)

**Why:** Two more documents the warehouse/courier workflow needs, distinct
from the full invoice: a small label to stick on the parcel, and a
prices-free list for whoever physically packs the order.

- `app/Http/Controllers/SalesController.php@Sale_Shipping_Label` /
  `@Sale_Packing_List` — new methods, added right after `Sale_PDF`.
  **Deliberately have no `authorizeForUser` call** — this matches every
  other sibling method in that same "Print & PDF" route block
  (`Sale_PDF`, `Quotation_pdf`, `Purchase_pdf`, etc.), which all sit
  outside the `auth:api` middleware group by original vendor design. Don't
  add an auth check to only these two without adding it to the whole
  block — that would be an inconsistent, confusing halfway fix.
- Routes in `routes/api.php`: `sale_shipping_label/{id}`,
  `sale_packing_list/{id}` — added next to `sale_pdf/{id}` in the same
  unguarded "Print & PDF" section for the reason above.
- New blade views: `resources/views/pdf/shipping_label.blade.php` (sender/
  receiver blocks + a COD amount banner shown only when
  `payment_status !== 'paid'`), `resources/views/pdf/packing_list.blade.php`
  (product/code/qty/box table, no prices).
- `resources/src/pages/sales/SaleDetails.vue` — two new toolbar buttons
  next to the PDF button, each just downloading from the routes above.

---

## 6. Sales list — bulk actions + Qty column

**Why:** With many sales selected via the existing bulk-delete checkboxes,
the business wanted to bulk-print invoices/labels and bulk-set shipping
status/zone/courier instead of doing it one sale at a time. Also wanted a
per-row total quantity column.

**Backend** (`app/Http/Controllers/SalesController.php`):
- `index()` — added `->withSum('details', 'quantity')` to the existing
  query and exposed it as `total_qty` per row (a single extra `SUM`
  subquery, no N+1).
- `renderSaleInvoiceHtml($id)` — new **private** helper. It is the exact
  data-building + Arabic-glyph-fix logic from `Sale_PDF`, extracted so it
  can be called once per sale in a loop. **`Sale_PDF` and `Sale_PDF_Inline`
  themselves were deliberately left untouched** (still have their own copy
  of this logic) rather than refactored to call the new helper — those are
  proven, high-traffic methods, and even a careful extract-method carries
  some risk; duplicating ~110 lines once was judged the safer trade next to
  touching them. If they're ever revisited, folding all three onto one
  helper would remove the duplication.
- `Sale_PDF_Bulk(Request $request)` — `GET sale_pdf_bulk?ids=1,2,3`. Loops
  `renderSaleInvoiceHtml()` per id, joins the HTML with a
  `page-break-after` div between each, renders ONE PDF containing every
  selected invoice back-to-back.
- `Sale_Shipping_Label_Bulk(Request $request)` — `GET
  sale_shipping_label_bulk?ids=1,2,3`. Same idea for the label template.
- `bulkUpdate(Request $request)` — `POST sales_bulk_update` with
  `{selectedIds, shipping_status?, zone_id?, courier_id?, tracking_ref?}`.
  Only the keys actually present in the request are written, so e.g.
  sending just `zone_id` never touches shipping_status on those rows.
- **Important gotcha found and fixed while building this**:
  `resources/views/pdf/sale_pdf.blade.php` declares a plain PHP function
  (`formatPrice`) inline in an `@php` block. That's harmless when the view
  renders once per request (every existing usage), but
  `renderSaleInvoiceHtml()` calls `view('pdf.sale_pdf', ...)->render()`
  once per selected sale **in the same request**, so the second render hit
  "Cannot redeclare formatPrice()" — a hard fatal, caught by testing this
  feature against a real database before shipping (see "Testing approach"
  below), not something that would show up in casual manual testing with
  only one invoice at a time. Fixed by wrapping the declaration in
  `if (! function_exists('formatPrice')) { ... }`. Any other blade view
  that declares a bare function and might ever be rendered more than once
  per request needs the same guard.
- **Second gotcha, found by the user after shipping the first version**:
  the bulk PDFs rendered with a stray blank page after every item. Cause:
  every `pdf.*` blade view is a full standalone HTML document (its own
  `<!DOCTYPE>`/`<html>`/`<head>`/`<body>`); naively concatenating several
  of those with a `page-break-after` div between them produces a string
  with multiple `<html>` roots, which HTML parsers handle unpredictably —
  in this case, dompdf treated each embedded `<html>` boundary as its own
  page. Fixed with two new private helpers, `splitHtmlDocument()` (parses
  one rendered document via `DOMDocument`, returns its `<style>` block(s)
  and the inner content of `<body>`) and `combineHtmlDocuments()` (builds
  exactly one real document: the first item's styles — they're identical
  across items, same template/settings — then every item's body content
  joined by a single page-break div between each, never after the last
  one). Verified by rendering through `Dompdf` directly in a test and
  calling `getCanvas()->get_page_count()` — confirmed N sales -> N pages,
  not 2N. Any future bulk-combine feature over these `pdf.*` views should
  use `combineHtmlDocuments()` rather than raw string concatenation.
- Bulk PDF/label routes sit in the same unguarded "Print & PDF" route
  block as `sale_pdf/{id}` etc. (see section 5 above for why); `bulkUpdate`
  is a normal authenticated route next to `sales_delete_by_selection`.

**Frontend** (`resources/src/pages/sales/Sales.vue`):
- New "Qty" column (`total_qty`, plain `dataIndex`, so it's included in
  export automatically like the others).
- `zoneOptions`/`courierOptions` are `ref([])`, kept in sync from
  `crud.payload` via a `watch` (not `computed` — `CreatableSelect`'s
  "+ add new" needs a mutable list it can push into via
  `v-model:options`, which a computed ref can't accept).
- `<DataTable>`'s existing `#toolbar` slot (already used for the built-in
  bulk-delete button) now also renders "Print Invoices", "Print Labels",
  and "Update Selected (N)" whenever `crud.selectedIds.value.length > 0`.
  "Update Selected" opens a modal (Shipping Status select, Zone/Courier
  `CreatableSelect`, Tracking Ref input) that POSTs only the fields the
  user actually touched to `sales_bulk_update`.

## Convention: hide low-priority list columns by default

`DataTable.vue` already supports `defaultHidden: true` on any column
definition — it starts unchecked in the "Columns" picker (gear icon) but
the user can turn it on any time from that dropdown, per session. When
adding a new Sales-list (or any DataTable-backed list) column that most
users won't need to see by default — extra reference fields, rarely-used
identifiers, anything that would otherwise widen the table and force more
horizontal scrolling — flag it `defaultHidden: true` rather than leaving
it always-visible. No new column-visibility mechanism needs to be built;
this one already exists and is the right tool for that.

## 7. Consignment ID, Return column, Shipping/Agent columns, Box qty toggle

**Why:** More Sales-list reporting needs (which zone/courier/agent handled
what, whether a sale was returned) plus making the "Box" feature (section
3) optional — it's specific to this business, not every Stocky install
needs it.

**Schema** — migration
`database/migrations/2026_09_09_000001_add_consignment_id_and_box_qty_toggle.php`:
- `sales.consignment_id` (string, nullable, indexed) — the courier's own
  shipment/consignment number, distinct from `tracking_ref` (which the
  user types in themselves; consignment_id is whatever the courier's
  system assigns).
- `settings.enable_box_qty` (boolean, **default true**) — so existing
  behavior (Box already shipped and in use) doesn't change for anyone
  until they explicitly flip it off in System Settings → Sales → Features.

**Backend:**
- `consignment_id` is threaded through every place `tracking_ref` already
  was: `Sale` model fillable, `SalesController@store/update/edit/show/
  bulkUpdate`, exactly mirroring that field's pattern.
- `SalesController@index` — added `consignment_id`, `shipping` (already
  existed on the model, just wasn't in the list payload), `sales_agent_name`
  (new `salesAgent` eager-load), and `return_amount` (sum of
  `SaleReturn.GrandTotal` for the sale — added as one extra query
  alongside the pre-existing has-a-return check, not a replacement of it,
  to avoid changing that existing query's behavior).
- Global search (`index`'s `search` param) extended to also match
  `tracking_ref`, `consignment_id` (plain indexed columns — cheap), and
  Zone/Courier/Sales-Agent **names** via `whereHas` (same pattern already
  used for Customer/Warehouse name search) — no meaningful performance
  concern at normal business scale; the FK columns behind those relations
  are already indexed.
- `Setting` model — `enable_box_qty` added to `$fillable`/`$casts`.
  `SettingsController`'s save handler and both of its "current settings"
  response blocks updated with the same has()-checked boolean pattern
  every other feature toggle already uses.
- `enable_box_qty` is returned by `SalesController@create`, `@edit`, and
  `@show` (wherever `$settings`/`$company` was already loaded) so the
  frontend knows whether to show the Box field/column without a separate
  API call.
- `resources/views/pdf/sale_pdf.blade.php` — the Box `<th>`/`<td>` are now
  each wrapped in `@if($setting['enable_box_qty'] ?? true)`. Verified by
  rendering the same sale's invoice with the setting on and off and
  checking for the `>Box<` header string in each — present when on,
  absent when off, single-invoice PDF otherwise byte-for-byte unaffected.

**Frontend:**
- `resources/src/pages/sales/Sales.vue` — "Return" column added right
  after Due (per request, sums any returns against that sale). "Shipping
  Charge", "Consignment ID", "Sales Agent" added at the very end of the
  column list, each flagged `defaultHidden: true` per the convention in
  section 6 — they show up in the "Columns" picker but don't widen the
  default view.
- `resources/src/pages/sales/SaleForm.vue` — Consignment ID text input
  next to Tracking Ref (same `sale.consignment_id` pattern as
  `tracking_ref` throughout: default value, submit payload, and all three
  bootstrap-load branches — create, edit, quotation-conversion). New
  `enableBoxQty` ref, loaded from whichever bootstrap response ran; the
  Box entry in `lineColumns` is now conditional
  (`...(enableBoxQty.value ? [...] : [])`) so the column simply doesn't
  exist when the feature is off, rather than being hidden CSS-side.
- `resources/src/pages/sales/SaleDetails.vue` — same `enableBoxQty` +
  conditional-column treatment for the items table; Consignment ID shown
  in the top info block next to Tracking Ref, same `v-if` pattern (hidden
  when empty).
- `resources/src/pages/settings/SystemSettings.vue` — new toggle in the
  Sales → Features tab, "Enable 'Box' Quantity on Sales", right above the
  existing Vehicle Fitment row (plain hardcoded label text, matching that
  row's style, not a `$t()` key — same reasoning as the Zone/Courier
  Report menu label in section 2: no translation-table entry exists yet).

**Not changed:** `resources/views/pdf/packing_list.blade.php` still always
shows its Box column regardless of the setting — packing lists are a
niche, business-specific document already, and an empty Box column there
when the feature is off is harmless. Revisit if that ever bothers someone.

## 8. Column reorder, fixed-column stripe bug, Sales Agent on Sale Detail, Consignment ID moved to bulk-only

**Why:** Requested Sales-list column order; a real visual bug in the
shared `DataTable` component found while using the new columns; showing
Sales Agent on the Sale Detail page; and moving Consignment ID entry from
the per-sale form to the list's bulk-update only (it's set after the
courier assigns it, not known at sale-creation time).

- **Column reorder** — `resources/src/pages/sales/Sales.vue`'s `columns`
  array is just reordered to: Action, Date, Reference, Tracking Ref, Zone,
  Customer, Warehouse, Status, Qty, Total, Paid, Due, Return, Payment
  Status, Shipping Status, Shipping Charge, Courier, Consignment ID, Sales
  Agent, Added by. This is array-order only — every `#bodyCell` template
  branches on `column.key`, and sorting/export/search all reference
  `dataIndex`/`key`, never array position — so reordering carries **no
  functional risk**, confirmed by rerunning the full test suite after.
  `Return` and `Shipping Charge` are `defaultHidden: true` (hidden by
  default, visible via the Columns picker); `Courier`, `Consignment ID`,
  `Sales Agent` are **not** hidden per this request (a change from how
  Consignment ID/Sales Agent shipped in section 7 — they defaulted hidden
  there, now don't).
- **Real bug found and fixed**: `resources/src/components/DataTable.vue`'s
  zebra-striping rule (`.dt-row-striped > td { background:
  rgba(128,128,128,0.045); }`) was overriding Ant Design Vue's own opaque
  `background: tableBg` on fixed/sticky columns (`.ant-table-cell-fix-left`
  /`-right`, e.g. the "Action" column here). A translucent background on a
  sticky column lets horizontally-scrolled content show through underneath
  it — the "ugly transparency" on the left edge when scrolling. Fixed by
  excluding those two Ant classes from the stripe selector, so fixed
  columns keep Ant's own theme-correct (light/dark-aware) opaque
  background and only non-fixed cells get the zebra tint. **This fix is in
  the shared `DataTable` component, so it also corrects the same latent
  bug on every other DataTable-backed list with a fixed column and
  striping enabled** — not Sales-list-specific.
- **Sales Agent on Sale Detail** — `SalesController@show` now eager-loads
  `salesAgent` and returns `sales_agent_name`; `SaleDetails.vue` shows it
  in the top info block with the same `v-if` (empty → not shown) pattern
  as Tracking Ref/Consignment ID/Zone/Courier.
- **Consignment ID removed from Create/Edit Sale form** — the input was
  taken out of `SaleForm.vue` entirely; `sale.consignment_id` stays in the
  component's internal state (loaded on Edit, included unchanged in the
  submit payload) purely so **saving an edited sale never wipes an
  existing Consignment ID** — `SalesController@update` sets the column to
  `null` when the field isn't present/filled in the request, so silently
  dropping it from the payload would have erased it on every edit. The bulk-
  update modal (section 6) gained a Consignment ID input as the new (only)
  way to set it, per the intended workflow: courier assigns it after the
  sale exists, so it's set from the list, not at creation time.

## 9. Stock Lookup — multi-warehouse "where is it" tool

**Why:** This is a multi-warehouse business (the same SKU can sit in
several warehouses at once). The only existing way to see a product's
stock across all warehouses was the Product Details page's own warehouse
table — useful, but requires navigating into that specific product first.
This adds a dedicated search-first tool: type a SKU/barcode/name, see the
per-warehouse breakdown immediately, from two places (POS and a new
Products-menu page).

**Important architecture note for whoever touches this next**: this app
runs **two different frontends side by side**. Pages under
`resources/src/pages/` (products, sales, reports, etc.) are the modern
Vue 3 `<script setup>` + Ant Design Vue SPA — that's everywhere else in
this document. **`resources/src/pages/pos/PosPage.vue` is NOT that** — it's
still the legacy Vue 2 Options API (`<script>`, not `<script setup>`) +
BootstrapVue (`<b-modal>`, `<b-button>`, `$bvModal.show(...)`) codebase,
not yet migrated. Do not add Ant Design Vue components into PosPage.vue —
they belong to a different Vue major version / component library and
won't work there. Match whichever file you're in.

**Backend** — `app/Http/Controllers/ProductsController.php` (two new
methods, both permission-gated the same as the rest of this controller —
`view` on `Product::class` — and both warehouse-scoped by the existing
`is_all_warehouses`/`UserWarehouse` pattern used throughout this
controller, verified with a restricted test user who correctly only saw
their one assigned warehouse and its stock, not the other warehouse's):
- `stockLookupSearch(Request $request)` — `GET stock_lookup/search?search=`.
  Matches `code`, `gtin` (the barcode column — the "Show Barcode (GTIN,
  UPC, EAN, ISBN)" setting seen in System Settings controls whether this
  is a visible product field, not whether it's searchable here), or `name`,
  LIKE `%search%`, ordered so an exact code/gtin match sorts first. Returns
  up to 15 lightweight candidates (id, name, code, gtin, price, image).
- `stockLookupDetail(Request $request, $id)` — `GET stock_lookup/{id}`.
  Same per-warehouse `SUM(product_warehouse.qte)` grouping already used by
  the Product Details page's own warehouse table, but returns it as a flat
  list with a `location` string (city + country) and a `total_qty`, scoped
  to only the warehouses the user is allowed to see.
- Routes added in the same `products` resource-route block in
  `routes/api.php` (so behind the same `auth:api` middleware group).

**Frontend — two entry points, two different codebases:**
- `resources/src/pages/products/StockLookup.vue` — new page (modern SPA),
  registered in `router/index.js` (`/products/stock-lookup`,
  `products_view` permission — same permission as the Products list, not a
  new one) and `config/menu.js` (both the legacy-path redirect and a new
  "Stock Lookup" entry in the Products submenu).
- `resources/src/pages/pos/PosPage.vue` — a "Stock Lookup" button was
  added next to the existing "Scan" button in the header, opening a new
  `<b-modal id="StockLookupModal">` (BootstrapVue, matching every other
  modal already in this file, e.g. `open_scan`). New `data()` fields
  (`stockLookupQuery`, `stockLookupResults`, `stockLookupDetail`, etc.) and
  three new `methods` (`openStockLookup`, `runStockLookupSearch`,
  `selectStockLookupProduct`) were added without touching any existing
  POS state or methods.
- Both frontends call the exact same two backend endpoints — no
  duplicated business logic, just two different UI shells (Ant Design
  table/card markup in the SPA page, plain styled `<div>`s in the POS
  modal since AntD isn't available there).
- Typing an exact SKU/barcode match (or getting exactly one result) skips
  straight to the detail view in both places — same "type the code, see
  the answer" flow as the reference design the request included
  screenshots of.
- Price is shown via each context's own existing currency helper
  (`money()` in the SPA via `useFormat`, `formatPriceWithCurrentCurrency()`
  in POS) — a bug from an early draft that referenced a non-existent
  `symbol` variable in the POS modal was caught and fixed before shipping.

## 10. Stock Lookup: variant support + polish; POS Recent Invoices

**Why:** Section 9's Stock Lookup only handled simple (non-variant)
products — a variant product (e.g. a shirt in Black/Red, each stocked at
several warehouses) needs a different shape of answer, since a single
per-warehouse qty would either hide the variant breakdown or need one row
per warehouse×variant pair. Also requested: a visual refresh of the
Products-menu page (not the POS modal), and a new "Recent Invoices" POS
tool to quickly reprint/relabel a just-completed sale.

**Backend — `ProductsController@stockLookupDetail` rewritten in two
branches:**
- Non-variant products: unchanged behavior from section 9.
- Variant products (`product.is_variant` + has variants): each warehouse
  row now carries its OWN `qty` (summed across that product's variants at
  that warehouse) **and** a `variants` array — `[{id, name, code, price,
  qty}]` — the per-variant stock at that specific warehouse. The frontend
  renders the warehouse row as the primary line and the variant array as
  an expand/collapse detail, per the request's own suggested design.
  Verified with 2 variants × 2 warehouses: each warehouse's qty is the
  correct sum of its variants, and the grand `total_qty` is the sum of
  everything (10+15+20+25=70 in the test).
- `stockLookupSearch` now also matches `product_variants.code`/`gtin` (via
  `orWhereHas`) — searching a variant's own SKU (which can differ from the
  parent product's code) returns the parent product, matching what the
  detail endpoint expects.
- New `SalesController@posRecentSales` (`GET pos/recent_sales?limit=`) —
  lightweight recent-sales list for the POS-only "Recent Invoices" tool
  below. Deliberately **not** the same as the full Sales-list `index()`
  (different permission needs, far fewer columns) — warehouse-scoped the
  same `is_all_warehouses`/`UserWarehouse` way as everything else, no
  extra policy check beyond that (matching the rest of the POS-realm
  endpoints, none of which do a separate `Sales_view` check).

**Frontend:**
- `resources/src/pages/products/StockLookup.vue` — visually reworked
  (gradient header banner, card-based layout, a total-stock badge) and
  gained variant handling via Ant Design's native `a-table` `expandable` /
  `#expandedRowRender` — each warehouse row expands to a nested table of
  that warehouse's variants. This is the SPA page only; **no changes to
  the POS modal's underlying data flow**, since it's already talking to
  the same (now variant-aware) backend endpoint.
- `resources/src/pages/pos/PosPage.vue` (legacy Vue2/BootstrapVue — see
  section 9's architecture note, still applies) — `StockLookupModal`
  gained a manual expand/collapse per warehouse row (a
  `stockLookupExpandedWarehouse` id in `data()`, toggled on click) since
  BootstrapVue has no built-in expandable-table feature to reach for here.
  A caught-before-shipping bug: a `<template v-for>` with the `:key`
  placed on a child element instead of the `<template>` tag itself failed
  the Vue 3 compiler outright (`vite build` caught it) — fixed by moving
  `:key="w.id"` onto the `<template>`.
- New **Recent Invoices** button next to Hold in the POS footer bar, and
  a new `RecentInvoicesModal` (BootstrapVue, matching the rest of the
  file): lists the last N sales (Date, Reference, Customer, Amount) with
  per-row **PDF** and **Label** download buttons reusing the existing
  `sale_pdf/{id}` / `sale_shipping_label/{id}` endpoints from sections 5/6
  — no new PDF-generation code, just wiring. An "Edit" action (present in
  the reference screenshot) was deliberately left out for now — editing a
  different sale mid-register-session is a bigger interaction question
  than a quick reprint, worth deciding deliberately rather than bolting on.

## 11. POS Sales view, Shipments enhanced with courier/tracking, Recent Invoices polish

**Why:** Three separate asks: a Sales-list view scoped to only POS-created
sales; the vendor's existing (pre-our-work) Shipments feature needed the
same courier/tracking fields we'd already added to Sales, plus a nicer
Edit modal; and the Recent Invoices modal (section 10) needed better
spacing, a row cap, and an Edit action with a safety check.

### POS Sales
- `SalesController@index` — added `is_pos` to the existing generic
  `$columns`/`$param` filter arrays (the same mechanism every other
  index() filter already uses), so `?is_pos=1` now filters correctly. No
  other index() code changed.
- **`resources/src/pages/sales/PosSales.vue` is a deliberate full copy of
  `Sales.vue`**, not a shared/parameterized component. Given how heavily
  customized and tested `Sales.vue` already is (sections 1, 6, 7, 8 all
  touch it), refactoring it to accept a "fixed filter" prop carried a real
  risk of subtly breaking the main Sales page for a comparatively small
  feature. The copy hardcodes `is_pos: 1` in `filterParams()`, changes the
  page title, and removes the "Add Sale" button (creating a sale from
  here would make a normal, non-POS sale that then wouldn't even show up
  in this filtered list — confusing). **If this page and Sales.vue drift
  out of sync over time, that's the known cost of this approach** — pull
  fresh from Sales.vue and reapply the same 3 edits if that happens.
- New route `/sales/pos` (`Sales_view` permission — not a new permission)
  and a "POS Sales" entry in the Sales submenu, using `raw: true` in
  `menu.js` so it displays as plain text instead of going through the
  (currently un-seeded) translation table.

### Shipments (pre-existing vendor feature — see `app/Models/Shipment.php`
/ `ShipmentController.php`, present in the vendor baseline commit, not
something built in this conversation)
- New migration: `shipments.phone_number` (nullable) — a delivery contact
  number, which can differ from the customer's account phone.
- **Courier / Consignment ID / Tracking Ref are NOT duplicated onto the
  `shipments` table** — they already live on `sales` (sections 1/7).
  `ShipmentController@index` now eager-loads `sale.courier` and exposes
  `courier_name`/`consignment_id`/`tracking_ref` read from the Sale;
  `@show`/`@update` read/write `courier_id`/`tracking_ref` on the
  **Sale**, not the Shipment, keeping one source of truth. `@show` also
  now returns `invoice_address` (the sale's client's address, for the
  Edit modal's "Same as invoice address" checkbox) and the same
  `zones`/`couriers` lookup lists used everywhere else.
- **A real bug avoided, not just fixed**: the original `@update` did
  `Shipment::whereId($id)->update($request->all())` — mass-updating with
  *every* request field. Sending the new `courier_id`/`tracking_ref`
  fields (which don't exist as `shipments` columns) through that
  unfiltered `update()` would have thrown a SQL error. Changed to
  `$request->only([...])` with the shipment's actual fillable fields;
  `courier_id`/`tracking_ref` are routed to the `Sale::update()` call
  instead, inside the same transaction.
- `resources/src/pages/sales/Shipments.vue` — 3 new columns (Courier,
  Consignment ID, Tracking Ref) placed right after Reference. Edit modal
  reworked: Delivered To + Phone Number side by side, Courier Partner
  (`CreatableSelect`, same "+ add new" component as everywhere else) +
  Tracking ID side by side, and a "Same as invoice address" checkbox next
  to the Address label that copies the sale's client address into the
  field and disables manual editing while checked. Opening the modal now
  makes one extra `GET shipments/{sale_id}` call to fetch the
  courier/tracking/phone/invoice-address data the list payload doesn't
  carry (kept the list query lean).

### Recent Invoices polish (section 10)
- Capped to 10 rows (`limit: 10` instead of 20) with a "Showing the N most
  recent invoices" footer note.
- Grid columns given fixed/generous widths (`130px 100px 1.3fr 130px
  1.9fr` instead of loose `fr` fractions) with `white-space: nowrap` on
  Date/Reference/Amount — the original layout wrapped Date and the
  currency symbol onto their own lines at typical modal width.
- New **Edit** button per row (`editRecentInvoice`): if the current POS
  cart (`this.details`) is empty, navigates to `/sales/{id}/edit`
  (`this.$router.push`, confirmed this file already shares the app's
  router — see `goToMobileTab`'s existing `this.$router.push('/')`). If
  the cart has items, shows a `this.$swal(...)` warning (the same
  SweetAlert2 pattern already used elsewhere in this file, e.g.
  `Remove_Draft_Sale`) telling the user to hold or complete the current
  sale first, and does not navigate — editing a different invoice with an
  unsaved cart open would silently abandon that cart's state.

### Testing note for whoever touches ShipmentController/SalesController next
`ShipmentController@update` (and `store`) call the **global** `request()`
helper for validation instead of the injected `$request` parameter — a
pre-existing vendor inconsistency, not something introduced here. This is
harmless in real HTTP requests (Laravel binds the container's `request()`
to the actual current request either way) but means a tinker/test harness
that manually constructs a `Request` object and rebinds it via
`app()->instance('request', $req)` to make that global-helper validation
work will, as a side effect, break `$request->user('api')` resolution for
the `authorizeForUser` call earlier in the same method (the 'api' guard
re-resolves from the newly-bound request's — empty — auth headers instead
of the manually-set test user). Encountered exactly this while testing
`ShipmentController@update` here: the fix for testing purposes was to
verify the authorization check and the transaction's data-writing logic
as two separate steps rather than one full `app()->instance()`-bound
call — both were confirmed correct independently. This is a testing
technique, not a production bug.

## 12. Fix: Recent Invoices modal cropped on the right

**Why:** The Recent Invoices modal (section 10/11) used a hand-rolled CSS
Grid (`grid-template-columns: 130px 100px 1.3fr 130px 1.9fr`) with three
text buttons ("PDF", "Label", "Edit") in the last column. The fixed-pixel
tracks plus three text buttons needed more horizontal space than the
modal reliably had, and there was no `overflow-x` handling on the
wrapper — so at most window widths the Actions column (and part of
Amount) was silently clipped at the modal's edge rather than wrapping or
scrolling.

**Fix** — replaced the CSS Grid with a real `<table>`
(`resources/src/pages/pos/PosPage.vue`): table columns size to their own
content instead of fighting fixed tracks, and the table sits inside a
`.ri-table-wrap` with `overflow-x: auto` as a fallback for any
still-too-narrow window rather than clipping. The three actions became
compact 30×30px icon buttons (`.ri-icon-btn` / `.ri-edit-btn`, inline SVGs,
native `title` tooltips) instead of text buttons, needing roughly a third
of the horizontal space the old ones did. All the styling moved out of
per-row inline `style` attributes into named classes in the file's
existing `<style scoped lang="scss">` block — the earlier version
repeated an identical multi-line inline style string in the header row
and (via `v-for`) implicitly for every data row, which was the "garbage"
being asked to clean up as much as the visual bug. Hover states
(`:hover:not(:disabled)`) are real CSS now rather than relying on
`b-button`'s default styling interacting correctly with inline overrides.

No backend changes; no other page touched.

## 13. Fix: Recent Invoices modal still needed horizontal scroll

**Why:** Section 12's table fix stopped content from being clipped/
inaccessible, but the `size="xl"` BootstrapVue modal still wasn't wide
enough for the table's 5 no-wrap columns to fit without triggering the
`.ri-table-wrap` horizontal scrollbar — an improvement over invisible
clipped content, but the user reasonably wanted it to just fit.

**Fix**: added `modal-class="ri-modal"` to the `<b-modal>` and a rule in
this file's existing **unscoped** `<style>` block (not the scoped one) —
`.ri-modal .modal-dialog { max-width: 900px; }`, with a `max-width:
calc(100vw - 32px)` fallback under 960px viewports. Has to be the
unscoped block: BootstrapVue modals render their `.modal-dialog` outside
this component's own DOM subtree (already documented at the top of that
block, re: the mobile tab dropdown), so scoped rules can never reach it.

## 14. Products list: business-insight columns (Last Purchase, Sold 30d, Last Sold, Warehouse Count)

**Why:** A planning discussion (not just a build request) about whether
`products.cost` — a static, manually-set field, never auto-updated on
receiving a purchase (confirmed by reading the codebase, not assumed) — is
the right thing to show as "Cost" on the product list, and what would
make the list a genuinely useful business-insight view. Landed on: leave
the accurate-inventory-valuation question (a proper FIFO-remaining-stock
cost calculation, which is a real, larger piece of work — see below) for
a future round, and ship four independent, purely additive metrics now
that don't depend on resolving that question at all.

**Backend** (`ProductsController@index`) — one shared block computing 4
fields per product, added once after the existing per-type branches
(single/combo/variant/service) so it applies uniformly regardless of
product type:
- `last_purchase_date` / `last_purchase_cost` — the single most recent
  **received** purchase line for that product (`purchase_details` joined
  to `purchases`, ordered by date desc). For variant products this looks
  across all of that product's variants (`purchase_details.product_id`
  is always set even for a variant line) — a deliberate choice: "when did
  we last restock this product, and at what price" is still a useful
  answer at the product level even when the specific variant differs.
- `total_sold_30d` — sum of `sale_details.quantity` for this product over
  the trailing 30 days, `sales.statut = 'completed'` only (matching the
  "completed only" convention already used everywhere else profit/COGS is
  computed in this app, e.g. `CalculatesCogsAndAverageCost`). This is a
  rolling window from *now*, not a fixed calendar period.
- `last_sold_date` — most recent completed sale date for the product.
- `warehouse_count` — how many of the warehouses that are relevant right
  now (the selected warehouse filter if one's applied, else the user's
  full allowed set for `is_all_warehouses = 0` users) currently hold
  `qty > 0` of this product. Verified with a warehouse-restricted test
  user: they see `1`, not the true `2`, when only one of the two
  warehouses is assigned to them — same scoping as every other
  warehouse-aware number on this page (Quantity, the warehouse filter
  itself).
- Deliberately followed this file's own existing per-row-query pattern
  (Quantity is *already* computed with a query per row in this same loop,
  not a bulk aggregate) rather than introducing a different, more
  "optimal" bulk-query style just for these 4 fields — consistency with
  what's already here, and the loop only ever runs over one page of
  results (≤100 typically), not the whole catalog.
- All 4 verified against a real database: correct product picked as
  "last" out of two purchases at different dates/costs; the 30-day sum
  correctly excludes a 60-day-old sale while `last_sold_date` still finds
  it if it *were* the most recent (independent windows — 30d sum vs.
  all-time last-sold); a product with zero purchase/sale/stock history
  returns clean `null`/`0` rather than erroring.

**Frontend** (`resources/src/pages/products/Products.vue`) — 4 new
columns after Quantity, all `defaultHidden: true` (this file already
uses that convention for Wholesale/Min Price). "Last Purchase" is one
column showing cost above date (mirrors this file's existing
`stacked-cell` pattern used for variant cost/price lines) rather than two
separate columns. Also added to the separate, hand-maintained
`exportColumns` list this file already keeps for Excel/PDF export (it
deliberately doesn't derive from `columns` — see the comment already
above that array) — the new fields flow into export automatically.

**Explicitly deferred, not forgotten**: "Inventory Value" and "Potential
Profit" both need a much more accurate cost figure than
`products.cost`, or even this app's existing "lifetime average purchase
cost" (`averageCostBulk` in the FIFO/avg-cost trait — averages over
*every* unit ever purchased, not what's actually still on the shelf, so
it silently overstates or understates true remaining value once
purchases at different prices happen and some are sold off). The
right calculation is a proper FIFO-remaining-stock valuation (burn all
historical sales against purchase layers in date order, value whatever's
left at each layer's own cost — return-aware, i.e. purchase returns
remove from a layer and sale returns effectively un-consume one), which
is real, separate work — not implemented yet. Do not backfill Inventory
Value/Potential Profit with `products.cost` or the lifetime-average as a
stand-in; that was explicitly discussed and rejected as misleading.

## 15. Products list: Sales Trend, Revenue Contribution, Return Rate; Last Purchase/Sold(30d) now always visible

**Why:** Continuation of section 14's business-insight discussion. "Last
Purchase" and "Sold (30d)" proved valuable enough to no longer be
`defaultHidden`; three more metrics added, all derivable from data
already on hand (no FIFO-cost dependency, consistent with section 14's
explicit deferral of that work).

**Backend** (`ProductsController@index`, same shared per-product block as
section 14):
- `total_sold_prev30d` — sum of `sale_details.quantity` for the *prior*
  30-day window (61-31 days ago, i.e. immediately before the existing
  `total_sold_30d` window), completed sales only. Exists purely so the
  frontend can compute a trend; not shown as its own column.
- `return_rate` — `SaleReturnDetails` (joined to `sale_returns` for the
  soft-delete check — `SaleReturnDetails`'s own relation to its parent is
  named `SaleReturn`, not `return`; used an explicit join instead of
  relying on that relation name) summed lifetime, divided by lifetime
  `sale_details.quantity` (completed sales) for the same product, ×100,
  rounded to 1 decimal. **Deliberately lifetime, not a 30-day window** —
  most products don't sell/return enough in any given 30 days for a
  percentage over that window to mean anything; `null` (not `0`) when
  lifetime sold is zero, so the frontend can show "—" rather than a
  misleading "0%".
- Verified against a clean, isolated test product (6 sold in the current
  30-day window, 3 in the prior one, 3 of 9 lifetime-sold returned):
  `total_sold_30d=6`, `total_sold_prev30d=3`, `return_rate=33.3` — all
  exact.

**Frontend** (`resources/src/pages/products/Products.vue`):
- "Last Purchase" and "Sold (30d)" columns lost `defaultHidden: true`.
- New "Trend" column (`defaultHidden: true`) — a `salesTrend()` helper
  compares `total_sold_30d` vs `total_sold_prev30d` and renders an up/down
  arrow with a percentage; shows a dash (not a misleading 0%/∞%) when the
  prior window was zero or the two are equal, since a percentage change
  against a zero baseline isn't a real number.
- New "Revenue (30d)" column (`defaultHidden: true`) — `total_sold_30d ×
  price`, computed client-side (both inputs already present, no reason to
  round-trip a third backend field for a one-line multiplication). Uses
  **today's** price, not the price at each historical sale's own time —
  an estimate for "which products matter most right now" ranking, not a
  reconstruction of actual historical revenue. Variant products carry a
  newline-joined multi-price string in `price` (one line per variant) with
  no single value to multiply against `total_sold_30d`'s single combined
  number — `revenue30d()` detects the `\n` and returns `null` (rendered as
  "—") rather than silently computing a meaningless number against `NaN`
  or one arbitrary variant's price.
- New "Return Rate" column (`defaultHidden: true`) — the backend
  percentage, highlighted in red/bold at ≥10% (a made-up-but-reasonable
  "worth a look" threshold — revisit if the business has its own
  standard).
- All 3 new derived metrics (`salesTrend`, `revenue30d`, and the return
  rate formatting) were also added to this file's separate,
  hand-maintained `exportColumns` array (see section 14's note on why
  that list isn't derived from `columns`) so Excel/PDF export includes
  them too.

## Known follow-ups (not done, intentionally)

- "Zone / Courier Report" menu label (`Zone_Courier_Report`) has no
  translation row yet — cosmetic only.
- Zone/Courier Report has no per-row drill-down (click a zone to see its
  sales) — out of scope when built; ask before adding if it comes up.

## Testing approach used for every change above

No local Laravel install existed for iterating against, so each backend
change was verified against a real, disposable MySQL database before
delivery: run `php artisan migrate` from a clean schema, then exercise the
actual controller methods (not mocks) via `php artisan tinker` — create
real `Sale`/`SaleDetail`/`Purchase` rows, call `SalesController@index`,
`@store`, `@update`, `@edit`, `@show`, `@Sale_PDF`, etc. directly, and
assert the JSON/HTML response contains the expected fields. The FIFO vs.
average-cost review in this same conversation was verified the same way:
real purchase/sale rows at known costs, run through
`CalculatesCogsAndAverageCost::calcCogsAndAvgCostFast`, and check the
result against a hand-computed expected COGS. Any future change to
`SalesController`, `PurchasesController`, or the cost-calculation trait
should be checked the same way before shipping — this app's calculations
being wrong is a business-critical failure, not a cosmetic bug.

## 16. Stocky 5.8 Build A stabilization — six low-risk fixes after post-merge audit

**Status:** ACTIVE — applied after the initial 5.8 merge. This section is an
append-only remediation record; sections 1–15 above are intentionally kept as
the historical implementation context. Do not rewrite those older sections to
make the history look cleaner — future vendor upgrades need to know what was
built first and what was corrected later.

**Scope rule for this build:** deliberately small. No stock-movement rules,
Product Insight formulas, warehouse analytics semantics, POS workflow, FIFO/
COGS, database schema, permission architecture, or Sales/POS page redesign was
changed here. The purpose is to remove six confirmed, reachable defects while
keeping the existing work pattern stable.

### 16.1 Bulk A4 invoice now matches Stocky 5.8 document-currency behavior

**Classification:** STOCKY 5.8 MERGE REGRESSION.

The custom `SalesController::renderSaleInvoiceHtml()` was created before the
vendor introduced the 5.8 document-currency snapshot. After the vendor merge,
`Sale_PDF()` converted tax/fixed discounts/shipping/totals/line money using
`helpers::Get_Document_Currency()`, while the custom bulk renderer still printed
base-currency values with the base currency code. That made the same foreign-
currency sale produce different single and bulk invoices.

**Fix:** the bulk-only renderer now resolves the sale's stored document currency
and applies the same 5.8 arithmetic as `Sale_PDF()`:
- percent discounts remain percentages;
- fixed discounts and monetary fields are multiplied by the stored document
  rate;
- line `price`, `total`, and fixed line discounts are converted before the
  existing tax/net calculations;
- previous dues are converted using the same rate;
- the PDF currency code comes from the document currency, not the current base
  currency.

A small pure helper, `app/Support/SaleDocumentMath.php`, was added for custom
document arithmetic. It intentionally does **not** replace the vendor's proven
single-invoice path in this stabilization build; this keeps the blast radius
small while giving custom PDF/label code one testable arithmetic contract.

**Files:**
- `app/Http/Controllers/SalesController.php`
- `app/Support/SaleDocumentMath.php`

**Vendor-update warning:** whenever Stocky changes `Sale_PDF()` currency/
discount semantics, compare `renderSaleInvoiceHtml()` again and run the Build A
regression tests before release.

### 16.2 Shipping Label COD now prints the outstanding balance

**Classification:** CUSTOM BUG.

The original custom label printed `GrandTotal` whenever `payment_status` was not
`paid`, so a partially paid order could ask the courier to collect the full
invoice again.

**Fix:** both single and bulk labels calculate:

`max(GrandTotal - paid_amount, 0)`

and the Blade template prints that `cod_amount` only when it is greater than
zero. This build intentionally preserves the label's existing base-currency
presentation; document-currency redesign of the shipping label is outside Build
A and must not be mixed into this small stabilization patch without a separate
business decision/test round.

**Files:**
- `app/Http/Controllers/SalesController.php`
- `resources/views/pdf/shipping_label.blade.php`
- `app/Support/SaleDocumentMath.php`

### 16.3 Product computed insight sorting no longer sends fake columns to SQL

**Classification:** CUSTOM BUG / REACHABLE UI CRASH.

`Sold (30d)` (`total_sold_30d`) and `Last Sold` (`last_sold_date`) are computed
response fields, not columns on `products`. Declaring `sorter: true` made
`useCrudTable` send those keys as `SortField`, eventually causing SQL "unknown
column" errors.

**Fix:** the existing Vue columns remain unchanged, so no frontend rebuild is
required for this replacement package. `ProductsController@index` now validates
`SortField` against an explicit whitelist and handles `total_sold_30d` and
`last_sold_date` with correlated aggregate subqueries that mirror the existing
displayed formulas. Real-column sorting and the special warehouse-aware
`quantity` sort remain unchanged, while direct/malformed sort keys safely fall
back to `id`.

**File:** `app/Http/Controllers/ProductsController.php`

**Important:** this does **not** optimize Product Insights N+1 queries and does
not change any Product Insight calculation. Performance/warehouse/unit
corrections remain a later, isolated batch.

### 16.4 Shipment computed/joined sorting can no longer crash SQL

**Classification:** VENDOR/CUSTOM INTEGRATION DEFECT, reachable through the
customized Shipments table.

The Vue table exposed server sorting for aliases/relations such as
`shipment_ref`, `sale_ref`, `customer_name`, and `warehouse_name`; those are not
real columns on `shipments` and could be passed to `orderBy()`.

**Fix:** the existing compiled/frontend behavior is preserved. The backend now
whitelists accepted sort keys, maps `shipment_ref` to the real `Ref` column, and
uses correlated subqueries for Sale Ref, Customer, and Warehouse. This keeps
true server-side sorting across pagination without sending aliases directly to
SQL. Unknown keys safely fall back to `id`, and sort direction is normalized to
`asc`/`desc`.

**File:** `app/Http/Controllers/ShipmentController.php`

### 16.5 Shipment creation now persists `phone_number`

**Classification:** CUSTOM IMPLEMENTATION OMISSION.

The edit/update path already stored `phone_number`, but the create path omitted
it. `ShipmentController::store()` now writes the submitted phone number using a
nullable fallback. No schema/API redesign was made.

**File:** `app/Http/Controllers/ShipmentController.php`

### 16.6 Sale update no longer clears custom metadata merely because a caller omitted it

**Classification:** CUSTOM BACKWARD-COMPATIBILITY BUG.

On update, `filled()` previously treated both "key absent" and "key present but
empty" as the same case and wrote `NULL`, so an older/partial API client could
silently erase `tracking_ref`, `consignment_id`, `zone_id`, or `courier_id`.

**Fix semantics (update only):**
- key absent → preserve the stored value;
- key present with empty/null → explicitly clear;
- key present with a value → update.

The **create** path is intentionally unchanged: a new Sale has no previous
metadata to preserve, so an omitted optional field correctly starts as `NULL`.

**File:** `app/Http/Controllers/SalesController.php`

### 16.7 Explicitly NOT changed in Build A

The post-merge audit found additional items, but they are intentionally deferred
so this stabilization build remains low-risk. In particular, Build A does not
change:
- Product Insights N+1/query aggregation;
- Product Insights warehouse scoping or soft-deleted-parent handling;
- Unit/Multi-Pack normalization for analytics/Sales Qty/Packing totals;
- POS Recent Invoices visibility/currency semantics;
- bulk-sale/Shipment authorization hardening;
- POS Sales Seller/Currency parity;
- credit-limit/minimum-price/tax-discount server-side guardrails;
- FIFO/COGS/revenue redesign;
- Sales/PosSales architectural consolidation.

Treat those as separate review/fix batches with their own behavior tests. Do not
silently bundle them into Build A when replaying this patch on a future vendor
version.

## 17. Build A automated regression contract

Two complementary test layers were added:

1. `tests/Unit/SaleDocumentMathTest.php` — normal PHPUnit unit tests for pure
   document arithmetic (currency conversion, percent-vs-fixed discount, and
   non-negative outstanding COD).
2. `tests/Unit/BuildAIntegrationContractTest.php` — PHPUnit upgrade-contract
   checks that ensure a future merge does not silently reconnect computed table
   keys to SQL sorting, drop Shipment phone persistence, drop document-currency
   integration, or revert preserve-on-omit semantics.
3. `tests/Regression/build_a_stability.php` — a **no-dependency** regression
   gate covering the same six stabilization fixes. It can run immediately after
   extracting the source, even before `composer install`:

```bash
php tests/Regression/build_a_stability.php
```

The no-dependency gate is not a replacement for Laravel/MySQL feature tests; it
is a fast upgrade safety net for this exact small patch. Full database-backed
coverage should be expanded in the later authorization/Product-optimization
batches where database behavior actually changes.

### Build A release checks

At minimum, before deployment/future replay run:

```bash
php tests/Regression/build_a_stability.php
find app tests routes database -name '*.php' -print0 | xargs -0 -n1 php -l
```

With normal Composer dependencies and required PHP extensions installed, also
run the regular PHPUnit suite. Frontend source must compile successfully with
the project's existing Vite build before production deployment.

### Build A delivery convention

The production replacement ZIP intentionally excludes the older
`stocky-vendor58-merged.bundle`: that bundle predates this remediation and would
be misleading if shipped beside newer source. An updated Git bundle is delivered
as a separate developer artifact so production code and repository-history
artifacts remain cleanly separated. No live `.env` is included or overwritten.

## 18. Build A.1 — Sales Currency column fallback + default-hidden UX

**Status:** ACTIVE. This is a deliberately tiny follow-up to Build A after
verifying the 5.8 Sales list on a real UI. No sale totals, payment math, stock,
warehouse rules, database schema, or document calculations were changed.

### 18.1 Base-currency Sales no longer show a blank Currency cell

**Classification:** STOCKY 5.8 DISPLAY/INTEGRATION GAP.

Stocky 5.8 stores legacy/base-currency documents with `currency_id = NULL` and
uses `helpers::Get_Document_Currency()` as the canonical fallback to the
configured base currency. The Sales list previously read only the optional
`currency` relation, so those perfectly valid base-currency rows rendered a
blank Currency badge while foreign-currency rows rendered their code.

**Fix:** `SalesController::index()` now resolves each row through
`helpers::Get_Document_Currency($Sale)` and returns that resolved code. This
preserves Stocky's intended semantics: a base/legacy sale displays the configured
base code (for example BDT), while a stored USD/EUR document displays its own
code. The Sales list monetary columns are still base-currency amounts; this
change is display metadata only.

### 18.2 Currency remains available but is hidden by default

The Currency column is useful for multi-currency auditing but is not needed in
everyday single/base-currency operation. `Sales.vue` therefore marks the column
`defaultHidden: true`. It remains available from the existing DataTable column
picker for the current session; no feature/menu is removed.

The deployment build also bumps the PWA cache namespace (`public/sw.js`) so a
browser that previously cached the old Sales chunk does not keep the always-
visible Currency column after the replacement package is deployed. This is a
frontend cache invalidation change only; it does not alter POS/offline business
logic.

**Files:**
- `app/Http/Controllers/SalesController.php`
- `resources/src/pages/sales/Sales.vue`
- `tests/Regression/build_a1_currency_column.php`
- `tests/Unit/BuildA1CurrencyColumnContractTest.php`

**Vendor-update warning:** if Stocky changes the document-currency snapshot or
Sales-list amount semantics in a later release, keep using the vendor's
canonical `Get_Document_Currency()` behavior rather than reimplementing fallback
logic in the list.

## 19. Build B — narrow authorization hardening (Bulk Sales + Shipments only)

**Status:** ACTIVE. Build B is intentionally limited to two independently
confirmed IDOR/data-isolation problems. It does not change Sale totals, Product
Insights, stock movement, Unit/Multi-Pack logic, POS Recent Invoices, currency
math, database schema, or normal authorized UI workflows.

### 19.1 Bulk Sale metadata update now re-applies Sale visibility before UPDATE

**Classification:** CUSTOM SECURITY BUG (horizontal authorization / IDOR).

The custom Sales-list bulk action accepted browser-supplied `selectedIds` and
previously executed a direct `Sale::whereIn(...)->update(...)` after only the
class-level `Sales_edit` permission check. A crafted request could therefore
include a Sale id outside the user's assigned warehouse or outside their
`record_view` ownership boundary.

**Fix:** `SalesController::bulkUpdate()` now builds the update query from the
same two row-level rules used by the Sales list:
- when `record_view` is disabled, only Sales whose `user_id` is the current user;
- when `is_all_warehouses` is disabled, only Sales in the user's
  `user_warehouse` assignments.

The existing bulk payload semantics are unchanged. Authorized selections update
exactly as before; out-of-scope ids are ignored by the SQL update and cannot be
modified. No additional Sale fields were added to the bulk action.

**File:** `app/Http/Controllers/SalesController.php`

### 19.2 Shipment endpoints inherit Sale ownership/warehouse visibility

**Classification:** VENDOR/CUSTOM INTEGRATION SECURITY BUG.

Shipment permission was class-level, while several routes trusted route/body
ids directly. In particular, `show()` had no Shipment authorization call, list
and status counters were global, and `update()` could accept Shipment A's id
with Sale B's `sale_id`, update the Shipment relation, then modify Sale B's
shipping metadata.

**Fix:** `ShipmentController` now has two small internal query scopes:
- `visibleSalesQuery($user)` — same `record_view` ownership + warehouse rules as
  the Sales list;
- `visibleShipmentsQuery($user)` — Shipments whose parent Sale is inside that
  Sale scope, implemented as a SQL subquery (no large id array in PHP).

These scopes are used by Shipment list, status counters, show, store, update,
and delete. `show()` now checks Shipment view permission. Store validates the
requested Sale through the visible Sale scope and refuses to reuse an existing
Shipment Ref for a different Sale. Update no longer writes `sale_id` at all and
explicitly rejects a payload whose Sale id differs from the stored Shipment
Sale. Related Shipment/Sale rows are locked during writes to avoid a concurrent
reassignment race.

**Normal workflow compatibility:** Sales/PosSales already submit the Shipment's
own Sale id, and the Shipments edit modal submits its stored Sale id, so normal
authorized requests keep the same API shape and behavior. Only out-of-scope or
cross-Sale crafted requests are rejected/filtered.

**File:** `app/Http/Controllers/ShipmentController.php`

### 19.3 Build B explicitly does NOT include other audit findings

Still deferred to isolated later batches:
- POS Recent Invoices `record_view` / `is_pos` behavior;
- Product Insights N+1 optimization, warehouse scoping, soft-deleted parents,
  date-window semantics, or Unit/Multi-Pack normalization;
- POS Sales 5.8 Seller/Currency parity;
- credit-limit/minimum-price/tax-discount backend guardrails;
- Zone/Courier manage permission redesign;
- large controller/service refactors.

### 19.4 Build B regression contract

Fast no-dependency gate:

```bash
php tests/Regression/build_b_authorization.php
```

Regular PHPUnit contract coverage is also added in
`tests/Unit/BuildBAuthorizationContractTest.php`. In a fully bootstrapped test
environment, database-backed authorization feature tests should additionally
exercise User A / Warehouse A against User B / Warehouse B; the source-contract
gate exists so a future vendor merge cannot silently remove the critical scope
wiring before those heavier tests run.

## 20. Safe-overlay delivery correction — preserve the exact merged frontend

**Status:** ACTIVE DELIVERY RULE. This section supersedes the *delivery method*
described in Section 18.2 for the current stabilization rollout; it does not
erase the historical A.1 attempt.

The first cumulative BuildAB package rebuilt the complete `public/js` Vite asset
tree in order to make the one-line `defaultHidden` Currency-column preference
take effect. That was too broad for this project's current "preserve the merged
build exactly and apply narrow fixes" requirement. It could replace a known-good
compiled frontend as a side effect of a tiny UI preference change.

**Correction:** the safe stabilization delivery is now an **overlay patch**. It
contains only the approved backend/template/test/documentation files and does
**not** ship `public/js`, `public/sw.js`, `resources/src/pages/sales/Sales.vue`,
or any unrelated frontend/menu/module file. Copy it over the existing merged
application; do not delete the existing application tree first.

The server-side A.1 currency fallback remains active, so base/legacy Sales rows
resolve the configured document currency code through
`helpers::Get_Document_Currency()`. The Currency column's "hidden by default"
preference is **deferred** until it can be rebuilt and verified against the
user's exact active frontend tree without replacing unrelated compiled assets.

### 20.1 Full-replacement warning

The user's active installation can contain runtime/vendor/module directories that
were not present in the uploaded `stocky-vendor58-merged.zip` snapshot. Therefore
future deliveries must not be described as safe "delete everything and extract"
replacements unless the exact active application tree has first been captured.
Use overlay patches for narrow remediation batches, or build a full replacement
from a fresh archive of the actual active application.

### 20.2 Regression contract

Run after applying the overlay:

```bash
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
```

The safe overlay deliberately leaves the existing compiled frontend byte-for-byte
untouched outside the explicitly supplied files (none are supplied under
`public/js`).

## 21. Build C — Product Insights set-based query optimization

**Status:** ACTIVE. **Base required:** the confirmed working Build A + safe A.1/B
overlay described in Sections 16–20. Build C is deliberately a performance-only
customization patch. It does not change Product Insight business formulas,
warehouse visibility semantics, Sale/Purchase soft-delete semantics, Unit/
Multi-Pack math, UI columns, database schema, Sales/POS/Shipment behavior, or
compiled frontend assets.

### 21.1 Why this patch exists

The custom Product-list insight fields were originally calculated inside the
`foreach ($products as $product)` loop. Each Product row independently queried:

- latest received Purchase;
- Sold (30d);
- previous 30-day sold quantity;
- Last Sold date;
- lifetime sold quantity;
- lifetime returned quantity;
- Warehouse Count.

That produced roughly **7 additional insight queries per Product row**. A normal
100-row result could therefore add ~700 round trips, and the existing export path
(`limit = -1`) amplified the problem for large catalogs.

### 21.2 Set-based implementation

A dedicated upgrade-friendly custom service now owns only these insight reads:

`app/Services/Custom/ProductInsightService.php`

`ProductsController::index()` sends the current result Product ids to the service
once, before the Product rendering loop. The service executes four set-based
metric queries for that result population:

1. one grouped completed-Sales query for Sold (30d), previous 30 days, Last Sold,
   and lifetime sold;
2. one grouped Sale Return query for lifetime returned;
3. one grouped `product_warehouse` query for Warehouse Count;
4. one latest-received-Purchase query using nested grouped subqueries.

The latest-Purchase query intentionally avoids a window-function dependency. It
first selects `MAX(purchase.date)` per Product and then `MAX(purchase_detail.id)`
on that date, preserving the previous ordering contract:

`purchase date DESC, purchase_detail id DESC`.

This keeps the patch compatible with the existing Stocky/MySQL/MariaDB style
without introducing a new database-version requirement for this optimization.

### 21.3 Business semantics are intentionally unchanged

Build C is **not** the analytics-correctness batch. To make performance changes
safe and independently reviewable, it preserves the exact pre-Build-C rules:

- Sales still require `sales.statut = completed`;
- Sold (30d) still starts at `now()->subDays(30)->format('Y-m-d')`;
- previous period still uses day 60 through day 31 ago;
- Sale/Purchase insight metrics are still global rather than restricted to the
  current user's warehouses (the existing Warehouse Count remains scoped);
- completed Sales soft-delete semantics are not changed here;
- received Purchase soft-delete semantics are not changed here;
- returns still exclude only soft-deleted parent `sale_returns` as before;
- raw transaction quantities remain raw (no Unit/Multi-Pack normalization);
- `return_rate` formula and price/date formatting remain unchanged.

Those known correctness items remain explicitly deferred to a separate later
batch so a changed number can never be confused with a query optimization
regression.

### 21.4 Regression and equivalence checks

Fast source-contract gate:

```bash
php tests/Regression/build_c_product_insights.php
```

Regular PHPUnit contract:

`tests/Unit/BuildCProductInsightContractTest.php`

The Build C gate ensures the Product render loop no longer performs per-row
insight queries, the service keeps four executed set-based metric statements,
and the legacy status/date/tie-break contracts remain present.

During Build C preparation, a representative database fixture was also evaluated
with both the old per-Product formulas and the grouped formulas, including:

- Products with and without Sales;
- current and previous date windows;
- pending vs completed Sales;
- deleted vs active Sale Returns;
- multiple received Purchases on the same latest date (detail-id tie-break);
- selected, restricted, and all-warehouse Warehouse Count scopes.

The optimized result matched the legacy result for every tested metric. The
structural custom-insight query pattern changes from `7 × Product count` to four
executed metric queries for the result set. Existing non-insight Product queries
(e.g. vendor Product type/variant/quantity behavior) are intentionally outside
this patch and are not claimed as optimized here.

### 21.5 Files and upgrade contract

**Runtime files:**

- `app/Http/Controllers/ProductsController.php`
- `app/Services/Custom/ProductInsightService.php` (new)

**Tests/documentation only:**

- `tests/Regression/build_c_product_insights.php`
- `tests/Unit/BuildCProductInsightContractTest.php`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- `BUILD_C_SAFE_OVERLAY_README.md`

**Not changed:** Sales/Shipment controllers, Vue source, `public/js`, service
worker, routes, migrations, database schema, stock movement, Sale totals,
invoice/shipping templates, POS pages, or any menu/module file.

**Future vendor-update warning:** if a later Stocky version introduces native
Product insight aggregates, compare the vendor implementation first. Prefer the
vendor implementation when it provides equivalent business fields; retain only
custom business deltas and keep this regression contract or adapt it to the new
canonical query path.

## 22. Build D1 — Product Insights warehouse visibility + soft-delete correctness

**Status:** ACTIVE. **Base required:** Build C SAFE overlay (Section 21) on top of the
confirmed Build A + safe A.1/B baseline. Build D1 is deliberately a small
analytics-correctness patch. It does **not** change Product Insight formulas,
Unit/Multi-Pack math, date-window boundaries, UI columns, database schema,
Sales/POS/Shipment workflows, or compiled frontend assets.

### 22.1 Business correction: Product Insights now respect warehouse visibility

Before D1, Product stock/Warehouse Count followed the logged-in user's warehouse
visibility, but several custom transaction metrics were aggregated globally.
That meant a warehouse-restricted user could see Sold/Return/Latest Purchase
activity originating from another warehouse.

Build D1 applies one consistent transaction-population rule inside
`ProductInsightService`:

1. if an allowed `warehouse_id` filter is selected, insight transactions use
   only that warehouse;
2. otherwise, users without `is_all_warehouses` are limited to their assigned
   warehouse ids;
3. all-warehouse users keep the existing all-warehouse view when no explicit
   warehouse filter is selected;
4. a restricted user with no assigned warehouse receives zero transaction
   insight rows rather than falling back to global data.

The scope is applied to:

- completed Sale metrics: Sold (30d), previous period, Last Sold, lifetime sold;
- Sale Return quantity used by Return Rate;
- Latest received Purchase date/cost.

**Intentional visible change:** values can decrease for restricted users because
other warehouses are no longer included. This is a correctness/data-isolation
fix, not a regression. An all-warehouse user with no selected warehouse retains
the previous global view.

### 22.2 Soft-deleted parent transactions are excluded

Build C intentionally preserved the old behavior while it optimized query count.
D1 now excludes soft-deleted parent rows from the custom analytics:

- `sales.deleted_at IS NULL` for completed-Sale metrics and insight sorting;
- `purchases.deleted_at IS NULL` at both stages of the Latest Purchase lookup and
  on the final selected Purchase row;
- the pre-existing `sale_returns.deleted_at IS NULL` rule remains active.

The Product-detail rows themselves are not given any new status/unit semantics in
this patch; D1 only corrects parent transaction eligibility.

### 22.3 Product Insight sorting stays aligned with displayed values

`ProductsController` already performs server-side sorting for `Sold (30d)` and
`Last Sold`. Once D1 narrowed the displayed insight population, leaving the sort
subqueries global would produce a subtle mismatch: rows could be ordered by
hidden/deleted warehouse activity while displaying a smaller scoped number.

Therefore those two sort subqueries now use the same rules as the displayed
metrics:

- completed Sale only;
- non-deleted parent Sale;
- selected warehouse, or assigned warehouses for restricted users;
- no transaction rows for a restricted user with no warehouse assignment.

No other Product sorting behavior is changed.

### 22.4 Deferred Product analytics rules remain deferred

Build D1 intentionally does **not** change:

- the current `now()->subDays(30)` date-window definition;
- the previous-period day 60 through day 31 definition;
- raw Sale/Return quantities or Unit/Multi-Pack normalization;
- Return Rate formula;
- historical-vs-estimated revenue semantics;
- Last Purchase cost unit normalization;
- FIFO/COGS/inventory valuation.

Those items must remain separate patches because they can materially change
business numbers for all users, not just remove unauthorized/deleted data.

### 22.5 Runtime files and upgrade contract

**Runtime files changed:**

- `app/Http/Controllers/ProductsController.php`
- `app/Services/Custom/ProductInsightService.php`

**Tests/documentation:**

- `tests/Regression/build_c_product_insights.php` (Build C gate updated so later
  correctness narrowing does not falsely fail the performance contract);
- `tests/Regression/build_d1_product_insight_scope.php`;
- `tests/Unit/BuildD1ProductInsightScopeContractTest.php`;
- `CUSTOMIZATIONS.md`;
- `README_VENDOR_UPDATE_BN.md`;
- `BUILD_D1_SAFE_OVERLAY_README.md`.

**Not changed:** Vue source, `public/js`, routes, migrations/schema, Sales or
Shipment controllers, POS pages, invoice/shipping templates, stock movement, or
Sale total calculations.

Fast regression sequence after applying the overlay:

```bash
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
php tests/Regression/build_c_product_insights.php
php tests/Regression/build_d1_product_insight_scope.php
```

**Future vendor-update warning:** if Stocky later introduces native Product
insights, preserve the business contract that a restricted user's analytics may
not infer transactions from warehouses outside their Sale/Purchase visibility.
Review vendor soft-delete scopes when converting raw joins/subqueries because
Eloquent global scopes are not automatically applied to every query-builder
join.

## 23. Build D2 — Exact 30-day windows + finalized Return Rate population

**Status:** ACTIVE. **Base required:** Build D1 SAFE overlay (Section 22). Build D2
is intentionally limited to two Product Analytics business-rule corrections. It
does **not** introduce Unit/Multi-Pack normalization, change the Return Rate
formula, modify Sales/POS/Shipment workflows, alter database schema, or rebuild
frontend assets.

### 23.1 Sold (30d) is now exactly 30 calendar dates

The earlier custom Product metric used:

`sd.date >= now()->subDays(30)`

Because `sale_details.date` is a SQL `DATE`, including today made that population
span 31 calendar dates. The previous comparison also used a different inclusive
boundary shape, so the two trend periods were not exact equal-length windows.

Build D2 defines one shared `ProductInsightService::rolling30DayWindows()`
business boundary in the application timezone:

- **current:** today minus 29 dates, inclusive, through tomorrow, exclusive;
- **previous:** today minus 59 dates, inclusive, through current-start,
  exclusive.

Example for 11 Sep 2026:

- current = `[2026-08-13, 2026-09-12)` → Aug 13 through Sep 11 = 30 dates;
- previous = `[2026-07-14, 2026-08-13)` → Jul 14 through Aug 12 = 30 dates.

This is a rolling 30-day metric; month length (28/29/30/31 days) does not change
the rule. Half-open `[start, end)` intervals also avoid end-of-day precision
issues and exclude accidentally future-dated SaleDetail rows from `Sold (30d)`.

`ProductsController` now uses this same helper for `Sold (30d)` server-side
sorting. Displayed values and sort order therefore cannot drift because of a
separately duplicated date boundary.

### 23.2 Return Rate counts only finalized Sale Returns

The Product Return Rate formula remains:

`lifetime received return quantity / lifetime completed-sale quantity × 100`

Build D2 changes only the eligible Return population. `sale_returns.statut` must
now be `received` in addition to the D1 rules (`deleted_at IS NULL` + authorized
warehouse scope).

This status is not a new custom interpretation. Stocky's existing
`SalesReturnController` only adds returned stock, applies returned batch/serial
credits, and reverses those stock movements when a Sale Return is `received`.
`pending` returns therefore represent unfinished/non-stock-affecting documents
and must not inflate the Product Return Rate.

The denominator remains completed, non-deleted Sales in the same D1 warehouse
scope. Lifetime behavior is preserved: D2 does not restrict Return Rate to the
30-day window.

### 23.3 Explicitly deferred rules

Build D2 still does **not** change:

- Unit operator / Multi-Pack quantity normalization;
- raw `sale_details.quantity` / `sale_return_details.quantity` semantics;
- actual historical Revenue/COGS/FIFO calculations;
- Last Purchase Cost unit normalization;
- Sales-list Qty or Packing List quantity semantics;
- Product columns/UI/Vue/public JS;
- Sales, POS, Shipment, Invoice or Shipping Label logic;
- routes, migrations or database schema.

Unit/Multi-Pack normalization remains a separate behavioral patch because it can
materially change displayed quantities and Return Rate percentages.

### 23.4 Automated regression contract

Fast no-dependency gate:

```bash
php tests/Regression/build_d2_product_analytics.php
```

Regular PHPUnit contract:

`tests/Unit/BuildD2ProductAnalyticsContractTest.php`

The D2 gate protects:

- exactly 30 current calendar dates and 30 immediately preceding dates;
- non-overlapping half-open date windows;
- one shared date-window helper for displayed metric + server-side sorting;
- `received` Sale Return status as the Return Rate numerator population;
- D1 warehouse/soft-delete constraints;
- Build C's four set-based executed insight queries;
- continued deferral of Unit/Multi-Pack normalization.

The Build C and D1 contract tests are also updated only where their historical
"date semantics unchanged" assertions were intentionally superseded by this D2
business correction; their performance and warehouse-isolation contracts remain
active.

### 23.5 Runtime files and future vendor-update contract

**Runtime files changed:**

- `app/Http/Controllers/ProductsController.php`
- `app/Services/Custom/ProductInsightService.php`

**Tests/documentation:**

- `tests/Regression/build_c_product_insights.php` (forward-compatible contract)
- `tests/Regression/build_d1_product_insight_scope.php` (forward-compatible contract)
- `tests/Regression/build_d2_product_analytics.php`
- `tests/Unit/BuildCProductInsightContractTest.php` (forward-compatible contract)
- `tests/Unit/BuildD1ProductInsightScopeContractTest.php` (forward-compatible contract)
- `tests/Unit/BuildD2ProductAnalyticsContractTest.php`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- `BUILD_D2_SAFE_OVERLAY_README.md`

**Not changed:** Vue source, `public/js`, routes, migrations/schema, Sales or
Shipment controllers, POS pages, invoice/shipping templates, stock movement, or
Sale total calculations.

**Future vendor-update warning:** if Stocky later changes Sale Return status
semantics, do not keep `received` by assumption. Re-check the vendor stock
movement workflow first and make Return Rate follow whichever status actually
finalizes/restocks a return. If Stocky adds a native rolling-sales metric, retain
the exact equal-length/non-overlapping period contract unless the business
explicitly approves a different definition.

## 24. Build E1 — POS Recent Invoices visibility + historical currency

**Status:** ACTIVE. **Base required:** Build D2 SAFE overlay (Section 23). Build E1
is intentionally limited to the POS **Recent Invoices** lookup. It does not alter
POS sale creation, payment/stock movement, Sales-list calculations, Product
Insights, Shipment logic, routes, migrations, or database schema.

### 24.1 Recent Invoices now follows POS + record visibility

The custom `SalesController::posRecentSales()` endpoint previously applied
warehouse restrictions but did not apply the Sales `record_view`/ownership rule,
and it did not explicitly restrict the result to POS-origin invoices. That meant
a user could see another user's Sale inside an allowed warehouse even when the
normal Sales list would hide that record, and a non-POS Sale could appear in a
modal labelled "Recent Invoices" inside POS.

Build E1 now applies the following contract in this order:

- requires the existing `Sales_pos` permission (the same permission used by
  Stocky's POS controller actions);
- excludes soft-deleted Sales;
- `is_pos = 1` (POS-origin only);
- `statut = completed` (invoice/completed Sale only);
- when `User::hasRecordView()` is false, only `sales.user_id = current user`;
- when the user is warehouse-restricted, only assigned `UserWarehouse` IDs;
- all-warehouse users retain the all-warehouse behavior, subject to record
  ownership when `record_view` is disabled.

This is a visibility/security correction only. The endpoint still returns a
small newest-first list and does not become the full Sales index.

### 24.2 Historical amount no longer follows the currency currently selected in POS

Sale monetary fields are stored in base currency. Before E1, the Recent Invoices
modal rendered `Sale.GrandTotal` through `formatPriceWithCurrentCurrency()`, so
an old invoice could be displayed using whichever currency/rate the cashier had
selected **now**, rather than the document currency/rate stored on that Sale.

Build E1 keeps the existing base fields for API/backward compatibility and adds
an immutable display snapshot per row:

- `document_grand_total`;
- `document_paid_amount`;
- `currency_symbol`;
- `currency_code`;
- `exchange_rate`.

The backend uses the existing Stocky 5.8 `helpers::Get_Document_Currency()` and
`helpers::to_document_amount()` helpers, so legacy/base Sales fall back to the
configured base currency and foreign-currency Sales use their stored exchange
rate. The Recent Invoices amount cell renders the document amount/symbol with
`formatPriceWithSymbol()` and no longer multiplies the historical Sale by the
current POS rate.

Example: a Sale stores base `100` with document rate `1.25`; Recent Invoices
shows `125` in that Sale's document currency even if the register has since
switched to another currency.

### 24.3 Frontend deployment is surgical; no broad admin rebuild

Because this project is being maintained as SAFE overlays and the live project
may contain additional module/runtime files outside the uploaded baseline, E1
**does not replace the full `public/js` tree**. Source-of-truth Vue is updated in
`resources/src/pages/pos/PosPage.vue`, and the exact existing POS lazy chunk is
synchronized with the same one-expression change:

- `public/js/chunks/PosPage.LolIxUV8.js`

The PWA cache version is bumped from `stocky-pwa-v9` to `stocky-pwa-v10` in
`public/sw.js` so clients purge the cached old POS chunk and fetch the updated
artifact. No other compiled chunk, entry bundle, manifest, menu asset, or module
asset is replaced.

This preserves the working frontend baseline while keeping Vue source aligned
for the next normal Vite rebuild. A future full `npm run build` will regenerate
content-hashed assets from the updated source and can retire this surgical
compiled-artifact synchronization.

### 24.4 Runtime files, tests, and upgrade contract

**Runtime/source files changed:**

- `app/Http/Controllers/SalesController.php`
- `resources/src/pages/pos/PosPage.vue`
- `public/js/chunks/PosPage.LolIxUV8.js`
- `public/sw.js` (cache-version bump only)

**Tests/documentation:**

- `tests/Regression/build_e1_pos_recent.php`
- `tests/Unit/BuildE1PosRecentContractTest.php`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- `BUILD_E1_SAFE_OVERLAY_README.md`
- `BUILD_E1_FILE_MANIFEST.txt`

**Not changed:** Product Insight service/controller, Shipment controller,
Sales/PosSales list pages, POS create/update/payment logic, invoice/packing/
shipping templates, routes, migrations/schema, stock calculations, Unit/
Multi-Pack logic, or other `public/js` chunks.

Fast regression sequence after applying E1:

```bash
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
php tests/Regression/build_c_product_insights.php
php tests/Regression/build_d1_product_insight_scope.php
php tests/Regression/build_d2_product_analytics.php
php tests/Regression/build_e1_pos_recent.php
```

**Future vendor-update warning:** if Stocky changes POS ownership (`user_id`),
`record_view`, document-currency snapshot semantics, or introduces a native
Recent Invoices endpoint, compare those vendor rules before replaying E1. Prefer
the vendor implementation when it provides equivalent visibility and historical
currency guarantees, retaining only any business-specific delta.

## 25. Build E2 — Sale metadata validation + Option B create permissions

**Status:** ACTIVE. **Base required:** Build E1 SAFE overlay (Section 24). Build E2
is deliberately an input-hardening patch. It does not redesign the Sales/POS/
Shipment UI, add Zone/Courier administration screens, change stock/payment
calculations, or introduce a migration.

### 25.1 Business decision: keep on-the-fly creation convenience (Option B)

The existing `CreatableSelect` workflow remains active. Staff who already have a
legitimate business workflow may still create a Zone/Courier inline:

- Sale create permission (`Sales_add` through the existing Sale policy);
- Sale edit permission (`Sales_edit`);
- POS permission (`Pos_view` via `SalePolicy::Sales_pos`);
- Shipment create/update permission (`shipment`).

Unrelated authenticated users no longer get a generic metadata-creation API just
because they are logged in. No new admin-only permission is introduced in E2,
because the approved business requirement is to preserve the current convenience.

E2 also does **not** add Zone/Courier delete or rename/edit features. Historical
references remain untouched. A future Active/Inactive management UI, if wanted,
must be designed as a separate feature rather than hidden inside this hardening
patch.

### 25.2 Centralized validation contract

New support class:

`app/Support/SaleMetadataRules.php`

The same metadata can be changed through normal Sale create/edit, Sales bulk
update, and Shipment edit. E2 therefore centralizes the API validation so these
entry points cannot silently drift apart.

Normal Sale create/edit validates:

- `zone_id`: nullable integer; must reference a non-deleted `sale_zones` row;
- `courier_id`: nullable integer; must reference a non-deleted `sale_couriers` row;
- `tracking_ref`: nullable string, max 255 (matches the VARCHAR column);
- `consignment_id`: nullable string, max 255;
- `details.*.box_qty`: nullable numeric, `0..99,999,999.99`.

`box_qty` remains decimal-compatible because the existing DB contract is
`DECIMAL(10,2)`. E2 does not reinterpret Box Qty as an integer-only field.

Shipment create/update shares the same courier/tracking contract and validates
shipping status against the existing application vocabulary:

`ordered`, `packed`, `shipped`, `delivered`, `cancelled`.

### 25.3 Bulk-update request hardening

The Sales-list bulk metadata endpoint keeps the Build B authorization boundary
and partial-update semantics, then adds validation before the update query:

- selected IDs must be integers, distinct, and non-deleted Sales;
- at most 1000 IDs per request;
- Zone/Courier IDs must be active lookup rows;
- Tracking/Consignment max 255;
- Shipping Status must be one of the existing five statuses.

The 1000-row cap is intentionally generous for normal UI use while preventing an
unbounded crafted request from becoming a database/authorization workload. It can
be changed later only if a documented operational workflow legitimately needs a
larger batch.

The existing custom response for a completely omitted/empty selection is
preserved; E2 does not redesign bulk-update UX.

### 25.4 Duplicate-safe Zone/Courier creation

`SaleMetaController` now normalizes harmless whitespace (`trim` + repeated
whitespace collapse), then searches active **and soft-deleted** rows
case-insensitively using `LOWER(TRIM(name))`.

Examples intended to resolve to the same existing lookup instead of creating
common duplicates:

- `Pathao`
- ` pathao `
- `PATHAO`

The first canonical spelling remains stored/displayed; E2 does not lowercase
existing names. A matching soft-deleted row is restored rather than duplicated.
The existing DB `unique(name)` constraint remains the final concurrency guard. If
two users submit the same new name at the same time and one insert wins, the
other request catches the unique-key race, re-reads the row, and returns it
cleanly instead of surfacing a raw database error.

No migration is required.

### 25.5 Runtime files, tests, and upgrade contract

**Runtime/source files changed:**

- `app/Http/Controllers/SalesController.php`
- `app/Http/Controllers/ShipmentController.php`
- `app/Http/Controllers/SaleMetaController.php`
- `app/Support/SaleMetadataRules.php` (new)

**Tests/documentation:**

- `tests/Regression/build_e2_metadata_validation.php`
- `tests/Unit/BuildE2MetadataValidationContractTest.php`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- `BUILD_E2_SAFE_OVERLAY_README.md`
- `BUILD_E2_FILE_MANIFEST.txt`

**Not changed:** Vue source, `public/js`, service worker, Product Insights,
POS Recent Invoices, invoice/packing/shipping templates, routes, migrations,
database schema, stock movement, Unit/Multi-Pack calculations, Sale totals,
payments, or Zone/Courier delete/edit behavior.

Fast cumulative regression sequence after applying E2:

```bash
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
php tests/Regression/build_c_product_insights.php
php tests/Regression/build_d1_product_insight_scope.php
php tests/Regression/build_d2_product_analytics.php
php tests/Regression/build_e1_pos_recent.php
php tests/Regression/build_e2_metadata_validation.php
```

**Future vendor-update warning:** if Stocky introduces native shipping zones,
couriers, or metadata validation, prefer the vendor entities/rules when they meet
these business contracts. Do not keep two parallel lookup systems. Preserve the
Option B workflow (authorized operational staff can create inline) unless the
business explicitly approves an admin-only model, and retain validation at every
write entry point that can mutate the same Sale metadata.

## 26. Build E3 — POS Sales 5.8 Seller/Currency parity + default-hidden Currency

**Status:** ACTIVE. **Base required:** Build E2 SAFE overlay (Section 25). Build E3
is deliberately a frontend parity/UX patch. It does not merge the POS Sales page
back into the normal Sales page, change POS transaction behavior, or touch any
Sale/stock/payment calculation.

### 26.1 Why this patch exists

Stocky 5.8 added shared Sales-list metadata for the Seller and document Currency.
The custom `PosSales.vue` page was intentionally copied/separated before that
vendor change, so it retained the existing POS-only workflow but drifted from the
5.8 Sales columns. The backend already returns `seller_name` and the Build A.1
resolved `currency_code`; E3 only brings the display contract into parity.

This is a **Stocky 5.8 merge-regression correction**, not a redesign of POS Sales.
The page still hard-codes `is_pos: 1` and remains a separate operational menu.

### 26.2 Seller/Currency parity

`resources/src/pages/sales/PosSales.vue` now includes:

- Seller (`seller_name`), matching the normal Sales list;
- Currency (`currency_code`) only while Stocky's multi-currency module is enabled;
- the same blue currency badge rendering used on the normal Sales list.

No backend field or API contract was added in E3 because the Sales API already
provides both values.

### 26.3 Currency is available, but hidden by default

The business preference is to keep Currency available without permanently using
horizontal table space. E3 therefore marks the Currency column
`defaultHidden: true` on both:

- normal Sales;
- POS Sales.

`DataTable.vue` already supports this contract: default-hidden columns start
unchecked in the Columns picker and the user can enable them for the current
session. Multi-currency disabled installations still do not create the Currency
column at all.

This changes visibility only. Sale amounts/currency calculations from earlier
builds remain untouched.

### 26.4 Surgical compiled-asset synchronization

Because the active project is maintained with SAFE overlays, E3 does **not** run
or ship a broad replacement of `public/js`. The source changes are synchronized
only into the two existing lazy chunks that implement these pages:

- `public/js/chunks/Sales.DrRmbclp.js`;
- `public/js/chunks/PosSales.Tm8D__HT.js`.

The PWA cache version is bumped from `stocky-pwa-v10` to `stocky-pwa-v11` so
clients fetch those updated chunks. No entry bundle, manifest, menu/module asset,
or unrelated lazy chunk is replaced.

A future normal Vite build should regenerate hashed assets from the source files;
the source-of-truth changes are already present in `Sales.vue`/`PosSales.vue`.

### 26.5 Runtime files, tests, and upgrade contract

**Runtime/source files changed:**

- `resources/src/pages/sales/Sales.vue`
- `resources/src/pages/sales/PosSales.vue`
- `public/js/chunks/Sales.DrRmbclp.js`
- `public/js/chunks/PosSales.Tm8D__HT.js`
- `public/sw.js` (cache-version bump only)

**Tests/documentation:**

- `tests/Regression/build_e1_pos_recent.php` (monotonic cache-version assertion: v10 or later)
- `tests/Regression/build_e3_pos_sales_parity.php`
- `tests/Unit/BuildE3PosSalesParityContractTest.php`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- `BUILD_E3_SAFE_OVERLAY_README.md`
- `BUILD_E3_FILE_MANIFEST.txt`

**Not changed:** controllers/API behavior, Product Insights, POS Recent endpoint,
Shipment, invoice/packing/shipping templates, routes, migrations/schema,
Sale totals, payment logic, stock movement, Unit/Multi-Pack calculations, or the
POS Sales menu/workflow.

Fast cumulative regression sequence after applying E3:

```bash
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
php tests/Regression/build_c_product_insights.php
php tests/Regression/build_d1_product_insight_scope.php
php tests/Regression/build_d2_product_analytics.php
php tests/Regression/build_e1_pos_recent.php
php tests/Regression/build_e2_metadata_validation.php
php tests/Regression/build_e3_pos_sales_parity.php
```

**Future vendor-update warning:** `PosSales.vue` is still intentionally separate
from `Sales.vue`. After each Stocky vendor update, compare shared Sales columns and
business-safe display behavior for drift. Do not blindly overwrite POS Sales with
the vendor Sales page; retain `is_pos: 1` and POS-specific workflow. If Stocky
later provides a native POS-sales list, prefer that vendor implementation when it
meets these business contracts and retire the duplicate custom page deliberately.

---

## 27. Build F — Stock Lookup unit, active-variant, and price-display cleanup

**Status:** ACTIVE. **Base required:** working Build E3 SAFE overlay (Section 26).
Build F is intentionally limited to the existing Stock Lookup feature. It does not
change stock movement, valuation, Product Insights, Sales/POS/Shipment, routes, or
database schema.

### 27.1 Why this patch exists

The Stock Lookup UI rendered every stock quantity as `Pcs`, even when the
product's base stock unit was Kg, Box, Litre, etc. Its variant relationship also
included soft-deleted variants because this codebase stores `deleted_at` without
Laravel's global `SoftDeletes` scope. Finally, a variant product's header/search
result used the parent product price; that value may be zero or unrelated to the
active variants.

### 27.2 Runtime contract

- The detail API eager-loads the product's base `unit` and returns
  `product.unit_label` (`ShortName`, falling back to the unit name).
- Every Stock Lookup quantity label uses that value. Stored quantity and stock
  aggregation remain untouched and stay in the existing base-stock unit.
- Search-by-variant code/GTIN and detail eager loads explicitly require
  `product_variants.deleted_at IS NULL`.
- Variant stock aggregation is restricted to the active variant IDs, so orphaned
  `product_warehouse` rows for deleted variants cannot reappear in totals.
- Search and detail payloads add `price_min`/`price_max` from active variants.
  The Vue page shows one price when equal, a range when different, and `Varies`
  when a variant product has no active variant price. Individual expanded rows
  continue to show each active variant's own price.

The existing `price` field remains in the payload for backward compatibility;
Stock Lookup alone chooses the variant-aware display. No shared pricing helper,
tax/discount rule, wholesale/min-price rule, or stored price is changed.

### 27.3 Surgical asset synchronization

The source-of-truth changes are in:

- `app/Http/Controllers/ProductsController.php`
- `resources/src/pages/products/StockLookup.vue`

Only the currently active lazy chunk referenced by the received Vite manifest is
synchronized: `public/js/chunks/StockLookup.D-UahGfI.js`. No entry bundle,
manifest, menu, router, or unrelated chunk is replaced. `public/sw.js` advances
to `stocky-pwa-v12` so clients do not keep the previous Stock Lookup chunk.

### 27.4 Tests and exclusions

Build F adds:

- `tests/Regression/build_f_stock_lookup_cleanup.php`
- `tests/Unit/BuildFStockLookupCleanupContractTest.php`

Older cache-version assertions in the A.1/E3 regression contracts are made
monotonic so later surgical PWA releases do not falsely fail them.

**Not changed:** routes, migrations/schema, product/variant records, core stock
calculation, warehouse authorization model, Product Insights, Sales/POS,
Shipment, Unit/Multi-Pack conversion, pricing engine, FIFO/COGS, invoice/PDF, or
any other application feature.

Fast cumulative regression sequence after applying Build F:

```bash
php tests/Regression/build_a_stability.php
php tests/Regression/build_a1_currency_backend.php
php tests/Regression/build_b_authorization.php
php tests/Regression/build_c_product_insights.php
php tests/Regression/build_d1_product_insight_scope.php
php tests/Regression/build_d2_product_analytics.php
php tests/Regression/build_e1_pos_recent.php
php tests/Regression/build_e2_metadata_validation.php
php tests/Regression/build_e3_pos_sales_parity.php
php tests/Regression/build_f_stock_lookup_cleanup.php
```

**Future vendor-update warning:** preserve the explicit active-variant filters
unless the `ProductVariant` model later adopts a verified global soft-delete
scope. If Stocky's build system regenerates hashed assets normally, regenerate
the manifest/chunk from `StockLookup.vue`; do not retain a hand-synchronized
chunk as the source of truth.

---

## Post-F continuity — G1, Phase 0, PO+GRN, and registration hotfix

After Build F, the active chain adds Product Insight base-quantity readiness
(G1), Purchase Form quick wins (Phase 0), and the Purchase Order to GRN linkage.
The full PO/GRN behavior, security decisions, schema, known GRN-edit limitation,
tests, and file inventory are documented in `PO_GRN_SAFE_OVERLAY_README.md` and
`PO_GRN_FILE_MANIFEST.txt`.

The first PO+GRN ZIP intentionally left `routes/api.php`,
`resources/src/router/index.js`, and `resources/src/config/menu.js` as manual
instructions. Applying only the ZIP installed the implementation but not every
registration, so `tests/Regression/build_po_grn.php` failed. The registration
hotfix packages those exact three files and extends the gate to cover all PO API
endpoints, both lazy imports, list/create/edit SPA routes, legacy aliases, and
permission-gated menu registration.

This hotfix changes registration only. It adds no migration or frontend build
and does not alter PO/GRN status, received quantity, stock, price, payment,
documents, email, or any unrelated workflow. The received compiled Vite entry
already contains the PO routes/menu and PO chunks; source is now aligned with it.

---

## PO+GRN Phase 1.2 — labels, GRN header layout, and actionable errors

**Status:** ACTIVE. **Base required:** PO+GRN registration hotfix.

The initial PO UI leaked raw vue-i18n keys because `$t()` returns the key on a
miss, making patterns such as `$t('PurchaseOrders') || 'Purchase Orders'`
ineffective. English PO keys are now provided by the translation API (without
overwriting database customizations) and recorded in the English translation
seeder. The PO list/form additionally use the exact approved labels in source:
Purchase Orders, Add Purchase Order, and Expected Delivery Date.

The Create Purchase/GRN header is Date, Supplier, Select Purchase Order, and
Warehouse in one four-column desktop row. The PO selector remains create-only,
optional, supplier-filtered, and warehouse-locking; no receipt or stock rule is
changed.

The PO list now passes `params: () => filterParams.value`; the earlier computed
ref was called as a function by `useCrudTable`, throwing before the list request
could run. The shared CRUD fallback then hid that programming error as
“Something went wrong”. A PO-specific handler now reports 403, 404, and 500
cases separately and reads backend messages from the custom fetch wrapper's
real `error.data` contract. `tests/Regression/po_grn_runtime.php` is a read-only
installation check for the PO routes, three tables, purchases link column, and
permission row. Full deployment and rollback instructions are in
`PO_GRN_PHASE1_2_UI_ERROR_FIX_SAFE_OVERLAY_README.md`.

**Not changed:** PO/GRN business formulas, receipt application/reversal,
statuses, stock, pricing, accounting/payment, documents/email behavior,
Sales/POS/Shipment, or any unrelated feature.

---

## PO+GRN Phase 1.3 — real PO view, human copy, documents/columns, safe GRN deletion

**Status:** ACTIVE. **Base required:** PO+GRN Phase 1.2.

PO View now opens an actual read-only details state showing header data and
ordered/received/remaining line quantities. Partially and fully received POs
automatically use that state, including when an old direct edit URL is opened;
Edit is shown only for Draft and Ordered records. `PoStatusAutoNote`,
`PoNotEditable`, `PoItemsAvailable`, and `LoadAllItems` are removed in favor of
human-readable labels and messages.

The PO list adds Created By and Last GRN Date as default-hidden columns, plus
Age and an attachment indicator. Attachments can be uploaded, downloaded, and
deleted from the action menu. Last GRN Date considers only active finalized
(`received`) GRNs.

The two supplied Claude ZIPs were audited, not blindly overlaid. Their useful
document/column and GRN-deletion ideas were manually merged. The GRN safety
implementation was corrected to aggregate duplicate lines and the entire bulk
selection in base units, then lock stock rows inside the same deletion
transaction. If the combined reversal would make any stock negative, the whole
operation is rejected before mutation.

Deployment and audit details are in
`PO_GRN_PHASE1_3_SAFE_OVERLAY_README.md`,
`PO_GRN_PHASE1_3_FILE_MANIFEST.txt`, and
`CLAUDE_PO_GRN_ZIP_AUDIT.md`.

**Not changed:** PO/GRN formulas, costing, payment logic, database schema,
permission identifiers, routes, Product Insights, Sales/POS/Shipment, or other
modules.

Known lifecycle limitations intentionally left for the next controlled phase:
linked GRN edits/status changes do not yet reconcile old/new PO receipt
contributions; backend remaining-quantity/concurrency and supplier-match checks
need hardening. Until then, a received PO-linked GRN should be safely deleted
and recreated rather than edited. Full evidence, required invariants, tests, and
GitHub-readiness findings are in
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`.

---

## PO+GRN Phase 1.4 — supplier match, over-receipt lock, unsafe-edit block

**Status:** ACTIVE. **Base required:** PO+GRN Phase 1.3.

This phase closes three of the P0/P1 gaps recorded in
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`. It deliberately
does NOT attempt full linked-GRN edit reconciliation (section 7's first P0) —
that requires reworking `PurchasesController::update()`'s stock-reversal/
reapply blocks in place, which the handoff itself flags as needing to be
proven safe first. Instead this phase takes the handoff's explicit fallback:
block the unsafe edit outright.

**1. Supplier match.** `PurchaseOrderReceiptService::validateReceivablePo()`
now takes the GRN's `supplier_id` and rejects (HTTP 422) a GRN that selects a
Purchase Order raised for a different supplier. Previously only existence,
warehouse scope, status, and warehouse match were checked.

**2. Backend over-receipt protection, under lock.** A new
`PurchaseOrderReceiptService::lockAndValidateReceiptLines()` runs inside
`PurchasesController::store()`'s existing transaction, before any row for the
new GRN is written, whenever the GRN is PO-linked and saved as `received`. It
`lockForUpdate()`s the PurchaseOrder row and every referenced
PurchaseOrderDetail row (stable ascending-id lock order, matching
`GrnDeletionSafetyService`'s convention), verifies each referenced line
actually belongs to the selected PO and matches the line's product/variant,
sums duplicate/split lines in the same GRN against the same PO detail, and
rejects the entire GRN (no partial apply, no clamping) if the locked
remaining quantity would be exceeded. Because the lock is held for the rest
of the transaction, a second concurrent request against the same PO lines
blocks until the first commits (or rolls back) and then re-reads the
now-updated remaining quantity — closing the race the handoff describes in
its "concurrent receipt race" section. The pre-transaction
`validateReceivablePo()` call remains as a fast, non-locking precheck only,
exactly as the handoff recommended.

**3. Unsafe linked-GRN edit is now blocked, not silently allowed.**
`PurchasesController::update()` now aborts with HTTP 422 at the very start of
its transaction — before any stock or detail row is touched — whenever the
GRN being edited is PO-linked AND either its current `statut` is `received`
or the request would set it to `received`. This covers the case the handoff
documents (editing an already-received linked GRN) and also a related case
found while implementing this: transitioning a linked GRN from
pending/ordered to `received` via `update()` moves stock through this
method's existing blocks but never called
`PurchaseOrderReceiptService::applyReceipt()`, so it would have desynced the
PO exactly like a direct edit. Editing a linked GRN that stays
pending/ordered on both sides is unaffected — no stock or PO contribution
exists yet either way. The linked GRN edit/status-transition
*reconciliation* itself (letting such an edit succeed and adjusting the PO
correctly) remains for a future phase, per the handoff's own P0 write-up.

**Verification performed:** all three changes were reviewed against the
existing behavior table in `docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`
section 4 and the acceptance matrix in its section 9 (items 3, 5, 8–12) —
see `tests/Regression/build_po_grn_phase1_4.php` for the automated
source-contract checks. **This phase's engineering environment had no
network access to Composer/Packagist and no vendor/ directory, so these
checks are static/source-contract only — they confirm the code is wired the
way this document describes, not that it behaves correctly against a live
database.** A real-database functional/concurrency run (the exact scenarios
in section 9) must still be done in the actual Laragon environment before
this is treated as verified in production. See
`tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md` for the exact steps and
expected results to run there.

**Not changed:** linked GRN edit reconciliation (still blocked, not fixed),
extra non-PO GRN lines policy, short-close workflow, cost variance, PO/GRN
formulas, costing, payment logic, database schema, permission identifiers,
routes, Product Insights, Sales/POS/Shipment, or other modules.

---

## Build H1 — Product Movement Ledger (backend)

**Why:** A single product's lifetime stock history was scattered across
seven separate modules (Purchases, Sales, Transfers, Adjustments, Sale
Returns, Purchase Returns, Damages) with no unified, chronological view
and no running balance — requested specifically to give a way to *find*
real instances of the known stock-integrity bug (see "Known unresolved
issues" below) on live data, not just reason about it in the abstract.

**Backend:**
- `app/Services/Custom/ProductMovementLedgerService.php` — new, isolated
  service (not inline in any vendor controller). `build($productId,
  $productVariantId, $warehouseId, $dateFrom, $dateTo)` unions all 7
  sources into one array sorted by `date` then `id`, computes a running
  balance per warehouse, and returns a `reconciliation` block comparing
  the computed ending balance against the live `product_warehouse.qte`
  per warehouse.
- `app/Http/Controllers/ProductsController.php@movement_ledger` — new
  method, thin: validates input, calls the service, returns JSON.
- Route: `GET products/movement-ledger` (`routes/api.php`).
- **Reconciliation semantics:** `row_missing: true` means no
  `product_warehouse` row exists at all for that warehouse (the
  stock-integrity bug's exact signature). `reconciled: false` with a
  `row_missing: false` means a row exists but the numbers disagree —
  this can also happen innocently for a Units/Multi-Pack product (see
  scope note below), so a mismatch alone doesn't prove the bug fired.
- **Variant products:** `product_variant_id = null` means "every variant
  combined", not "non-variant rows only" — the reconciliation SUMs every
  variant's own `product_warehouse` row for that warehouse. Pass a
  specific variant id to check one variant in isolation (needed because a
  partial case — one variant's row missing, others present — can still
  sum to the right total and read as reconciled).
- **QuickBooks noise:** none directly (this build predates the
  Activity Log's own QuickBooks-noise discovery, and doesn't touch models
  with `quickbooks_*` columns in a way that matters here).

**Scope limits (documented, not fixed):**
- Quantities are the RAW value stored on each detail row, not run through
  any per-line unit/Multi-Pack conversion — each of the 7 source
  controllers resolves that conversion differently today, and unifying
  them was judged out of scope for Phase 1. For a product that never uses
  Units/Multi-Pack, raw quantity IS the base-unit quantity and the
  reconciliation is exact.
- Not a single SQL UNION — each of the 7 sources is queried and merged in
  PHP. Correct and simple for realistic log volumes; revisit if it's ever
  slow in practice.

## Build H2 — Product Movement Ledger (frontend) + two real bugs found and fixed

**Frontend:**
- `resources/src/pages/products/MovementHistoryCard.vue` — new,
  self-contained component: chronological table, running balance,
  warehouse/date filters, and the reconciliation badges (red = row
  missing, orange = numbers disagree, green = reconciled) right at the
  top. Plain English labels throughout (no `$t()` — see "House rule:
  plain-English UI text" below).
- Embedded in **two** places: `resources/src/pages/products/
  ProductDetails.vue` (one import + one component tag) and a new tab on
  the existing `resources/src/pages/reports/StockDetailReport.vue`
  (Reports → Stock Report → a product → Stock Detail Report), which
  already had separate Sales/Purchases/etc. tabs for a product — this
  build added the unified view as one more tab there rather than
  building a second, competing report page.

**Bugs found while building the frontend (fixed in the same delivery):**
1. **Variant products showed "no movement."** The backend required an
   exact `product_variant_id` match; a variant product's rows always
   carry one, so the "all variants" view matched nothing. Fixed in
   `ProductMovementLedgerService` (see Build H1's notes above — this is
   where that fix actually landed).
2. **`users.name` doesn't exist.** An unrelated but real bug caught later
   in the Activity Log work (Build I1) turned out to share a root cause
   worth noting here too: this codebase's `users` table is
   `firstname`/`lastname`, never a single `name` column. Any new code
   written against a `User` relation must build the display name from
   those two fields, not assume `->name` — this bit twice (see Build I1).

**Environment/process notes surfaced while shipping this (all resolved,
none code bugs):**
- A stale PHP OPcache (Apache's own PHP module, not the CLI used for
  `php artisan`) served an old compiled `ProductsController.php`/
  `routes/api.php` after edits — CLI commands like `route:list` correctly
  saw the new route while the actual web server didn't, because CLI and
  the Apache PHP module can hold separate OPcache state. A full Laragon
  restart (not just `optimize:clear`) was needed to clear it.
- A genuinely pre-existing, unrelated problem was uncovered in the same
  session: `.env`'s `APP_KEY` was empty, but the app had been silently
  running on a stale cached config with a valid key baked in from before
  — clearing caches (as part of normal troubleshooting) exposed it.
  `php artisan key:generate` fixed it. Worth a periodic sanity check
  (`php artisan config:clear` then confirm the app still works) since a
  cached-over problem like this can sit invisible indefinitely.

## Build I1 — Activity Log (Phase 1: Sales, Purchases, Products, Customers + existing logins, unified)

**Why:** No general "who did what" audit trail existed — only narrow,
unrelated logs (`MarketingActivityLog`, `MeetingActivityLog`) and a
**self-service-only** Login Activity Report/Login Device Management pair
(each user sees only their own login history — confirmed via
`SecuritySettingsController::loginActivityReport()`'s
`where('user_id', $user->id)`, not an admin-wide view).

**Schema** — `database/migrations/2026_09_18_000001_create_activity_logs_table.php`:
new `activity_logs` table: `user_id` (nullable), `module`, `action`
(created/updated/deleted), `subject_type`/`subject_id`, `description`,
`old_values`/`new_values` (json), `ip_address`, `user_agent`,
`created_at` only (a log row is never edited after the fact).
`database/migrations/2026_09_18_000002_add_activity_log_report_permission.php`:
adds the `activity_log_report` permission, auto-granted to whichever
role(s) already hold `report_device_management` (same reserved-id
convention as the `purchase_orders` permission migration).

**Backend — the instrumentation (the actual hard part):**
- `app/Services/Custom/ActivityLogger.php` — `log()` writes a row;
  `diff()` reduces a model's `getChanges()`/`getOriginal()` to just the
  changed fields worth showing; `sanitize()` does the same for a full
  attribute snapshot (a `created` row's `new_values` is the whole row,
  not a diff). Both exclude `updated_at`/`created_at`/`deleted_at`/
  `remember_token`/`password`/`NewPassword` unconditionally, and every
  `quickbooks_*` column **by prefix** (see "QuickBooks noise" below).
- `app/Providers/ActivityLogServiceProvider.php` — new, registers
  model-event closures, same "dispatch from model lifecycle, don't touch
  controllers" approach the vendor's own `AccountingV2ServiceProvider`
  already uses elsewhere. Registered in `config/app.php`'s providers
  array.
- **Confirmed, not assumed, before writing any hook:** which of Sale,
  Purchase, Product, Client's create/update/delete calls persist via an
  Eloquent instance (`new X; ->save()` / `$x->update([...])` — fires
  events, hookable) vs. a query-builder bulk update
  (`X::whereKey($id)->update([...])` — does NOT fire events, needs an
  explicit call site). Sale/Purchase/Product: fully observable. Client:
  `created` observable, `update()`/`destroy()` are bulk — explicit
  `ActivityLogger::log()` calls added directly in `ClientController.php`
  at those exact call sites (commented `--- Build I1 (Activity Log)`).
- **Soft-delete detection:** none of these models ever call Eloquent's
  own `->delete()` — every delete here is
  `$model->update(['deleted_at' => now(), ...])`, which fires `updated`,
  not `deleted`. The provider's `updated()` closures check
  `wasChanged('deleted_at')` specifically; a registered `deleted()`
  closure would simply never fire in this codebase and isn't used.
- `app/Http/Controllers/ActivityLogController.php` — new, the report
  endpoint. Merges `activity_logs` with the **existing**
  `user_login_sessions` table (same table behind Login Device
  Management/Login Activity Report) **read for all users**, not
  duplicated — Auth/login rows in the unified feed are just that table
  queried without the `user_id` filter the self-service pages use.
  Merged in PHP (not a SQL UNION) for the same reasoning as the Movement
  Ledger.
- Route: `GET reports/activity-log` (`routes/api.php`).

**Frontend:**
- `resources/src/pages/reports/ActivityLogReport.vue` — new, built on the
  existing generic `ReportPage` + `useCrudTable` shell (same Filter
  drawer / Export Excel-PDF-Print / search every other report already
  has). Date range, User, Module filters; a 👁 action opens an old→new
  diff modal for `updated` rows.
- Menu + router: `resources/src/config/menu.js`,
  `resources/src/router/index.js`.

**Bugs found and fixed in this delivery:**
1. **Menu link silently fell back to Dashboard.** This app's `/next/`
   SPA keeps a `MIGRATED_ROUTES` lookup table at the top of `menu.js`
   translating legacy `/app/...` menu paths to the real router path — a
   new menu entry needs a matching row there too, or it resolves as
   "not yet migrated" and deep-links into the legacy SPA instead.
   Missed on the first pass, fixed as a hotfix.
2. **`users.name` doesn't exist** (see Build H2) — hit again here in
   `ActivityLogController`'s eager-loaded `user` relation and the Users
   filter dropdown query. Fixed with a small `userName()` helper that
   builds the display name from `firstname`/`lastname` everywhere a
   `User` needs to be shown.
3. **`SettingPolicy` needs an explicit method per permission — a
   `permissions` table row is not enough on its own.** The new
   `activity_log_report` permission existed correctly in the database
   (row + role assignment, confirmed directly in phpMyAdmin) but still
   403'd, because this app's authorization for Settings-area abilities
   goes through `app/Policies/SettingPolicy.php`, which needs one PHP
   method named exactly after each permission string — Laravel's
   Gate/Policy resolution has no code path for a permission that only
   exists as a database row. Fixed by adding
   `SettingPolicy::activity_log_report()`, mirroring the existing
   `report_device_management()` method exactly. **This is a standing gotcha
   for any future Settings-area permission**, not specific to this
   feature — check `SettingPolicy.php` before assuming a new
   `permissions` row alone will authorize anything under Settings.
4. **QuickBooks background sync created phantom "updated" log rows.**
   Every Sale save (create or update) triggers a QuickBooks sync attempt
   regardless of whether QuickBooks is connected; when it's not, it
   writes an error into `quickbooks_sync_error`, which `ActivityLogger`
   was — correctly, per its own logic — treating as a real field change.
   Fixed by excluding every `quickbooks_*` column **by prefix** (not a
   fixed list) from both `diff()` and `sanitize()`. **Standing rule for
   any future model hooked into this log:** if it has QuickBooks columns,
   they're already excluded automatically; no per-model action needed.
5. **A misleading, unrelated-looking 403 that turned out to be exactly
   that — a permission problem, not a code bug** (see item 3) —
   documented here because it cost real debugging time going down other
   paths (stale cache, OPcache, browser cache) before the actual cause
   (missing Policy method) was found. **Diagnostic order worth
   remembering:** for a 403 or "Something went wrong" on a *new*
   authenticated endpoint, check the relevant Policy class for a matching
   method *before* chasing cache/environment theories — a
   `permissions`-table row existing is necessary but never sufficient in
   this codebase.

## Build I2 — Activity Log (Phase 2: Adjustments, Transfers, Users, Roles & Permissions)

**Why:** Extends Build I1 to the two areas judged most valuable next:
stock-affecting actions (ties directly to the stock-integrity bug's own
audit-trail motivation) and access-control changes (who can do what, and
who changed it — arguably more security-sensitive than Sales).

**Backend:**
- `ActivityLogServiceProvider` extended: `Adjustment` and `Transfer` are
  **fully observable** (confirmed via the same direct-code-read
  discipline as Build I1 — both use `new X;->save()` and
  `$current->update([...])` throughout, including their soft-delete
  path). `User` is observable for `created` and soft-delete (`destroy()`
  uses an instance `->save()`), but its regular profile edit
  (`UserController::update()`) is a bulk `User::whereId($id)->update()` —
  same non-observable shape as Client — logged via an explicit call site
  there instead.
- **Role create/update/delete/bulk-delete** — all four are bulk/pivot
  operations (`Role::whereKey($id)->update()`,
  `$role->permissions()->attach()/detach()`), none observable. Four
  explicit `ActivityLogger::log()` calls added directly in
  `PermissionsController.php`. The **update** log specifically diffs
  name, description, **and the permission list** (old list → new list),
  not just "Role updated" — the actual point of logging a permission
  change is seeing which permissions moved.
- **Security hardening, applied proactively (not from a bug report this
  time):** a `User`'s `created` event snapshots the whole row via
  `getAttributes()`, which includes the hashed password. Every such
  full-snapshot call site (the provider's generic `hookModel()`, and
  `Client`'s own `created` hook) now goes through
  `ActivityLogger::sanitize()`, and `password`/`NewPassword` were added
  to the permanent exclusion list — applied here *before* it could
  surface as an incident, having just fixed the QuickBooks version of the
  same class of mistake in Build I1.

**Frontend:** `ActivityLogReport.vue`'s Module filter extended with Stock
Adjustments, Stock Transfers, Users, Roles & Permissions.

**Known scope limit:** `UserController::IsActivated()` (the
enable/disable toggle, separate from a full profile edit) isn't logged —
a smaller bulk-update call site than the main `update()`, deferred as
lower priority.

**Still deferred, by explicit agreement, to a future round:** failed
login attempt tracking (nothing tracks a wrong-password attempt today;
the "Failed/Warning" concept from the original reference design needs
this and doesn't exist yet).

## Build J1 — Purchase Orders enable/disable toggle

**Why:** A settings-level switch for the whole PO → GRN feature, for a
store that doesn't use formal purchase orders — requested explicitly
matching the existing "Enable Box Quantity" pattern, but PO is a full
feature (its own data model, pages, routes), not a single display field,
so this one is enforced server-side, not just cosmetic.

**Schema** — `database/migrations/2026_09_18_000003_add_enable_purchase_orders_toggle.php`:
new `enable_purchase_orders` boolean on `settings`, default `true` (no
behavior change on existing installs until deliberately flipped).

**Backend:**
- `app/Http/Middleware/CheckPurchaseOrdersEnabled.php` — new, modeled
  directly on the vendor's own existing `EnsureStoreEnabled` middleware
  (same shape: read a settings flag, `abort()` if off). Registered as the
  `po.enabled` named alias in `Kernel.php`.
- The **entire** `purchase_orders` route group in `routes/api.php`
  (list/create/edit/delete/documents/PDF/email/price-variance report) is
  wrapped in `Route::middleware('po.enabled')->group(...)` — one
  middleware wrap, zero changes to `PurchaseOrderController.php` itself.
- `SettingsController.php` — read/save handling at the same 3 call sites
  `enable_box_qty` already has (save handler + both GET-response spots).
- **Confirmed before building:** GRN receiving has no separate route of
  its own — it's a regular Purchase referencing a PO id, going through
  the existing (unaffected) `purchases` routes — so wrapping only the
  `purchase_orders`-prefixed routes correctly blocks the entry point
  without needing to touch Purchases.

**Frontend:** `SystemSettings.vue` — new toggle under "Enable Box
Quantity".

**Known, documented scope limit:** the "Purchase Orders" and "Price
Variance Report" sidebar links still show even when the feature is off —
clicking hits the 403 from the backend rather than the menu item
disappearing. Making the menu itself react to the setting is a separate,
reasonable fast-follow (this app's sidebar-rendering engine wasn't judged
safe to change for this delivery — see also the existing whole-module
toggle system, `resources/src/config/modules.js`, which operates at a
coarser top-level-menu-section granularity and doesn't fit a single
child page like this one).

## Build J2 — Public Invoice URL (branded HTML page)

**Why:** A shareable, no-login link to a sale's invoice — viewable and
downloadable without the customer ever logging in.

**Schema** — `database/migrations/2026_09_18_000004_add_public_token_to_sales.php`:
new `public_token` (40-char random string, unique, nullable) on `sales`.
Nullable and lazily generated on first request, not backfilled — a sale
nobody has ever asked to share has no link to leak.

**Backend:**
- `app/Services/Custom/PublicInvoiceLinkService.php` — new, tiny.
  `getOrCreateToken()`/`regenerateToken()`, both via `saveQuietly()`
  specifically so generating a link never fires a model event (which
  would otherwise show up as a spurious "Sale updated" row in the
  Activity Log — applied proactively, same lesson as the QuickBooks fix
  above, this time anticipated rather than found by a bug report).
- `app/Http/Controllers/PublicInvoiceController.php` — new. `link()`/
  `regenerate()` are authenticated (staff, from the Sale Detail page).
  `show()` (JSON data for the page) and `pdf()` are genuinely public — no
  auth, looked up by the unguessable token rather than the sale's own id.
  `pdf()` deliberately delegates to the existing
  `SalesController::Sale_PDF()` rather than reimplementing PDF
  generation, so the downloaded PDF can never drift from what staff see.
- `SalesController.php@Sale_PDF` — one additive line: honors an `inline`
  request flag (stream vs. force-download) for the public page's
  Download button; every existing caller is unaffected since none pass
  it.
- Routes: `sales/{id}/public-link` (GET) and
  `sales/{id}/public-link/regenerate` (POST) — authenticated, near the
  existing sale-document routes. `public/invoice/{token}` (JSON) and
  `public/invoice/{token}/pdf` — genuinely public, in the same no-auth
  block as the existing customer-display routes.

**Frontend:**
- `resources/src/pages/public/PublicInvoice.vue` — new. A real financial
  document, designed accordingly (restrained slate/ink palette, one
  accent color reserved for the Download button, tabular-aligned
  numbers, system fonts, print-aware — the Download button hides when
  printed — responsive down to mobile).
- `resources/src/pages/sales/SaleDetails.vue` — new "Public Link"
  dropdown-button: main click copies the link (with an
  `execCommand('copy')` fallback and a manual-copy dialog for when even
  that fails — see the clipboard bug below); dropdown → "Regenerate"
  invalidates the old link.
- `resources/src/router/index.js` — new top-level route,
  `/invoice/:token`, `meta: { skipAuth: true }`, registered as a sibling
  to the existing `/ping` diagnostic route (i.e. genuinely outside
  `AdminLayout`, not nested under it).

**Scope decision:** the page's own numbers are a fresh, simple read of
the sale (not run through `Sale_PDF`'s multi-currency conversion — that
method mixes PDF-specific concerns into one long block with no separable
"just get the data" entry point, and refactoring it was judged riskier
than this modest, explicit duplication). The **Download PDF** button
still produces the fully currency-converted, authoritative PDF via the
unchanged `Sale_PDF`.

**Bugs found and fixed across this delivery:**
1. **Delivered a `routes/api.php` with an accidental dependency on the
   (not-yet-applied) PO toggle build.** Caught and reissued as a
   standalone version built from the correct earlier baseline — flagging
   as a process reminder: when multiple builds are in flight
   simultaneously, each delivery's shared-file edits must be checked
   against exactly which *other* builds the recipient has actually
   applied, not the most recent one produced.
2. **Clipboard copy failed over plain HTTP.**
   `navigator.clipboard.writeText()` needs a secure context (HTTPS, or
   the literal hostname `localhost`) and silently throws on a
   plain-HTTP custom hostname (this environment's own `stocky.test`).
   Fixed with a `copyToClipboard()` helper: modern API first, falls back
   to `execCommand('copy')`, and as a last resort shows the link in a
   dialog to copy by hand.
3. **The public page redirected to login even in a fresh incognito
   window — the real cause was one level below the Vue Router.** The
   entire `/next/*` SPA is served by one catch-all in `routes/web.php`
   (`Route::view('/next/{any?}', 'next')`) wrapped in `auth:web`
   middleware — that runs server-side, before any JavaScript loads, so
   an unauthenticated visitor never received the app at all; the
   client-side router's `skipAuth` meta never got a chance to run.
   Fixed with one new, more specific, unguarded route,
   `Route::view('/next/invoice/{token}', 'next')`, registered *before*
   the auth-gated catch-all group (Laravel matches routes in
   registration order) — scoped to this exact path only; every other
   `/next/*` path is unaffected. **Standing lesson for any future public
   page inside `/next/`:** a client-side router `skipAuth` route meta is
   necessary but not sufficient in this app — the corresponding
   server-side carve-out in `routes/web.php` must be added too, or the
   page is unreachable by the very unauthenticated visitors it's meant
   for.

## House rule adopted this session: plain-English UI text, always

Every new/changed page or component built from this point forward uses
literal English strings for labels, titles, placeholders, and messages —
**not** `$t('Some_Key')`-style translation keys — even where the rest of
this codebase's convention is a translation key. Reasoning: this app's
`$t()` returns the raw key text verbatim when no matching row exists in
the translations table (a documented lesson already in this file, see
"Hard lessons from real incidents" in `docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`),
and every key introduced by a *new* feature is, by definition, not yet in
that table — so a new feature's own key would render as raw text like
`Activity_Log_Report` regardless. Writing the plain-English text directly
sidesteps that entirely and needs no follow-up translation-seeding step.
This is an explicit, standing instruction — apply it to all future work
in this codebase, not just the features listed above.

## Known unresolved issues (confirmed this session, not yet fixed)

**1. Stock-integrity bug — still open, now better understood.**
Pre-existing vendor pattern across Purchases/Transfers/Adjustments/Sales/
Sale Returns/Purchase Returns/Damages: checks whether a `product_warehouse`
row exists and only updates it if so, with no else-branch to create one —
a product's first-ever stock movement in a given warehouse can silently
do nothing. Confirmed still present in the code (both fresh vendor 5.8
and this customized version). **Revised understanding from this
session:** it's rarer in practice than first assumed, because both
"create product" and "create warehouse" already auto-backfill
`product_warehouse` rows for the full product×warehouse grid — the bug
only bites when that backfill was bypassed (bulk import — though the
current `ProductImport.php` turns out to be an empty, non-functional
stub anyway — legacy pre-backfill data, or a variant added after the
fact). **The Movement Ledger (Build H1/H2)'s reconciliation check is now
the tool to go find a real instance on live data** before deciding
whether/how to fix this — a `row_missing: true` badge on a real product
is the bug's exact signature.

**2. `ReportController` — ~45 instances of a related bug, confirmed,
not fixed.** `$perPage = $request->limit;` pattern repeated ~45 times
across report methods — same category of issue as the stock bug in
spirit (an unguarded assumption that repeats across many call sites) but
this one is about report pagination, not stock quantity. Confirmed
present in both fresh vendor 5.8 and this customized version. Not yet
started.

## GitHub repository note

`imran2022/stocky-app` was found set to **Public** visibility (previously
believed Private) during this session — `.gitignore` itself is correctly
configured (excludes `.env`, `storage/*.key`, sessions, logs,
`database.sqlite`), so no secret is known to have been exposed, but
CodeCanyon license terms and business-logic exposure are both reasons to
set it back to Private. **Confirm this was actually done** — it was
flagged and the owner said they'd handle it, but this doc can't confirm
the follow-through happened.

Also found: earlier in the repo's history, `public/js` (the built
frontend bundle) had been committed despite `.gitignore` already
excluding it going forward — a `git rm -r --cached public/js` plus a
fresh commit was recommended to stop tracking it (build output doesn't
belong in version control) and to get GitHub's copy of the repo current
with all of this session's work (Movement Ledger, Activity Log Phase 1/2,
PO toggle, Public Invoice URL). **Confirm this push actually happened** —
same caveat as above.

## Build K.4 — Activity Log Report invisible on fresh install + custom permissions missing from Roles & Permissions

Found from a live fresh-install report (`php artisan migrate:fresh --seed`
run for testing): after seeding, "Activity Log Report" was gone from the
Reports menu for every role, including Owner, and separately, neither
"Activity Log Report" nor "Purchase Orders" could be selected anywhere in
the Roles & Permissions screen when building a new role's permission set.

**Bug 1 — Activity Log Report invisible after a fresh install.**
`2026_09_18_000002_add_activity_log_report_permission.php` creates the
`activity_log_report` permission and grants it to whichever role(s)
currently hold `report_device_management` — but that grant logic runs
*inside the migration itself*. Migrations always run before seeders, so
on `migrate:fresh --seed` the `permission_role` table is still completely
empty at the moment this migration executes (no roles or role-permission
links exist yet — those only get created afterward, by
`RoleSeeder`/`PermissionRoleSeeder`). The permission gets created, but is
granted to zero roles, so it's invisible in the sidebar for everyone,
including a brand-new Owner account.

This is the exact same bug already found and fixed once before, for
`purchase_orders` — that's precisely why `PurchaseOrdersPermissionSeeder`
exists and is explicitly called *after* `PermissionRoleSeeder` in
`DatabaseSeeder.php` (see that seeder's own docblock, which documents the
original discovery). The Activity Log migration was added afterward and
never got the equivalent fix.

**Fix:** added `ActivityLogPermissionSeeder` (mirrors
`PurchaseOrdersPermissionSeeder` exactly — same grant rule, same
idempotent check-before-insert), called in `DatabaseSeeder.php`
immediately after `PurchaseOrdersPermissionSeeder`. Confirmed no other
migration in the codebase creates a `permissions` row with this same
self-contained-grant pattern (`purchase_orders` and `activity_log_report`
are the only two), so this closes the bug class completely for now — see
the standing rule added below for future permissions.

**Bug 2 — custom permissions not selectable in Roles & Permissions, on
any install.** `resources/src/config/permissions.js` is a static,
hand-frozen catalogue — its own header comment says it was "extracted"
once from the legacy Vue2 permission-editor template (239 entries at the
time) — not a live read of the `permissions` database table (which has
since grown to 309 rows). Every permission added after that extraction,
including both of ours (`purchase_orders`, `activity_log_report`), was
simply never added to this file, so the Roles & Permissions screen has no
way to offer them as checkboxes at all — correctly configuring the
backend changes nothing here.

Diffed all 309 canonical DB permission names against the file's 305
entries: only 4 gaps existed. Two were `purchase_orders` and
`activity_log_report` (fixed here — added to the "Purchases" and
"Reports" groups respectively, using the file's existing `v`/`l`/`f`
fallback-label pattern so they render as plain English without needing a
translations-table row, consistent with the plain-English-UI-text house
rule). The other two, `record_view` and `module_settings`, are
pre-existing base items unrelated to this fix and were deliberately left
alone: `record_view` is set per-user directly on `UserForm.vue`, not
meant to be a role-level checkbox; `module_settings` (permission id 125,
well within the original vendor ID range) is a separate, pre-existing
naming inconsistency between its menu gate (`business_modules`/
`setting_system`) and its policy check — unrelated to any of our
customization work, not touched here.

**For the already-seeded live/test database** (no need to
`migrate:fresh` again — this is additive and idempotent): run
```
php artisan db:seed --class=ActivityLogPermissionSeeder
```
then rebuild the frontend (`npm run build`) so the updated
`permissions.js` ships, and the Activity Log Report checkbox will need to
be turned on by hand once for any role that should see it beyond Owner
(Owner gets it automatically via the `report_device_management` rule,
same as before).

**Standing rule going forward (new — add to house rules):** any new
permission, whether added via `PermissionsSeeder.php` or a standalone
migration, must ship with BOTH: (1) if granted via a migration's own
self-contained logic, a matching seeder call placed after
`PermissionRoleSeeder` in `DatabaseSeeder.php` — mirroring
`PurchaseOrdersPermissionSeeder`/`ActivityLogPermissionSeeder` — so a
fresh install ends up in the same state as an already-running site; and
(2) an entry in `resources/src/config/permissions.js`, in the relevant
group, using the `v`/`l`/`f` pattern — or it will never be assignable
from Roles & Permissions no matter how correct the backend is. Skipping
either half has now caused the identical user-visible bug twice.

Verification: every assertion in
`tests/Regression/build_k4_activity_log_permission_and_config_gap.php`
was manually checked against the delivered files with grep/Python (no
PHP CLI in the build environment), including a full diff confirming zero
remaining DB-to-frontend permission gaps outside the two documented,
deliberate exceptions.

**Not resolved by this build:** the user separately recalled a second
report also missing from the menu after the fresh install, but couldn't
remember its name. Checked the strongest candidate, "Zone / Courier
Report" — its permission (`Reports_sales`) is one of the 307 base
permissions confirmed correctly granted to Owner, and its legacy-path
route mapping (`/app/reports/zone_wise_report` → `/reports/zone-wise` in
`MIGRATED_ROUTES`) is registered correctly — found no structural reason
for it to be invisible. Worth the user re-checking the menu after this
fix is applied; if something is still missing, we'll need the actual name
to trace it (a static-analysis diff can't find a bug it isn't pointed
at).

## Build I3 — Activity Log: real reference numbers + full audit-trail coverage (2026-09-19)

**Why:** A deep audit (see `docs/AUDIT_REPORT_2026-09-19.md`) found the
Activity Log Report describing Sale/Purchase entries as "Sale #5" /
"Purchase #12" — the internal database id — instead of the human invoice
number ("SL-104") actually shown everywhere else in the app. Separately,
only 8 of the app's ~19 document/record types were logged at all (Sale,
Purchase, Product, Adjustment, Transfer, User, Customer, Role/Permission)
— Sale Return, Purchase Return, Damage, Quotation, Purchase Order,
Warehouse, Shipment, System Settings, and all four Payment types had no
audit trail whatsoever.

**Root cause of the reference-number bug:** Sale/Purchase describers read
a non-existent `->reference` attribute — the real column is `Ref` — which
is always `null` on an Eloquent model, so the description silently fell
back to the raw `id` every single time. Adjustment/Transfer never
attempted to use `Ref` at all.

**Fix — reference numbers (`app/Providers/ActivityLogServiceProvider.php`):**
every describer for Sale, Purchase, Adjustment, Transfer, and all newly
added modules below now reads `$model->Ref`, falling back to `'#'.$id`
only when `Ref` is genuinely empty (`?:`, not `??`, since an empty string
still needs the fallback). Verified against a real seeded database:
creating a Sale with `Ref = 'SL-9001'` produced the log line
`Sale SL-9001 created`, not `Sale #5 created`.

**Fix — coverage extension.** Nine more record types added, following the
same investigation discipline as Build I1/I2 (read each controller
directly to confirm whether create/update/delete go through an instance
`->save()`/`->update()` — observable via Eloquent model events and the
existing `hookModel()` helper — or a bulk `Model::whereId()->update([...])`
— not observable, needs an explicit `ActivityLogger::log()` call site,
same pattern as the existing Client/User bulk-update handling):

- **Sale Return, Purchase Return, Damage, Quotation, Purchase Order** —
  confirmed fully instance-based (create, update, and delete-via-update)
  → added via `hookModel()`, same as Sale/Purchase/Adjustment/Transfer.
  A Purchase Order's GRN-driven status transitions (`ordered` →
  `partially_received` → `received`) go through `$po->update([...])` in
  `PurchasesController`, so they already show up as ordinary
  "Purchase Order ... updated" entries with a status old/new diff — no
  separate "received" action was needed.
- **Warehouse** — create is instance-based (`hookModel()` covers it), but
  `update()`, `destroy()`, and `delete_by_selection()` all use a bulk
  `Warehouse::whereId()->update([...])` — three explicit log call sites
  added directly in `WarehouseController.php` (update logs an old/new
  diff of the changed fields; both delete paths snapshot the warehouse
  name before the bulk update runs, since it's gone from the row after).
- **Shipment** — create/update are instance-based (`hookModel()` covers
  them), but `destroy()` is the one place in the whole app that calls a
  model's *real* Eloquent `->delete()` (Shipment doesn't use the
  `SoftDeletes` trait, just a plain `deleted_at` cast) — fires Eloquent's
  `deleted` event, not `updated`, so it needed its own explicit
  `Shipment::deleted(...)` listener rather than `hookModel()`'s shared
  `wasChanged('deleted_at')` logic.
- **Payment (Sale/Purchase/Sale Return/Purchase Return)** — all four:
  create (`Model::create([...])`) and update (`$payment->update([...])`)
  are both instance-based → covered by `hookModel()`. Delete is a bulk
  `Model::whereId()->update(['deleted_at'=>...])` in all four controllers
  → one explicit log call site added at each of the four `destroy()`
  methods.
- **System Settings** — the entire settings-save endpoint
  (`SettingsController::update()`) is one large bulk
  `Setting::whereId($id)->update([...])` covering 100+ columns, including
  several backup-credential fields (S3 access/secret key, Google Drive/
  Dropbox access & refresh tokens, Google Calendar client secret) that
  must never be persisted into a log table with a wide admin readership.
  Handled with an explicit log call built by hand from the `$setting`
  instance already loaded (pre-update) at the top of the method, diffed
  against a fresh re-query after the update — every value passed through
  `ActivityLogger::sanitize()`, which now also strips those specific
  credential keys (added to `ActivityLogger`'s `$ignoredDiffKeys`).
  Verified against a real database: setting `backup_s3_secret_key` to a
  test value and checking the resulting `activity_logs.new_values` column
  confirmed the secret never appears in it.

**Changed:**
- `app/Providers/ActivityLogServiceProvider.php` — Ref-based descriptions
  for Sale/Purchase/Adjustment/Transfer; `hookModel()` calls for
  SaleReturn, PurchaseReturn, Damage, Quotation, PurchaseOrder, Warehouse,
  and the four Payment models; explicit `Shipment::deleted()` listener.
- `app/Services/Custom/ActivityLogger.php` — added 8 backup/OAuth
  credential field names to `$ignoredDiffKeys`.
- `app/Http/Controllers/SettingsController.php` — explicit Settings
  change log after the bulk update, secret-safe.
- `app/Http/Controllers/WarehouseController.php` — explicit update/
  destroy/delete_by_selection log calls.
- `app/Http/Controllers/PaymentSalesController.php`,
  `PaymentPurchasesController.php`, `PaymentSaleReturnsController.php`,
  `PaymentPurchaseReturnsController.php` — explicit destroy() log calls.
- `resources/src/pages/reports/ActivityLogReport.vue` — module filter
  dropdown extended with all 9 new modules.
- New: `tests/Regression/build_i3_activity_log_reference_numbers.php`,
  `tests/Regression/build_i3b_activity_log_coverage_extension.php`.

**Verification:** both new regression test files pass; the full existing
23-file regression suite and the project's PHPUnit suite (37 tests) were
re-run afterward with no new failures. Additionally, real runtime
verification against a seeded SQLite database: created one real instance
of every newly-hooked model (SaleReturn, PurchaseReturn, Damage,
Quotation, PurchaseOrder, Shipment, PaymentSale, PaymentPurchase) and
confirmed each produced a correctly-described `activity_logs` row;
directly exercised the Warehouse/Settings explicit update paths and the
Shipment hard-delete path; confirmed a Settings update carrying a test
secret value never leaked that value into the logged `new_values`.

**Still not covered (documented, not built this round — candidates for a
future pass if wanted):** exports/downloads (who printed/exported a
report or invoice), failed/denied action attempts (403s), and delete
paths not capturing a full pre-delete snapshot (currently `deleted`
entries log only the description, not the record's last-known values —
they can still be reconstructed from the record's own `updated` history
in the log, but not in one row). Flagged in the audit report as ideas,
not requested for this build.

## Build L1 — Public Invoice: parity with Sale Detail page (2026-09-19)

**Why:** The public, no-login invoice page (`/invoice/:token`) showed only
Item/Qty/Price/Total and a short totals box — far less than the
authenticated Sale Detail page shows for the same sale (per-line Discount
and Tax, Box Qty, Previous Dues, Net Balance, Discount from Points, IMEI/
batch numbers, pack quantity breakdown). Customers viewing their own
invoice via the shared link were seeing a stripped-down document.

**What changed:**
- `app/Http/Controllers/PublicInvoiceController.php` — `show()` rebuilt to
  compute the same per-line Discount/Tax figures as `SalesController::
  show()` (same DiscountNet/tax_method math), added Box Qty (only emitted
  when the company's `enable_box_qty` setting is on — the same flag the
  authenticated page reads), IMEI number, batch numbers, pack name/
  multiplier, and unit resolution (sale_unit_id, falling back to the
  product's own sale unit). Order-level totals gained Discount from
  Points, Previous Dues, and Net Balance — the last two via a
  `clientPreviousDues()` copied from `SalesController` (that method is
  `private`, not shared; duplicating ~15 lines was judged lower-risk than
  changing its visibility or extracting a shared service for one caller,
  matching this controller's existing documented approach for `show()`
  itself). Customer's own email removed from the response — the page is
  reached via a link the customer already holds, so showing their email
  back to them serves no purpose.
- `resources/src/pages/public/PublicInvoice.vue` — item table gained Box
  (conditional), Discount, and Tax columns, IMEI/batch display, and pack
  quantity breakdown; totals gained Order Tax (unconditional, matching the
  authenticated page), Discount from Points, Previous Dues, and Net
  Balance rows; customer email no longer rendered; company header block
  reordered to Name / Address / VAT/BIN / Phone / Mail / Website, each on
  its own line (previously combined phone+email onto one line and put
  VAT/BIN and website in a different order).
- New: `tests/Regression/build_l1_public_invoice_parity.php`.

**Verification:** the new regression test passes; the full existing
regression suite (now including Build I3/I3b) was re-run with no new
failures. Additionally, real runtime verification against a seeded
SQLite database: created a sale with a 10% order discount, discount-from-
points, a per-line fixed discount, a per-line tax, box_qty = 1.5, and a
second completed/unpaid sale for the same client to produce real previous
dues — confirmed every number in the JSON response (line discount 30,
line tax 21.6, order discount 25, due 150, previous dues 600, net balance
750) matched hand computation; confirmed box_qty comes back `null` in the
payload when `enable_box_qty` is switched off; confirmed the response's
`client` object carries no `email` key at all.

**Not changed (kept off this page on purpose — flagged for the user to
decide, not built without asking):** tracking reference, consignment ID,
sales agent name, zone, and courier — these are internal routing/ops
details shown on the authenticated Sale Detail page but not typically
meant for a customer-facing document; can be added the same way if wanted.

## Build L2 — Previous Dues / Net Balance: independent show/hide toggle (2026-09-19)

**Why:** Previous Dues and Net Balance (the client's outstanding balance
from other sales, and that balance plus this sale's own due) always
printed automatically whenever a client had a balance — on the Sale
Detail page, the Sale PDF, and the new public invoice page — with no way
to turn them off, unlike almost every other line item on these documents.

**What changed:** Reused the app's existing "Invoice PDF" customizer
(Settings → Invoice PDF, backed by `PdfTemplate`) rather than inventing a
new settings mechanism — this feature already had a generic on/off
"Sections" panel wired straight into `sale_pdf.blade.php`; this build just
added two more keys to it: `show_previous_dues`, `show_net_balance`
(both default **on**, matching the previous always-on behavior — nothing
changes for anyone who doesn't touch the new toggles).
- `app/Models/PdfTemplate.php` — two new keys in `DEFAULTS`.
- `app/Http/Controllers/PdfTemplateController.php` — validates them.
- `resources/views/pdf/sale_pdf.blade.php` — the Previous Dues and Net
  Balance rows (both the RTL and LTR layout variants) now each check
  their own flag, independently of each other, in addition to the
  existing "only if greater than zero" condition.
- `app/Http/Controllers/SalesController.php@show`,
  `app/Http/Controllers/PublicInvoiceController.php@show` — both now read
  `PdfTemplate::settingsFor('sale')` and pass the two flags through.
- `resources/src/pages/sales/SaleDetails.vue`,
  `resources/src/pages/public/PublicInvoice.vue` — gate their rows on the
  flags (defaulting to shown if a flag is ever absent from an API
  response, so nothing regresses if an old cached response is replayed).
- `resources/src/pages/settings/InvoicePdfSettings.vue` — two new toggles
  in the Sections panel, shown only for the Sales Invoice doc type
  (quotations/purchase orders have no previous-dues concept), each with a
  short description, plus a live-preview reflection.
- New: `tests/Regression/build_l2_previous_dues_toggle.php`.

**Verification:** real runtime check, in separate PHP process invocations
per case (`PdfTemplate::settingsFor()` caches per-process — a single
script re-reading after an update sees stale data, which is not a real
concern for a normal one-request-per-process PHP-FPM deployment, but does
mean a real check needs fresh processes each time): confirmed
`SalesController::show()`, `PublicInvoiceController::show()`, and the
actual **rendered PDF bytes** (text extracted with `pdftotext`) all agree
for three combinations — both lines on, both off, and Previous Dues on
with Net Balance off. The new regression test and the full existing suite
(including L1) pass.

## Build L3 — Sales Invoice: multiple selectable PDF templates (2026-09-19)

**Why:** Only one A4 invoice layout existed. The user supplied a
ready-made, fully-styled alternate design (a modern layout with a
built-in shipping-label/COD section) and asked for a way to pick between
the two, like the POS receipt's own settings already let you turn
sections on/off — but for the whole layout, not just sections.

**What changed:**
- `app/Models/PdfTemplate.php` — new `layout` key in `DEFAULTS` (default
  `'classic'`, so nothing changes unless you switch it), and a new
  `LAYOUTS` map (`doc_type => [key => label]`) — currently only `sale`
  has a second option, `'modern'`.
- `app/Http/Controllers/PdfTemplateController.php` — validates `layout`
  against the doc type's allowed values; `show()` now also returns the
  available layouts so the settings page can list them.
- New: `resources/views/pdf/sale_pdf_modern.blade.php` — the
  user-supplied "Modern (with Shipping Label)" design, used as-is except
  for one addition: the Build L2 Previous Dues/Net Balance toggle was
  wired in so both layouts stay in sync with that setting. Unlike
  Classic, this layout is self-styled (its own colors/fonts) and does not
  read the Colors/Typography/Layout/Items-table panels of the Invoice PDF
  customizer.
- `app/Http/Controllers/SalesController.php` — added
  `saleInvoiceViewName()`, which resolves the saved `layout` setting to a
  Blade view name (falling back to Classic for any unrecognized value —
  can never 404 from a bad/old setting). All three places that used to
  hardcode `view('pdf.sale_pdf', ...)` — `Sale_PDF()`,
  `Sale_PDF_Inline()`, and the bulk-download `renderSaleInvoiceHtml()`
  helper — now go through this one method, so the chosen template applies
  everywhere the Sales Invoice PDF is produced, including the public
  invoice page's Download button (which delegates to `Sale_PDF()`).
- `resources/src/pages/settings/InvoicePdfSettings.vue` — a Template
  picker appears above the customizer, only when the current doc type has
  more than one layout (today, only Sales Invoice). Choosing a non-Classic
  layout shows a notice that the Colors/Typography/etc. panels don't apply
  to it, and replaces the live preview (which only approximates Classic's
  markup) with a plain "save and check a real PDF" message rather than
  showing a misleading preview.
- New: `tests/Regression/build_l3_multi_template_sale_pdf.php`.

**Verification:** real PDF render test — switched the setting to
`'modern'`, called the actual `Sale_PDF()` controller method, and
confirmed (via `pdftotext` on the real output bytes) the Modern layout's
distinctive shipping-label section ("DELIVERY INFORMATION", "CASH ON
DELIVERY") appears, and that Previous Dues/Net Balance still honor the
Build L2 toggle inside this layout. Switched back to `'classic'` and
confirmed the shipping-label section is gone and Previous Dues/Net
Balance still render correctly (no regression). New regression test and
the full existing suite pass.

**How to add a third template later:** drop a new Blade file in
`resources/views/pdf/`, add its key/label to
`PdfTemplate::LAYOUTS['sale']`, and it appears in the Template picker
automatically — no other changes needed unless the new template needs its
own extra data beyond what `$sale`/`$details`/`$setting`/`$symbol`
already carry (the Modern layout needed none).

## Build L4 — Modern Sale Invoice: bug fix, Box Qty, and matching shipping label (2026-09-19)

**Why:** The user asked for a review of their own uploaded Modern
template (added as-is in Build L3): "did you find any issues, does Box
Qty work here, and can this layout be customized too like Classic?" They
also asked for the standalone Shipping Label PDF to be restyled to match
the invoice's own embedded shipping-label section.

**What changed:**
- `resources/views/pdf/sale_pdf_modern.blade.php`:
  - **Bug fix (real calculation bug):** the template computed the order
    discount as `(float) $sale['discount']` directly. When the discount
    is percent-based (`discount_Method === '1'`), `$sale['discount']`
    holds the raw percent number (e.g. `10` for 10%), not a dollar
    amount — so a 10% discount on a $350 subtotal was shown as a flat
    "$10.00" instead of the correct "$35.00", and the backward-derived
    Subtotal was wrong too. Fixed using the same percent-vs-fixed
    branching the Classic layout already uses (see the code comment in
    the file for the derivation), and the Discount row now shows
    "- 10.00% (USD 35.00)" the same way Classic does.
  - **Box Qty added:** was completely absent. Added a "Box" column,
    shown only when the `enable_box_qty` company setting is on AND at
    least one line item actually has a box quantity — matching the
    dynamic-column approach the template's own author already used for
    the Disc/VAT columns, and the description column still widens to
    fill the space when it's hidden.
  - **Discount from Points added:** was computed (needed for the fixed
    subtotal math) but never shown. Added a "Discount from Points" row,
    shown only when it's greater than zero.
  - **Customization parity with Classic:** the Sections and Text & labels
    panels of the Invoice PDF customizer were always shown for this
    layout too, but only Previous Dues/Net Balance (Build L2) actually
    did anything — the rest of those toggles were silently ignored. Wired
    in the remaining ones: Customer block, Sales Status line, Notes,
    Thank-you line (+ its text override), Footer text override, and the
    Document title override. The Colors/Typography/Layout & logo/Items
    table panels are still Classic-only (self-styled by design), exactly
    as the settings page already tells you.
- `resources/views/pdf/shipping_label.blade.php` — restyled from its
  previous unrelated card design to match the visual language of the
  Modern invoice's own "DELIVERY INFORMATION" section (uppercase
  micro-labels, sender/shipment-ref/receiver blocks, dashed COD-vs-PAID
  badge), so a shop using the Modern invoice gets a matching shipping
  label. Same `$sale`/`$company`/`$symbol` inputs as before — no
  controller change needed.
- New: `tests/Regression/build_l4_modern_invoice_fixes_and_shipping_label.php`.

**Verification:** real PDF render tests against seeded sales — a 10%
percent-discount sale with a $5 points discount, $15 shipping, one line
with a box quantity and one without: confirmed via `pdftotext` that
Subtotal/Discount/Discount from Points/Shipping/GRAND TOTAL are all
internally consistent and the Box column shows correctly per line.
Re-rendered with `enable_box_qty` off (column disappears). Re-rendered a
flat-dollar-discount sale (regression: still shows a plain dollar amount,
no stray percent text). Re-rendered with every Sections toggle switched
off plus a title/thank-you/footer override (all honored correctly) and
with everything back to defaults. Rendered the standalone Shipping Label
for a COD sale and a fully-paid sale (both badge states correct).
Re-rendered Sale Invoice with `layout = 'classic'` throughout to confirm
none of this touched Classic's own (already-correct) behavior. Full
existing regression suite (L1-L3) re-run with no new failures.

**Not covered:** the Colors/Typography/Layout & logo/Items table panels
still don't apply to Modern — it remains self-styled by design, per the
user's own request to use their design as-is.

## Build L5 — Packing List restyled to match the Modern Sale Invoice (2026-09-19)

**Why:** The user liked the Modern Sale Invoice's look and asked for the
Packing List PDF (used by warehouse staff, no prices) to follow the same
visual style.

**What changed:**
- `resources/views/pdf/packing_list.blade.php` — rebuilt using the Modern
  invoice's color palette, header layout (company name/logo on the left,
  big document title on the right), product-table styling, and uppercase
  micro-labels. The Box column is now dynamic like the invoice's: it only
  appears when at least one line item actually has a box quantity, and
  honors the `enable_box_qty` company setting — previously it always
  showed a "Box" column with a dash for every item, regardless of the
  setting.
- `app/Http/Controllers/SalesController.php` — `Sale_Packing_List()` now
  also fetches and passes the company settings row (`setting`) to the
  view, since the old design had no company header at all.
- New: `tests/Regression/build_l5_packing_list_modern_style.php`.

**A real bug found and fixed along the way:** the restyled template (and,
on inspection, Build L4's `shipping_label.blade.php`) opened with a
documentation comment written as a raw HTML `<!-- -->` comment containing
em-dash characters. Unlike a Blade comment, a raw HTML comment is not
stripped at compile time — it reaches the rendered HTML ahead of the
`<meta charset="UTF-8">` tag, and DomPDF's encoding auto-detection got
confused by those early non-ASCII bytes, garbling every em-dash further
down the document (visible as `â??` in the rendered PDF). Fixed in both
files by switching to a Blade comment block, which compiles away
entirely. A second, related mistake was caught while fixing it: the
replacement comment's own explanatory text initially spelled out the
literal Blade comment delimiters as an example, which closed the comment
block early and leaked the rest of the note into the rendered PDF —
fixed by describing them in prose instead.

**Verification:** real PDF render tests — a sale with a box quantity on
one line item and not the other (Box column shows the right value/dash
per line, company header shows, no leaked comment text or mis-encoded
characters); a sale with no box quantities anywhere (Box column and
"Total Boxes" row both absent entirely, not just dashed out). Re-rendered
the Build L4 shipping label after the same comment fix to confirm it
still renders correctly. Full existing regression suite (L1–L4) re-run
with no new failures.

## Build L6 — Packing List: page-margin fix + full header parity (2026-09-19)

**Why:** After Build L5 shipped, the user reported the Packing List
looked cut off at the page edges, and that the header was missing the
company phone/email line their invoice shows.

**What changed:**
- `resources/views/pdf/packing_list.blade.php`:
  - **Root cause fixed:** the file used a plain `@page { margin: 0.4in; }`
    rule for its page margins. The Modern invoice instead sets
    `@page { margin: 0; }` for a PDF download and does the visual margin
    with body padding instead — the pairing this app's actual PDF
    download pipeline is already proven reliable with. Packing List
    always downloads, so it now unconditionally uses that exact same
    pairing rather than a different approach that turned out to render
    content flush against the page edges in production.
  - Added the company **Phone | Email** line to the header, so it now
    shows the same three lines the invoice does (Name, Address,
    Phone | Email) — previously only Name + Address were shown.
  - The Code/SKU column now widens when the Box column is hidden, so
    product codes don't wrap onto two lines unnecessarily.
- New: `tests/Regression/build_l6_packing_list_margin_fix.php`.

**Verification:** real PDF renders converted to PNG (`pdftoppm`) and
visually compared side-by-side against a Modern invoice PDF rendered from
the same sale — margins, header layout and typography now match. Checked
both a sale with box quantities and one without. Full existing
regression suite (L1–L5) re-run with no new failures.
