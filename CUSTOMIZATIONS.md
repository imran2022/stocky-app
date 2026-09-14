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

## 25. Build E2 — Sale metadata validation + Zone/Courier creation authorization

**Status:** ACTIVE. **Base required:** Build E1.

- New `app/Support/SaleMetadataRules.php` centralizes validation for
  `zone_id`/`courier_id` (must reference an active, non-deleted row),
  `tracking_ref`/`consignment_id` (max 255), `box_qty` (0–99999999.99), and
  `shipping_status` (whitelist of 5 known values). Applied to Sale create/
  update, `sales_bulk_update`, and Shipment update — previously these fields
  had no server-side validation at all, so a bad `zone_id` (e.g. a deleted
  or non-existent zone) surfaced as a raw 500 DB-integrity error instead of a
  clean 422.
- `SaleMetaController::storeZone()`/`storeCourier()` previously had no
  authorization check beyond the global `auth:api` middleware — any
  authenticated user could create zone/courier lookup rows regardless of
  role. Now requires a permission tied to Sale/POS/Shipment access ("Option
  B" model — chosen over a brand-new dedicated permission so staff who
  already manage sales/shipments aren't newly locked out). Includes
  race-condition-safe duplicate-name handling and soft-deleted-row restore.

## 26. Build E3 — PosSales.vue Seller/Currency column parity

**Status:** ACTIVE. **Base required:** Build E2.

`Sales.vue` (the main Sales list) already had `Seller` and `Currency` columns
from the vendor 5.8 Multi-Currency/Seller-tracking features. `PosSales.vue`
(our own separate "POS Sales" view, built by duplicating Sales.vue's
structure before those vendor columns existed) never received them. Added
both columns to `PosSales.vue`, matching Sales.vue's exact convention
(Currency column `defaultHidden: true`, only shown when multi-currency is
enabled). No backend change — the data was already being returned.

## 27. Build F — Stock Lookup soft-delete/unit-label/variant-price cleanup

**Status:** ACTIVE. **Base required:** Build E3.

Three related, previously-unverified audit findings, all inside
`ProductsController` methods backing the Stock Lookup tool and
`StockLookup.vue`:

- Soft-deleted product variants were not excluded from variant search,
  detail rows, or the per-warehouse stock aggregation — a deleted variant
  could still appear in Stock Lookup results and contribute phantom stock
  quantity. Fixed with `whereNull('deleted_at')` on the variant relation/
  subqueries.
- The frontend hardcoded the unit label as `"Pcs"` regardless of the
  product's actual unit. Backend now returns `unit_label` (from the
  product's real `Unit` relation); frontend renders that instead.
- Variant price display now correctly shows a single price, a min–max
  range, or "Varies" (when no active-priced variant exists) — previously it
  could include soft-deleted variants' prices in the range calculation.

## 28. Build G1 — Unit/Multi-Pack quantity normalization for Product Insights

**Status:** ACTIVE. **Base required:** Build F.

Sold (30d)/Previous 30d/Lifetime Sold/Lifetime Returned/Return Rate on the
Products list previously summed `sale_details.quantity`/
`sale_return_details.quantity` as stored — one row per sale line, regardless
of whether that line was sold as a single unit, a Multi-Pack Selling bundle,
or a non-base sale unit (e.g. a "Carton" of 24). A product sold as "3
Cartons + 2 pcs" showed Sold (30d) = 5 (the raw line count) instead of the
actual 74 units moved, and Return Rate could exceed 100% the same way.

New, isolated `app/Support/UnitQuantityResolver.php` converts each line's
quantity into base units — `quantity × pack_multiplier × unit_conversion`,
using the exact same `operator`/`operator_value` convention already used
for stock deduction elsewhere in this codebase (see `PosController`) —
before it's summed in `ProductInsightService`. The Products list's Sold
(30d) column sort was updated to use the identical expression, so sorting
and the displayed number can't disagree. A business that never uses a
non-base unit or Multi-Pack Selling sees no change (multiplier resolves to
1 for every line). Verified against live data: 2 pcs + 3 Cartons (24 each)
correctly produced Sold (30d) = 74, not 5.

## 29. Phase 0 — Purchase form quick wins

**Status:** ACTIVE. **Base required:** Build G1.

Three small, independent additions to the Purchase (GRN) creation form:

- **Last Purchase hint** — shows the most recent received-purchase cost,
  date, and supplier under the product name/code when adding an item,
  using a single most-recent-row lookup added to the existing per-item
  `show_product_data` endpoint (already called once per item added — no
  new per-keystroke or per-list-row query).
- **Net Unit Cost inline-editable** — the cost cell in the line table can
  now be edited directly (matching how Quantity already worked), instead of
  requiring the pencil-icon modal for a plain cost change. Writes to the
  same underlying `Unit_cost` field and goes through the same
  `recomputeCostLine()` the modal's Save already used, so a line with its
  own discount/tax still computes correctly.
- **Sell Price + Profit Margin % columns** — Sell Price reuses `Unit_price`
  (already computed by `show_product_data`, no new query); Profit Margin %
  is a pure client-side calculation from the row's own Sell Price and Net
  Cost.

Deliberately excluded from POS/thermal receipt printing — this is a
business-specific need (shown only on the A4 invoice PDF, which already had
it via the `enable_box_qty` setting toggle), not a universal requirement.

## 30. Purchase Order (PO) + GRN linkage

**Status:** ACTIVE. **Base required:** Phase 0. **Real schema change** — 6
new migrations (see below), unlike the SAFE-overlay builds above.

A Purchase Order precedes a GRN (`Purchase`) and does not itself affect
stock. New tables: `purchase_orders`, `purchase_order_details`,
`purchase_order_documents`; nullable `purchase_order_id`/
`purchase_order_detail_id` link columns added to the existing `purchases`/
`purchase_details` tables (NULL for the original, unchanged direct-purchase
flow); a new coarse-grained `purchase_orders` permission (matching the
existing single-permission `shipment` convention, not Purchases' four-way
split), auto-granted to whichever role(s) already had `Purchases_view`.

**Status lifecycle:** `draft`/`ordered`/`cancelled` are user-set;
`partially_received`/`received` are computed exclusively by the new,
isolated `app/Services/Custom/PurchaseOrderReceiptService.php` the moment a
GRN is received against a PO — never set by hand, and deliberately excluded
from the frontend's status dropdown so a user can't set one only to have
the next receipt silently overwrite it.

**Architecture:** `PurchaseOrderController` is its own controller (not
folded into `PurchasesController`) — a PO shares GRN's vocabulary but none
of its stock/batch/serial/payment behavior. `PurchasesController::store()`
gained exactly one new field (`purchase_order_id`) and calls
`PurchaseOrderReceiptService` in two places (a pre-transaction validation
call, then `applyReceipt()` after its own existing logic) — not inlined PO
logic, keeping this large, vendor-adjacent controller's merge-conflict
surface minimal. Deleting a PO-linked GRN (both the single-record and
bulk-delete paths) calls `revertReceipt()` before the GRN's detail rows are
hard-deleted, reversing the PO's received_quantity/status correctly.

**Frontend:** new Purchase Orders list + form pages; the existing Create
Purchase (GRN) form gained a PO selector next to Warehouse (visible once a
supplier is chosen) with a one-click "Load All Items" that pre-fills
remaining PO lines at the PO's agreed cost. PDF (dedicated template — no
payment/due fields, since a PO has none yet) and email-to-supplier (same
link-in-body pattern as the existing Purchase email feature) included.

