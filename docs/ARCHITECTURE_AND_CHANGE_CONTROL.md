# Architecture and Change Control

## System shape

StockyUltimate is a Laravel application with a Vue 3 administrative SPA. Laravel
owns authentication/authorization, business services, persistence, APIs, PDF
views, scheduled commands, and integrations. Vue owns the operational admin UI.
Vite compiles the Vue source into hashed assets under `public/js/`.

```text
Browser/PWA
  -> public/index.php / public/js assets
  -> Vue routes and pages in resources/src
  -> authenticated API routes in routes/api.php
  -> controllers in app/Http/Controllers
  -> models/services/support in app
  -> relational database evolved by database/migrations
```

## Repository map

| Path | Responsibility | Change caution |
| --- | --- | --- |
| `app/Http/Controllers/` | Request validation, authorization, orchestration, response shaping | High for Sales/Products/Reports |
| `app/Models/` | Relationships, casts, reusable product pricing behavior | Check explicit soft-delete filters |
| `app/Services/Custom/` | Set-based custom analytics and business helpers | Preserve metric contracts |
| `app/Support/` | Cross-cutting helpers such as metadata validation/scope | Review every caller |
| `routes/` | API/web/portal entry points | Avoid route drift or public exposure |
| `database/migrations/` | Additive schema history | Never edit an applied migration |
| `resources/src/` | Vue 3 admin SPA source of truth | Rebuild or synchronize active asset |
| `resources/views/` | Blade, PDF, and print templates | Test multi-render and currency behavior |
| `public/js/.vite/manifest.json` | Active source-to-hashed-asset mapping | Never guess the active hash |
| `public/js/chunks/` | Deployed lazy chunks | Generated output, not source of truth |
| `public/sw.js` | PWA caching/version namespace | Increase monotonically after asset sync |
| `Modules/` | Modular/vendor business features | Preserve unless explicitly scoped |
| `tests/Regression/` | Fast cumulative release contracts | Run in listed build order |

## Data and authorization conventions

- Reporting money (sales, tax, shipping, discount, net, COGS) has one source: `Support/Reporting/SalesFigures`,
  `Support/Reporting/CashFlowFigures` and `Traits/CalculatesCogsAndAverageCost`. A new report must call them, not
  re-aggregate `sales`. Payment totals/status: `Support/PaymentReconciler`. Only completed/received documents count.
- Anything that changes settings, modules, integrations or their secrets must check the `setting_system` permission
  (`authorizeForUser($user, 'update', Setting::class)`).

- Many legacy models use a `deleted_at` column without Laravel's `SoftDeletes`
  trait. Do not assume a global scope; add `whereNull('deleted_at')` explicitly
  when business rules require active rows.
- User warehouse visibility follows `is_all_warehouses` or IDs from
  `UserWarehouse`. Existing endpoints may also apply record ownership. Preserve
  both layers when touching Sales, Shipments, POS Recent, or analytics.
- Legacy/base-currency sales can have null currency snapshot fields. Display code
  must use the established document-currency fallback; monetary storage remains
  in base currency.
- Product stock in `product_warehouse.qte` is the existing base-stock quantity.
  Sale/purchase units and Multi-Pack can convert presentation/transaction units;
  do not silently reinterpret stored stock.
- Product variants have their own code, GTIN, price, cost, and stock rows. A
  variant product's parent price is not a reliable display price.

## Customization domains

The exhaustive chronological details live in `CUSTOMIZATIONS.md`. High-risk
domains include:

- Sale metadata: tracking/consignment, Zone, Courier, shipping status, Box Qty.
- Bulk Sale documents/actions and Shipment authorization.
- Multi-currency Sales/POS presentation and historical currency snapshots.
- Product Insights: rolling windows, return-rate formula, warehouse/ownership
  scope, set-based aggregation.
- Stock Lookup: warehouse visibility, active variants, base unit, price range.
- Unit/Multi-Pack, min/wholesale pricing, FIFO/COGS: deliberately separate from
  Build F and require their own controlled release.
- Purchase Orders and GRN receipts: `PurchaseOrderReceiptService` owns linkage,
  base-unit receipt quantities, reversal, and PO status refresh. API, SPA router,
  and permission-gated menu registration must ship with the feature—never as an
  undocumented/manual post-copy step.

## Modern Dashboard

Own table (`dashboard_preferences`), own controllers and `Support\Dashboard` / `Support\Reporting\DashboardInsights` classes; the
frontend lives under `pages/dashboard/` and `stores/dashboardPrefs.js`. Only the route line and additive report keys touch vendor files.
Bundled map data is CC BY 4.0 (attribution required, shown in the UI).

## Inventory Costing (Moving Average)

Own tables (`inventory_cost_*`, one migration, nothing in a vendor table altered) and own service layer
(`app/Services/Costing/*`): `MovingAverageEngine` (pure replay function), `MovementSource` (loads a product's
documents), `InventoryCostingService` (write side — sync, verify, on/off switch), `CostingReader` (the only door
reports use; a no-op pass-through while off). Report/dashboard files only gained additive hooks that branch on
`CostingReader::active()` — the legacy branch is byte-for-byte the original code path. OFF by default; switched with
`php artisan costing:rebuild`. See `CUSTOMIZATIONS.md` "Inventory Costing (Moving Average)" for the full design,
limitations, and enabling procedure.

## Frontend release modes

Normal development should run the documented Vite build and deploy the complete
new manifest plus all assets produced by that same build. A SAFE overlay may
surgically synchronize an already-referenced lazy chunk only when preserving the
active customized build is safer than a broad rebuild. In that case:

1. Verify the active chunk from `public/js/.vite/manifest.json`.
2. Keep the Vue source change authoritative.
3. Touch only the mapped chunk and increment `public/sw.js`.
4. Add a regression assertion linking source, chunk, manifest, and cache version.
5. Document that a future normal Vite build should regenerate the hash.

Never copy every historical chunk from one installation to another. Stale
hashed files may coexist, but the manifest identifies the active one.

## Change risk levels

| Level | Examples | Minimum verification |
| --- | --- | --- |
| Low | Label/layout, additive display field | Static contract + browser check |
| Medium | Validation, query filters, permission scope | Regression + role/warehouse scenarios |
| High | Stock, totals, price conversion, returns, FIFO/COGS | Automated tests + realistic database reconciliation |
| Critical | Migration/data rewrite/vendor merge | Staging, full backup, rollback rehearsal, reconciliation |

Build F is low-to-medium: it changes Stock Lookup query filtering and response
display fields but not stored data or stock arithmetic.
