# Phase 1.4 — Manual Real-Database Verification

**Why this file exists:** the engineering environment used to write Phase
1.4 had no `vendor/` directory and no network access to Composer/Packagist,
so `php artisan test`, migrations, and any real Eloquent/database run were
not possible there. `build_po_grn_phase1_4.php` only checks that the code is
*wired* the way `CUSTOMIZATIONS.md` describes (string/position checks on the
source) — it cannot prove the SQL, locking, or business math are actually
correct. Run every scenario below against a real test database (Laragon, a
copy of production data or a seeded test DB — not the live production
database) before treating Phase 1.4 as verified.

Run the static gates first:

```bash
php tests/Regression/build_po_grn.php
php tests/Regression/build_po_phase1_3.php
php tests/Regression/build_po_grn_phase1_4.php
php tests/Regression/po_grn_runtime.php
```

All four must pass before starting the scenarios below.

## 1. Supplier match

1. Create PO-A for Supplier X, warehouse W, status `ordered`.
2. Open **Create Purchase (GRN)**, select warehouse W, but pick **Supplier Y**
   (different from X) in the supplier field, then try to select PO-A.
3. **Expected:** the PO picker should already filter PO-A out (existing
   Phase 1.2 UI behavior). To actually exercise the new backend check, submit
   the GRN create request directly (e.g. via the browser network tab replay,
   or Postman) with `supplier_id` = Y's id and `purchase_order_id` = PO-A's
   id. **Expected:** HTTP 422, message mentions "different supplier". No
   Purchase row, no PurchaseDetail rows, no stock change.

## 2. Over-receipt is rejected before mutation

1. Create PO-B, one line: Product P, quantity 100, warehouse W.
2. Set PO-B to `ordered`.
3. Create GRN-1 against PO-B, quantity 40, status `received`. Confirm PO-B
   becomes `partially_received`, received_quantity = 40, remaining = 60.
4. Create GRN-2 against PO-B, quantity **61** (i.e. more than the remaining
   60), status `received`.
5. **Expected:** HTTP 422, message includes "ordered 100, already received
   40, attempted 61, remaining 60" (or equivalent numbers). GRN-2 is NOT
   created at all — check the purchases table has no new row, `product_warehouse.qte`
   for P in W is unchanged, and PO-B's received_quantity is still 40.
6. Repeat with quantity exactly 60 — this MUST succeed, PO-B becomes
   `received`, remaining 0.

## 3. Duplicate/split lines in one GRN are summed

1. Reset PO-B to remaining 60 (delete GRN-1/GRN-2 from step 2, or use a
   fresh PO).
2. Create a GRN with TWO lines both referencing the same PO-B detail:
   quantity 35 and quantity 30 (sums to 65, exceeding the remaining 60).
3. **Expected:** HTTP 422 rejecting the whole GRN (the two lines' 65 combined
   exceeds remaining 60), not silently accepted because each line
   individually looks fine.

## 4. Concurrency (the actual race)

This is the hardest to reproduce manually but the most important to attempt:

1. Create PO-C, one line, quantity 100, `ordered`.
2. Open two separate browser sessions/tabs (or two Postman requests) both
   prepared to submit a GRN against PO-C for quantity 60, status `received`.
3. Fire both submissions as close together as possible (ideally via two
   terminal `curl` commands backgrounded with `&` so they overlap).
4. **Expected:** exactly ONE of the two succeeds (bringing PO-C to
   `received`, quantity 60). The other must fail with the over-receipt 422
   (since after the first commits, remaining is 40, and 60 > 40) — it must
   NOT succeed and leave PO-C over-received at 120/100.
5. If your database/PHP setup makes true concurrent requests hard to trigger
   manually, at minimum confirm via `SHOW ENGINE INNODB STATUS` (MySQL) or
   equivalent that the `purchase_order_details` row is actually locked
   (`SELECT ... FOR UPDATE`) for the duration of a GRN submission — e.g. by
   pausing execution with `dd()`/a breakpoint inside
   `lockAndValidateReceiptLines()` mid-transaction and confirming a second
   query against the same row blocks until the first transaction ends.

## 5. Linked GRN edit is now blocked, not silently desyncing

1. Create PO-D, quantity 100, GRN against it for 40, `received`. PO-D is now
   `partially_received`, 40/60.
2. Try to **edit** that GRN (same GRN, still linked to PO-D, still
   `received`) — change its quantity to 25.
3. **Expected:** HTTP 422, message mentions the GRN is linked to a Purchase
   Order and involves a "received" state, and that it should be deleted and
   recreated instead. Confirm via the database that received_quantity on
   PO-D's line is UNCHANGED at 40, and `product_warehouse.qte` for the
   product is unchanged — i.e. the edit did nothing, not a partial change.
4. Create a second linked GRN against PO-D as `pending` (not yet received).
   Edit it while keeping it `pending` (e.g. change notes or quantity, but
   leave statut as `pending`) — **this must succeed** (no stock/PO impact
   either way, so it's not blocked).
5. Now edit that same pending linked GRN and change its status to
   `received`. **Expected:** HTTP 422 (this is the second, less obvious case
   Phase 1.4 blocks — transitioning to `received` via edit, which would move
   stock without ever crediting the PO). Confirm no stock change occurred.
6. Confirm the pre-existing safe path still works: delete a received linked
   GRN (not edit it) — this must still succeed exactly as in Phase 1.3,
   reversing stock and PO received_quantity correctly.

## 6. Regression — nothing else broke

Re-run the full acceptance matrix in
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md` section 9,
items 1, 2, 4, 6, 7, 9, 10, 13, 14, 15, 16 (the items Phase 1.4 did not
target) to confirm they still behave exactly as before. Also spot-check a
handful of ordinary, non-PO-linked purchases (create, edit, delete) to
confirm the direct-purchase flow is completely untouched.

## Sign-off

Only after every scenario above has been run against a real test database
with real rows — not assumed from reading the code — should Phase 1.4 be
considered verified. Record the actual request/response pairs or screenshots
for at least scenarios 2, 4, and 5, since those are the ones a future
developer (or a support ticket) will most want evidence for.
