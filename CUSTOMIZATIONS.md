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

## Build M1 — Payment Terms & Due Dates (Phase A of Customer Ledger plan) (2026-09-19)

**Why:** The user shared a 15-section spec for a full Customer Ledger +
Payment Terms + Invoice-wise Payment Allocation system and asked what
already exists and what's feasible. After a feasibility audit and a
3-phase proposal (A: Payment Terms & Due Dates, B: Real Admin Customer
Ledger, C: Invoice-wise Payment Allocation), the user confirmed:
"Payment Terms & Due Dates cholo eta kori age" — Phase A first. Phases B
and C are proposed future work, not started, and out of scope here.

**What changed — a new 3-level Payment Term hierarchy:**
- Level 1 **System Default** — `settings.default_payment_term_days`
  (0/7/15/30/Custom, default 7).
- Level 2 **Customer Default** — `clients.payment_term_days` (nullable;
  overrides the system default for that customer).
- Level 3 **Invoice Override** — chosen per sale; wins over both when
  present.
- A sale **snapshots** its resolved term (`sales.payment_term_days`) and
  derived due date (`sales.due_date`) at create/edit time — it is never a
  live reference to the current customer/system defaults, so a later
  change to either never silently changes an already-issued invoice's due
  date. Formula: `Invoice Date + Payment Term = Due Date`. A sale is
  **Overdue** when `today > due_date AND outstanding balance > 0`.

**Files touched:**
- New: `database/migrations/2026_09_19_000001_add_payment_terms_and_due_dates.php`
  — adds `settings.default_payment_term_days`, `clients.payment_term_days`,
  `sales.payment_term_days`, `sales.due_date` (indexed).
- New: `app/Support/PaymentTerms.php` — single source of truth for the
  hierarchy resolution (`resolveDays()`), due-date math (`dueDate()`),
  overdue detection (`isOverdue()`), and preset labels (`label()`).
- `app/Models/Setting.php`, `app/Models/Client.php`, `app/Models/Sale.php`
  — new fields added to `$fillable`/`$casts`.
- `app/Support/SaleMetadataRules.php` — validation rule for the
  invoice-level `payment_term_days` override.
- `app/Http/Controllers/SalesController.php` — `store()` and `update()`
  resolve and snapshot the term + due date on every save; `show()`,
  `index()` and `edit()` expose `payment_term_days`, `payment_term_label`,
  `due_date` and `is_overdue`.
- `app/Http/Controllers/ClientController.php` — `store()`/`update()`
  handle the customer-level override (omitted on update = preserved,
  explicit empty = cleared); `clientBrief()` now also returns
  `payment_term_days` so the Sale form can show the customer's default.
- `app/Http/Controllers/SettingsController.php` — `update()` persists
  `default_payment_term_days` (clamped 0–3650); both read-side endpoints
  expose it.
- `resources/src/pages/settings/SystemSettings.vue` — new "Default
  Payment Term" control in the Features tab (Immediate/7/15/30/Custom).
- `resources/src/pages/people/CustomerForm.vue` — new "Payment Term"
  control on the customer form (Use system default/Immediate/7/15/30/
  Custom).
- `resources/src/pages/sales/SaleForm.vue` — new "Payment Term" selector
  and a live **Due Date** preview on the sale create/edit form; selecting
  a customer shows their own default term as the "default" option's
  label; the resolved/overridden term is sent with the sale and reloaded
  correctly when editing an existing sale.
- New: `tests/Regression/build_m1_payment_terms_and_due_dates.php`.

**Verification:** a real, DB-backed end-to-end test that calls
`SalesController`, `ClientController` and `SettingsController` directly
against the project database (not mocked) and covers: system default
alone; customer default overriding system default; invoice override
winning over both; re-resolution on `update()` when the override is
cleared (falls back to the customer default, not the stale old
override); **snapshot stability** — changing the system default
afterwards does not retroactively change an already-created sale's
stored term/due date; overdue detection via both `show()` and `index()`;
Settings read/write round-trip; Client store/update round-trip
(including "omitted preserves, explicit null clears").

**Not yet done (deliberately, per the phased plan):** a Due Date /
Overdue column on the Sales list page and the Sale Detail page (the
backend already returns the data — this is pure frontend display, not
started); surfacing due date on the Sale PDF templates or the Public
Invoice page. Phases B (Real Admin Customer Ledger) and C (Invoice-wise
Payment Allocation) from the original spec are proposed future work only.

## Build M2/M3 — Payment Terms fixes, on/off toggle, Due Date display (2026-09-19)

**Why:** After Build M1 shipped, the user reported that clicking "Custom"
for the Default Payment Term on Settings > Features did nothing visible
(the custom-days input never appeared) — confirmed working on the
Customer form's own field. They also asked whether the whole Payment
Terms feature could be switched on/off, and asked for a Due Date column
on the Sales list (fine if hidden by default) and Sale Detail page, plus
a show/hide toggle for Due Date on the invoice PDF, matching the existing
"Previous Dues" toggle.

**Bug fixed — the "Custom" preset silently snapping back:** all three
"switch to Custom" handlers (`SystemSettings.vue`, `CustomerForm.vue`,
`SaleForm.vue`) reused the CURRENTLY STORED value as the starting point
for the custom-days input. When that value already happened to be one of
the presets (0/7/15/30 — the common case, e.g. a fresh install's default
of 7, or "Immediate" = 0), the getter immediately re-classified it back
to that preset instead of "custom", so the radio silently snapped back
and the custom input never rendered. `CustomerForm.vue`/`SaleForm.vue`
avoided the worst case only by accident (`value || 45` treats `0` as
falsy) but had the exact same latent bug for 7/15/30. Fixed in all three
the same way: never reuse a value that IS one of the presets; fall back
to 45 instead.

**New: master on/off switch — `enable_payment_terms`** (Settings >
Features, default ON since the feature already ships active). When OFF:
`SalesController::store()`/`update()` skip Payment Terms resolution
entirely (both `payment_term_days` and `due_date` stay `null` on the
sale — never silently resolved in the background); the Payment Term
controls disappear from the Customer form, the Sale form, and the Sale
Detail page; the invoice PDF's Due Date line naturally has nothing to
show.

**New: Due Date on the Sales list** — a `defaultHidden: true` column
(same mechanism already used for Currency/Return/Shipping Charge), so it
exists in the column picker but stays off the list until the user turns
it on. Shows an "Overdue" tag when applicable.

**New: Due Date on the Sale Detail page** — a row next to Payment
Status, with the same "Overdue" tag, gated on `enable_payment_terms`.

**New: `show_due_date` PDF toggle** — added to `PdfTemplate::DEFAULTS`
(default `true`) and the Invoice PDF customizer's Sections panel,
mirroring `show_previous_dues` exactly. Wired into both the Classic
(`sale_pdf.blade.php`, both RTL and LTR label layouts) and Modern
(`sale_pdf_modern.blade.php`) invoice templates, and into the Public
Invoice page/API — the Due Date line only prints when the sale actually
has one (feature was on when it was saved) AND the toggle is on; an
overdue invoice's due date prints in red with an "(Overdue)" suffix.
Added `pdf.due_date`/`pdf.overdue` translation keys (en, ar).

**Files touched:**
- `resources/src/pages/settings/SystemSettings.vue` — Custom-preset fix;
  new "Enable Payment Terms & Due Dates" switch; Default Payment Term row
  now hides when the switch is off; submits `enable_payment_terms`.
- `resources/src/pages/people/CustomerForm.vue` — Custom-preset fix;
  fetches and gates on `enable_payment_terms`.
- `resources/src/pages/sales/SaleForm.vue` — Custom-preset fix; Payment
  Term controls + Due Date preview now gate on `enable_payment_terms`.
- `resources/src/pages/sales/SaleDetails.vue` — new Due Date row.
- `resources/src/pages/sales/Sales.vue` — new Due Date column
  (`defaultHidden: true`).
- `resources/src/pages/settings/InvoicePdfSettings.vue` — new
  "Due Date line" toggle + preview row.
- `resources/src/pages/public/PublicInvoice.vue` — Due Date row.
- New: `database/migrations/2026_09_19_000002_add_enable_payment_terms_toggle.php`.
- `app/Models/Setting.php`, `app/Models/PdfTemplate.php`.
- `app/Http/Controllers/SettingsController.php`,
  `app/Http/Controllers/SalesController.php`,
  `app/Http/Controllers/PublicInvoiceController.php`.
- `resources/views/pdf/sale_pdf.blade.php`,
  `resources/views/pdf/sale_pdf_modern.blade.php`.
- `resources/lang/en/pdf.php`, `resources/lang/ar/pdf.php`.
- New: `tests/Regression/build_m2_payment_terms_fixes_and_due_date_display.cjs`,
  `tests/Regression/build_m3_due_date_pdf_and_toggle_backend.php`.

**Verification:** the Custom-preset fix was verified by extracting and
directly EXECUTING the real shipped setter code from all three .vue
files (not a re-implementation of the logic) for every starting preset
(0/7/15/30), confirming none of them snap back anymore. The
`enable_payment_terms` toggle and the Due Date PDF line were verified
with a real, DB-backed test that renders the actual invoice HTML
(`SalesController::Sale_PDF_Inline` — the same template the downloadable
PDF uses) in a fresh PHP process per settings change (so `PdfTemplate`'s
per-process settings cache reflects each change exactly the way a real
new HTTP request would), confirming the Due Date + Overdue line appears,
hides when the PDF toggle is off, and never appears at all for a sale
that has no due date. Full existing regression suite (L1–L6, M1) re-run
with no new failures.

## Build M4 — Customer Statement (admin) (2026-09-19)

**What was asked:** an admin-side "Customer Statement" page — the same
unified, running-balance ledger (Date / Type / Ref / Description / Debit
/ Credit / Balance, with Opening/Closing balance cards) the client
portal already shows the customer — reachable from a new "Customer
Statement" menu item, plus a "View Statement" button on the Customer
Details page (after "Pay Due"), downloadable as PDF (styled like the
Modern Sale Invoice) or Excel, and a fix for the Customer Details page
not showing the customer's address.

**Better-plan note (asked for, and applied):** the app already had
THREE different things in this area, easy to confuse with each other:
1. The client portal's own statement (`PortalStatementController`) —
   the correct unified ledger format, but only the customer could see it.
2. The existing admin "Customer Ledger" page/PDF (`CustomerLedger.vue`,
   `ClientController::export()`, `pdf/customer_ledger.blade.php`) — a
   *different* design (KPI tiles + four tabbed lists of everything,
   unfiltered). Left exactly as-is; nothing about it changed.
3. What was actually being asked for: the portal's ledger *format*,
   made available to admins, as a *separate, additional* page — not a
   replacement for #2.

Rather than write the 7-source ledger-building query a third time (sales
+ payments + opening-balance payments + returns + refunds + service jobs
+ service payments — already duplicated once between the portal and the
existing "Customer Ledger" PDF), that logic was extracted out of
`PortalStatementController::index()` into a single new service,
`App\Services\ClientStatementService::build()`. The portal was refactored
to call it (same response shape, verified byte-for-byte in the test
below); the new admin endpoints call the exact same service. This means
the admin's new Statement page and the customer's own portal statement
can never show different numbers for the same customer — they are
provably the same calculation, not two copies that started identical and
will eventually drift (the risk this engagement flagged earlier about
`clientPreviousDues()`).

**New backend:**
- `App\Services\ClientStatementService` — the shared ledger builder
  (moved out of the portal controller, logic unchanged).
- `App\Http\Controllers\ClientStatementController` — three admin,
  `auth:api`-protected endpoints, all gated the same way
  `clients/{id}/brief` already is (`authorizeForUser(..., 'view', Client::class)`):
  - `GET clients/{id}/statement` — same JSON shape as the portal.
  - `GET clients/{id}/statement/pdf` — server-rendered PDF, Modern-invoice
    styled (`resources/views/pdf/customer_statement_modern.blade.php`;
    same slate/blue palette and header layout as
    `sale_pdf_modern.blade.php`, RTL-shaped the same way the existing
    "Customer Ledger" PDF export already is).
  - `GET clients/{id}/statement/excel` — server-rendered `.xlsx` via
    `App\Exports\ClientStatementExport` (Maatwebsite/Laravel-Excel,
    already a dependency — used elsewhere for `StockExport`), with a
    header block (customer, period, opening/closing balance) above the
    same seven columns the screen and PDF show.
- `PortalStatementController::index()` — refactored to call the shared
  service instead of building the ledger inline; response shape
  unchanged (one small addition: `client.id` is now included, which the
  existing portal page does not read).
- New routes in `routes/api.php`, next to the existing
  `clients/{id}/brief` route.

**New frontend:**
- `resources/src/pages/people/CustomerStatement.vue` — new page: hero
  banner (matches `CustomerLedger.vue`'s style), 3 KPI cards (Opening /
  Total Debit / Closing Balance), a date-range filter, the ledger table,
  and Download PDF / Download Excel buttons.
- New route `customers/:id/statement` (same `Customers_view` permission
  as Details/Ledger).
- `Customers.vue` — new "Customer Statement" row-action menu item,
  alongside (not replacing) the existing "Customer Ledger" item.
- `CustomerDetails.vue`:
  - new "View Statement" button in the page header, right after "Pay Due".
  - **Address fix**: the page was already receiving the client's
    `adresse` field from `GET clients/{id}` (confirmed in the test
    below) — it just wasn't in the template. Added as a new
    Descriptions row next to Phone.

**Files touched:**
- New: `app/Services/ClientStatementService.php`,
  `app/Http/Controllers/ClientStatementController.php`,
  `app/Exports/ClientStatementExport.php`,
  `resources/views/pdf/customer_statement_modern.blade.php`,
  `resources/src/pages/people/CustomerStatement.vue`.
- `app/Http/Controllers/Api/Portal/PortalStatementController.php` —
  refactored to use the shared service.
- `routes/api.php` — 3 new routes.
- `resources/src/router/index.js` — new route.
- `resources/src/pages/people/Customers.vue` — new menu item.
- `resources/src/pages/people/CustomerDetails.vue` — "View Statement"
  button, Address field.
- `resources/lang/en/messages.php` — new keys (`Customer_Statement`,
  `View_Statement`, `Closing_Balance`, `Debit`, `Credit`, `Type`,
  `From_Date`, `To_Date`, `Download_Excel`, and a few more — English
  only; other locales fall back to English for these new strings, same
  as any other locale gap in this app).
- New: `tests/Regression/build_m4_customer_statement.php`.

**Verification:** a real, DB-backed test creates a customer with an
opening balance, a completed sale, and a partial payment, then: (1)
calls `ClientStatementService::build()` directly and checks the entries
list, ordering, and the running balance math (500 opening + 300 invoice
− 100 payment = 700 closing); (2) calls the portal controller and the
new admin controller for the *same* customer and asserts their
`entries` and `closing_balance` are byte-identical — proving the
shared-service refactor didn't change portal behavior and that admin
and portal can't drift; (3) renders the real
`customer_statement_modern.blade.php` template with real data and
checks it shows the customer name, "ACCOUNT STATEMENT", the correct
closing balance, and the address; (4) exercises
`ClientStatementExport`'s header/row mapping directly; (5) confirms
`ClientController::show()` already returns `adresse`. Existing
regression suite spot-checked (M1, M2, M3, L1, L4) with no new
failures; DB confirmed back to its 7-client/16-sale baseline after the
test run (test cleans up after itself; a first run that failed on a
missing `user_id` on the payment fixture left one orphan client, fixed
in the test and cleaned up by hand before the final passing run).

## Build M4.1 — Customer Statement fixes/polish (2026-09-19)

Follow-up to Build M4, from feedback after seeing the live page:

1. **Untranslated labels ("Customer_Statement", "Download_Excel", etc.)**
   — root cause: this app's admin frontend does NOT read
   `resources/lang/*/messages.php` (that's the Laravel/Blade side only,
   used correctly for the PDF templates). The Vue admin's `$t()` is
   served by `GET /api/translations/{locale}`, backed by a `translations`
   DB table that's seeded from `database/seeders/translations/{locale}.php`
   via `TranslationSeeder`. Build M4 mistakenly added the new label keys
   to `resources/lang/en/messages.php` (harmless but useless for the
   frontend — reverted). The actual fix: added the missing keys
   (`Customer_Statement`, `View_Statement`, `Closing_Balance`,
   `From_Date`, `To_Date`, `Download_Excel`,
   `No_transactions_in_this_period`, `Failed_to_load_statement`,
   `Opening_Balance_Payment`, `Sale_Return`, `Service_Payment`) to
   `database/seeders/translations/en.php`. Several keys the page already
   used (`Ref`, `Debit`, `Credit`, `Type`, `Opening_Balance`, `Apply`,
   `Reset`, `Back`, `Description`, `Download_PDF`, `Total_Credit`, …)
   already existed and needed no change — only genuinely new labels
   were missing. **Apply step added:** re-run
   `php artisan db:seed --class=Database\Seeders\TranslationSeeder`
   after deploying this build (safe to re-run — it upserts by
   locale+key and explicitly preserves any translation the client has
   customized through the Translations UI).
2. **Hero section** now shows phone and address too (not just email),
   matching what the existing "Customer Ledger" page's hero shows.
3. **4th KPI card**: added "Total Credit" (total paid/credited across
   the period) alongside Opening Balance, Total Debit, Closing Balance.
   Reused the already-existing `Total_Credit` translation key (from the
   Accounting module) rather than adding a new one.
4. **Closing Balance now also shown boldly below the table** (both on
   the screen and, as a highlighted total row, at the bottom of the
   Excel export) — not just in the KPI card and the header meta block.
5. **PDF template**: removed the customer code and email from the
   customer block, per feedback — only name, address, and phone remain.