**Known, documented limitation:** editing an existing GRN's *quantities* in
place (via `PurchasesController::update()`) does not adjust its linked PO's
received_quantity — creating and deleting are both fully handled; only the
narrower "edit quantities on an already-received GRN" case is deferred, as
`update()`'s existing batch/serial re-alignment path is already large and
this was judged rare enough not to add more risk to it in the same pass.

### 30.1 Security review addendum (done before deployment, not assumed)

A dedicated adversarial re-read (separate from the original build/test
pass) found and fixed two real gaps:

- `PurchasesController::store()` accepted any `purchase_order_id` with no
  validation — a crafted request could reference a PO outside the user's
  warehouse scope, already cancelled/fully-received, or for a different
  warehouse than the GRN itself claimed. Fixed with
  `PurchaseOrderReceiptService::validateReceivablePo()`, called before the
  GRN's DB transaction opens.
- `applyReceipt()` didn't verify a `purchase_order_detail_id` actually
  belonged to the GRN's own `purchase_order_id` — a crafted request could
  pair a legitimate PO with a detail-line ID borrowed from a different PO,
  crediting that unrelated PO's `received_quantity`. Fixed by validating
  the requesting PO's actual detail-ID set once per receipt.

Both were live-tested against real PO records (not just source-level
assertions) and confirmed rejected/ignored correctly with no data
corruption. `PurchaseOrderController`'s own warehouse-scoping
(`abortIfWarehouseDenied` on every single-record action) and the
delete-time `revertReceipt()` wiring were already correct from the
original build and needed no changes.

