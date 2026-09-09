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