**Files touched (in addition to Build M4's):**
- `app/Services/ClientStatementService.php` — `client` array now
  includes `phone`, `adresse`, `city`, `country`, `code`.
- `app/Exports/ClientStatementExport.php` — new `WithEvents`/
  `AfterSheet` bold "Closing Balance" total row after the data.
- `resources/views/pdf/customer_statement_modern.blade.php` — removed
  code/email from the customer block.
- `resources/src/pages/people/CustomerStatement.vue` — hero phone/
  address, 4th KPI card, bold closing-balance line under the table.
- `database/seeders/translations/en.php` — new keys (see above).
- `resources/lang/en/messages.php` — reverted (Build M4's addition
  there was a mistake; see point 1).
- `tests/Regression/build_m4_customer_statement.php` — extended: PDF
  no longer shows code/email; the REAL generated `.xlsx` file (not
  just the export class's in-memory row mapping) is loaded back with
  PhpSpreadsheet and checked for the bold Closing Balance total row.

**Verification:** re-ran the extended `build_m4_customer_statement.php`
— all 6 scenarios pass, including loading the actual generated `.xlsx`
binary back with PhpSpreadsheet to confirm the total row's label, bold
style, and figure. Seeded the new translation keys into this sandbox's
`translations` table and confirmed all resolve to the intended English
text via the same case-insensitive lookup logic the frontend uses (no
browser available in this sandbox to screenshot, so verified at the
data layer instead). DB confirmed back to its 7-client/16-sale baseline
with no leftover temp files after the run.

## Build M5 — Company header (VAT/BIN, Website) on "Modern"-family PDFs + shipping section removed from invoice (2026-09-19)

**What was asked:** the Modern Sale Invoice's top-left header only showed
company name/address/phone/email — no VAT/BIN number, no website — even
though the Classic invoice (and Purchase, Quotation, Return PDFs, etc.)
already showed both since Build K1. Wanted the same treatment applied to
"other PDF documents" too. Also asked to remove the embedded shipping-
label section from the bottom of the Modern Sale Invoice.

**Root cause:** Build K1 (VAT/BIN + Website) only touched the *Classic*
company "From" box design. The separate "Modern" family of templates —
built later (L3–L6, M4), which use a plain top-left header instead of
that boxed design — never got the same fields added.

**Fix — new header format** (address, then one line per field, matching
what the user showed):
```
<Company Address>
VAT/BIN: <vat_number>      (only when set)
Phone: <CompanyPhone>
Mail: <email>
Website: <website>          (only when set)
```
Applied to all four "Modern"-family PDFs that share this header design:
- `resources/views/pdf/sale_pdf_modern.blade.php` (the Modern Sale Invoice).
- `resources/views/pdf/packing_list.blade.php`.
- `resources/views/pdf/customer_statement_modern.blade.php` (Build M4).
- `resources/views/pdf/shipping_label.blade.php` — already had VAT/BIN
  (just Phone, no Mail/Website); added Mail and Website, reordered to
  match the new standard order.

The Classic-family PDFs (Sale, Purchase, Quotation, Returns, Service
Job, etc.) already showed VAT/BIN and Website via Build K1's "From" box
— left untouched, nothing to fix there.

**Shipping section removed from the Modern Sale Invoice:** the invoice
template had an entire second "DELIVERY INFORMATION" shipping-label
design embedded at the bottom (duplicate of the standalone
`shipping_label.blade.php`, which still exists and is downloaded
separately from the Sales list). Removed the HTML block and its CSS
(`.shipping-wrapper`, `.cut-line`, `.hub-label-box`, `.hub-cell`,
`.hub-title-tag`) entirely — the invoice now ends after the thank-you/
footer block. Nothing referenced these classes elsewhere (no PDF
customizer toggle was wired to it), so this is a clean removal.

**Files touched:**
- `resources/views/pdf/sale_pdf_modern.blade.php`,
  `resources/views/pdf/packing_list.blade.php`,
  `resources/views/pdf/customer_statement_modern.blade.php`,
  `resources/views/pdf/shipping_label.blade.php`.
- New: `tests/Regression/build_m5_company_header_and_shipping_removal.php`.

**Verification:** a real, DB-backed test renders all four templates
with real Setting data (this sandbox's VAT number and website already
match the user's own example exactly) and confirms each header shows
"VAT/BIN: …", "Phone: …", "Mail: …", "Website: …" in order, and that
the Modern Sale Invoice's rendered HTML no longer contains "DELIVERY
INFORMATION" or any of the removed CSS class names. Re-ran L3–L6 and M3
regression gates plus M4 — all still pass, confirming the header/CSS
changes didn't disturb anything else in these shared templates.

---

## Build M6 — Delivery Info (Warehouse / Tracking Ref / Zone / Courier) on the Sale Invoice PDF (2026-09-19)

**Request:** the Sale Detail page's header card already shows Warehouse,
Tracking Ref, Zone and Courier (see the red-boxed area in the user's
screenshot) — the user asked whether the same four fields could also
print on the downloadable/printable Sale Invoice PDF, and how.

**What shipped:** a new "Delivery Info" block on the Sale Invoice PDF,
on BOTH layouts (Modern and Classic), showing only the fields that are
actually set on that sale:
- **Warehouse** — always set (required on every sale).
- **Tracking Ref**, **Zone**, **Courier** — optional; each line is
  simply left out when the sale doesn't have one, so a sale with none
  of the three still shows a clean "Warehouse: …" line and nothing
  else.

Gated by a new PDF customizer toggle, **Settings → Invoice PDF →
Sections → "Delivery Info (Warehouse / Tracking Ref / Zone /
Courier)"** (`show_delivery_info`, default **on**) — same place as the
existing Previous Dues / Net Balance / Due Date toggles, sale-only
(no effect on Quotation/Purchase PDFs, same as those three).

**Where the block sits:**
- Modern layout: a light bordered box between the Customer/Date-&-
  Status row and the items table.
- Classic layout: same position, styled to match Classic's existing
  bordered-box language (grey header line + light background), with
  its own translation keys so it can be translated like the rest of
  the Classic PDF.

**Files touched:**
- `app/Models/PdfTemplate.php` — new `show_delivery_info` default
  (`true`).
- `app/Http/Controllers/SalesController.php` — `Sale_PDF()`,
  `Sale_PDF_Inline()`, and `renderSaleInvoiceHtml()` (used by the bulk
  "Print Invoices" action) each now eager-load `warehouse`, `zone`,
  `courier` and add `$sale['warehouse']`, `$sale['tracking_ref']`,
  `$sale['zone_name']`, `$sale['courier_name']` — same field names
  already used by `show()` for the Sale Detail page, so both pull from
  the exact same source fields on the `sales` table
  (`tracking_ref`, `zone_id`, `courier_id`) and their relations.
- `resources/views/pdf/sale_pdf_modern.blade.php`,
  `resources/views/pdf/sale_pdf.blade.php` — new Delivery Info block.
- `resources/lang/en/pdf.php` — new keys: `delivery_info`,
  `tracking_ref`, `zone`, `courier` (`warehouse` already existed).
- `resources/src/pages/settings/InvoicePdfSettings.vue` — new
  `show_delivery_info` toggle in the Sections panel (sale-only) + a
  matching line in the Classic layout's live preview.
- New: `tests/Regression/build_m6_invoice_delivery_info.php`.

**Verification:** a real, DB-backed test creates a sale with a real
Warehouse, SaleZone and SaleCourier plus a Tracking Ref, and:
1. Renders it on the Modern layout — confirms all four fields print.
2. Renders it on the Classic layout — confirms the same, translated.
3. Creates a second sale with only its (required) Warehouse set —
   confirms only "Warehouse:" prints, no empty Tracking
   Ref/Zone/Courier labels.
4. Turns `show_delivery_info` off — confirms the whole block
   disappears even though the sale has all four fields.
Re-ran L3–L6, M3, M4 and M5 regression gates — all still pass.

## Build M7 — Real-time Sales Counter bug fixes + Dashboard "Today's sales by hour" / "Sales by Warehouse" (2026-09-19)

**Request:** on the Real-time Sales Counter page (Sales menu), the
"Today's sales by hour" chart never updated — it always showed
everything stuck at 00h — even though the table below it (Recent
Sales) had the correct timestamps; and that same Recent Sales table's
"Reference" column was always blank. The user also asked to bring the
same "Today's sales by hour" chart, plus a new "Sales by Warehouse"
breakdown, onto the main Dashboard.

**Root causes found (both pre-existing bugs, not something a prior
build introduced):**
1. **Hourly chart stuck at 00h.** `sales.date` is a DATE-only column
   (no time-of-day) and `sales.time` is a *separate* TIME column, but
   the hourly-breakdown query grouped sales by `HOUR(sales.date)` —
   `HOUR()` on a bare DATE value is always `0` in MySQL, so every sale
   landed in the midnight bucket regardless of when it actually
   happened. The Recent Sales table below it builds its displayed
   timestamp from `date` **and** `time` together, which is why that
   table looked correct while the chart above it didn't. Fixed to
   derive the hour from `sales.time` instead, via a new
   driver-portable `hourExpression()` helper (works the same way in
   the live MySQL app and in this sandbox's SQLite tests).
2. **Reference column blank.** The backend's `recent_sales` payload
   returned the field as lowercase `'ref'`, but the Vue table's column
   definition reads `dataIndex: 'Ref'` (capitalized, matching every
   other invoice-reference field in this app) — a silent key-name
   mismatch, not a missing/empty column. Fixed the backend to return
   `'Ref'`.

**What shipped:**
- Both bugs above fixed in `real_time_sales_counter_data()`.
- Two new Dashboard panels, matching what the user asked to see,
  added to the main Dashboard page (new row between "Stock Alert /
  Top Selling" and "Recent Sales"):
  - **"Today's sales by hour"** — a smooth gradient area chart (with
    hover markers, matching the styling of the Payment Sent/Received
    chart already on this Dashboard), always today, same
    hourly data source as the Real-time Sales Counter's chart (now
    fixed).
  - **"Sales by Warehouse"** — a small table (Name / Total Invoice /
    Amount) respecting the Dashboard's own date-range filter
    (defaults to the last 7 days when no range is picked, same as the
    rest of the Dashboard).
- Two new `DashboardController` methods backing these panels:
  `HourlySalesToday()` and `SalesByWarehouse()`.

**Note on the "Default dashboard widget order" setting (Dashboard
Settings page):** while investigating where to add these two new
panels, we found that this existing setting (which lets you drag-
reorder dashboard sections) is not actually wired up anywhere in
`Dashboard.vue` — it saves and loads, but nothing on the Dashboard
reads it, so re-ordering there currently has no visible effect. This
is a pre-existing gap in the vendor's own code, unrelated to anything
this build changed, and the user didn't ask for it to be fixed, so it
was left as-is. The two new panels were added as fixed-position
sections (same pattern as the rest of the Dashboard's existing
sections) rather than hooked into that non-functional reorder system.
If reordering the Dashboard is something you'd like working properly,
that would be a separate, larger fix — let us know.

**Files touched:**
- `app/Http/Controllers/DashboardController.php` — new
  `hourExpression()` helper (driver-portable: SQLite vs MySQL); fixed
  `real_time_sales_counter_data()`'s hourly grouping and its
  `recent_sales` key name; fixed a related date-range bug (see below);
  new `HourlySalesToday()` and `SalesByWarehouse()` methods; both
  wired into `dashboard_data()`'s JSON response as
  `hourly_sales_today` and `sales_by_warehouse`.
- `database/seeders/translations/en.php` — new `Sales_by_Warehouse`
  translation key.
- `resources/src/pages/Dashboard.vue` — new "Today's sales by hour" /
  "Sales by Warehouse" row, new chart/table computed properties, demo
  data extended, `load()` extended to read the two new response keys.
- New: `tests/Regression/build_m7_dashboard_hourly_warehouse_and_realtime_fixes.php`.

**Secondary date-range bug found and fixed along the way:** the
Real-time Sales Counter's "today" and "yesterday" filters compared
`sales.date` (DATE-only) against full Carbon **datetime** bounds
(`startOfDay()`/`endOfDay()`). This happens to work in MySQL (it
widens the comparison automatically) but is not portable — worth
knowing if this query is ever touched again. Switched to plain
date-string bounds (`->toDateString()`), which is equivalent and
correct on any DB driver. Applied the same safe pattern in the two new
`HourlySalesToday()`/`SalesByWarehouse()` methods.

**Verification:** a real, DB-backed test creates two real sales (via
the actual `SalesController::store()` flow, on two different real
warehouses) with their `time` column forced to known hours (02:15 and
14:40), then confirms against the real rendered data:
1. The Real-time Sales Counter's hourly chart data shows +1 at hour 2
   and +1 at hour 14 — and confirms hour 0 (midnight) did **not**
   absorb them (the original bug).
2. The Recent Sales entries carry a non-empty `'Ref'` key matching the
   sale's real reference, and the old lowercase `'ref'` key is gone.
3. The Dashboard's `hourly_sales_today` shows the same +1/+1 at hours
   2 and 14.
4. The Dashboard's `sales_by_warehouse` shows +1 invoice on each of
   the two warehouses used.
Re-ran L3–L6, M3, M4, M5 and M6 regression gates — all still pass.

## Build M8 — Invoice Receivables Report (2026-09-19)

**Request:** a new, dedicated report — one row per invoice (Sale) —
showing Original Invoice Amount, Sales Return Amount, Net Invoice
Amount, Paid Amount, Remaining Receivable, Due Date, Overdue Days and
Payment Status, with summary cards and Date/Customer/Status/"Show
Outstanding Only" filters, so management/accounting/collection staff
can see at a glance what's fully paid, partially paid, due, or overdue
— per the user's own written spec.

**What shipped:** a new report page, **Reports → Invoice Receivables
Report**, one row per completed Sale:
- **Invoice Total** — `sales.GrandTotal`.
- **Sales Return Amount** — sum of `sale_returns.GrandTotal` for that
  sale, **only** rows with `statut = 'received'` (a still-pending
  return hasn't actually reduced what's owed yet — same rule already
  established for Return Rate in Build D2 and reused as-is by
  `ClientStatementService` for the Customer Statement).
- **Net Invoice Amount** = Invoice Total − Return Amount.
- **Paid Amount** — `sales.paid_amount` (the same denormalized field
  every other Sales report already reads).
- **Remaining Receivable** = Net Invoice Amount − Paid Amount, floored
  at 0 via the existing `SaleDocumentMath::outstanding()` helper.
- **Due Date** / **Overdue Days** — reuses the existing Payment Terms
  system (`app/Support/PaymentTerms.php`, Build M1) rather than
  reimplementing due-date logic; `sales.due_date` is already
  snapshotted per-invoice at create/edit time, so this report never
  re-resolves the hierarchy itself. Overdue Days is a new day-count
  calculation (the existing `PaymentTerms::isOverdue()` is boolean
  only) added alongside it.
- **Payment Status** — a new, **derived** 4-state value (Paid /
  Partial / Due / Overdue). The existing `sales.payment_statut` column
  is only 3-valued (paid/partial/unpaid) and knows nothing about due
  dates, so this report computes its own status per row:
  `remaining <= 0` → Paid; `remaining > 0` and overdue → Overdue;
  `remaining > 0` and something's been paid → Partial; otherwise →
  Due.

**Summary cards:** Total Invoice, Total Return, Net Invoice, Total
Paid, Total Remaining, Total Overdue — computed over the whole
filtered set (not just the page on screen), same convention as every
other report's KPI tiles in this app.

**Filters:** Date From/To, Customer, Status (All/Paid/Partial/Due/
Overdue), and a "Show Outstanding Only" checkbox (hides fully-paid
invoices) — exactly the filter set from the user's spec, no more.

**Files touched:**
- `app/Http/Controllers/ReportController.php` — new
  `Report_InvoiceReceivables()` method.
- `routes/api.php` — new `report/invoice_receivables` route.
- `database/migrations/2026_09_19_000001_add_invoice_receivables_report_permission.php`
  (NEW) — adds the `invoice_receivables_report` permission, auto-granted
  to whichever role(s) currently hold `Reports_sales` (same
  reserved-id-migration pattern already used for `purchase_orders` and
  `activity_log_report`).
- `database/seeders/InvoiceReceivablesPermissionSeeder.php` (NEW) +
  `database/seeders/DatabaseSeeder.php` — the matching fresh-install
  seeder call, registered *after* `PermissionRoleSeeder`, per the
  standing rule from Build K.4 (a migration's own grant logic finds
  zero roles on a fresh `migrate:fresh --seed`, since migrations run
  before seeders).
- `app/Policies/SalePolicy.php` — new `invoice_receivables_report()`
  method. **A real gotcha caught before shipping**: this app's
  authorization requires an *explicit PHP method per permission* on
  the relevant Policy class — a `permissions` table row alone is not
  enough (documented once already for `SettingPolicy` in Build I1).
  Without this method, every request came back 403 even though the
  permission was correctly granted to the role; confirmed via the
  regression test below, which failed with exactly that
  `AuthorizationException` on the first run, before this method was
  added.
- `resources/src/pages/reports/InvoiceReceivablesReport.vue` (NEW) —
  built from the same `ReportPage`/`DataTable`/`useCrudTable` pattern
  as `SalesReport.vue`.
- `resources/src/lib/statusColors.js` — new `receivableStatusColor()`
  helper (the existing `payStatusColor()` only knows the 3-state
  paid/partial/unpaid vocabulary, not Due/Overdue).
- `resources/src/router/index.js` — new lazy route
  `reports/invoice-receivables`.
- `resources/src/config/menu.js` — new sidebar entry under Reports,
  plus the matching `MIGRATED_ROUTES` mapping and permission added to
  the Reports group's own permission list (so the group stays visible
  to roles that only hold this one report permission).
- `resources/src/config/permissions.js` — new entry so the permission
  is selectable from Roles & Permissions.
- `database/seeders/translations/en.php` — new
  `Invoice_Receivables_Report` key (used by the sidebar/permissions
  label, which both route through `$t()`; all other on-page text
  follows the standing "plain-English UI text" rule since it's brand
  new and wasn't seeded anywhere else).
- New: `tests/Regression/build_m8_invoice_receivables_report.php`.

**Verification:** a real, DB-backed test creates 5 real sales (via the
actual `SalesController::store()` flow) on one dedicated test
customer, force-sets `paid_amount`/`due_date` and creates real
`SaleReturn` rows (one `received`, one `pending`) to hit all 4 status
states plus the pending-return-exclusion rule, then calls
`Report_InvoiceReceivables()` directly (scoped to that one customer,
so it can't be polluted by the sandbox's other fixture sales) and
asserts every computed field — Net Invoice, Remaining, Overdue Days,
Payment Status — against hand-worked expected values, plus the
summary-card totals and the Status/"Show Outstanding Only" filters.
Re-ran L1–L6, A, A.1, B, C, D1, D2 and M1, M3–M7 regression gates —
all still pass.

---

## Build N1a — Security Hardening (Phase 0 / Critical findings from third-party audit)

**Why:** The user shared a third-party ("ChatGPT") deep security/financial
audit of a packaged export of this app ("STOP-SHIP" verdict). Each
Critical finding was independently re-verified against THIS codebase
(not just taken on faith) before fixing — every one of the 5 Criticals
turned out to be real. This build fixes all 5. It does not attempt the
audit's separate High-severity list (overpayment capping, stock-row
DB locking everywhere, float→decimal columns on Purchase Orders, etc.)
or extend the C-01/C-02 fixes beyond the Sales module — see "Known gaps"
below for exactly what's left and why.

### C-01 — Server trusted client-submitted invoice totals

**The problem:** `SalesController::store()`/`update()` saved
`GrandTotal`, `TaxNet`, and every line's price/subtotal exactly as
submitted by the request, with no server-side consistency check
whatsoever. The audit demonstrated submitting a line with
`Unit_price=100` but `subtotal=1`, and a `GrandTotal=987,654.321`
completely unrelated to the actual line items — both were saved
unchanged.

**The fix — and, importantly, what it deliberately does NOT do:**
`app/Support/SaleTotalsGuard.php` now checks, before anything is saved,
that every line's discount%/tax% are within a sane 0–100 range, that
each line's submitted subtotal is arithmetically consistent with its
own quantity/price/discount/tax (a generous, rounding-tolerant band —
not an exact single formula, to allow for tax-inclusive vs -exclusive
handling), and that the header `GrandTotal` exactly equals the sum of
(now-validated) line totals minus the header discount plus shipping.
Any violation returns HTTP 422 with a plain message instead of saving.

This does **not** lock `Unit_price` to the product's catalog price, and
does **not** forbid per-line discounts. Staff typing a negotiated
price/discount per line is this app's existing, intended behavior (no
"override price" permission gates it anywhere) — this fix only rejects
a total that doesn't mathematically follow from the quantity/price/
discount/tax the *same* request also submitted. Locking prices to
catalog would be a real change to how the business works and needs its
own explicit decision — it was **not** bundled into this security fix.

### C-02 — A completed sale could silently skip inventory deduction

**The problem:** Every stock-mutating call site in `SalesController`
looked up the `product_warehouse` row with `->first()` and only acted
`if ($product_warehouse)` — if no row existed yet for that product in
that warehouse (e.g. a product just added, never stocked there before),
the condition was silently false: no row was created, no quantity was
deducted, and the sale still completed normally, as if nothing were
wrong.

**The fix:** `app/Support/StockMutator::lockOrCreate()` always returns a
row — creating one at `qte = 0` first if necessary, and row-locking it
(`lockForUpdate()`) for the rest of the transaction (this also closes
part of the audit's H-02 concurrency finding for these specific call
sites). All 8 stock-mutation sites in `SalesController` (create,
edit-restore-old-line, edit-deduct-new-line, delete-restore) now go
through it. Where the code can't safely compute a quantity at all (the
line's unit couldn't be resolved), the whole sale is now aborted with a
clear error instead of silently doing nothing.

### C-03 — Unrestricted file upload (`.php` into the public webroot)

**The problem:** Sale/Purchase/Purchase-Order/Expense document uploads
validated only `'required|file|max:10240'` — no extension or MIME
check at all — and stored the file under `public/images/..._documents`
using the client's own filename. A `.php` file uploaded this way landed
directly in the public webroot.

**The fix:** `app/Support/SafeDocumentUpload.php` adds an allow-list
(pdf, jpg, jpeg, png, gif, webp, doc, docx, xls, xlsx, csv, txt, zip)
enforced by Laravel's `mimes:` rule (checks both the extension AND the
file's actual detected content — a `.php` file renamed to `.pdf` still
fails), and the on-disk filename is now built ONLY from the validated
extension, never from the client's original filename — so a
`invoice.pdf.php` double-extension trick can't land a `.php` file on
disk either. Applied to all four upload endpoints (Sale, Purchase,
Purchase Order, Expense).

### C-04 — Backup design (public path, predictable name, delete-before-verify, CLI password)

**The problem:** `database:backup` wrote to
`storage/app/public/backup/backup-YYYY-MM-DD.sql` — a location that
becomes web-downloadable if `php artisan storage:link` has ever been
run, with a predictable filename — and deleted **every existing
backup** before even attempting the new dump, so a failed run left zero
recovery points. It also passed the DB password as a `--password=...`
command-line argument (readable by other local users via `ps`).

**The fix:**
- Backups now write to `storage/app/backups` (private — never under
  `storage/app/public`, so `storage:link` can never expose it). Any
  backups still sitting at the old public path are moved into the new
  location automatically the first time the backup command runs.
