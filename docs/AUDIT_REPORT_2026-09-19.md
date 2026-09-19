# StockyUltimate — Deep Audit Report (2026-09-19)

## What actually changed this time

For the first time, this audit was run against **real, running code** —
not just text-pattern checks. Using the `vendor/` folder you provided:

- Ran a genuine `php artisan migrate:fresh --seed` against a real
  database (SQLite file, since MySQL isn't installed in this sandbox) —
  every one of the ~150 migrations and every seeder actually executed.
- Queried the resulting database directly to confirm permission grants,
  not just that the code "looks right".
- Ran all 23 `tests/Regression/build_*.php` scripts for real (`php
  <file>`), not just grepped their assertions.
- Ran the project's actual PHPUnit suite (`tests/Feature`, `tests/Unit`).

This surfaced two things static review never could have: **a real
regression in the Purchase Order / GRN module**, and **confirmation that
the fresh-install permission bugs are actually fixed**, both with hard
evidence below.

---

## 1. CONFIRMED GOOD — K.1 through K.4 are all present and working

Checked directly against the seeded database and source, not assumed:

| Item | Check | Result |
|---|---|---|
| K.1 VAT/BIN + Website | `settings` table has `website`, `vat_number` columns | ✅ present |
| K.2 Transfer stock-integrity fix | `resolveProductWarehouseRow()` used at all 37 sites in `TransferController.php` | ✅ present |
| K.3 Quick Add Customer + PWA fix | `public/sw.js` VERSION | ✅ `stocky-pwa-v13` |
| K.4 Activity Log permission | `activity_log_report` permission, fresh DB | ✅ id 900002, **granted to role 1 (Owner)** |
| K.4 Purchase Orders permission | `purchase_orders` permission, fresh DB | ✅ id 900001, **granted to role 1 (Owner)** |
| K.4 Roles & Permissions UI sync | both permissions in `permissions.js` | ✅ present |

**This is the concrete proof the checklist approach works**: on a genuine
fresh install, both previously-buggy permissions now grant correctly, and
nothing else regressed in the fresh-install path.

---

## 2. CRITICAL FINDING — Purchase Order / GRN UI has regressed

Two regression tests genuinely fail — not false alarms, confirmed by
reading the live source files directly:

- `tests/Regression/build_po_phase1_3.php` — **17 failures**
- `tests/Regression/build_po_grn.php` — **10 failures**

**What this means in plain terms:** the documented "Phase 1.3" polish for
Purchase Orders/GRN — a proper read-only view mode (`?mode=view`),
human-readable button labels ("View Purchase Order", "Edit Purchase
Order", "Receive Items"), the Ordered/Received/Remaining Quantity
breakdown on the read-only view, and the "Load Remaining Items" wording
on the GRN form — **is not in the current code.** The live files instead
use a different, earlier-looking implementation (generic `View`/`Edit`
labels, a `/purchase-orders/{id}/view` route instead of the `?mode=view`
pattern).

**Evidence this happened during a later, unrelated build:**
`PurchaseOrders.vue`, `PurchaseOrderForm.vue`, `purchases/PurchaseForm.vue`
and `reports/PriceVarianceReport.vue` all carry the **exact same file
timestamp** (2026-09-15 21:50:12) — meaning whatever build added the
Price Variance Report also touched (and apparently rewrote from an older
base) the three PO/GRN files, quietly dropping the Phase 1.3 polish. This
lines up with something already flagged as an open issue in your notes
("PO/Price Variance Report menu items still showing when the PO toggle is
off") — both point at the same build having handled the PO module
carelessly.

**Is it broken, or just less polished?** Based on source review, the
underlying PO → GRN workflow (create PO, receive against it, stock
effects) still appears functionally intact — this looks like a UI/wording
regression, not a stock-accuracy one. But I have not been able to
click-test it live, so please verify PO view/edit/receive still work
correctly end-to-end on your side before treating this as "just cosmetic".

**Recommendation:** treat this as its own dedicated build (like K.2 was)
to restore the Phase 1.3 polish deliberately, rather than patching it
inside this audit — it touches 3 sizeable Vue files and I don't want to
guess-reconstruct UI copy under time pressure on a financial module.

---

## 3. CRITICAL, ALREADY-KNOWN FINDING — confirmed still open with exact locations

The "silent stock-row" bug (a product's *first-ever* stock movement into a
warehouse does nothing, silently, because the code only updates an
existing `product_warehouse` row and never creates one when none exists)
was already flagged as an open issue for six modules. I confirmed with
exact line numbers that **all six are still unfixed**:

| Module | File | Confirmed unfixed at |
|---|---|---|
| Purchases (stock in) | `PurchasesController.php` | lines 305, 321, 520, 537, 577, 594, 783, 800, 958, 975 (+more) |
| Sales (stock out) | `SalesController.php` | lines 486, 502, 868, 884, 926, 942, 1525, 1541 (+more) |
| Adjustments (+/-) | `AdjustmentController.php` | 35 call sites |
| Sale Returns (stock in) | `SalesReturnController.php` | 14 call sites |
| Purchase Returns (stock out) | `PurchasesReturnController.php` | 14 call sites |
| Damages (stock out) | `DamageController.php` | 18 call sites |

Sample (Purchases, line 310–317 — identical pattern repeats everywhere):
```php
$product_warehouse = product_warehouse::where('deleted_at', '=', null)
    ->where('warehouse_id', $order->warehouse_id)
    ->where('product_id', $value['product_id'])
    ->first();

if ($unit && $product_warehouse) {   // <-- no `else` branch
    $product_warehouse->qte += ...;
    $product_warehouse->save();
}
// if $product_warehouse is null, this purchase silently adds ZERO stock
```

**Only Transfers has this fixed (Build K.2)**. Everywhere else, the very
first time a product is purchased/sold/adjusted/returned/damaged in a
warehouse it hasn't touched before, the stock change is **silently
dropped** — no error, no log, nothing. This is a real, current
data-integrity risk on your live site, worst on Purchases (stock you paid
for and received never appears) and Sales (oversold stock never
decremented, if a variant is sold into a new warehouse).

**Recommendation:** this is a big, financially-sensitive change (~100+
call sites across 6 files). I deliberately did **not** attempt to
mass-patch all of them in this same session under time pressure — that's
exactly the kind of rushed change that causes the bugs you're worried
about. I recommend doing this the same way K.2 was done: one dedicated
build, one module at a time (or all six together if you'd rather, but
reviewed carefully), each with its own regression test, verified against
the real seeded database before delivery.

---

## 4. Security spot-check on customized code

Checked backend authorization on every new/changed controller (not just
frontend menu-hiding, which alone is not real security):

- `PurchaseOrderController.php` — ✅ every action (view/create/update/delete)
  calls `authorizeForUser(...)`.
- `TransferController.php` — ✅ view/create/update/delete all authorized.
- `ActivityLogController.php` — ✅ `authorizeForUser(..., 'activity_log_report', ...)`.
- `SaleMetaController.php` — ✅ correctly gated (read access requires an
  existing Sales/POS/Shipment view permission; create access requires
  Sales/POS/Shipment create-or-update permission) — this was deliberately
  hardened in Build E2 and is still correct.
- No raw/unescaped SQL string concatenation found in any customized
  controller or service.

No security regressions found in customization-owned code.

---

## 5. Test suite results (real execution)

- **PHPUnit** (`tests/Feature` + `tests/Unit`): 37 tests, 170 assertions,
  **36 pass**. The 1 failure is Laravel's own stock `ExampleTest`
  (expects `GET /` to return 200; the app correctly redirects
  unauthenticated visitors to login, 302) — not a real bug, just a
  leftover Laravel boilerplate test that was never adapted to this app.
- **tests/Regression** (23 files, all run for real): **21 pass**, **2
  genuinely fail** (Section 2 above — the PO/GRN regression). A 3rd,
  `build_e1_pos_recent.php`, fails here only because this sandbox hasn't
  run `npm run build` yet (it checks a compiled JS chunk file that
  doesn't exist until you build) — not a real code problem.

---

## 6. The "supplier-type report" you half-remembered

I could not find a second custom report built alongside the Zone/Courier
Report — `CUSTOMIZATIONS.md` and the actual route list (`routes/api.php`)
both show only **one** new report route was added at that time
(`report/zone_wise`). What I did find: the vendor app already ships
**Suppliers Report**, **Top Suppliers Report**, and **Supplier Detail
Report** (People → Suppliers → Report, and under Reports). I confirmed
directly against the fresh-install database that both are correctly
permissioned to Owner (`Reports_suppliers`, `Top_Suppliers_Report` — both
granted). So if what you're thinking of is a Suppliers-related report,
it already exists and should already be visible in your Reports menu —
please check and let me know if it's genuinely missing from your live
site's menu (in which case it'd point to a live-site-specific permission
gap, not a code bug), or if you meant something else entirely.

---

## 7. Vendor-only issues found (not your customizations, informational only)

While getting a from-scratch install running, I hit several places where
vendor migrations use **MySQL-only SQL** (`SHOW INDEX`, `ALTER TABLE ...
MODIFY`, `information_schema.TABLE_CONSTRAINTS`, `IF()`/`CONCAT()` in raw
`UPDATE` statements, and duplicate default index names across tables).
None of this affects your live MySQL site — MySQL handles all of it
correctly. It only surfaced because this audit ran against SQLite to get
a real, running database for testing. Not a bug to fix; just documented
here in case a future audit environment hits the same thing.

---

## Summary — what needs a decision from you

1. **Purchase Order/GRN UI regression** (Section 2) — restore the Phase
   1.3 polish, as its own dedicated build?
2. **Silent stock-row bug**, 6 modules (Section 3) — extend the proven
   K.2 fix to Purchases, Sales, Adjustments, Sale Returns, Purchase
   Returns, Damages — one build (or module-by-module, your call)?

Both are real and worth fixing, but both are big enough that I did not
want to rush them inside this audit turn. Tell me which to build first.