## 31. Line-ending normalization (housekeeping, no logic change)

**Status:** ACTIVE.

A full audit found that `SalesController.php`, `Sales.vue`, and
`PosSales.vue` had their entire line-ending convention silently converted
from this codebase's own CRLF (confirmed against the vendor-merge baseline)
to plain LF at some point during earlier editing — most likely a script
that read a file in default text mode (which normalizes CRLF to LF) and
wrote it back without preserving the original ending.

This had zero functional effect, but it defeated the minimal-merge-
footprint discipline this project otherwise follows: `git diff` against the
vendor baseline showed `SalesController.php` as ~4975 of its ~4979 lines
"changed" from line endings alone, when the real logic changes are ~118
lines. Restored to CRLF; `PurchaseForm.vue` (21 stray LF lines, not a full
conversion) was normalized the same way. Verified with `php -l`, the full
regression + PHPUnit suite, and a clean frontend rebuild — no logic changed.
A full sweep of every other file touched by this project found no further
instances.

## 32. PO Document Attachments UI + List Columns

**Status:** ACTIVE. **Base required:** PO+GRN linkage.

The PO document upload/download/delete backend endpoints existed from the
original PO+GRN delivery but had no frontend to use them — added an
attachments modal to the PO list, mirroring `Purchases.vue`'s existing
attachment pattern exactly. Also added 4 list columns: Created By, Last GRN
Date, Age (days since placed), and an attachment indicator icon. Last GRN
Date and the attachment indicator are each a single grouped query for the
whole page (same N+1-safe shape as the existing `received_percent`
calculation), not one query per row.

## 33. PO Fulfillment Stats + Price Variance Report

**Status:** ACTIVE. **Base required:** Build 32.

Summary stat cards on the PO list (Open POs count/value, Overdue
count/value — click Overdue to filter), a "Days Overdue" tag, and an
`overdue_only` quick-filter — stats computed server-side over the filtered-
but-not-paginated query, same convention as `PurchasesController::index()`'s
own `stats` block.

New Price Variance Report compares each GRN line's actual cost against its
originating PO line's agreed cost (joined via `purchase_order_detail_id` —
only lines actually received against a PO have anything to compare, by
design). KPI tiles, filters (supplier, date range, minimum variance %
threshold), color-coded variance tags.

## 34. Fix — GRN deletion could push stock negative

**Status:** ACTIVE. **Base required:** Build 33.

A reported concern: deleting a received GRN naively reverses the stock it
added; if some of that stock had since been sold, the reversal could push a
product's warehouse quantity negative with no warning. New, isolated
`App\Services\Custom\GrnDeletionSafetyService` performs a read-only
pre-flight check per line before either delete path
(`PurchasesController::destroy()`/`delete_by_selection()`) runs — using the
exact same base-unit conversion the existing stock-reversal logic already
uses, so the "would this go negative" arithmetic can never disagree with
the "how much do we actually subtract" arithmetic. When blocked, the
response lists every affected product with exact numbers.

**Important ordering detail, found while building this:** the check runs
BEFORE `DB::transaction()` opens in both delete methods, not inside the
closure — a response returned from inside
`DB::transaction(function () {...})` is silently discarded by these two
methods' own unconditional success response afterward (a real, pre-existing,
separate defect in the neighboring PurchaseReturn-exists check, which
blocks the underlying deletion correctly but never actually reaches the
user with an error message — left as-is, out of scope, but this new check
deliberately does not repeat that mistake). See
`docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`'s "Hard lessons" section.