- Old backups are pruned only **after** a new backup is confirmed
  non-empty on disk, and the last 14 are kept (previously: only 1, and
  it was deleted before the new one even started).
- The DB password is now written to a short-lived, mode-0600
  `--defaults-extra-file` that `mysqldump` reads directly and which is
  deleted immediately after — never a command-line argument.
- `BackupController` (list/generate/delete), `SystemHealthController`
  ("last backup" dashboard widget), and the legacy `AutoUpdateController`
  backup/restore flow were all updated to the new path so nothing that
  reads "where are backups" was left pointing at the old, now-unused
  location.

### C-05 — Invoice/PDF/print routes had no authentication

**The problem:** Routes like `sale_pdf/{id}`, `purchase_pdf/{id}`,
`transfer_pdf/{id}`, `payment_sale_pdf/{id}`, shipping labels, packing
lists, etc. (23 routes total) sat **outside** any `auth:api` middleware
group in `routes/api.php`, and most of their controller methods
performed no authorization check at all — an unauthenticated request
could view any invoice/PO/payment receipt by guessing its numeric ID.

**The fix:** All 23 routes are now inside a
`Route::middleware(['auth:api', 'Is_Active'])->group(...)` block, and
every one of the 20 controller methods that lacked it now calls the
matching model's `view` policy (`Sale`, `Purchase`, `Quotation`,
`SaleReturn`, `PurchaseReturn`, `PaymentSale`, `PaymentPurchase`,
`PaymentSaleReturns`, `PaymentPurchaseReturns`, `Transfer`,
`Adjustment`, `Damage` — `Booking`/`ServiceJob`'s PDF methods already
had this check). The separate, intentionally-public,
token-based `public/invoice/{token}` route (unguessable token, not a
sequential ID) is untouched — that one is meant to be shareable.

**Files touched:**
- New: `app/Support/SaleTotalsGuard.php`, `app/Support/StockMutator.php`,
  `app/Support/SafeDocumentUpload.php`.
- `app/Http/Controllers/SalesController.php` — C-01 guard wired into
  `store()`/`update()`; all `product_warehouse` lookups replaced with
  `StockMutator::lockOrCreate()`; `view`-policy check added to
  `Sale_PDF`, `Sale_Shipping_Label`, `Sale_Packing_List`,
  `Sale_PDF_Bulk`, `Sale_Shipping_Label_Bulk`, `Sale_PDF_Inline`,
  `Print_Invoice_POS`, `Direct_Network_Print_POS`; upload validation
  hardened in `uploadDocuments()`.
- `app/Http/Controllers/PurchasesController.php`,
  `PurchaseOrderController.php`, `ExpensesController.php` — upload
  validation hardened.
- `app/Http/Controllers/QuotationsController.php`,
  `SalesReturnController.php`, `PurchasesReturnController.php`,
  `PaymentPurchasesController.php`, `PaymentSaleReturnsController.php`,
  `PaymentPurchaseReturnsController.php`, `PaymentSalesController.php`,
  `TransferController.php`, `AdjustmentController.php`,
  `DamageController.php` — `view`-policy check added to the relevant
  PDF/print method.
- `app/Console/Commands/DatabaseBackUp.php` — new private backup path,
  safe retention, CLI-password fix, legacy-path migration.
- `app/Http/Controllers/BackupController.php`,
  `SystemHealthController.php`, `AutoUpdateController.php` — updated to
  the new backup path.
- `app/Services/Updater/UpdaterPaths.php` — excludes the new backup
  path from the updater's own application-file backup ZIP.
- `resources/src/pages/settings/UpdateSettings.vue` — one line of
  documentation text updated to the new path.
- `routes/api.php` — 23 document/PDF/print routes moved inside
  `auth:api` + `Is_Active`.
- New: `tests/Regression/build_n1_security_hardening.php`.

**Verification:** a real, DB-backed test (a) submits the audit's exact
tampered-total shape (price 100/subtotal 1, GrandTotal 987,654.321,
TaxNet 777.777) through the real `SalesController::store()` and
confirms HTTP 422 + no Sale row created, AND separately confirms a
normal sale with a genuine 10% line discount and 5% tax is still
accepted (so the new guard doesn't break real checkouts); (b) creates a
brand-new warehouse with zero stock rows, completes a sale against it
through the real controller, and confirms a `product_warehouse` row now
exists with the correct deducted quantity (previously: no row, ever);
(c) validates a `.php` and a double-extension `invoice.pdf.php` file
both fail the new upload rule while a genuine `.pdf` still passes; (d)
confirms the backup directory is no longer under `storage/app/public`,
that a file left at the old path gets migrated, and that retention
keeps the newest N and never deletes the just-written file; (e)
confirms `sale_pdf/{id}` and `transfer_pdf/{id}` now require
`auth:api` while the public token-based invoice route remains
unauthenticated by design. Full cumulative regression suite (all 41
prior build test files) re-run afterward — no new failures (3
pre-existing failures — `build_e1_pos_recent`, `build_po_grn`,
`build_po_phase1_3` — are unrelated compiled-frontend-asset/UI-label
checks that already fail on a pristine checkout with no `npm run
build` run yet, confirmed by running them against the last-committed
state before this build's changes).

**Known gaps — intentionally out of scope for this build (documented,
not silently skipped):**
- The audit's High-severity findings (H-01 overpayment cap, H-02 DB
  row-locking/unique constraints beyond the Sales call sites this build
  touched, H-06 `Ref` unique constraint, H-07 Purchase Order FLOAT
  columns, H-08 npm dependency upgrades, H-09 audit-trail/soft-delete
  history) are **not** part of this build.
- C-01 (total-consistency guard) and C-02 (stock lock-or-create) were
  applied to the **Sales** module only — the exact flow the audit's
  runtime tests reproduced against. The same two bug patterns exist in
  `PurchasesController` (11 more `product_warehouse` call sites) and
  likely `TransferController`/`AdjustmentController`/`DamageController`
  as well. Recommended as a follow-up build (N2) rather than rushed
  into this one, given how much of the app's pricing/stock logic each
  module touches.

---

## Build N1b — Readable Product + Variant Display Names (2026-09-19)

**Request:** replace the visually awkward `[Grey]Apple Macbook 2026`
format with `Apple Macbook 2026 - Variant: Grey` in item lists, POS,
Sale Create, suggestions, reports, documents, and every other user-facing
surface.

**Root cause:** product/variant labels were assembled independently in dozens
of controller and service paths. Most used `[Variant]Product`, while a few
used `Product - Variant` or returned only the variant name. There was no shared
display contract.

**Fix:** added `App\Support\ProductDisplayName` as the single formatter. It
keeps product and variant database fields separate and changes presentation
only. Simple products remain unchanged; blank variants add no suffix; compound
variant names such as `Blue / XL` remain intact.

**Coverage:** Products/suggestions, POS, Sales, Purchases, Purchase Orders,
Quotations, both Return flows, Transfers, Adjustments, Damage, Reports,
public/portal invoices, online orders, kitchen orders, and Xero descriptions.

**Files:** see `BUILD_N1_PRODUCT_VARIANT_DISPLAY_NAMES.md` and the release
overlay manifest. New tests:

- `tests/Unit/ProductDisplayNameTest.php`
- `tests/Regression/build_n1_product_variant_display_name.php`

**Database/frontend impact:** no migration, no stored-name rewrite, and no
frontend component change. Production assets were rebuilt as a regression
check because this is delivered with the complete customized application.

---

## Build N2 — High-Severity Findings from the Same Third-Party Audit (2026-09-20)

**Why:** Build N1a fixed the audit's 5 Critical findings, scoped to the
Sales module only, and explicitly documented the High-severity findings
and the Purchases/Transfer/Adjustment/Damage versions of C-01/C-02 as
follow-up work ("Build N2"). This build does that follow-up. Each finding
below was independently re-verified against the actual (post-N1a/N1b)
codebase via a dedicated verification pass before any fix was written —
all four were confirmed real.

### H-01 — Overpayment/change accounting inflated paid_amount past GrandTotal

**The problem:** Neither Sale nor Purchase payment creation capped the
amount applied to `paid_amount` at the document's own `GrandTotal`. A
customer/supplier tendering more than due — completely normal in retail,
e.g. handing over a larger note and getting change back — had the FULL
tendered amount added to `paid_amount`; `change` was recorded on the
payment row but never subtracted. The audit's own reproduction: a
1,044.06 sale tendered 1,245.06 with 201.00 change recorded ended up with
`paid_amount = 1,245.06` — 201.00 more than the invoice was ever worth.
Same shape on Purchases.

**The fix:** `app/Support/PaymentCapper.php` — `capPaid($grandTotal,
$rawPaidAmount)` returns `max(0, min($grandTotal, $rawPaidAmount))`.
Deliberately minimal: it does not change what a payment *means*, does not
touch `change` (still recorded exactly as before), and does not touch
account/cash-drawer balance logic (a separate concern from what the
document's own `paid_amount` is allowed to say). Wired into:
- `SalesController`'s inline payment-on-checkout block.
- `PaymentSalesController::store()/update()/destroy()`.
- `PaymentPurchasesController::store()/update()/destroy()`.

Also fixed in passing: `PaymentPurchasesController::getNumberOrder()`
crashed (`Undefined array key 1`) on any legacy/malformed `Ref` with no
`_` separator — now falls back to the default prefix, matching the
tolerant pattern already used by Sales/Purchases/Adjustment's own
`getNumberOrder()`.

### C-02/H-02 (extension) — stock lock-or-create beyond Sales

**The problem:** Build N1a fixed the "`product_warehouse` row silently
never created, so a stock mutation silently no-ops" bug (C-02) and added
row-locking against concurrent double-updates (part of H-02) — but only
in `SalesController`. The identical pattern was confirmed still present,
unfixed, in `PurchasesController` (8 call sites), `AdjustmentController`
(the add/subtract × single/combo × store/update/destroy matrix — 4
call-site *groups*, dozens of literal occurrences), and
`DamageController` (4 call-site groups). `TransferController` had its own
partial fix (`resolveProductWarehouseRow()`, from an earlier build) that
already auto-created a missing row, but never row-locked it (the H-02
half was still open) — and had a separate latent bug: when called with a
NULL `product_variant_id` it did not filter with
`whereNull('product_variant_id')`, so on a product that also has
variant-specific stock rows it could return the WRONG row.

**The fix:** all four controllers now go through
`app/Support/StockMutator::lockOrCreate()` — the same hardened helper
Sales already used — instead of their own copy-pasted
`->first()`+`if($product_warehouse)` blocks:
- `PurchasesController` — all 8 stock-mutation sites (store ×2 flows,
  update reverse/apply, destroy ×2 reversal paths) replaced directly.
- `AdjustmentController`/`DamageController` — the repeated add/subtract ×
  single/combo blocks were extracted into a small private
  `applyStockDelta()` helper (delegating to `StockMutator`) to both fix
  the bug and remove ~500 lines of copy-pasted logic; `DamageController`'s
  helper preserves its existing "never go below zero" floor via a
  `$clampFloor` flag. This also fixed a latent bug in
  `AdjustmentController::update()`'s "apply new lines" block, which
  checked the stale leftover `$value['product_variant_id']` from an
  earlier, already-finished loop instead of the actual row being
  processed (`$product_detail['product_variant_id']`) — a variant-typed
  line being edited could silently fall into the wrong branch.
- `TransferController::resolveProductWarehouseRow()` now delegates to
  `StockMutator::lockOrCreate()` directly (same method signature, so
  every one of its 36 existing call sites is unaffected) — gaining both
  the row lock and the NULL-variant filtering fix.

### H-06 — `sales.Ref`/`purchases.Ref` had no database-level uniqueness

**The problem:** Both `SalesController::getNumberOrder()` and
`PurchasesController::getNumberOrder()` generated the "next" reference
number purely at the application level (read the last Ref, increment) —
with no DB constraint and no locking read. Two near-simultaneous requests
could both compute and save the same Ref.

**The fix:** migration
`2026_09_20_000001_add_unique_ref_to_sales_and_purchases` adds a real
unique index on `sales.Ref` and `purchases.Ref` — deliberately a *plain*
unique index, not composite with `deleted_at` (unlike the earlier
`payment_sales` fix): both `getNumberOrder()` methods already compute
"last Ref" from every row regardless of `deleted_at`, so this app's own
numbering logic never intended to reuse a deleted document's Ref, and a
composite index would reopen the exact gap the audit itself pointed out
for `payment_sales` (MySQL allows unlimited NULLs in a unique index, and
`deleted_at` is NULL for every active row). `app/Support/UniqueRefGenerator.php`
wraps the generate+save step: on a unique-constraint collision it
regenerates a fresh Ref and retries (up to 5 attempts) instead of ever
surfacing the collision to the user. Wired into `SalesController::store()`
(both flows) and `PurchasesController::store()` (both flows).

### H-07 — Purchase Order columns used FLOAT for money/quantity

**The problem:** `purchase_orders`/`purchase_order_details` (created
2026-09-13) used `FLOAT` for `tax_rate`, `TaxNet`, `discount`, `shipping`,
`GrandTotal`, `cost`, `quantity`, `received_quantity`, `total` — the exact
problem the sibling `purchases`/`purchase_details` tables had already been
fixed for, 7 months earlier
(`2026_02_11_000002_convert_purchases_and_purchase_details_float_to_decimal`).
Binary floating point cannot exactly represent most decimal currency
values, so repeated arithmetic can silently drift by fractions of a cent.

**The fix:** migration
`2026_09_20_000002_convert_purchase_orders_float_to_decimal` applies the
identical `DECIMAL(15,2)`/`DECIMAL(12,3)` precision already used on
`purchases`/`purchase_details`, so a PO's totals and a GRN's totals for
the same items are computed with identical precision.

**Verification:** `tests/Regression/build_n2_high_severity_fixes.php` —
real, DB-backed: (a) confirms the DB itself now rejects a duplicate
`sales.Ref` insert, and that `UniqueRefGenerator::save()` retries past a
collision rather than raising it (H-06); (b) confirms
`purchase_orders`/`purchase_order_details` columns are DECIMAL and that a
fractional PO detail cost/quantity round-trips exactly through save/reload
(H-07); (c) submits the audit's exact overpayment numbers through the
real `PaymentSalesController`/`PaymentPurchasesController` and confirms
`paid_amount` is capped at `GrandTotal`, never above it (H-01); (d)
creates a fresh warehouse with zero stock rows and confirms
Purchases/Adjustment(add+subtract)/Damage(floor-at-zero)/Transfer all
create-or-lock the stock row correctly instead of silently skipping the
mutation (C-02/H-02 extension). `tests/Regression/build_k2_transfer_stock_integrity.php`
(an existing Transfer test) was updated to check for the new
`StockMutator`-delegating implementation instead of the old inline shape
it used to grep for. Full cumulative regression suite (44 files) re-run
afterward — no new failures (the same 3 pre-existing
compiled-frontend-asset failures as Build N1a, unrelated to this build).

**Known gaps — intentionally out of scope for this build:**
- H-03 (attachment access control on upload/download/delete endpoints),
  H-04 (release artifact hygiene — cached app key, machine-specific
  paths), H-05 (regression-suite shell-exit-code masking a fatal DB
  error), H-08 (npm dependency upgrades), and H-09 (soft-delete audit
  trail depth) are **not** part of this build.
- The same C-02/H-02 stock-mutation pattern also exists, unfixed, in
  `PurchasesReturnController` and `SalesReturnController` — found during
  this build's own verification pass but outside the audit's explicit
  High-severity list and this build's stated scope. Recommended as a
  further follow-up if/when those flows matter for this deployment.

## Build N3 — User-Reported Bugs After Build N2 (2026-09-20)

Seven items reported by the client (with screenshots) right after applying
Build N2. None of these came from the third-party audit — all are direct
usage feedback.

### 1. Public invoice "Download PDF" started returning 403 (regression)

**Root cause:** Build N1a's C-05 security fix added a `'view'` policy
check inside `SalesController::Sale_PDF()`. That method is not only hit
via the authenticated `sale_pdf/{id}` route — `PublicInvoiceController::
pdf()` (the public, unguessable-token download used by the "Download PDF"
button on the customer-facing invoice page) also calls `Sale_PDF()`
**in-process**, so the same policy check silently started running there
too, with no logged-in user to satisfy it.

**Fix:** `PublicInvoiceController::pdf()` now sets a
`publicly_authorized_via_token` attribute on the request after its own
token lookup (`findByToken()`) has already confirmed the sale belongs to
this link. `Sale_PDF()` checks that attribute and skips the redundant
policy check only when it's present — every other caller (including the
direct `sale_pdf/{id}` route) is authorized exactly as before.

**A general pattern worth remembering:** any time one controller calls
another controller's method in-process (not over HTTP), an authorization
check later added to the callee can silently start applying to callers
that were never meant to need it. Worth checking for at any other
same-process controller-to-controller call in this app.

### 2. Public invoice page styling

`resources/src/pages/public/PublicInvoice.vue`: the "Billed to" label is
now bold or (`font-weight: 700`, darker color); the item table header now
has a light background with rounded outer corners; each line item now has
a running serial number (`#`) column.

### 3. Sale unit price — direct inline edit

`resources/src/pages/sales/SaleForm.vue`'s line table "Net Unit Price"
column was static text. It's now an `a-input-number` (mirroring
`PurchaseForm.vue`'s existing "Net Cost" column pattern exactly): editing
it sets the line's `Unit_price` (converted through the multi-currency
doc/base helpers, same as Purchase) and recomputes discount/tax/subtotal
via the existing `recomputeLine()`. The below-minimum-price warning
already reads the recomputed `Net_price` reactively, so it keeps working
unchanged.

### 4. Company address block: missing fields, wrong order

The authenticated Sale/Purchase/Quotation/Sale-Return/Purchase-Return
Detail pages showed the company box as Name → Phone → Email → Address,
with no VAT/BIN or Website — unlike every PDF (Builds K1/M5) and the
public invoice page (Build L1), which all use Name / Address / VAT-BIN /
Phone / Mail / Website. `company.vat_number` and `company.website` were
already present in every one of these pages' own API response (the
`Setting` model was never missing the columns) — they just weren't
rendered. Reordered and added on all 5 pages to match the standard.

### 5. Packing List PDF missing customer info + order meta

`resources/views/pdf/packing_list.blade.php` showed only the customer
name and date. Now also shows customer address/phone (when set) and a
Warehouse / Order Status / Payment Status block, matching what the Sale
Invoice PDF already shows. `SalesController::Sale_Packing_List()` now
eager-loads the `warehouse` relation and passes `client_phone`,
`client_adr`, `warehouse`, `statut`, `payment_status` into the view.

### 6. Menu/report labels not human-readable

`database/seeders/translations/en.php` had no entry at all for two keys
`menu.js` references (`Zone_Courier_Report`, `PriceVarianceReport`) and
was missing a third (`StockLookup`). Because `TranslationSeeder` only
overwrites a key it actually has an entry for (and never touches a row
flagged `is_customized` — see its own doc comment), whatever was already
sitting in the live `translations` table for these — including any typo —
could never be corrected by re-seeding. All three keys added with correct
English text; verified by actually running `TranslationSeeder` against a
real database and confirming the stored values. **If a translation still
looks wrong after applying this build and re-seeding**, check Settings →
Translations for that exact key — it likely has `is_customized = 1` (was
hand-edited through the Translations UI at some point) and needs fixing
there directly; re-seeding intentionally will not touch it.

### 7. Invoice PDF template label

`PdfTemplate::LAYOUTS['sale']['modern']` renamed from
`"Modern (with Shipping Label)"` to plain `"Modern"` — the "(with
Shipping Label)" part was already stale before this fix, since Build M5
removed the embedded shipping-label section from that layout. This is the
single source the System Settings dropdown is built from
(`PdfTemplateController`), so no frontend change was needed.

**Files touched:**
- `app/Http/Controllers/SalesController.php` (`Sale_PDF()` flag check,
  `Sale_Packing_List()` new fields).
- `app/Http/Controllers/PublicInvoiceController.php` (`pdf()` sets the
  flag).
- `app/Models/PdfTemplate.php` (label rename).
- `database/seeders/translations/en.php` (3 new/fixed keys).
- `resources/views/pdf/packing_list.blade.php`.
- `resources/src/pages/public/PublicInvoice.vue`.
- `resources/src/pages/sales/SaleForm.vue`.
- `resources/src/pages/sales/SaleDetails.vue`,
  `resources/src/pages/purchases/PurchaseDetails.vue`,
  `resources/src/pages/quotations/QuotationDetails.vue`,
  `resources/src/pages/sale_return/SaleReturnDetails.vue`,
  `resources/src/pages/purchase_return/PurchaseReturnDetails.vue`.
