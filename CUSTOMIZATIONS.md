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