## 35. Fix — Purchase Order permission migration broke fresh installs

**Status:** ACTIVE. **Base required:** Build 34.

Found via a real user report: `php artisan migrate:fresh --seed` failed
with a duplicate-key error during `PermissionsSeeder`. The PO permission
migration's grant-to-existing-roles logic used `insertGetId()` with no
explicit ID; on an already-seeded site this safely landed past the
seeder's own ID range, but on a fresh install (migrations run before
seeders) the empty `permissions` table handed it ID 1 — directly colliding
with the seeder's own hardcoded `id=1`. Fixed by reserving an explicit,
high ID (900001) instead. A second bug found while fixing the first: an
initial fix used a top-level `const`, which threw "already defined" when
Laravel's migrator loaded the file twice in the same `migrate:fresh`
process — moved to a class-scoped `private const`.

## 36. Fix — Purchase Orders menu invisible on fresh install

**Status:** ACTIVE. **Base required:** Build 35.

Separate bug surfaced by the same fresh-install testing: after a
successful `migrate:fresh --seed`, the Purchase Orders menu item didn't
appear for any role, including Owner. The permission-granting migration's
own grant-to-roles-with-Purchases_view logic runs during the migration
phase, which happens BEFORE any seeder on a fresh install — at that point
`permission_role` is completely empty, so the loop correctly found zero
roles to grant to. Added `PurchaseOrdersPermissionSeeder`, hooked into
`DatabaseSeeder` immediately after `PermissionRoleSeeder` (which is what
actually creates the `Purchases_view` role links this depends on) —
idempotent, safe to re-run.

## 37. Fix — Zone-Wise Report crashed with an ambiguous-column SQL error

**Status:** ACTIVE. **Base required:** Build 36.

Found via a real user log (not initially connected to PO work, but
investigated as part of the same "Something went wrong" report):
`ReportController::zoneWiseReport()` built its base query with an
unqualified `whereNull('deleted_at')` on the `Sale` model, then later
left-joined `sale_zones`/`sale_couriers` — both of which also have their
own `deleted_at` column. Once the join was added, MySQL could no longer
tell which table's `deleted_at` the earlier WHERE clause meant, and
rejected the entire query (error 1052) — a 500 on every call to this
report. Fixed by qualifying the column (`sales.deleted_at`) at the point
the base query is built; both the zone and courier breakdowns clone this
same base query, so the one fix covers both.

## 38. Fix — PO list and Price Variance Report never loaded any data

**Status:** ACTIVE. **Base required:** Build 37.

The real, root-cause fix for the "Something went wrong" reports on the PO
list and Price Variance Report pages (Builds 34–37 above were genuine bugs
found and fixed along the way, but were not this one).
`useCrudTable`'s `fetchRows()` calls its `params` option directly as a
function — `params()`. Both `PurchaseOrders.vue` and
`PriceVarianceReport.vue` passed `filterParams` as a `computed()` ref
instead of a plain function, so `params()` threw `TypeError: params is not
a function` **before** any HTTP request was built — which is why the
browser's Network tab showed zero requests for these pages rather than one
visible failed request. Fixed by changing `filterParams` to a plain
`() => ({...})` function in both files, matching the working pattern
already used elsewhere (`Bookings.vue`) and documented in
`useCrudTable.js`'s own inline comment for the `params` option. Confirmed
with a standalone Node.js reproduction of the exact call pattern (Node
shares the V8 engine with Chrome) — see
`docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`'s "Hard lessons" section for the
full incident writeup and the process change this motivated.

## Regression test suite index

Every build from A onward has a corresponding `tests/Regression/build_*.php`
script (source/static assertions, no DB required) and, for most, a mirrored
`tests/Unit/Build*ContractTest.php` (PHPUnit). Run the regression scripts in
build order after any deploy; run the full PHPUnit suite (`php artisan
test`) for everything else. As of Build 38 (this entry), 17 regression
scripts exist; all pass except the one known, pre-existing, unrelated item
noted throughout this document (a vendor-scaffold `ExampleTest` asserting
the homepage returns 200 outside auth, which this app correctly redirects
instead of returning).