- New: `tests/Regression/build_n3_bug_fixes.php`.

**Verification:** real, DB-backed test (`build_n3_bug_fixes.php`) —
(a) calls `PublicInvoiceController::pdf()` with no authenticated user and
a real token and confirms a 200 with real `%PDF` content, while the
direct `Sale_PDF()` call with no token-flag and no user still throws an
authorization exception (C-05 still intact); (b) renders the real
`packing_list` Blade view with a real Sale/Client/Warehouse and confirms
the new fields actually appear in the output; (c) runs the real
`TranslationSeeder` against the real database and confirms the 3 keys
land with the correct values; (d) static source-contract checks for the
Vue-only changes (styling, inline price edit, company-box field/order)
where there's no server-side data to assert against, following the same
pattern used since Build L1. Full cumulative regression suite (46 files)
re-run afterward — no new failures (same 3 pre-existing
compiled-frontend-asset failures, unrelated to this build).

## Build N5 — POS receipt toggles + 2 new layouts, POS SKU search fix, Dashboard chart/profit clarity, Customer Statement fix (2026-09-20)

Client sent a 6-item batch request with screenshots. All 6 items:

### 1. POS Receipt: Show VAT/BIN + Show Website toggles

The receipt-content toggles already had "Show Address" / "Show Email" /
etc. Added the same pattern for two more fields:

- New migration `2026_09_20_000005_add_vat_bin_website_toggles_to_pos_settings_table.php`
  adds `pos_settings.show_vat_bin` (default **1**, to preserve existing
  ZATCA-compliant receipts the instant the migration runs — see the
  regression note below) and `pos_settings.show_website` (default 0).
- `PosSetting` model: both added to `$fillable` + `$casts`.
- `SettingsController::update_pos_settings()`: both accepted with the
  same tri-state boolean coercion as every other toggle.
- `PosReceipt.vue` (Settings → POS Receipt, the live preview + toggle
  list) and `PosPage.vue` (the real printed receipt): both new toggles
  wired into **every layout** (1 through 7, including the two new ones
  added in item 2 below), each gated the same way the existing toggles
  are (`v-show="pos_settings.show_vat_bin"` / `show_website`).
- **Bug caught during this build:** Layout 4's VAT/BIN line was
  previously *unconditional* (`v-if="setting.vat_number"`, not tied to
  any toggle at all). Wiring it to the new `show_vat_bin` toggle could
  have silently hidden VAT/BIN on every existing ZATCA receipt the
  moment the migration ran (a plain new boolean column normally defaults
  to 0/off) — caught before shipping and fixed by defaulting the column
  to `1`, with a comment in the migration explaining why.
- Company address / phone / email that the client set in Company Settings
  now flow onto the POS receipt exactly the same way the existing
  Address/Email toggles already did — no separate wiring was needed for
  that part, since the underlying data binding already existed.

**Regression test:** `tests/Regression/build_n5_receipt_vat_website_toggles.php`.

### 2. Two new POS receipt layouts (Layout 6 "Roomy" + Layout 7 "Simplified Tax Invoice, English")

- **Layout 6 — Roomy:** same visual design as the existing Layout 5
  ("Minimal") — logo, store name, contact block, a clean meta/items/
  totals stack with light dividers — but sized like Layout 1-4 instead
  of Layout 5's compact spacing: wider (330px vs 240px), larger type
  (12px vs 11px base), taller line-height (1.6 vs 1.4), and roomier
  padding throughout. Reuses the exact same markup and toggle gating as
  Layout 5 (`pos_settings.show_*`), just under `.receipt-layout-6` /
  `.minimal-*` CSS selectors with the roomier values.
