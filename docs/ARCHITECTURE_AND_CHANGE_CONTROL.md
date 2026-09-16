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
  scope, set-based aggregation, and (since Build G1) Unit/Multi-Pack base-unit
  normalization via `App\Support\UnitQuantityResolver`.
- Stock Lookup: warehouse visibility, active variants, base unit, price range.
- Purchase Order (PO) + GRN linkage (since the PO+GRN build): a real schema
  addition (`purchase_orders`, `purchase_order_details`,
  `purchase_order_documents`, plus nullable link columns on `purchases`/
  `purchase_details`). PO status transitions into `partially_received`/
  `received` are computed exclusively by
  `App\Services\Custom\PurchaseOrderReceiptService` — never set by hand, and
  the frontend deliberately excludes them from the manual status dropdown.
  GRN deletion (`PurchasesController::destroy()`/`delete_by_selection()`) is
  guarded by `App\Services\Custom\GrnDeletionSafetyService`, which blocks a
  delete that would push a product's warehouse stock negative — checked
  BEFORE the delete's `DB::transaction()` opens, not inside it (see the
  "Hard lesson" callout below for why that ordering matters).
- Unit/Multi-Pack, min/wholesale pricing, FIFO/COGS: deliberately separate from
  Build F and require their own controlled release.

## Hard lessons from real incidents (read before touching similar code)

These are not hypothetical risks — each was a real bug that reached (or
nearly reached) the user's live site. Recorded here so the same mistake
doesn't get repeated in a future session that hasn't read the full chat
history.

1. **A response returned from inside `DB::transaction(function () {...})`
   is silently discarded if the caller doesn't capture and return
   `DB::transaction()`'s own result.** `PurchasesController::destroy()` and
   `delete_by_selection()` both have this shape: an early `return
   response()->json([...], 403)` *inside* the closure only exits the
   closure — the method's own unconditional success response below the
   `}, 10);` still fires. This is real, pre-existing behavior (confirmed on
   the PurchaseReturn-exists check), not fixed everywhere, but every NEW
   validation added to these methods must run *before* the transaction
   opens (see `GrnDeletionSafetyService`'s call site for the pattern), not
   inside it.

2. **`useCrudTable`'s `params` option must be a plain function
   (`() => ({...})`), never a `computed()` ref** — it's called directly as
   `params()` inside `fetchRows()`. Passing a `computed()` ref throws
   `TypeError: params is not a function` *before* any HTTP request is
   built, which means the bug produces **zero network requests** in the
   browser — not a visible failed request — making it unusually easy to
   miss in testing that only checks "did a request go out" or "did the
   build succeed" (Vite does not type-check this). Before using any
   composable for the first time, find an existing *working* caller of it
   in the codebase and match its exact usage shape — don't infer the
   contract from the option name alone. Two Vue files shipped with this
   exact mistake in the same delivery because the same wrong pattern was
   copy-pasted between them.

3. **A migration's own logic can be unreachable on a fresh install.**
   `add_purchase_orders_permission`'s grant-to-existing-roles logic worked
   correctly on an already-running site (roles/permissions already exist at
   migration time) but silently granted nothing on `migrate:fresh --seed`,
   because migrations run *before* seeders — `permission_role` is empty
   when the migration executes. The same grant logic had to be duplicated
   as a *seeder* (`PurchaseOrdersPermissionSeeder`, hooked in after
   `PermissionRoleSeeder` in `DatabaseSeeder`) to cover both paths. Any
   migration that reads or joins against seeded reference data (roles,
   permissions, settings defaults) needs this same two-path treatment,
   or must be written to no-op safely and rely on the seeder alone.

4. **A migration inserting into a table a master seeder ALSO explicit-ID
   inserts into must use its own reserved, explicit ID — never
   `insertGetId()`.** On a fresh install, an empty table hands auto-increment
   ID 1 to whatever inserts first; if `PermissionsSeeder` runs afterward
   with its own hardcoded `id=1`, the seed aborts entirely with a duplicate-
   key error. Also: a top-level `const` in a migration file can throw
   "already defined" if the migrator loads the file more than once in the
   same process (observed during `migrate:fresh`) — use a class-scoped
   `private const` instead, never a global one.

5. **An unqualified `whereNull('deleted_at')` becomes ambiguous the moment
   a join is added later in the same query**, if the joined table also has
   its own `deleted_at` column — MySQL rejects the whole query (error 1052),
   not just a wrong result. `ReportController::zoneWiseReport()` hit this
   when a `sale_zones` join was added after the base `whereNull('deleted_at')`
   was already written unqualified. Any `deleted_at` filter written before
   you know whether a join will be added later should be qualified with its
   table name from the start (`sales.deleted_at`), not left bare.

6. **`$t('SomeKey') || 'Fallback text'` does not do what it looks like it
   does.** When a translation key is missing, vue-i18n returns the KEY
   ITSELF as a non-empty string (not null/empty) — so `||`'s right-hand
   fallback never triggers, since the left side is already truthy. This
   shipped across 5 files (PO list, PO form, GRN create form, Price
   Variance Report) before a user report caught raw key text like
   "PoStatusAutoNote" rendering on screen. The fix is either plain text
   (no `t()` call) for any label without a confirmed existing translation
   key, or querying the actual `translations` table before assuming a key
   exists — never assume `||` will catch a missing key.

7. **A Vite build never deletes stale output from a previous build** —
   every `npm run build` adds new content-hashed files but leaves old
   ones sitting in `public/js/chunks/`, `public/js/assets/`, and
   top-level `public/js/app.*.js`/`.css` files behind. Across ~20 builds
   in one project's history, this accumulated to 13,000+ orphaned files
   and pushed a ~20MB build up to 267MB — invisible in normal use (the
   manifest only ever points to the current files) but bloating every
   delivery ZIP. Before packaging a delivery ZIP, diff `public/js`'s
   actual file list against `public/js/.vite/manifest.json`'s referenced
   files and delete anything not referenced — never assume the build
   output directory is already clean. `public/js/` itself is gitignored,
   so this never affects the git history, only local build output and
   ZIP deliveries built from it.

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