- **Layout 7 — Simplified Tax Invoice (English):** same structure as the
  existing Layout 4 (Bilingual Arabic+English "ZATCA-style Simplified Tax
  Invoice") but with **all Arabic text and the 3-column bilingual layout
  removed** — English-only, 2-column rows throughout (label / value
  instead of label / value / Arabic-label). Dropping the Arabic column
  frees up horizontal room on narrow 58mm/80mm receipt printers, which is
  what avoids the crop/wrap issue the client specifically asked to avoid.
- Both new layouts are selectable from the same `receipt_layout` dropdown
  (Settings → POS Receipt and Settings → POS Settings) and validated
  server-side (`SettingsController`, now accepts 1-7, was 1-5).
- Both layouts follow the exact same v-else-if chain pattern as every
  existing layout, in both `PosReceipt.vue` (live preview) and
  `PosPage.vue` (real receipt), gated by the exact same
  `pos_settings.show_*` toggles as every other layout (including the new
  VAT/BIN and Website toggles from item 1).
- Print CSS added to `public/css/pos_print.css` for both new layouts,
  mirroring the existing Layout 4/5 print rules (border neutralization,
  grayscale-safe colors, `@media print` overrides).
- **Bug caught during this build:** the `currentReceiptLayout` computed
  property (in both `PosReceipt.vue` and `PosPage.vue`) had a hardcoded
  `[1, 2, 3, 4, 5].includes(n) ? n : 1` allowlist that would have
  silently forced Layout 6/7 selections back to Layout 1 — found and
  fixed to `[1, 2, 3, 4, 5, 6, 7]` before shipping.
- **Bug caught during this build:** Layout 7's QR-code blocks were
  initially given their own ref names (`zatcaQrcodePos2`/`invoiceUrlQr2`)
  to avoid an apparent naming clash with Layout 4/5/6 — but the existing
  QR-rendering JS methods look up the exact ref names `zatcaQrcodePos`/
  `invoiceUrlQr` regardless of which layout is active (this is safe
  because only one v-else-if branch ever renders at a time). Using
  different ref names for Layout 7 would have left its QR codes
  permanently blank. Found and fixed to reuse the shared ref names,
  matching every other layout.

**Regression test:** `tests/Regression/build_n5_receipt_layouts_6_7.php`.

### 3. Fix: POS search by variable product's main SKU returned nothing

Root cause: `PosController::GetProductsByParametre()` (the product search
endpoint used by the POS screen) never exposed the *parent* product's own
SKU on a variant row — only the variant's own code. Sales > Create Sale's
equivalent search (`ProductsController::Products_by_Warehouse()`) already
had this exact fix (`$item['product_code'] = ...`), which is why searching
by the main SKU worked there but not in POS. Ported the identical fix to
`PosController`, and extended POS's own JS-side `search()` filter
(`PosPage.vue`) to also match against the new `product_code` field
(both the exact-match and fuzzy-match branches).

**Regression test:** `tests/Regression/build_n5_pos_main_sku_search.php`
(creates a real variable product via `ProductsController::store()`, then
calls the real `PosController::GetProductsByParametre()` and confirms the
main SKU now appears on every variant row alongside the variant's own
code).

### 4 & 5. Dashboard: modern chart + Profit card clarity

**Sales vs Purchases chart (item 4):** client asked for something more
modern than the plain bar chart. Client picked "smooth gradient area
chart" when asked to choose a direction. Changed `salesChart` from a bar
chart to a smooth gradient area chart (matching the existing style
already used by the Payment Methods chart on the same page) — new
stroke/fill/markers/tooltip config, `curve: 'smooth'`, gradient fill.
**Important implementation detail:** vue3-apexcharts' `<apexchart>`
component takes its render-time chart type from the `type` **prop** on
the template tag, not from `options.chart.type` in the JS config — both
had to be changed together (`type="area"` on the template, plus
`options.chart.type: 'area'`), or the chart would silently keep
rendering as a bar chart despite the options object being fully updated.
This was caught and fixed during the build, not left as a residual bug.

**Profit card (item 5):** investigated the "seems wrong" report in
depth — the underlying formula (`completed sales total − FIFO COGS −
expenses + service job profit`, using the same
`App\Traits\CalculatesCogsAndAverageCost` trait the P&L report already
relies on) is **correct accrual accounting**, proven with a real,
isolated-warehouse regression scenario matching the screenshot's shape
exactly. What was actually confusing: the **Sales** stat card includes
*all* sales regardless of status (including drafts/holds), while the
**Profit** card only counts *completed* sales — so the two numbers can
look inconsistent even though both are individually correct. Rather than
"fixing" a calculation that wasn't broken, added an info-icon tooltip to
both the Sales and Profit stat cards explaining exactly what each one
counts, so the discrepancy is self-explanatory going forward.

**Regression test:** `tests/Regression/build_n5_dashboard_profit_verification.php`
(dedicated fresh warehouse for isolation; proves the Profit formula
against a hand-computed scenario; proves Sales includes non-completed
sales while Profit does not; proves Purchases isn't subtracted directly
from Profit; static checks for the tooltips and the chart-type fix).

### 6. Customer Statement: pagination vs fixed date range

Client asked which is better for a page that gets very long for
customers with lots of history. **Recommendation given: a default date
range, not pagination** — pagination on a running-balance ledger needs
real engineering (every page needs to know the balance carried in from
every prior page, which either means computing the whole history anyway
or a more complex incremental-balance API), while a date range is simply
safer and simpler to ship correctly, and the page already had a
date-filter UI sitting mostly unused.

**Bug found and fixed while implementing this (would have made the
date-range approach unsafe to ship):** `App\Services\ClientStatementService::build()`
excluded every row dated before `fromDate` from its 6 source queries
(sales, payments, sale returns, refunds, service jobs, service job
payments) when a date filter was applied — but never folded the net
effect of those excluded rows into the **Opening Balance**, which was
still only the client's static `opening_balance` column plus
all-time (unfiltered) opening-balance payments. Concretely: a client
with real activity before the filter's start date would show a wrong
Opening Balance (and therefore a wrong running Balance for every entry
after it) the moment any date filter was applied — this bug already
existed and would affect anyone using the existing filter UI even
before this build's default-range change. Fixed by adding a
`$carryForward` calculation (only computed when `$fromDate` is set) that
sums the net effect of all six source types for dates before the
cutoff and folds it into the Opening Balance.

`CustomerStatement.vue` now defaults to a 90-day range on first load
(same underlying date-filter UI, just pre-filled instead of empty), with
a relabeled "Show All Time" button to clear it and see full history.

**Regression test:** `tests/Regression/build_n5_customer_statement_range_fix.php`
(real client/sale/payment fixtures proving: before this fix, a
date-filtered Opening Balance would have been wrong by the full net
effect of the pre-cutoff activity; after the fix it's correct; the
all-time closing balance is byte-for-byte unaffected — this fix only
corrects what's shown for a filtered range, not the true final number).

**Full cumulative regression suite after all 6 items (52 files, up from
49 before this build): no new failures** — the same 3 pre-existing,
unrelated compiled-frontend-asset failures remain
(`build_e1_pos_recent.php`, `build_po_grn.php`, `build_po_phase1_3.php`).

**Migration:** `php artisan migrate` (new `pos_settings.show_vat_bin` /
`show_website` columns only — additive, safe `down()` provided).

---

## Consolidated September 20–23 handoff (Builds O1–O9)

This section records every customization added after Build N5 during the
September 20–23 working session. It is intentionally appended; none of the
earlier history above was rewritten or removed. At final handoff, GitHub
`origin/master` was still at `8ca9b05db76be225f2c4a10f4b960bde860011c7`
(Build N5), while local `master` contained 30 additional commits through
`1aadfe2ff9e20e45dfcfe2651911eb6c60e54dfe`, plus the final dashboard,
product-insight, and Movement History working-tree changes below. The final
bundle converts that remaining tree into an isolated review commit; it does
not push or rewrite the owner's branch.

### Build O1 — Client Portal modernization and document parity

**Goal:** modernize the existing Vue client portal without changing business
logic, make it responsive on desktop/tablet/mobile, retain optional dark mode,
and align portal downloads with the modern document family.

**UI:**

- `resources/src/portal/portal.css` now provides compact cards, responsive
  spacing, readable typography, light/dark surfaces, and mobile table overflow.
- `PortalLayout.vue` keeps language, theme, full-screen and responsive
  navigation controls accessible.
- Shared `DataTable.vue` supports horizontally scrollable invoice/payment
  tables on mobile rather than space-heavy card stacks.
- Dashboard hero/KPIs were compacted while retaining Total Paid and a prominent
  due figure. Invoice Detail received separate desktop/mobile treatment:
  reference/status grouped on the left, duplicate Paid badges/arrows removed,
  item totals aligned, and redundant navigation removed.
- Invoices, Payments, Statement, Quotations, Contracts, Appointments and
  request/detail flows use the same responsive style without endpoint or
  calculation changes. English/Arabic/French/Spanish portal labels were
  updated where required.

**Documents:** `PortalInvoicePdfController` now uses the configured modern
sale template instead of an old/default template. `PortalStatementController`
and `routes/portal.php` add authenticated Statement PDF download.

**Files:** portal PDF/statement controllers and routes; portal layout, shared
table and CSS; Dashboard, Invoice Detail, Invoices, Payments, Statement,
Appointments, Contracts and Quotations views; four portal language files; and
generated `public/js/portal` assets.

**Build:** run `npm run build:portal`; never hand-edit generated portal assets.

### Build O2 — Customer Display compact two-segment layout

**Goal:** long carts must not push totals below the viewport. Desktop now uses
a scrollable item list on the left and sticky subtotal/discount/tax/total/
payment breakdown on the right, with a responsive mobile collapse. Product
images were deliberately omitted.

- Unit price stays aligned below its heading.
- Percentage discount shows its calculated amount, not only the percentage.
- Background, row rhythm and date/time header were modernized while retaining
  the existing token/session update flow.

**Files:** `resources/src/customer-display/CustomerDisplay.vue`, compatible
payload changes in `resources/src/pages/pos/PosPage.vue`, and generated
`public/js/customer-display` assets. Build with
`npm run build:customer-display`.

### Build O3 — Secure token-based Real-Time Sales Displays

**Goal:** expose the real-time sales counter as one or more safe shareable TV/
manager displays without an authenticated admin session on the display device.

**Architecture:**

- New `real_time_sales_displays` table/model with multiple active named
  displays, warehouse scope, creator, expiry, refresh rate, customer-name
  visibility, standard/manager profile, last seen, last successful sync,
  consecutive failure count, recovery time, revoked time and archived time.
- Each link uses a 64-character random token. Public lookup uses only its
  SHA-256 hash; an encrypted copy allows authorized admins to recover an active
  Copy/Open URL. Encryption depends on the existing `APP_KEY`—do not rotate it
  without a migration plan.
- Regenerate changes only the selected display. Edit changes selected metadata;
  Revoke disables; Archive safely disables then hides. Legacy cache-only
  configuration migrates into the database, so cache clear no longer loses it.
- Scheduler cleanup removes old expired/revoked/archived rows. Production must
  run `php artisan schedule:run` every minute.

**Security:** authenticated management honors assigned warehouses and display
ownership/visibility. Public page/data reject missing, revoked, archived or
expired tokens and return only token-scoped data. Payloads are cached for 8
seconds per token to control query load.

**UX:** light is default and dark optional. Recent Sales and Sales by Warehouse
use readable headers, row hover, aligned totals and warehouse last-sale time.
Latest Sale ticker uses consistent invoice/warehouse/amount/time typography.
New-sale notification detects a changed latest record. Manager profile adds
top warehouse, sales velocity, peak sales hour and warehouse comparison while
keeping the original tracker design. Sync success/failure/recovery remains
visible and polling auto-recovers after temporary errors. Mobile management
actions were compacted.

**Files:**

- migrations `2026_09_20_000006...` and `2026_09_21_000007...`
- `app/Models/RealTimeSalesDisplay.php`
- `app/Http/Controllers/Api/RealTimeSalesDisplayController.php`
- `app/Console/Kernel.php`, `routes/api.php`, `routes/web.php`
- `resources/src/pages/RealTimeSalesCounter.vue`
- `resources/src/realtime-sales-display/{main.js,RealTimeSalesDisplay.vue}`
- `resources/views/real_time_sales_display.blade.php`
- `vite.realtime-sales-display.config.js`, `package.json`, generated display assets.

**Deploy:** preserve `APP_KEY`, run migrations, build with
`npm run build:realtime-sales-display`, and verify scheduler cron.

### Build O4 — POS keyboard search and parent-SKU variant picker

**Goal:** make POS suggestions keyboard-accessible and make a variable
product's main SKU useful instead of forcing cashiers to know every variant
SKU.

- Arrow Down/Up changes the active suggestion; Enter selects it without
  conflicting with existing POS shortcuts.
- A variable product parent-SKU match opens a variant picker instead of adding
  an arbitrary variant. The modal shows identifying variant name/SKU and adds
  the selected variant through the existing cart/stock logic.
- Simple-product, barcode, variant-SKU and mouse selection remain intact.
- Desktop/mobile close button, spacing, and right-aligned Cancel/Add to Cart
  footer were corrected.

**Files:** `resources/src/pages/pos/PosPage.vue` and
`resources/src/pos-compat/posKeyboardShortcuts.js`.

**PWA warning:** every future `PosPage.vue` delivery must compare/bump
`public/sw.js` cache version against production or installed POS clients can
keep stale code.

### Build O5 — Supplier Statement and Supplier Ledger parity

**Goal:** give suppliers the same statement/ledger workflow as customers while
keeping supplier documents in their own templates.

- Supplier Details includes Statement and Download Ledger beside payment
  actions; Supplier list exposes Statement navigation.
- Responsive Supplier Statement supports date filtering, KPI cards, opening
  balance/carry-forward, running debit/credit/balance, PDF and Excel.
- `ProviderStatementService` merges supplier opening balance, received
  purchases, purchase payments, completed purchase returns and return refunds
  with payable-side debit/credit semantics. A From date carries all earlier
  movement into Opening Balance, preserving the true closing balance.
- `supplier_statement_modern.blade.php` and `supplier_ledger.blade.php` are
  deliberately separate from customer templates. Supplier Ledger mirrors
  Customer Ledger styling/address order with provider-side totals and rows.
- Customer Ledger company address was aligned and customer code removed from
  the customer address block. The older provider report remains for backward
  compatibility, but Details downloads the dedicated Supplier Ledger.

**Files:** Provider Statement controller/service; reusable statement export;
Supplier Details/List/Statement pages; supplier statement/ledger, customer
statement/ledger and provider-report Blade templates; router and API routes.

**Authorization:** every statement/ledger endpoint is authenticated and reuses
`Suppliers_view`, matching Supplier Details.

### Build O6 — Dashboard chart readability and hourly timeline

**Goal:** make chart meaning obvious, remove negative baseline confusion,
handle Today/empty data consistently, and preserve the established layout.

- Sales & Purchases and Payment Sent & Received use stable smooth area/spline
  charts with zero-based axes.
- Single-day series remain renderable when Today returns one date point.
- Empty comparison series use the normal app empty state.
- Today's Sales by Hour remains below the comparison charts as a 0–23 hour bar
  timeline; missing hours are zero so time position is preserved, while an
  all-empty day uses the no-data state.
- Existing Top Selling, Top Customers, Stock Alert, Sales by Payment, Stock
  Value, Sales by Warehouse and Recent Sales panels remain.

**File:** `resources/src/pages/Dashboard.vue`.

### Build O7 — Dashboard section-order persistence repair

**Problem:** Settings Up/Down changed the local list, but saved order was not
consumed by Dashboard. It also treated two cards sharing one responsive row as
independent sections, which cannot be separated safely.

**Fix:**

- `UserController` includes default date range, parsed section order, font size
  and font family in the authenticated payload.
- Settings orders complete responsive rows, displays both card labels for a
  paired row, and awaits `auth.reload()` after save.
- Dashboard flex-orders sections from auth settings. Normalization maps legacy
  individual IDs to row IDs, removes duplicates and appends missing/future
  defaults.
- The hourly-sales + sales-by-warehouse row is now configurable; it was missing
  from the settings list. Font and default period use the same auth payload.

**Files:** `app/Http/Controllers/UserController.php`,
`resources/src/pages/settings/SystemSettings.vue`, and Dashboard. No new
schema was needed because the settings columns/migrations already existed.

### Build O8 — Products table insight visibility and semantics

**Goal:** Sold (30d), Trend, Revenue (30d), Return Rate and Last Sold stay
visible by default and work meaningfully for variants and zero-baseline trends.

**Performance fact:** hidden DataTable columns did not stop the endpoint from
calculating these metrics; hiding saved only horizontal space. The set-based
`ProductInsightService` remains the query boundary and no per-row/N+1 query was
added.

- Trend compares rolling current 30 days to the preceding rolling 30 days:
  current > 0 with previous 0 = `New`; equal non-zero = `0%`; both zero = dash;
  other cases retain up/down percentage.
- Revenue (30d) is now actual `sale_details.total` from completed, non-deleted
  sales in the same date/warehouse scope. It no longer estimates units ×
  today's price and no longer goes blank for variable products.
- Server-side Revenue sorting uses the same scoped aggregate.

**Files:** `ProductInsightService.php`, `ProductsController.php`, and
`resources/src/pages/products/Products.vue`.

### Build O9 — Dedicated Product Movement History workspace (final V4)

**Goal:** extend the embedded movement diagnostic into a professional Products
menu workspace with filters, party/variant identity, reconciliation, summary,
and export.

**Stock sources/rules:**

- Merges received Purchases, completed Sales, approved Transfers (source Out
  for sent/completed; destination In for completed), Adjustments, received Sale
  Returns, completed Purchase Returns, and Damages.
- Converts detail quantities to base units using line/product unit operators;
  Sale and Sale Return pack multipliers are included.
- Transfer creates distinct source-Out and destination-In rows. Running balance
  is maintained per warehouse.
- Customer appears for Sale/Sale Return; Supplier for Purchase/Purchase Return.
- `Opening stock...` adjustment notes are classified separately from ordinary
  adjustments in the summary.
- Zero-quantity retained detail lines are filtered, removing duplicate-looking
  return rows with no stock effect. Multiple non-zero variants under one
  reference remain separate and Variant name/SKU explains why.

**Filters/security:**

- Products → Movement History route/menu; filters for Product, Warehouse,
  Variation and Date Range.
- Typeahead matches product name/main SKU plus variant name/SKU.
- Soft-delete-aware validation, `date_to >= date_from`, correct
  `ProductPolicy::view`, assigned-warehouse scope on every source, and explicit
  403 for an out-of-scope requested warehouse.
- Literal product routes are registered before `Route::resource('products')`
  to prevent Laravel dispatching them to `show()`.

**Range/reconciliation:**

- A From date folds earlier movement into per-warehouse Opening Balance.
- A bounded To date is a historical snapshot and is not compared with today's
  stock; UI says Calculated Closing.
- Current/unbounded view compares calculated closing with
  `product_warehouse.qte`, distinguishing match, numeric difference and missing
  row. Badges now say Calculated stock, not ambiguous Ledger.
- This is read-only diagnostic logic; it never auto-corrects stock.

**Summary/export:** responsive In/Out/Totals panels cover Purchase, Opening
Stock, Sale Return, Transfer, Adjustment, Sale, Purchase Return, Damage,
Opening Balance, Net Movement, Calculated Closing and Current Stock. The
dedicated page exports the active filtered rows to Excel or landscape PDF with
date/time, type, variant, reference, warehouse, In, Out, balance and party.
Embedded Product/Stock Detail cards do not gain extra export controls.

**Files:** `ProductMovementLedgerService.php`, `ProductsController.php`, new
`MovementHistory.vue`, shared `MovementHistoryCard.vue`, router/menu/API routes,
and `tests/Regression/build_h1_stock_movement_ledger.php`.

**Known data limitation:** opening stock older than the virtual opening-stock
adjustment mechanism may not be reconstructible from transaction detail tables.
The resulting mismatch is intentionally visible. Never hide it by forcing the
calculated closing figure to equal current `product_warehouse.qte`.

## Final release file map (O1–O9)

Relative to GitHub's Build N5 baseline, the handoff changes 68 runtime,
configuration, generated-asset, documentation, or regression files grouped as:

- Portal controllers/routes/languages, shared portal layout/table/CSS, 13
  portal views and generated portal assets.
- Customer Display Vue/generated assets plus POS compatibility.
- Real-Time Display controller/model/migrations/scheduler/routes, management
  page, public Vue/Blade/Vite entry and generated assets.
- Supplier controller/service/statement page/actions/export adapter and
  supplier/customer/provider PDF templates.
- Dashboard, User auth payload and System Settings.
- ProductsController, both custom product services, Products table, Movement
  History page/card, router/menu/API and regression gate.
- `public/js/.vite/manifest.json` plus standalone compiled bundles.

## Required deployment order

1. Back up database/application files; record deployed commit and `APP_KEY`.
2. Review/merge the handoff branch onto the intended vendor/custom branch.
3. Install dependencies using repository lock files.
4. Run `php artisan migrate --force` for both real-time-display migrations and
   any earlier unshipped migrations in the 30 commits.
5. Run `npm run build`. Surgical minimum: `build:admin`, `build:portal`,
   `build:customer-display`, and `build:realtime-sales-display`.
6. Run `php artisan optimize:clear`; rebuild production config/route/view
   caches if that deployment uses them.
7. Confirm scheduler cron runs `php artisan schedule:run` every minute.
8. Run regression and manual smoke checks before broad access.

## Consolidated risk register and developer instructions

1. No production push was performed. Review first, then push only after
   Claude/human review and real-database verification.
2. Database-backed token URL recovery depends on `APP_KEY`; preserve it.
3. Display cleanup depends on scheduler. Links expire logically without cron,
   but old rows will not be purged.
4. Movement History is reconstruction, not stock authority. Investigate
   mismatches; never auto-write stock from the report.
5. Historical opening stock can be incomplete as documented in O9.
6. Product insight columns do not become cheaper when hidden. Optimize the
   set-based query if scale requires it.
7. Generated assets must match sources. Never deploy source-only POS/portal/
   display changes with stale bundles or PWA cache keys.
8. Supplier Statement and Supplier Ledger must keep separate Blade files even
   though their design mirrors customer documents.
9. Portal UI modernization must not change totals, payment status, permissions
   or document scope.
10. Warehouse visibility is a security boundary for every movement/display
    query and any future source added to them.
11. Keep literal product routes before the resource route.
12. Do not collapse real multiple-variant movements; only zero-quantity detail
    lines are noise.
13. Keep the 30 local commits intact through review; squash/rebase only if the
    repository owner explicitly decides after approval.
14. Applying only the last Movement History overlay is not equivalent to the
    full September handoff; use the bundle/full source for all work.

## Verification status at packaging

- Live remote HEAD verified as `8ca9b05db76be225f2c4a10f4b960bde860011c7`;
  local history was 30 commits ahead before the final handoff commit.
- Admin Vite production build passed after final Movement History changes.
- Portal/customer-display/real-time-display builds are re-run during final
  packaging and recorded in the delivery manifest.
- ZIP integrity, Git bundle verification and SHA-256 checks are final gates.
- This workspace has no PHP runtime or live app database. PHP lint, migrations,
  DB-backed PHPUnit/regression, PDF rendering with production-like fixtures and
  browser E2E are therefore not claimed as passed and remain mandatory.

## Planned/deferred work (not included in O1–O9)

- Inventory valuation, COGS, average cost, GRN cost flow, last purchase cost vs
  product master cost, and vendor-upgrade-safe costing architecture.
- Optional warehouse-address-on-receipt/document behavior. Company information
  remains the default; multi-warehouse is not yet true multi-company/branch.
- Optional Dashboard 1/Dashboard 2 selector.
- Further Manager Screen target/margin/return/no-sale/high-value alerts. Only
  Sales Velocity and Peak Sales Hour from that discussion were approved here.

## Mandatory smoke test after merge

- Portal: desktop/mobile Dashboard, Invoice Detail, scrollable billing tables,
  modern invoice PDF, Statement PDF, dark/language/full-screen controls.
- Customer Display: long cart, sticky totals, unit price, discount amount,
  date/time, desktop/mobile.
- Real-Time Display: two named links, warehouse scope, Copy/Open/Edit/
  Regenerate/Revoke/Archive, profiles, expiry/online/sync/recovery/ticker.
- POS: mouse plus Arrow/Enter search, simple/variant/parent SKU, variant modal,
  cart totals, desktop/mobile.
- Supplier: Details actions, Statement carry-forward/KPIs/PDF/Excel, Ledger PDF,
  Customer Ledger unaffected.
- Dashboard: Today/7d/30d/custom, empty states, charts, 0–23 hours, order save/
  reload, real-time row, font/default date.
- Products: all insights visible, New/0%/dash, variable revenue, server sort,
  warehouse-restricted user.
- Movement: every source/status, unit/pack, transfer sides, party/variant,
  restricted warehouse, opening balance, historical/live reconciliation,
  zero-return cleanup and filtered PDF/Excel.

---

## Build P — Live reconciliation & release hardening (2026-09-24)

Independent review of the O1–O9 handoff against the live installation. Full
findings: `docs/RECONCILIATION_2026-09-24.md`.

### P0 — Repository hygiene (no behaviour change on the live site)

- Reverted 14 vendor-owned files that an earlier audit helper had modified on
  GitHub (`config/database.php` hard-coded `:memory:` → env switch, sqlite
  index renames, an `IF()` guard, `TEST-COPY-ONLY` notes). They now equal the
  CodeCanyon originals, i.e. exactly what runs live. Consequence:
  `php tests/Regression/*.php` runtime scripts need a real (MySQL) **test
  copy** of the database — never the live one — because the sqlite connection
  is `:memory:` again.
- Adopted the live-only Modern PDF templates that were never committed:
  `payment_sale`, `payments_purchase`, `po_pdf`, `sale_pdf_modern`. Re-added
  what those live versions had dropped: Build M6 Tracking Ref + Courier on the
  Modern invoice, and the Website line on the PO header.
- Replaced the committed `public/js` (7,150 files, 150 MB, manifest pointing at
  486 files that did not exist in git) with one consistent production build
  (526 files, 21 MB, every manifest entry present, every import resolves, no
  orphans). It is source-identical to what the live site runs, apart from the
  POS fix in P2. `public/js` is git-ignored but tracked, so add with `git add -f`.

### P1 — Supplier Statement date-range fix

`ProviderStatementService`: with a From date the opening line still counted
opening-balance payments made before the period (e.g. 1250 instead of 1050) and
listed them as in-period rows; an opening payment after the To date was also
deducted. Pre-period opening payments are now folded into the carry-forward and
only in-period ones are listed. Test: `build_p1_supplier_statement_range.php`
(fails on the old service, passes on the fix). All-time closing balance was
always correct.

### P2 — POS: Enter must not add a stale suggestion; service-worker bump

`PosPage.vue selectHighlightedProduct()` picked row 0 of the on-screen list even
when the input had changed since the list was built (800 ms debounce). Typing
"Bat", waiting for the list, then "Bat Alp" + Enter immediately added the OLD
first row (verified in a real browser against the previous build). Only a fresh
list (`productSearchActiveIndex >= 0`) can be selected now; otherwise the
current input is searched immediately. `public/sw.js` VERSION bumped
`stocky-pwa-v13` → `v14` because PosPage.vue changed by ~600 lines in O-series
without a bump. Test: `build_p2_pos_search_enter_guard.php`.

### Test-suite repairs (no product change)

- `build_e1_pos_recent.php` now finds the compiled POS chunk through the Vite
  manifest instead of a pinned content-hash filename.
- `build_n5_pos_main_sku_search.php` no longer depends on Build N4: it finds the
  created product by SKU (N3-based `store()` returns no `product_id`) and guards
  the N4-only `product_variation_sets` cleanup.
- Known stale (not product failures): `build_po_grn.php` and
  `build_po_phase1_3.php` still expect the originally documented PO/GRN wording
  (owner confirmed the current PO/GRN screens are correct as they are).

---

## Build Q1 — Create Sale compact payment-term header (2026-09-24)

- Create/Edit Sale top card is responsive and keeps Date, Customer, Payment
  Term, Due Date and Warehouse in one desktop row while stacking safely on
  mobile.
- Payment Term/Custom Days/Due Date were removed from the lower calculation
  section to prevent duplication.
- When Payment Terms is disabled, those controls disappear and the remaining
  Date/Customer/Warehouse fields return to an even three-column layout.
- The selected customer's positive previous due appears beside Customer in
  base currency; zero/credit balances remain hidden.
- Due-date preview now uses the actual backend system default rather than a
  hardcoded seven-day fallback.
- No `PosPage.vue` change; `public/sw.js` remains `stocky-pwa-v14`.

## Build Q2 — Zone/Area & Courier management + customer contact search (2026-09-24)

- Added Sales-menu pages for Zones / Areas and Couriers using the existing app
  shell, PageHeader, DataTable preferences, responsive modal and permissions.
- Both pages provide server-side name search, pagination, safe allowlisted
  sorting, active-sale usage count, created/edit workflow and last-updated data.
- Existing inline creation on Create/Edit Sale, POS Sales and Shipments remains
  unchanged. Page-created values automatically appear in those selectors and
  inline-created values appear on the management pages.
- Extracted canonical normalization, case-insensitive duplicate resolution,
  soft-delete restoration, unique-race handling and collision-safe rename into
  `App\Services\SaleLookupService`; controllers do not duplicate this logic.
- Rename requires `Sales_edit`. Read/create access continues to match the
  existing Sale/POS/Shipment workflows. Delete/archive was deliberately not
  exposed because the requested scope is create/edit/update and historical
  references need a separate retention decision.
- Create/Edit Sale customer options now include phone, email and customer code;
  search matches all four identifiers (including name) and shows contact detail
  in the dropdown without changing the selected label or `onClientChange()`.
- The contact search remains in-memory over the already-loaded customer list:
  no request per keystroke and no N+1 query. The bootstrap query selects four
  additional scalar columns only.
- Detailed developer contract, risk notes and smoke test:
  `docs/BUILD_Q2_SALE_ZONE_COURIER_MANAGEMENT.md`.
- No migration and no `PosPage.vue` change; service worker remains v14.

## Build Q3 — Sale create/edit totals-guard correction (2026-09-24)

- Corrected `SaleTotalsGuard` so header validation mirrors the Sale form:
  line totals minus manual discount and loyalty-points discount, plus order tax
  and shipping.
- Fixes valid Create/Edit Sale submissions with non-zero Order Tax; previously
  `TaxNet` appeared in the UI Grand Total but the backend guard omitted it and
  returned HTTP 422. Loyalty-point discounts had the same latent mismatch and
  are covered by the same correction.
- The guard still validates every line, rejects disconnected/tampered grand
  totals, and performs the existing TaxNet plausibility check. No stored sale,
  stock, payment, COGS, POS, return, quotation or purchase logic changed.
- Tests: `tests/Unit/SaleTotalsGuardTest.php` and
  `tests/Regression/build_q3_sale_totals_guard.cjs`.
- Source-only change: no migration, no `PosPage.vue` change, no compiled assets,
  and `public/sw.js` remains v14.

## Audit Batches 1-5 — correctness and security hardening (2026-09-24)

Goal (owner's rule): stock, sales, payments, dues, purchases, reports and inventory calculations must always agree, in
every module. New logic lives in separate `app/Support` / `app/Support/Reporting` classes; vendor controllers only carry
small hooks marked `Audit Batch` / `Audit B<n>` so a vendor 5.9 merge stays easy. File-by-file hook list and merge
procedure: `docs/AUDIT_MERGE_GUIDE.md`. Business decisions confirmed by the owner: only COMPLETED sales / RECEIVED
purchases / RECEIVED sale returns / COMPLETED purchase returns count anywhere; net sales = GrandTotal - tax - shipping -
returns; percent discounts are shown in money.

**Batch 1 - sale totals and sale returns.** `SaleTotalsVerifier` recomputes Sales/POS totals on the server (tight
tolerance). `SaleReturnLimits`: a return can never exceed sold quantity / price / totals, refund is capped.

**Batch 2 - document state and stock.** Deleted documents cannot be edited, re-deleted, approved or paid
(`LiveDocument`). `StockGuard` / `StockDocumentRules`: no overselling, adjustments/transfers/purchase returns cannot drive
stock negative, no false "success" on failure.

**Batch 3 - payments, loyalty, cash register.** `PaymentReconciler`: paid amount and payment status are always recomputed
from payment rows; overpayment refused; a card-payment edit no longer drains the account. Loyalty redemption validated
(`LoyaltyRedemption`). Cash register close expects only cash actually received/refunded (`CashRegisterCash`). Loyalty
report enforces its permission. Ref collision retry (`UniqueRefGenerator`). GRN against a PO rejects non-positive and
unlinked lines.

**Batch 4 - reports.** `Reporting/SalesFigures` is the ONE definition of sales / line+order tax / shipping / discount /
net used by Dashboard, Profit & Loss, Analytics, Tax and Discount summaries. COGS converts units and pack sizes to the base
unit and nets received sale returns. `Reporting/CashFlowFigures`: cash flow includes return refunds, table and chart share
data. Sales/Purchases/product/customer/supplier/seller/zone/category reports default to completed/received documents.
A warehouse-limited user can no longer read another warehouse via `warehouse_id`. Inventory valuation paging fixed.
Fixed 500s: warranty report, orphan sale lines, 4 dead report routes. Vue: Profit & Loss page rebuilt
(`npm run build:admin`, `public/js` is tracked).

**Batch 5 - payments below paid, security, balances, speed.**
- `PaymentReconciler::assertTotalCoversPayments` (hook in Sales/Purchases/SalesReturn/PurchasesReturn update): an edit that
  would make the total smaller than what is already paid is refused. BEHAVIOUR CHANGE: before, a negative due was stored.
- Security: module upload / enable / disable and Clear Cache need `setting_system`; module zip entries cannot escape the
  module folder and module names are restricted; QuickBooks (8 methods), custom fields and storefront pages editing need
  `setting_system`; Woo settings need `view`; integration secrets are returned only to users allowed to update settings;
  public route `products_clean_names` removed.
- Pending sale/purchase returns no longer count in customer/supplier balances (`ClientController`, `ProvidersController`,
  `PublicInvoiceController`, `SalesController` customer figures): only `received` / `completed` returns.
- `TodaySummaryController` (top-bar drawer) now uses `SalesFigures`, the shared COGS trait and net-of-returns profit, so
  it matches Dashboard and P&L; sargable date filters.
- Migration `2026_09_24_000001_add_report_and_stock_indexes.php`: 14 `idx_b5_*` indexes (additive, idempotent).
  `ReportController` caches unit lookups per request (product report 14s -> 2s at 100k sales).
- Tests: `tests/Regression/audit_*.php` (seeded stock, money and variant stress with the movement-ledger oracle,
  unit conversion, edit-below-paid, security endpoints, secrets, query count, pending returns, today summary);
  run everything with `tests/run_regression.sh`. Twelve older `build_*` scripts fail only in the MySQL test harness
  (FK cleanup) and pass with `foreign_key_checks=0`; they are not code failures.
- Known remaining items: P&L / dashboard COGS query is 2.5-3s at 100k sales; `dashboard_data` has no permission gate
  (product decision); some send-SMS/WhatsApp endpoints lack a permission check; draft->POS conversion and account balance
  vs payments have no dedicated stress test yet.
- Deploy: `php artisan migrate` (indexes) then `php artisan optimize:clear`.

## Audit Batch 6 — Dashboard "Today" hourly charts (2026-09-24)

- When the Dashboard header range is ONE day (Today or a single custom day), the Sales & Purchases chart is an hourly
  line (00:00-23:00) and Payment Sent & Received is an hourly bar chart. 7D / 30D / MTD / YTD are drawn exactly as
  before (one point per day).
- New class `App\Support\Reporting\DashboardHourly`: same rows, scope (record_view, warehouse filter) and definitions as
  the day-by-day charts, only grouped by hour, so the 24 hourly values always add up to the daily value. Sales hour =
  `sales.time`, purchases hour = `purchases.time`, payments/expenses hour = `created_at`.
  Sales = completed only; purchases = received only.
- Hook: `DashboardController::dashboard_data` returns an extra `hourly` key (null unless from == to). The daily Payment
  chart now also ignores soft-deleted expenses.
- Vue: `resources/src/pages/Dashboard.vue` (`buildCharts(..., hourly)`); admin assets rebuilt (`npm run build:admin`).
- Test: `tests/Regression/audit_b6_dashboard_hourly.php`.
- Note: a payment's hour is the time the payment row was recorded (created_at); payment rows have no separate time column.

## Modern Dashboard (2026-09-25) — Classic stays, Modern added alongside

**What it is.** A second dashboard ("Modern") next to the untouched Classic one, chosen with a Classic | Modern switch above the
dashboard. Each user picks their own; an administrator (permission `update` on Setting) can "Make this the default for everyone".
Resolution: user choice -> organisation default -> Classic. If the preference call fails the app simply shows Classic.

**No wrong data.** Modern reads the same `dashboard_data` endpoint as Classic for every figure Classic shows, plus the new
`dashboard_insights`. A failed request shows an error with Retry - there is no demo data and no zero-filled fallback; a figure that
cannot be computed shows "-". Percent changes are hidden when the previous period is 0 (never Infinity).

**Layout.** Container-query CSS (`.dm-*`, breakpoints 640 / 1024 px of the dashboard's own width): phone 1 column, tablet 6, laptop and
wide 12. Sections can be dragged (grip handle; pointer events, works with mouse/finger/pen; keyboard: focus grip + arrow keys),
hidden/shown, saved per user, reset, or (admin) saved as the organisation default. Dark mode and the app's primary colour are followed.

**Sections (17):** headline numbers (hero "Today's sales" + 6 KPIs), business insights, needs attention, sales & purchases, top products
(donut), sales by payment, stock value, quick actions, payments sent & received, top customers, stock alert, top products (list),
recent activity, today's sales by hour, sales by warehouse, sales map, recent sales.

**Insight definitions** (`App\Support\Reporting\DashboardInsights`):
- Where the money went: cost of goods (FIFO), expenses, profit from the Classic profit figure; margin = profit / net sales (tax and delivery excluded), "-" when net sales <= 0.
- Collections: invoiced / paid / due of completed sales in the range, due = GrandTotal - paid_amount.
- Who owes you: unpaid completed sales, ALL dates, aged from the invoice date as of today (0-7, 8-30, 31+ days).
- Stock health: low / out counts; slow stock = stock on hand at COST of products created more than 60 days ago with no completed sale in 60 days.
- Needs attention: low stock, unpaid 31+ days, purchases pending/ordered, pending sales, open cash registers.
- Expenses / net cash flow / previous-period comparison come from `CashFlowFigures` and `SalesFigures` (same numbers as the Cash Flow report).
- Top customers: "invoices, this month" (Classic data) or "amount, selected period" (toggle). Top products donut is "this year, times sold", list is "this month, by amount"; hourly chart is always today.
- Recent activity is shown only to roles with `activity_log_report`.

**Sales map.** Completed sales by customer city on a Bangladesh district map. City names are matched to districts ONLY through an exact
alias table (English + Bangla district, upazila and Dhaka-area names; ambiguous names such as Mirpur/Daulatpur are deliberately NOT
matched). Unrecognised cities, cities outside Bangladesh and customers without a city are listed as such, never guessed.
Map data is bundled (`resources/src/pages/dashboard/modern/data/bd-districts-map.json`, generated by `build_bd_map.py`).
**Licence: boundaries (c) geoBoundaries, CC BY 4.0 (attribution is shown under the map - keep it); place names from bd-geojson (MIT).**
Only the top 60 city groups are returned by the API.

**Data / code added (no vendor table touched):** table `dashboard_preferences` (migration `2026_09_25_000001`, org default = row with
`user_id` NULL); `App\Models\DashboardPreference`; `App\Support\Dashboard\DashboardPreferences`; `DashboardPreferenceController`;
`DashboardInsightsController`; routes `GET|PUT /api/dashboard_preferences`, `PUT /api/dashboard_preferences/default`,
`GET /api/dashboard_insights`; frontend `resources/src/pages/dashboard/DashboardSwitch.vue`, `.../dashboard/modern/**`,
`stores/dashboardPrefs.js`. Vendor-file hooks: `router/index.js` (one line: `Dashboard` now points at `DashboardSwitch.vue`, which
mounts the unchanged `Dashboard.vue` for Classic), `DashboardController.php` (four additive numeric keys: `today_net_revenue`,
`today_cogs`, `today_expenses`, `return_purchases_amount`).
**Classic bug fixed:** the Purchases Return card showed 0 for any amount >= 1,000 (formatted string parsed as NaN).
New labels use `tt(key, English)`: if a translation key is missing the English text is shown, never the raw key.
**Performance:** insights ~3 s (today) to ~6 s (30 days) on a 1M-sale / 3M-line database; the page loads them separately so charts appear first.
Tests: `audit_ui1_dashboard_prefs.php`, `audit_ui2_dashboard_insights.php`.

### Modern Dashboard — update 2 (2026-09-25): compact switch, System Settings option, faster loading, insight clean-up

- **Compact switch.** The Classic | Modern switch is now a small pill (with a "..." menu for admin actions) inside the Modern header, and a slim right-aligned row above Classic. It is hidden when the organisation turns switching off.
- **System Settings > Dashboard** (new block `pages/dashboard/DashboardModeSettings.vue`, saved instantly, own API): default dashboard for everyone (Classic / Modern) and "Let users switch between Classic and Modern". When switching is off everyone sees the default and the server refuses a user's style change (403); users can still arrange their own Modern layout; administrators (permission `update` on Setting) can still switch. Stored as `allow_user_switch` on the organisation row (migration `2026_09_25_000002`).
- **Speed.** (1) The two slowest queries (top products this year / this month) are requested separately (`dashboard_data?only=products`), and the main request skips them (`skip=products`), so everything else appears first; Classic never sends these flags and is unchanged. The query bodies moved unchanged into `DashboardController::TopProductsMonth` (report_dashboard calls it). (2) The three Modern requests start in parallel, and start before the Modern page has downloaded (on hover/click of "Modern" or when Modern is the saved choice); a prefetch is reused only if fresh (20 s) and for the identical range and warehouse. (3) The last chosen style is remembered per user in the browser so the right dashboard starts loading immediately; the choice switches at once (optimistic) and reverts if the server refuses. (4) The Modern page is downloaded quietly in idle time when Classic is shown.
- **Charts.** Modern draws its own small SVG charts (`parts/DmChart.vue`, donut, sparklines): no ApexCharts, no extra library; Classic still uses ApexCharts.
- **Insights now (no repeats):** Profit margin (margin %, cost / expenses / profit split), Money to collect (collected % of the period's invoices + unpaid overall aged 0-7 / 8-30 / 31+; the period's "Sales due" stays only as a KPI), Stock health (slow stock value, slow products, share of stock at cost; low/out counts live only under Needs attention), Customer mix (top customer share, top 3, top 5 share, average ticket vs previous period). KPI cards for Purchases, Expenses and Net cash flow show a trend line (`insights.daily`, one value per day of the range, hidden for one-day ranges or when flat). Net cash flow also shows money in / out. Small headings "Business insights" and "Needs attention" added as in the design.
- New tests: `audit_ui3_dashboard_split.php`; `audit_ui1` and `audit_ui2` extended (switch setting, daily series).

### Modern Dashboard — update 3 (2026-09-25): mobile KPI amounts, sales map by Zone, typography

- **Mobile KPI cards.** Amounts never break across lines any more (`white-space: nowrap`, font size follows the card width with a container query `clamp(16px, 8.4cqi, 24px)`), and the sparkline no longer squeezes the number (Return card included). Insight big numbers use the same rule.
- **Customize button** is hidden below 640 px (`.dm-customize`); layout editing stays available on tablet/desktop.
- **Sales map placement rule (data fix).** A sale is placed by its **Zone** (`sales.zone_id` -> `sale_zones.name`); if it has no Zone, by the customer's city; otherwise it is listed as "without a location". Previously only the customer city was used, which is mostly blank, so the map under-reported. Unmatched/foreign names are listed honestly. `dashboard_insights` geo rows now carry `source` (zone/city). Still only completed sales.
- **Map display.** Every district with sales has a dot sized by amount and an always-visible name (collision-avoiding); a "Show all district names" checkbox shows the rest. The rule is written under the map. geoBoundaries CC BY 4.0 attribution unchanged.
- **Typography.** Desktop scale bumped a little (card titles, insight numbers, list rows) for readability; no layout change.
- Test `audit_ui2_dashboard_insights.php` extended with the zone-first rule.

### Modern Dashboard — update 4 (2026-09-25): KPI badge overflow, map zoom, phone bottom navigation

- **KPI overflow.** The change badge ("▲ 1791.5% vs previous 7 days") and the "In / Out" line of Net cash flow could run past the card on phones. Badge now shows only "▲ x%" on narrow cards (full text on wide cards and as tooltip); In / Out sit on two rows on narrow cards. Checked at 320/360/390/768/1440 px: no KPI element outside its card.
- **Map zoom.** `+` / `−` / Reset buttons (top-right of the map), drag to pan when zoomed, Ctrl/Cmd + wheel to zoom (a plain wheel still scrolls the page). Dots and names keep their on-screen size while zooming and more district names appear as there is room. View-only; no data change.
- **Phone bottom navigation** (`modern/parts/MobileNav.vue`, screens below 768 px, Modern dashboard only): Home, Sales, POS (centre button), Products, More (sheet: Purchases, Customers, Suppliers, Expenses, Reports, Activity log). Items follow the same permissions as the pages; hidden while customizing. Classic dashboard and the rest of the app are untouched.
- Known, not from the dashboard: below ~340 px the vendor top bar (user menu) is a few pixels wider than the screen.

### Modern Dashboard — update 5 (2026-09-25): map geometry fix, always-named districts, Recent activity, Menu tab, fewer requests

- **Map geometry (data fix).** Some islands / river chars in `bd-districts-map.json` were attached to a district on the other side of the country (estuary islands painted as Sylhet or Dhaka, pieces of Dhaka inside Jashore, etc.), so a district with sales lit up in two places. `data/fix_bd_map_islands.py` hands any piece lying more than 30 map units from its district's main body to the nearest district (14 pieces moved; idempotent; run it after `build_bd_map.py`). Colours and dots now match the district that really has the sales.
- **Names.** Every district with sales always shows its name: the label tries below / above / right / left of the dot and falls back to below rather than being dropped (before, Rajshahi lost its name to the Natore label). "Show all district names" extras are still skipped when they would overlap.
- **Recent activity** rebuilt: tabs (All / Sales / Purchases / Payments / Stock, only those that have rows), grouped Today / Yesterday / date, runs of identical lines folded ("Sale SL_0047 updated ×3"), Deleted marker, click a Sale or Purchase row to open it. Reads the same Activity Log rows (limit 8 -> 30, primary-key order, negligible cost); still hidden for roles without the Activity Log permission. Not included: "needs review" flags (would need rules on the log, not done).
- **Phone bottom bar:** the last tab is now "Menu" and opens the normal sidebar (it presses the top bar's own menu button; no vendor file changed).
- **Fewer requests:** if both product-ranking sections are hidden in the layout, the slowest query (`dashboard_data?only=products`) is not requested at all; turning a section on fetches it then.

### Modern Dashboard — update 6 (2026-09-25): map fills its box, no click frame, Recent activity 5 rows

- **Map size / zoom.** The map box now uses the full width of its column (square up to 560 px on desktop, 4:5 on phones). While zoomed, the view is computed from the box's real shape, so it fills the whole box (before, it stayed inside a narrow 380 px strip with empty space on both sides). Zoom, pan, reset unchanged.
- **Black frame on click** was the browser's focus outline on the clicked district / map; removed (keyboard focus still shows a dark district border).
- **Recent activity** shows at most 5 rows (tabs and day groups stay); "View all" opens the full Activity Log.

## Inventory Costing (Moving Average)

**Problem.** The vendor stored no per-sale cost. COGS was recomputed at report time from `products.cost` /
`product_variants.cost` (a single "master cost" field), so editing a product's cost changed the profit of sales made
months ago, different reports could disagree with each other (some used FIFO layers from purchases, some a cumulative
average, some the current master cost), and there was no way to ask "what was my stock worth on 1 January".

**Design.** Documents (purchases, sales, returns, adjustments, transfers, damages) stay the single source of truth —
nothing about how a document is created, edited or deleted changes. A new, additive service layer
(`app/Services/Costing/`) replays every product's documents in chronological order through a pure Moving Weighted
Average engine and stores the result:

- `App\Services\Costing\MovingAverageEngine` — pure function, no I/O: given one product/variant/warehouse's
  chronological movements, returns the ledger rows and running balance. Rules: a receipt (purchase, purchase return,
  transfer-in, positive adjustment) blends into the average; every kind of stock-out leaves at the current average
  except a sale return, which comes back at its *original sale line's* cost (so a return can never manufacture or
  destroy profit) and a purchase return, which leaves at the cost written on the return line itself. A receipt into a
  zero-or-negative balance resets the average to that receipt's cost. Rows carrying a fallback/estimated cost are
  flagged `is_estimated`.
- `App\Services\Costing\MovementSource` — loads a product's movements from the documents in one set-based query per
  source type (mirrors the inclusion rules already used by `ProductMovementLedgerService`: which statuses count,
  which warehouse a transfer affects and when, soft-deleted headers excluded, service products skipped).
- `App\Services\Costing\InventoryCostingService` — the write side: turns Moving Average on/off
  (`costing_method` in `inventory_cost_meta`, default `legacy` — deploying this changes nothing until an admin
  switches it), costs a batch of products (`syncProducts`), and keeps the ledger fresh for readers through four cheap
  checks so nothing in the UI ever has to wait for a full rebuild: (1) id high-water marks on the detail tables catch
  new documents, (2) a per-window row-count check catches an edit/status-flip/delete that didn't create a new id,
  (3) an on-hand-qty-vs-ledger-balance check catches stock changed by an import or a raw DB edit, (4) a full
  CRC32 fingerprint verification of every product's documents runs at most once per 30 seconds
  (`ensureFresh`) and on demand (`costing:rebuild --verify`). All four are read-triggered, not written on the
  document-save path — no controller that writes a Sale/Purchase/etc. was changed.
- `App\Services\Costing\CostingReader` — the only door reports/dashboards use. Every method is a no-op / pass-through
  while costing is off, so wiring a report to it never changes a number until Moving Average is switched on. When on:
  a sale line's COGS is its ledger row (immune to later master-cost edits); a sale return reverses the *original
  sale's* cost, never the current one; stock value is qty x running average per warehouse, and can be asked "as of"
  any date (last ledger balance on/before it); a Profit-report-style base query
  (`profitLinesBase`/`profitLinesTemp`) gives one row per completed sale line and received return line with its
  ledger cost, for reports that group by product/category/unit/customer/date/warehouse.

**Data (all new tables, nothing in a vendor table altered):** migration
`2026_09_26_000001_create_inventory_costing_tables.php` creates `inventory_cost_ledger` (one row per movement:
qty/cost/value delta, running balance and average, `is_estimated`), `inventory_cost_balances` (current balance per
product/variant/warehouse), `inventory_cost_seeds` (write-once valuation for on-hand stock no document explains — see
below), `inventory_cost_stamps` (write-once cost for an adjustment that adds stock but carries no cost of its own),
`inventory_cost_keys` (per-product document fingerprint, for `verify()`), `inventory_cost_corrections` (an audit trail
row every time re-costing changes a *previously posted* sale/return line's cost — e.g. an old GRN's cost gets
corrected behind the app), and `inventory_cost_meta` (the on/off switch and sync bookkeeping).

**Cost basis for a purchase line:** the app's own "Net Unit Cost" — the line's discount removed and its inclusive tax
carved out with the same formula already used by `PurchasesController::show`, converted to a per-base-unit cost (a
box purchase is divided by its pack size). Order-level discount/shipping/landed cost is **not** currently allocated
into unit cost — documented limitation, Phase 2 candidate.

**Unexplained stock (imports, marketplace syncs, a raw DB edit that changed `product_warehouse.qte` with no
document):** handled as write-once "seeds", never silently absorbed into the average or dropped. The *first*
unexplained amount for a product/warehouse is dated just before that key's first real movement and valued at the
product's master cost (this is the one-time "opening stock" valuation — exactly what an opening Adjustment would have
been, had one been entered); any *later* unexplained increase is valued at the running average at that point, and
dated now; an unexplained *decrease* is dated now with no cost (it only removes qty). An adjustment that adds stock
but was never given a cost gets the same write-once master-cost stamp. Both are recorded so the same unexplained
amount is never re-priced on a later resync.

**Historical stock value:** because every row keeps its running balance and average, "stock value as of 1 January" is
answered from the ledger (`CostingReader::valueAsOf`), not recomputed — no report has to guess what the average was
back then.

**Known limitations (by design, documented rather than silently approximated):**
- Undocumented opening stock is dated at the product's first real movement, not at the (unknown) date it actually
  arrived — its rupee value is still correct, only its *placement in time* is a best estimate.
- Order-level landed cost (freight, customs, etc. entered once for a whole purchase) is not yet allocated across
  lines; only the per-line Net Unit Cost is used.
- Damage and adjustment-*out* losses are tracked in the ledger but are **not** currently deducted from the Profit
  report's profit figure — this is an accounting-policy choice (some businesses expense write-offs immediately,
  others net them elsewhere) left to the business to decide; the cost is available (`adjustmentAbsCost`) for whoever
  wires that decision in.
- A receipt landing on a negative balance (stock had gone negative, e.g. a sale allowed to oversell) resets the
  average to that receipt's cost rather than preserving strict value conservation through the negative period — a
  known edge case of Moving Average costing generally, not specific to this implementation.
- Batch/expiry/GRN-level cost tracing (FIFO by batch) is **not** part of this phase — every GRN blends into one
  running average per product/variant/warehouse. If the business needs to trace cost back to a specific GRN/batch
  (e.g. expiry-driven stock, batch recalls), that is a Phase 2 design (FIFO/batch costing) with its own settings
  switch; Moving Average and FIFO are designed to be switchable per `costing_method` without re-touching the report
  hooks, since every hook reads through `CostingReader` rather than the engine directly.

**Report/dashboard hooks (all additive; each is a no-op while costing is off):**
`app/Traits/CalculatesCogsAndAverageCost.php` (Dashboard/Today Summary/P&L COGS), `ReportController`
(`inventory_valuation_summary`, `stock_inventory_valuation`, `Warhouse_Count_Stock`, `analyticsSummary` opening/closing
stock and adjustment cost, `negative_stock_report`, `deadStock`, the Adjustment report's `purchase_cost`),
`DashboardController` (`StockValue`), `TodaySummaryController` (stock at cost), `App\Support\Reporting\DashboardInsights`
(slow-stock value), `ProfitReportController` (base query, cost expression, and — for the by-dimension breakdown —
`CostingReader::profitLinesTemp`, which materializes the window's sale/return lines into one indexed temporary table
instead of re-running the ledger join for each of the count/rows/kpi/chart queries; the temp table is uniquely named
per request and dies with the connection, so nothing is cached or reused across requests), `ReportQuestionService`
(by-product profit cost).

**Enabling procedure (deploy -> migrate -> compare -> switch on):**
```
php artisan migrate --path=database/migrations/2026_09_26_000001_create_inventory_costing_tables.php
php artisan costing:rebuild --dry-run          # costs every product, prints legacy vs Moving Average COGS/stock
                                                # value side by side; nothing is switched on
php artisan costing:rebuild --apply --enable   # (re-)cost everything, then flip costing_method to moving_average
```
`--apply` is idempotent (safe to re-run any time, e.g. after a bulk import); `--verify` runs a full fingerprint check
on demand instead of waiting for the 30 s TTL. A daily `costing:rebuild --verify` is scheduled
(`app/Console/Kernel.php`) as a no-op safety net while costing is off, and a real nightly catch-all once it's on.

**Tests (all new, `tests/Regression/`):**
- `unit_costing_engine.php` — 34 pure-engine checks (no DB): blending, conservation, sale return at original cost,
  purchase return, transfer, negative stock, the estimated flag, determinism.
- `build_costing_scenario.php` — a small scenario through the real controllers (opening via adjustment; an
  import-style unexplained seed), checked against a hand-computed answer.
- `build_costing_realworld.php` — two warehouses, box/piece units, an approved transfer, a sale return, a purchase
  return, adjustments, damage — checked against an independent oracle across every report this phase touches (P&L,
  Profit report by every dimension, both stock-valuation reports, Dashboard/Today Summary stock value, analytics
  opening/closing), plus: editing a product's master cost moves nothing, editing an old GRN behind the app gets
  detected and logged as a correction, and a full rebuild from the documents matches the incrementally maintained
  ledger exactly.
- `build_costing_stress.php` — random operations (seed-controlled) against the same independent oracle; invariants:
  no dirty fingerprints after a settle, on-hand qty matches the ledger, no unexplained seed beyond the legitimate
  opening one, value conservation, and rebuild == incremental. `php tests/Regression/build_costing_stress.php <seed>
  <steps>`.
- `build_costing_large.php` — the "test with large data" pass: two warehouses' worth of independent bookkeeping
  across 200 products x 730 days (~287k movement lines), checking every sale line's stored COGS, every balance, the
  P&L and Profit report (by every dimension) against the independent books, both stock-valuation reports, and
  analytics opening/closing — plus first-time costing time, an idle refresh, a same-day new-sale refresh, a full
  verify, and the P&L/Profit-report response time, each asserted against a budget (all passed on a
  200-product/730-day/287k-line run: first-time costing ~37 s one-off, idle refresh ~5 ms, new-sale refresh ~0.3 s,
  full verify ~1.4 s, P&L legacy/active ~5.7 s/6.2 s, Profit report by-dimension legacy/active ~5.9 s/8.8 s).
  `php tests/Regression/build_costing_large.php [products] [days] [seed]`.

Run any of these with `DB_DATABASE=stk_v1 AUDIT_FK_OFF=1 php tests/Regression/<file>.php` against a scratch database
that has run the costing migration.

### Inventory Costing — update 2 (2026-09-26): Damage + Adjustment losses expensed in Profit & Loss / Dashboard / Today Summary

**Problem.** Standard accounting practice — and every professional inventory system (QuickBooks, Zoho Inventory,
Odoo) — expenses stock that is lost to damage or a shrinkage adjustment the moment it happens, the same way a sale's
COGS is expensed. This app's Profit & Loss, Dashboard and Today Summary never did: Damage documents and Adjustment
decreases silently reduced stock on hand with no effect on reported profit, so profit was systematically overstated
by whatever had been damaged or written down.

**Design decision (confirmed with the business):** an Adjustment **increase** (a stock count found MORE than the
system expected) is never counted as income — accounting conservatism: found stock corrects inventory value, but is
not recognised as a gain until it is actually sold. Only the **decrease** side of an Adjustment, and every Damage
line, are expensed. This mirrors how losses and gains are treated asymmetrically in every mainstream accounting
system.

**Implementation.** A new class, `App\Support\Reporting\InventoryWriteOffFigures::cost($from, $to, $warehouseId,
$warehouseIds)`, is the one place this is computed — mirroring `SalesFigures` / `CashFlowFigures`. It reads through
`CostingReader::writeOffCost()` (new method) when Moving Average costing is on — valuing the loss at the cost the
stock actually carried at the moment it was lost, from the ledger — and falls back to a direct master-cost query
(damage_details + adjustment_details `type = 'sub'`) when costing is off, exactly like every other legacy figure.
This is a general correctness fix, **not gated behind the costing switch** — every store gets accurate write-off
expensing whether or not Moving Average is enabled; only the cost *basis* changes with the switch.

Wired into:
- `ReportController::ProfitAndLoss` — new field `inventory_writeoff_sum`; both `profit_fifo` and `profit_average_cost`
  now subtract it.
- `DashboardController` — new field `today_inventory_writeoff`; `today_profit` subtracts it. Exposed to the Modern
  Dashboard's cost/expense/profit split (`InsightsSection.vue`) as its own row so the split still adds up to revenue
  exactly, rather than folding it into Expenses.
- `TodaySummaryController` — new field `profit.inventory_writeoff`; `profit.net` subtracts it.
- Classic `ProfitAndLossReport.vue` — new income-statement row (shown only when non-zero) and a new component in the
  "build your own profit formula" tool, enabled by default.

**What did NOT change:** the Profit report (`ProfitReportController`, per-product/category/date/customer/warehouse
profitability) — that report is specifically about sold products' profitability and has no natural place for a loss
that was never sold; Damage/Adjustment continue to show only in the stock/adjustment reports, as before.

**Translation:** `Inventory_writeoff` added to `database/seeders/translations/en.php` — run
`php artisan db:seed --class=Database\Seeders\TranslationSeeder --force` after deploying, or the label will show its
raw key on the Classic report (the Modern Dashboard's own `tt()` helper always falls back to English regardless).

**Tests:** `tests/Regression/build_writeoff_expense.php` — a Damage of 10, an Adjustment decrease of 5, and an
Adjustment increase of 8 on the same product, in BOTH costing modes: legacy write-off = master-cost × (damage +
decrease) exactly, matching in P&L/Dashboard/Today Summary; Moving-Average write-off = the ledger's actual cost at
the moment of loss (checked to differ from the master-cost figure, and to equal the ledger's own damage +
adjustment-decrease rows); the +8 increase is confirmed present in the ledger (it still corrects inventory value) but
absent from every write-off/profit figure. `build_costing_large.php` (the 287k-line large-data test) was extended
with an independent running tally of expected write-off cost through its whole 2-year simulation, checked against the
served figure. `audit_b4_profit_and_loss.php`'s profit-formula assertions now include the write-off term (0 in that
fixture, so the check is unchanged in effect, but the formula it encodes is now the current one).

**Frontend:** `resources/src/pages/reports/ProfitAndLossReport.vue` and
`resources/src/pages/dashboard/modern/sections/InsightsSection.vue` changed — rebuild required (`npm run
build:admin`).

**Files touched:** `app/Support/Reporting/InventoryWriteOffFigures.php` (new), `app/Services/Costing/CostingReader.php`
(`writeOffCost()`), `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/DashboardController.php`,
`app/Http/Controllers/TodaySummaryController.php`, `database/seeders/translations/en.php`,
`resources/src/pages/reports/ProfitAndLossReport.vue`, `resources/src/pages/dashboard/modern/sections/InsightsSection.vue`,
`tests/Regression/build_writeoff_expense.php` (new), `tests/Regression/build_costing_large.php`,
`tests/Regression/audit_b4_profit_and_loss.php`.

### Inventory Costing — update 3 (2026-09-26): Costing Method setting in System Settings

**Problem.** Switching between Legacy and Moving Average required SSH/CLI access to run
`php artisan costing:rebuild --apply --enable`. The business owner has no CLI access.

**Implementation.** A new, small controller — `App\Http\Controllers\Settings\CostingSettingsController` — wraps the
exact same flow the console command already uses (`InventoryCostingService::syncProducts()` / `setMethod()` /
`forgetMethodCache()`), exposed as `GET/POST costing_settings`. It adds **no new costing logic**: switching to
Moving Average costs every non-service product into the ledger (same chunked loop as
`CostingRebuild::costAll()`) only if the ledger is still empty or a `resync` flag is passed, then flips
`costing_method`. A 3,000-product cap refuses the web request for very large catalogs and points to the CLI command
instead (this business's real catalog is ~5 products, so this never bites in practice — it's a safety margin, not a
real limitation here).

**Frontend.** New self-contained `resources/src/pages/settings/CostingSettings.vue` (same pattern as the existing
`FeatureToggles.vue` — its own GET/POST, not part of the giant `settings/{id}` form) registered as a new "Costing
Method" item in System Settings' sidebar (`SystemSettings.vue`'s `embeddedPages` map + menu). Shows the current
method, a Legacy/Moving Average dropdown, a products-costed counter, and an optional "re-cost every product now"
checkbox.

**Explicitly out of scope:** a real lot/batch-level FIFO costing engine. This only switches between the two costing
methods that already exist.

**Translation:** `Costing_method`, `Costing_method_help`, `Legacy_master_cost`, `Moving_average`,
`Costing_tables_missing`, `Costing_products_costed` added to `database/seeders/translations/en.php` — reseed after
deploying.

**Tests:** `tests/Regression/build_costing_settings_ui.php` — GET reports tablesReady/method/stats correctly; POST
actually flips `InventoryCostingService::isActive()` both ways (checked via direct service calls, not just the JSON
response); switching to Moving Average with an empty ledger populates `inventory_cost_balances`; switching back to
Legacy leaves the already-costed ledger in place; `resync=true` re-costs even with existing rows; an unknown method
is rejected (422); the safety-cap guard's presence is checked in source (seeding 3,000+ real products to exercise it
end-to-end would be disproportionate for this size of catalog). Re-ran `unit_costing_engine.php`,
`build_costing_scenario.php`, `build_costing_realworld.php`, `build_writeoff_expense.php` and
`audit_b4_profit_and_loss.php` against a freshly migrated DB afterwards — all pass, no regression.

**Frontend:** `resources/src/pages/settings/CostingSettings.vue` (new), `resources/src/pages/settings/SystemSettings.vue`
— rebuild required (`npm run build:admin`).

**Files touched:** `app/Http/Controllers/Settings/CostingSettingsController.php` (new), `routes/api.php`,
`resources/src/pages/settings/CostingSettings.vue` (new), `resources/src/pages/settings/SystemSettings.vue`,
`database/seeders/translations/en.php`, `tests/Regression/build_costing_settings_ui.php` (new).

### Inventory Costing — update 4 (2026-09-25): Profit Report (legacy mode) valued COGS at today's cost, not history

**Problem — found during the 4-5 year historical audit requested by the owner.** With Legacy costing active,
`App\Http\Controllers\ProfitReportController::index()` valued every sale line as
`quantity * COALESCE(product_variant.cost, product.cost, 0)` — the product's **current** master/variant cost, read
fresh on every request. Every other report in this app that shows COGS in legacy mode (Profit & Loss, Dashboard,
Today Summary — all via `App\Traits\CalculatesCogsAndAverageCost`) already anchors the cost to the date being
reported on, precisely so that editing a product's cost today can never rewrite what already happened. The Profit
Report alone was still doing it the old, wrong way: opening the Profit Report for January and then correcting a
product's cost today silently changed January's reported cost and profit, with no sale, purchase or adjustment ever
being touched. This was dormant on the live site (Moving Average has been active there since update 1), but the
Costing Method Settings toggle shipped in update 3 makes switching back to Legacy a one-click action, which turned a
theoretical bug into a live risk — the owner asked for it fixed as soon as it surfaced.

**Root cause.** `averageCostBulk()` (in the shared trait) already computes exactly the right number — a weighted
average of purchase and adjustment history up to a given date — but only for the Profit & Loss report's callers, and
only for an explicit list of product/variant ids known in advance. The Profit Report groups by whichever
product/variant ids happen to appear in the requested date range, so that method couldn't be called directly without
either changing its signature (touching a trait shared by other controllers) or pre-computing an id list.

**Fix.** New standalone `App\Support\Reporting\HistoricalCostAtDate` (does not touch `CalculatesCogsAndAverageCost`).
Reimplements the same purchases-up-to-`$end` + adjustments-up-to-`$end`, weighted-average-per-key aggregation as a
single set-based query, materialized ONCE per request into an indexed temp table (`product_id, product_variant_id,
avg_cost`) — same pattern `CostingReader::profitLinesTemp()` already uses for the Moving Average branch of this same
controller, so the legacy branch now costs no more queries than before. `ProfitReportController`'s legacy `$base()`
query LEFT JOINs this temp table with a null-safe `product_variant_id <=> ` match (variant id is nullable), and the
cost expression becomes `quantity * COALESCE(historical_avg_cost, variant.cost, product.cost, 0)` — historical cost
first, current master/variant cost **only** when a product/variant has no purchase or adjustment history at all
(e.g. an opening balance written straight into stock with no document — the same one case the trait's own
`averageCostBulk()` falls back for). The Moving Average branch (`CostingReader::profitLinesTemp`) is untouched.

**Verification.** New test `tests/Regression/audit_fix_profit_report_legacy_cost.php`: a product with real purchase
history (10 @ 100, then 10 @ 120 — weighted average 110) sold for 5 units; a second product with no purchase/
adjustment history at all (opening stock written directly, no document). Runs the Profit Report for the sale's
month, then edits BOTH products' master cost (100 → 777, 60 → 999) and re-runs the same report for the same past
month: the first product's reported cost/profit is byte-identical before and after (the bug this fixes); the second
product's cost correctly follows the new master cost, because there is no history to anchor to (the documented,
correct fallback — not a regression). Also checks the KPI total still equals the sum of the row costs (the new LEFT
JOIN doesn't fan out any line) and that every other report dimension (warehouse/date/category/customer/unit) still
reconciles rows-to-KPI. All 17 checks pass. Re-ran `audit_b4_profit_and_loss.php` and the full
`build_full_audit_5yr.php` 5-year historical suite afterwards on a freshly migrated DB — no regression.

**Explicitly out of scope:** this does not change the Moving Average branch, does not add a real FIFO/batch costing
engine, and does not change what `CalculatesCogsAndAverageCost` does for the Profit & Loss report, Dashboard or
Today Summary (those were already historically correct).

**Files touched:** `app/Support/Reporting/HistoricalCostAtDate.php` (new),
`app/Http/Controllers/ProfitReportController.php` (legacy `$costExpr` + one LEFT JOIN),
`tests/Regression/audit_fix_profit_report_legacy_cost.php` (new). No migration, no frontend rebuild needed.

**Also in this update:** the update-3 translation strings were reworded to read like plain shop-owner language
instead of a technical spec (`Costing_method`, `Costing_method_help`, `Legacy_master_cost`, `Moving_average`,
`Costing_tables_missing`, `Costing_products_costed` in `database/seeders/translations/en.php` — only the *values*
changed, the keys are unchanged so nothing else needs updating). Reseed after deploying
(`php artisan db:seed --class=Database\Seeders\TranslationSeeder --force`).

### Inventory Costing — update 5 (2026-09-25): Legacy Profit Report — completed-only, returns netted, base units, per-warehouse cost

**Problem — found by an external code review of update 4.** Update 4 fixed ONE bug (cost anchored to a date instead
of today), but the Legacy branch of `ProfitReportController` had several other, independent, PRE-EXISTING defects,
none introduced by update 4, all confirmed live against a throw-away database before being fixed here:

1. No `sales.statut = 'completed'` filter — a **pending/draft sale was counted as realized revenue and profit**.
2. No sale-return handling at all — a **received sale return was never netted off** revenue/quantity/cost.
3. Cost was `quantity * unit_cost` using the raw SALE-unit quantity (e.g. "2 boxes"), not the base-unit quantity — a
   **box/pack sale understated COGS** by the pack/unit conversion factor (reproduced: a 2-box sale of a 12-per-box
   product costed as 2 base units instead of 24).
4. The date-anchored average cost (update 4) was blended across ALL allowed warehouses when no single warehouse was
   selected — **"Profit by Warehouse" applied the same blended cost to every warehouse row**, even when each
   warehouse's actual purchase cost was different.

All four were reproduced against a live disposable MySQL database (not just read as code) — see
`PROFIT_REPORT_REVIEW_RESPONSE_2026-09-25.md` for exact reproduction steps/numbers, shared with the owner.

**Fix.**
- New `App\Support\Reporting\LegacyProfitLines` — one normalized set of legacy profit lines (completed sales UNION
  received sale returns, returns negated), with quantity converted to BASE units via `UnitQuantityResolver` using the
  exact same math `App\Traits\CalculatesCogsAndAverageCost::cogsSoldQty()`/`cogsReturnedQty()` already use for the
  Profit & Loss report — materialized once per request into an indexed temp table, same pattern as
  `CostingReader::profitLinesTemp()` (the Moving Average branch).
- `App\Support\Reporting\HistoricalCostAtDate` now groups and outputs `warehouse_id` as part of the key (was
  product/variant only), so each warehouse gets its own average cost, never a blended one; also now takes an
  optional product-id list so it only aggregates history for products actually sold/returned in the report window
  (performance — the previous version scanned every product with any purchase/adjustment history at all, flagged as
  a real concern by the review, measured in `build_full_audit_5yr.php`'s timing table).
- `ProfitReportController` now builds its per-dimension query from `LegacyProfitLines` (legacy) or
  `CostingReader::profitLinesTemp()` (Moving Average) — the SAME dimension/label/join logic now serves both costing
  modes (previously duplicated), reading `cost = base_quantity * COALESCE(historical_avg_cost, variant.cost,
  product.cost, 0)` joined on product_id **+ warehouse_id** + a null-safe variant match.

**Explicitly NOT fixed — documented, permanent Legacy limitations, not further engineering:**
- **One report-window average, not transaction-time cost.** A purchase recorded AFTER a sale, but still before the
  report's `to` date, can still move that earlier sale's reported cost within the SAME report. Building true
  transaction-time costing into Legacy would duplicate the Moving Average ledger — use Moving Average instead if this
  matters.
- **Adjustment history has no stamped cost.** A purchase's cost is a value stored on the purchase line itself
  (stable forever); an old ADJUSTMENT addition has no equivalent — it is still valued at the CURRENT
  `product(_variant).cost` when the average is computed, so a key with adjustment history in its mix can still shift
  with a later master-cost edit. Moving Average's ledger stamps a cost once, going forward, and does not have this
  gap for new documents.
- Legacy is Legacy — for exact, reliable COGS/inventory value/historical profitability, Moving Average is the
  authoritative method. Legacy is now labeled "Legacy / Estimated Cost" in System Settings, with an on-screen warning
  when it's selected, and a confirmation prompt before switching FROM Moving Average back TO Legacy.

**Verification.** `tests/Regression/audit_fix_profit_report_legacy_cost.php` (update 4's test) still passes unchanged
— its scenarios use the default unit (1:1 base-quantity conversion), so nothing in it depended on the bugs above.
New checks reproduce all four defects fixed above (before: bug present; after: fixed) plus the two documented
limitations (before/after: still present, by design) — see `tests/Regression/audit_fix_profit_report_legacy_correctness.php`.
Re-ran `unit_costing_engine.php`, `build_costing_scenario.php`, `build_costing_realworld.php`,
`build_writeoff_expense.php`, `build_costing_settings_ui.php`, `audit_b4_profit_and_loss.php` and the full 5-year/
800k-line `build_full_audit_5yr.php` afterwards — all pass, no regression to Moving Average or any other report.

**Frontend:** `resources/src/pages/settings/CostingSettings.vue` — new warning banner when Legacy is selected, and a
confirmation dialog before switching from Moving Average to Legacy. **Needs `npm run build:admin`.**

**Translation:** `Legacy_master_cost` reworded to "Legacy / Estimated Cost"; `Moving_average` now says "(tracked
automatically, accurate)"; new keys `Costing_legacy_warning`, `Costing_switch_to_legacy_confirm_title`,
`Costing_switch_to_legacy_confirm_body` — reseed after deploying.

**Files touched:** `app/Support/Reporting/LegacyProfitLines.php` (new),
`app/Support/Reporting/HistoricalCostAtDate.php` (now warehouse-keyed + product-id-scoped),
`app/Http/Controllers/ProfitReportController.php` (legacy branch rebuilt on `LegacyProfitLines`, unified dimension
array), `resources/src/pages/settings/CostingSettings.vue`, `database/seeders/translations/en.php`,
`tests/Regression/audit_fix_profit_report_legacy_correctness.php` (new),
`tests/Regression/build_full_audit_5yr.php` (stale comment corrected). No migration.

### Sale Return pack/unit integrity (2026-09-25)

**Problem — found by a follow-up external review of update 5.** Two further, PRE-EXISTING defects in the Sale
Return flow itself (neither caused by update 5), both confirmed live against a throw-away database:

1. **Pack snapshot lost on return.** `SalesReturnController::create_sell_return()` — the endpoint that pre-fills
   the return form — never sent back a sale line's `product_pack_id`/`pack_multiplier`/`pack_name` at all.
   `resources/src/pages/sale_return/SaleReturnForm.vue` defaults the missing field (`pack_multiplier: d.pack_multiplier
   ?? 1`), so a "2 packs of 6" sale silently became a "return of 1 base unit" the moment it was returned —
   understating the restocked quantity AND (in Legacy mode) the reported COGS reversal by a full pack's worth.
   Reproduced: sell 2 packs of 6 (12 base units) at cost 10, receive a return of 1 pack — stock was only credited
   +1 (not +6) and the Legacy Profit Report showed a net cost of 110 instead of the correct 60.
2. **Fallback sale unit discarded, then a hard crash instead of a clean rejection.** When a sale line had no
   explicit `sale_unit_id`, the code resolved the product's default sale unit into `$unit` and then immediately
   discarded it with an unconditional `$unit = null;` — repeated identically in `store()`, `update()`, `destroy()`
   and `delete_by_selection()`. The prefill response then sent back `sale_unit_id: ''` (empty string, not null).
   Submitting a return with that value did not silently skip stock restoration as expected — it threw an
   **uncaught FK `QueryException` (HTTP 500)**, rolled back by the transaction (no partial data), but with a raw
   SQL error reaching the client instead of a clean validation message.

**Fix.** One new class, `App\Support\SaleReturnStock`, is now the single implementation of pack/unit resolution and
stock mutation for every Sale Return code path:

- `resolveUnit($saleUnitId, $productId)` — resolves the effective unit, keeping a resolved default-unit fallback
  instead of discarding it (fixes defect 2's root cause).
- `deriveSnapshot($saleId, $productId, $variantId)` — re-derives `sale_unit_id`/`product_pack_id`/`pack_multiplier`/
  `pack_name` **server-side**, from the ORIGINAL sale detail (looked up by sale_id + product_id + product_variant_id,
  the same key `edit_sell_return()` already used) — a browser-submitted value for any of these fields is never
  trusted for a return line again (fixes defect 1: a resubmitted wrong `pack_multiplier` is simply ignored). Throws
  `\InvalidArgumentException` (caught into a clean 422, the same pattern `SaleReturnLimits` already used) when the
  product/variant isn't on the referenced sale, or when a non-service product has no unit that can be resolved for
  it — never an uncaught `QueryException`/500 reaching the database (fixes defect 2's failure mode). A service
  product (`type = 'is_service'`) legitimately has no unit; that is not an error.
- `baseQuantity()` / `applyStock()` — one signed base-unit-quantity computation and one locked stock mutation
  (`StockMutator::lockOrCreate`, replacing an unlocked `product_warehouse::first()` + conditional `save()` that
  could silently no-op when no stock row existed yet for that product/warehouse), used identically by `store()`,
  `update()`, `destroy()` and `delete_by_selection()`.
- `store()`/`update()` now correct every submitted return line's pack/unit fields BEFORE calling
  `SaleReturnLimits::assertValid()` — so that check's own base-quantity/charged-amount math (which reads
  `sale_unit_id`/`pack_multiplier` straight off the `$details` it is given) becomes correct too, with **no change
  needed inside `SaleReturnLimits` itself**.
- `update()`'s old `no_unit !== 0 || is_service` gate is removed — it used to silently DROP an entire return line
  (not just its stock mutation) whenever the line had no explicit `sale_unit_id`, even a perfectly valid return with
  a resolvable default unit. Every line is now resolved-or-rejected upfront, so nothing is left to gate on.
- `destroy()`/`update()`'s reversal loop no longer gates stock-reversal-and-removal on `sale_unit_id !== null`
  either — a line with no explicit unit previously could never have its stock reversed NOR be removed from the
  return via `update()` at all.

**Moving Average is fixed for free.** `App\Services\Costing\MovementSource::saleReturns()` computes its own
base-unit quantity for the ledger reversal directly from the persisted `sale_return_details` row
(`sale_unit_id`/`pack_multiplier`), independent of the Legacy report. Once `store()`/`update()` persist the
CORRECT values, the Moving Average ledger reversal is correct too — no Costing-engine code was touched.

**Verification.** New `tests/Regression/audit_fix_sale_return_pack_unit.php` (9 scenarios: pack sale → partial pack
return with exact stock/pack-snapshot/Legacy-COGS assertions; variant + pack return; null `sale_unit_id` with a
valid product default resolving correctly; a completely unresolvable unit rejected with a clean 422 and NO
header/detail/stock mutation left behind; a Moving Average control proving the ledger reversal is exact after the
same fix) — all PASS. Re-ran the full existing battery afterwards with no regressions: both Profit Report
correctness tests, the whole Costing suite (`unit_costing_engine`, `build_costing_scenario`,
`build_costing_realworld`, `build_costing_stress`, `build_writeoff_expense`, `build_costing_settings_ui`,
`audit_b4_profit_and_loss`), and — because `SalesReturnController` was rewritten — every existing regression test
that exercises Sale Returns: `audit_b1_sale_return`, `audit_b2_stock_documents`, `audit_b5_money_stress`,
`audit_b5_stock_stress`, `audit_b5_unit_conversion`, `audit_b5_variant_stress`, `build_d2_product_analytics`,
`build_n1_product_variant_display_name`, and the full 5-year/800k-line `build_full_audit_5yr.php`.

**Files touched:** `app/Support/SaleReturnStock.php` (new),
`app/Http/Controllers/SalesReturnController.php` (`create_sell_return()`/`edit_sell_return()` prefill now carry the
pack snapshot and a resolved unit; `store()`/`update()`/`destroy()`/`delete_by_selection()` rebuilt on
`SaleReturnStock`), `tests/Regression/audit_fix_sale_return_pack_unit.php` (new). No migration, no frontend change
(the existing Vue form already round-trips whatever the prefill sends — the fix is entirely in what the backend
sends/derives/trusts).

### Adjustment PDF costing parity + ReportQuestionService salesByProduct rebuild (2026-09-25)

**Problem.** `AdjustmentController::adjustment_pdf()` valued every line at `$unitCost * $qty` where `$unitCost`
came straight off the product/variant's live master cost — even when Moving Average costing was ON, so the
printed Adjustment PDF's "purchase cost" column disagreed with `stockAdjustmentReport()` (the on-screen report),
which already used the ledger. Separately, `ReportQuestionService::salesByProduct()` (the natural-language "sales
by product" report question) built its own raw `SaleDetail` join with the same live-master-cost problem the
Legacy Profit Report had already been fixed for elsewhere, so it could disagree with the Profit Report for the
same period/product.

**Fix.** New `App\Support\Reporting\AdjustmentLineCost::forDetails(array $detailIds): array` — returns the
absolute ledger cost per adjustment-detail id when Moving Average is active (summed `ABS(value_delta)` from
`inventory_cost_ledger` for `source_type = 'adjustment'`), or an empty array when costing is off (caller falls
back to the existing master-cost calculation, unchanged for Legacy). `adjustment_pdf()` now looks up this map
once before the details loop and prefers it: `$costByDetailId[$detail->id] ?? ($unitCost * $qty)`.

`ReportQuestionService::salesByProduct()` was rebuilt on the same normalized-lines infrastructure the Legacy
Profit Report fix already introduced — `App\Support\Reporting\LegacyProfitLines::temp()` /
`App\Services\Costing\CostingReader::profitLinesTemp()` — instead of its own raw join, so it now agrees with the
Profit Report by construction. Both of those methods also gained optional `bool $viewRecords = true, ?int $userId
= null` parameters (default preserves every existing caller) so a future "my records only" filter can reuse them
without another rewrite; `qty` stays the raw sale-unit `SUM(sd.quantity)` (unchanged display semantics), only
`cost` changed.

**Files.** `app/Support/Reporting/AdjustmentLineCost.php` (new), `app/Http/Controllers/AdjustmentController.php`
(`adjustment_pdf()`), `app/Services/Costing/CostingReader.php` (`profitLinesBase()`/`profitLinesTemp()` signature
extension), `app/Support/Reporting/LegacyProfitLines.php` (`temp()` signature extension),
`app/Services/ReportQuestionService.php` (`salesByProduct()` rebuilt), `tests/Regression/
audit_fix_adjustment_pdf_costing_parity.php` (new — proves `AdjustmentLineCost` total equals the on-screen
report's `purchase_cost` for the same document, in both costing modes; smoke-tests the PDF itself renders).
No migration, no frontend change.

### MF-05: POS/Sales canonical product type — no client-trust bypass (2026-09-25)

**Problem (external "Must-Fix" audit 2026-09-25, MF-05).** `PosController::CreatePOS()` decided whether a line
was a service (skip stock validation/deduction entirely) from the client-submitted `product_type` field. A
request could describe a genuinely physical product as `is_service` and bypass stock deduction completely — sell
any quantity of a real product with zero effect on stock. The identical spoof existed in the Multi-Pack
oversell guard (`assertPackStockSufficient()`, duplicated verbatim in both `PosController` and `SalesController`)
via the same client-trusted field. Also found in the same pass: `CreatePOS()`'s stock-deduction block still used
a raw `product_warehouse::where(...)->first()` lookup — silently skipped the deduction when no row existed yet
for a product never stocked in that warehouse (instead of creating one), the same defect class already fixed
elsewhere via `StockMutator::lockOrCreate()`.

**Fix.** `product_type` is never read from the request again for this decision: `PosController::CreatePOS()` now
resolves `$isService` from `Product::find($id)->type` and `assertPackStockSufficient()` in both controllers now
loads the product first and checks ITS `type` column, not the request line. The shared
`App\Support\StockGuard::needsFromLines()` (used by Sales/Transfers/Adjustment/etc. to build the "what do we
need" list `assertAvailable()` then checks) had the same client-trust bug — it now resolves the product from the
database before deciding whether to include a line in the needs list at all, so a spoofed line can no longer
avoid the availability check either. `CreatePOS()`'s stock-deduction block now uses
`StockMutator::lockOrCreate()`, matching every other stock-mutating controller.

**Files.** `app/Http/Controllers/PosController.php` (`CreatePOS()`, `assertPackStockSufficient()`),
`app/Http/Controllers/SalesController.php` (`assertPackStockSufficient()`, identical duplicated method),
`app/Support/StockGuard.php` (`needsFromLines()`), `tests/Regression/audit_fix_mf05_pos_product_type_spoof.php`
(new — type-spoof stock-deduction bypass, type-spoof pack-oversell-guard bypass, never-stocked-product row
creation). No migration, no frontend change (the client field is simply ignored server-side now).

### MF-03: Damage quantity validation + authoritative availability check (2026-09-25)

**Problem (external "Must-Fix" audit 2026-09-25, MF-03).** `DamageController::store()`/`update()` had no
positive-quantity rule at all — a negative damage quantity literally INCREASED stock (`delta = -(-5) = +5`) — and
no authoritative locked availability check. An over-large damage recorded the FULL submitted quantity on the
document (batches, movement history, write-off expense all read that stored quantity), while
`applyStockDelta()`'s `$clampFloor` silently truncated the REAL stock movement to whatever was actually on hand
— so the document could permanently disagree with the real stock movement it supposedly caused. `store()` also
had no warehouse authorization at all (a restricted user could submit any `warehouse_id`), unlike every other
stock-changing module.

**Fix.** A validation pass (`firstInvalidDamageQuantity()`) runs before any write and rejects a non-numeric,
non-finite, zero or negative quantity with a clean 422 — no partial mutation. The silent clamp is removed
entirely; `App\Support\StockGuard::assertAvailable()` (the same locked, "Allow overselling"-aware check every
other stock-changing module already uses) now runs first inside the transaction and rejects with 422 when the
submitted quantity exceeds what is actually on hand — so a damage document's quantity and the real stock
movement can never disagree again. With "Allow overselling" ON, the full quantity is still applied (goes
negative, matching every other module's behavior under that switch) rather than floored at zero.
`abortIfWarehouseDenied($request->warehouse_id)` was added to `store()` (parity with `update()` and every other
module).

**Files.** `app/Http/Controllers/DamageController.php` (`firstInvalidDamageQuantity()`, `damageStockNeeds()` new;
`store()`/`update()` call both before mutating; `$clampFloor` argument removed from all `applyStockDelta()`
calls), `tests/Regression/audit_fix_mf03_damage_validation.php` (new — negative/zero/non-numeric rejection,
over-large-damage rejection not clamped, valid-damage exact reconciliation, overselling-ON negative-stock
behavior, `update()` increase/decrease scenarios). No migration, no frontend change (a client already sending a
sane quantity sees no difference; only invalid/over-large submissions now get a clear rejection instead of a
silently wrong document).

### MF-07: SalesController legacy null-unit stock reversal leak (2026-09-25)

**Problem (external "Must-Fix" audit 2026-09-25, MF-07).** The same "resolve a fallback unit, then immediately
discard it via an unconditional `$unit = null;`/`$old_unit = null;` placed AFTER the resolution attempt" defect
already found and fixed in `SaleReturnStock`/`SalesReturnController` (see "Sale Return pack/unit integrity"
above) turned out to be present INDEPENDENTLY in 8 more locations in `SalesController.php` — evidently the same
copy-paste pattern, not a shared root cause. In `update()` and `delete_by_selection()` specifically, the
discarded unit gated the entire stock-restore block (`if ($old_unit) { ... }`), so editing or bulk-deleting an
old/legacy sale line whose `sale_unit_id` was NULL silently skipped giving its stock back — the deducted stock
leaked permanently even though the sale itself was changed or removed. The other 6 occurrences (`show()`,
`Print_Invoice_POS()`, `Sale_PDF()`, `Sale_PDF_Inline()`, `edit()`, `get_Products_by_sale()`) are display/PDF/
prefill methods where the same discard caused a missing unit label instead of a stock leak.

**Fix.** All 8 occurrences keep the resolved fallback unit instead of discarding it (the null-init moved BEFORE
the resolve attempt, matching the correct pattern already used by `publicInvoiceView()` elsewhere in the same
file). Two structurally similar but NOT-buggy occurrences were deliberately left untouched:
`renderSaleInvoiceHtml()` (a deliberate no-fallback design) and a Quotation-to-Sale conversion method (a
different, out-of-scope issue — no fallback attempted at all, not a discard-after-resolve).

**Files.** `app/Http/Controllers/SalesController.php` (`update()`, `delete_by_selection()`, `show()`,
`Print_Invoice_POS()`, `Sale_PDF()`, `Sale_PDF_Inline()`, `edit()`, `get_Products_by_sale()`),
`tests/Regression/audit_fix_mf07_sale_null_unit_reversal.php` (new — proves exact stock arithmetic, old quantity
handed back then new quantity reapplied, across `update()` qty-increase, `update()` qty-decrease and
`delete_by_selection()`, all using a simulated legacy NULL `sale_unit_id` row). No migration, no frontend change.

### MF-14: write-off expense valued at date-anchored historical cost (2026-09-25)

**Problem (external "Must-Fix" audit 2026-09-25, MF-14).** `App\Support\Reporting\InventoryWriteOffFigures::cost()`
(the legacy/costing-off branch feeding the inventory-write-off figure in Profit & Loss, Dashboard and Today
Summary) valued every Damage and Adjustment-decrease loss at TODAY's live product/variant `cost` column. Editing
a product's cost today silently rewrote the write-off expense of every past period that had already reported it
— the same bug class already fixed for legacy COGS in `ProfitReportController` via `HistoricalCostAtDate`.

**Fix.** Anchored the same way: one date-anchored average cost per (product, variant, warehouse) as of the
report's `to` date, built from purchase history up to that date, via the existing `HistoricalCostAtDate::temp()`.
A NULL average (no purchase history yet for that key) falls back to today's master/variant cost — the same
documented fallback contract that class already has. `HistoricalCostAtDate::temp()` gained a new
`bool $includeAdjustments = true` parameter (default preserves `ProfitReportController`'s existing call
unchanged). `InventoryWriteOffFigures` passes `false`: it is itself pricing Adjustment-decrease write-offs, so
folding adjustments into the very average used to price them is self-referential — a `'sub'` adjustment in that
average is valued at today's master cost (not a stamped historical one), and when the same adjustment being
priced is also in the average, this produced badly wrong (even negative) blended averages, confirmed by a
failing test before this restriction was added. A purchases-only average avoids that for this caller.

**Files.** `app/Support/Reporting/HistoricalCostAtDate.php` (`temp()` gains `$includeAdjustments`),
`app/Support/Reporting/InventoryWriteOffFigures.php` (`cost()` rebuilt on the historical-cost temp table),
`tests/Regression/audit_fix_mf14_writeoff_historical_cost.php` (new — report figure uses the purchase cost in
force at the time; an already-reported figure is unchanged by a later master-cost edit; no-history fallback to
master cost; Adjustment-decrease priced consistently with Damage). Also updated
`tests/Regression/build_writeoff_expense.php`: its legacy-mode expectation was hard-coded to master cost 100,
only ever coincidentally right because the test lacked purchase history; it does have a same-day purchase at
cost 120, so under the fix the correct historical-average basis is 120 — expected figure and comment updated
(the Moving Average half of that test is untouched). No migration, no frontend change.

### Zone/Area → Bangladesh Division linking (2026-09-27)

**Why.** Imran's Zone/Area list (`sale_zones`) is free text with no geography behind it, so there was no way to
group sales by Division/State for reporting. He wants each Zone/Area optionally linked to one of Bangladesh's 8
Divisions, auto-detected from the Zone's name (e.g. a zone named "Gazipur" is under Dhaka Division) but always
overridable by a human — never a silent, possibly-wrong guess — and a Zone/Area should be deletable once nothing
still uses it (no delete existed before).

**Schema** — migration `database/migrations/2026_09_27_000001_create_bd_geography_and_zone_division_link.php`
(idempotent — `Schema::hasTable`/`hasColumn` guarded, safe to run twice):
- New reference tables `bd_divisions` (id, name unique, sort_order) and `bd_districts` (id, `division_id` FK
  cascadeOnDelete, name unique, `aliases` JSON nullable) — seeded in the migration itself with Bangladesh's real
  8 Divisions / 64 Districts (verified against Wikipedia, 2026-09-25), including known alternate spellings as
  aliases (Bogra/Bogura, Jessore/Jashore, Chittagong/Chattogram, Comilla/Cumilla, Cox's Bazar variants, etc.).
- New column `sale_zones.division_id` — nullable FK to `bd_divisions`, `nullOnDelete()`. A Zone/Area is not
  always a real district (custom delivery zones, city sub-areas), so this is never a required field.
- A one-time backfill in the same migration: every EXISTING `sale_zones` row is matched (exact, normalized,
  case/punctuation-insensitive) against the district+alias list and linked when it matches — left `NULL`,
  never guessed, when nothing matches.

**Backend:**
- `app/Models/BdDivision.php`, `app/Models/BdDistrict.php` — new, trivial reference-data models (`districts()`/
  `zones()` on Division; `division()` on District).
- `app/Support/BdDistrictMatcher.php` — new. Resolves a free-text Zone/Area name to a Division id by exact match
  (never fuzzy/"contains") against every district's canonical name and its aliases, normalized
  (lower-cased, non-alphanumeric stripped). Returns `null` on no match — a human always makes the final call.
  Cached forever (`bd_district_matcher_map`), invalidated via `forgetCache()`.
- `app/Models/SaleZone.php` — `division_id` added to `$fillable`; new `division()` `belongsTo(BdDivision)`.
- `app/Services/SaleLookupService.php` — `zones()` now eager-loads `division`; new `divisions()` (the 8-row
  list for the dropdown) and `suggestDivisionId()` (live-suggest while typing). `createZone()`/`updateZone()`
  gained a `bool $divisionProvided` + `?int $divisionId` pair: when the caller explicitly chose (or explicitly
  cleared) a Division, that choice always wins; otherwise the Division is (re-)resolved from the name via
  `BdDistrictMatcher`. This applies symmetrically to both create AND edit, per the client's explicit answer
  ("auto-detect... both at creation and edit time; a human can always override or clear it"). The shared
  private `createOrRestore()`/`update()` helpers gained an optional `array $extra` merged into the
  create/update payload, threaded through so `SaleCourier` (which never passes `$extra`) is unaffected.
  New `destroyZone()`: refuses (via `ValidationException`) to delete a Zone/Area that still has any Sale
  referencing it; otherwise deletes it (soft delete, same as the rest of this table).
- `app/Http/Controllers/SaleMetaController.php` — new `divisions()` (`GET sale_divisions`) and
  `suggestDivision()` (`GET sale_zones/suggest_division`) endpoints; `storeZone()`/`updateZone()` now read
  `division_id` explicitly-vs-absent from the request (absent = auto-resolve, used by the quick "+ add new"
  picker which only ever sends `{name}`; present, even empty, = the human's explicit choice) and return the
  loaded `division`; new `destroyZone()` (`DELETE sale_zones/{zone}`) returning a clean 422 with a readable
  message when the zone still has sales, 200 otherwise.
- `routes/api.php` — added `GET sale_divisions`, `GET sale_zones/suggest_division`,
  `DELETE sale_zones/{zone}`.

**Frontend:** `resources/src/pages/sales/SaleLookupManager.vue` (the existing Zone/Area & Courier admin page) —
added a "Division / State" column (Zone tab only, shows "—" when unlinked); a "Division / State" dropdown in the
create/edit modal (Zone tab only, `allow-clear`), pre-filled by a 400ms-debounced live suggestion while typing
the name, but any manual pick (or clear) by the user stops further auto-suggestions from overwriting it for the
rest of that create/edit; opening Edit on an already-linked zone always treats its current Division as a
deliberate choice (never silently overwritten by a later name tweak). A delete button (Zone tab only) is
disabled with a tooltip while `sales_count > 0`, otherwise asks for confirmation and calls the new delete
endpoint. The Sale form itself is unchanged — it still only picks a Zone; Division is always derived, never a
separate field there, per the client's request.

**Files.** New: `database/migrations/2026_09_27_000001_create_bd_geography_and_zone_division_link.php`,
`app/Models/BdDivision.php`, `app/Models/BdDistrict.php`, `app/Support/BdDistrictMatcher.php`,
`tests/Regression/build_zone_division_linking.php`. Modified: `app/Models/SaleZone.php`,
`app/Services/SaleLookupService.php`, `app/Http/Controllers/SaleMetaController.php`, `routes/api.php`,
`resources/src/pages/sales/SaleLookupManager.vue` (needs `npm run build:admin`). Nothing about `SaleCourier`
changed in behavior — verified by the shared `Option B permission contracts` regression test still passing.
