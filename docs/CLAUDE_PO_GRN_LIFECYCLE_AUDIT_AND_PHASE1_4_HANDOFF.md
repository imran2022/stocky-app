# Stocky 5.8 PO/GRN Lifecycle Audit and Phase 1.4 Engineering Handoff

**Prepared for:** Claude or another AI/human developer  
**Current release:** PO/GRN Phase 1.3 Safe Overlay  
**Baseline:** customized Stocky 5.8 through A–E3, F, G1, Phase 0, PO/GRN,
registration hotfix, Phase 1.2, and Phase 1.3.

## 1. Executive conclusion

Phase 1.3 is complete for its approved scope: genuine read-only PO view, human
labels, PO columns/attachments, PO rollback after linked-GRN deletion, and
transactional cumulative negative-stock protection for single/bulk GRN delete.
Standalone GRNs remain supported.

The full PO/GRN lifecycle is not final. The next controlled phase must address:

1. linked GRN edit/status-transition reconciliation;
2. backend over-receipt prevention;
3. concurrent receipt locking; and
4. backend supplier-to-PO matching.

Until then, the safe operating rule is: create a PO-linked GRN directly as
`received`; do not edit a received linked GRN—safely delete and recreate it.

## 2. Mandatory reading and change boundary

Read, in order:

1. `docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`
2. `CUSTOMIZATIONS.md`
3. `PO_GRN_SAFE_OVERLAY_README.md`
4. `PO_GRN_PHASE1_2_UI_ERROR_FIX_SAFE_OVERLAY_README.md`
5. `PO_GRN_PHASE1_3_SAFE_OVERLAY_README.md`
6. `CLAUDE_PO_GRN_ZIP_AUDIT.md`
7. this file and relevant `tests/Regression/` scripts

This is a customized Stocky 5.8 merge. Do not restart from a vendor ZIP, replace
the full application, or copy an historical `public/js` directory. Use a small
safe overlay against the current installed baseline.

## 3. Current architecture

| Concern | Source of truth |
| --- | --- |
| PO CRUD/list/attachments/PDF/email | `app/Http/Controllers/PurchaseOrderController.php` |
| GRN create/update/delete and stock | `app/Http/Controllers/PurchasesController.php` |
| PO receipt apply/status/reversal | `app/Services/Custom/PurchaseOrderReceiptService.php` |
| GRN deletion stock preflight | `app/Services/Custom/GrnDeletionSafetyService.php` |
| PO status constants/relations | `app/Models/PurchaseOrder.php` |
| PO list/actions | `resources/src/pages/purchase_orders/PurchaseOrders.vue` |
| PO edit/read-only view | `resources/src/pages/purchase_orders/PurchaseOrderForm.vue` |
| GRN form and PO selector | `resources/src/pages/purchases/PurchaseForm.vue` |

Stock in `product_warehouse.qte` is base quantity. Receipt validation, PO
progress, and reversal must use the identical purchase-unit conversion.

## 4. Confirmed current behavior

| Scenario | Result | Assessment |
| --- | --- | --- |
| Draft/Ordered PO, no GRN | Editable and deletable with permission | Correct |
| Partially received PO | Read-only; more receipt allowed; no Edit/Delete | Correct |
| Fully received PO | Read-only; no further receipt; no Edit/Delete | Correct |
| Direct PO delete with any active linked GRN | HTTP 422 | Correct/conservative |
| Safe linked received-GRN delete | Stock and PO-line receipt reverse; PO status refreshes | Correct |
| Unsafe delete after stock consumption | Whole operation rejected before mutation | Correct |
| Bulk delete sharing one stock row | Cumulative locked check; all-or-nothing | Correct |
| GRN has Purchase Return | Delete rejected | Correct |
| Standalone GRN (`purchase_order_id = NULL`) | Original purchase flow; no PO change | Correct |
| Safe standalone received-GRN delete | Stock reverses; no PO service call | Correct |
| Pending/Ordered standalone-GRN delete | No stock reversal | Correct |
| PO/GRN warehouse mismatch | Rejected | Correct |
| Draft/Received/Cancelled PO receipt attempt | Rejected | Correct |

Example, PO ordered quantity 100:

- receive 40 → Received 40, Remaining 60, `partially_received`;
- delete that GRN safely → Received 0, Remaining 100, `ordered`;
- receive 40 then 60 → Received 100, Remaining 0, `received`;
- delete the 60 GRN safely → Received 40, Remaining 60,
  `partially_received`.

If reversal stock is insufficient, GRN, stock, PO, payment, batch, and serial
effects remain unchanged because preflight happens before mutation.

## 5. Phase 1.3 protections — do not regress

- View routes to `/purchase-orders/{id}?mode=view` and renders actual read-only
  details.
- Old edit URLs for partial/full POs automatically render read-only details.
- Edit is offered only for `draft` and `ordered`.
- PO delete is hidden after receipts; backend independently blocks a PO with any
  active linked GRN.
- Removed raw keys: `PoStatusAutoNote`, `PoNotEditable`, `PoItemsAvailable`,
  `LoadAllItems`.
- Human actions: View/Edit Purchase Order, Receive Items, Download PDF, Email to
  Supplier, Attachments.
- List fields: Created By and Last GRN Date (default-hidden), Age, attachment
  indicator. Last GRN Date uses active `received` GRNs only.
- Single/bulk GRN delete calls `GrnDeletionSafetyService` before mutation.
- Safety aggregates duplicate lines and the complete bulk selection by
  warehouse/product/variant in base units, sorts lock order, and locks stock
  rows with `FOR UPDATE`.

## 6. Preserved Claude ZIP audit

### `STOCKY_GRN_DELETE_SAFETY(1).zip`

The idea was useful, but the implementation did not reliably aggregate duplicate
lines or multiple selected GRNs against one stock row. Its check also was not
protected by the same transaction/row lock as reversal, leaving a TOCTOU race.
Phase 1.3 replaced it with the current aggregate transactional service. Never
restore the ZIP's controller/service versions.

### `STOCKY_PO_DOCUMENTS_COLUMNS(1).zip`

The columns/attachments ideas were valid, but the ZIP contained a large stale
`public/js` history and pre-Phase-1.2 source. Applying it could restore the bad
PO-list `params` contract, raw labels, and source/manifest mismatch. Phase 1.3
manually merged the valid ideas, preserved `params: () => filterParams.value`,
rebuilt the frontend, and shipped one active manifest closure. Do not apply the
Claude ZIP.

## 7. Remaining defects

### P0 — linked GRN edit/status transition desynchronizes PO

`applyReceipt()` runs on GRN create and `revertReceipt()` on delete, but the
existing `PurchasesController::update()` does not reconcile old/new PO receipt
contributions.

Failure cases:

- linked received GRN quantity 40→25: stock may become 25 while PO stays 40;
- linked Pending/Ordered GRN→Received: stock may increase without PO progress;
- linked Received GRN→Pending/Ordered: stock may reverse while PO stays credited;
- line removal/replacement can leave stale PO received quantities.

Required implementation:

1. one database transaction;
2. lock GRN, PO, PO details, and relevant stock rows in stable order;
3. reverse old persisted PO contribution if old state was linked+received;
4. run existing GRN stock/detail update;
5. validate and apply the new contribution if new state is linked+received;
6. refresh PO status before commit.

If this cannot be proven safe, explicitly block edit/status change for received
PO-linked GRNs and require safe delete/recreate. Never keep silent drift.

### P0 — backend over-receipt protection missing

UI loads remaining quantity, but backend does not enforce it. Crafted, stale, or
concurrent requests may receive above the PO balance.

Required:

- referenced detail must belong to selected PO;
- product and variant must match that detail;
- converted base quantity must be positive and not exceed locked remaining;
- reject the entire GRN with HTTP 422 before stock/PO mutation;
- return a human message with ordered, already received, attempted, and
  remaining quantities. Do not silently clamp.

### P0 — concurrent receipt race

Two users can load the same remaining amount and submit together. Move the
authoritative validation into the GRN transaction, lock PO and detail rows with
`FOR UPDATE`, then recalculate remaining after locks. The current pre-transaction
validation may remain only as a fast precheck.

### P1 — backend supplier mismatch

UI filters PO by supplier, but backend validation checks only existence,
warehouse scope/status, and warehouse equality. Pass GRN supplier ID into the
authoritative validator and reject supplier mismatch with HTTP 422.

### P1 — extra non-PO lines policy

A PO-linked GRN line without `purchase_order_detail_id` receives stock but does
not count toward PO completion. Product-owner decision is required:

- recommended: allow it with “Not on Purchase Order” warning/confirmation and
  never count it toward PO progress; or
- strict: reject unlinked lines in a PO-linked GRN.

Never infer linkage only from product ID; variants/repeated PO lines exist.

### P1 — short-close workflow

A partial PO cannot formally close when the supplier will not deliver the
balance. A future `closed_short` state should record reason, user, timestamp,
and quantity snapshot without stock movement. This needs a separate approved
migration/reporting phase; do not misuse Received, Cancelled, or Delete.

### P2 — later controls

- PO planned cost versus GRN actual-cost variance and approval threshold.
- Attachment soft-delete/audit retention and separate delete permission.

Do not mix these with the P0 lifecycle patch or change COGS/FIFO/accounting.

## 8. Recommended Phase 1.4 boundary

Include only:

1. supplier match;
2. PO-detail ownership and product/variant match;
3. base-unit remaining enforcement;
4. PO/detail transaction locks for create/update;
5. linked GRN edit/status reconciliation, or explicit safe edit block;
6. regression and realistic database tests.

Exclude unless separately approved: short-close schema, variance approvals,
COGS/FIFO, pricing formulas, Product Insights, Sales/POS, Shipment, permission
renaming, vendor replacement, and broad historical asset copying.

## 9. Acceptance matrix

1. PO 100; receive 40 → partial 40/60.
2. Receive another 60 → received 100/0.
3. Attempt 61 with remaining 60 → entire GRN rejected; no data change.
4. Duplicate lines in one GRN → combined amount checked.
5. Concurrent submissions → only amount within locked remaining commits.
6. Variants → checked independently.
7. Purchase-unit `/` and `*` conversions → PO and stock base quantities agree.
8. Wrong supplier/same warehouse → rejected.
9. Correct supplier/wrong warehouse → rejected.
10. Detail from another PO or mismatched product/variant → rejected.
11. Linked Pending→Received and Received→Pending → PO/stock both reconcile or
    operation is explicitly blocked.
12. Linked received line/quantity edit → atomic old reversal/new application or
    explicit block.
13. Safe and unsafe linked single/bulk delete → correct all-or-nothing behavior.
14. Purchase Return → delete blocked.
15. Standalone GRN create/edit/delete → original behavior; no PO touched.
16. Role, ownership, and warehouse scopes → unchanged.

Run existing gates:

```bash
php tests/Regression/build_po_grn.php
php tests/Regression/build_po_phase1_3.php
php tests/Regression/po_grn_runtime.php
```

Add a Phase 1.4 source-contract gate and real database integration/concurrency
tests. Stock and transaction correctness cannot be proven by string tests alone.

## 10. Documentation audit

Present: chronological `CUSTOMIZATIONS.md`, architecture/change-control,
AI/human handoff, release/rollback runbook, original PO/GRN design, Phase 1.2
and 1.3 release docs/manifests, Claude ZIP audit, and regression scripts.

`docs/BASELINE_AUDIT_2026-09-12.md` is intentionally historical; it describes
the earlier Build F upload, not today's workspace contents.

This pass updates `docs/DELIVERY_INDEX.md`,
`docs/AI_HUMAN_DEVELOPER_HANDOFF.md`, and
`docs/RELEASE_AND_ROLLBACK_RUNBOOK.md` to point to Phase 1.3 and this handoff.

## 11. GitHub-readiness audit

**Verdict: not GitHub-ready yet.** The inspected folder is not a Git worktree;
commit ancestry and remote cannot be verified. It also contains material that
must not enter an initial repository import:

- `.env`;
- `storage/oauth-private.key` and `storage/oauth-public.key`;
- sessions/cache/logs including `storage/logs/laravel.log`;
- `database/database.sqlite`;
- `vendor/` and `node_modules/`;
- accumulated `public/js` unless a deliberate compiled-asset policy is chosen.

`.gitignore` covers most paths, but that does not prove secrets were never
staged. Its `/.env.*` rule also ignores a future `.env.example`; add
`!.env.example` when creating a sanitized placeholder-only example.

No `.github/workflows` CI is present. Root `LICENSE`, `CONTRIBUTING.md`, and
`SECURITY.md` are absent; for a private proprietary repository these are policy
choices, not automatic blockers.

Required preparation:

1. use a sanitized developer-source copy, never the active installation folder;
2. rotate Passport keys if they crossed the trusted server boundary;
3. create sanitized `.env.example` and unignore it;
4. choose source-repo policy (preferred: ignore `public/js`, build in CI) or
   deployment-repo policy (track only one active manifest closure);
5. initialize Git only in the sanitized copy and tag the exact baseline;
6. add CI for Composer checks/tests, npm clean build, Vite-manifest integrity,
   raw-key scan, and PHP regression gates;
7. add project-specific setup/migration/test/build/release instructions;
8. inspect the staged list and run a secret scanner before first push;
9. use a private repository unless licensing/data exposure is reviewed.

Do not automatically `git init` or publish this active workspace. Repository
owner, destination, visibility, and sanitized-source boundary must be confirmed.

## 12. Required next-developer delivery

Return a minimal cumulative safe overlay against Phase 1.3, exact manifest and
SHA-256, updated customization/handoff docs, consistent source+Vite assets,
new automated/database test results, environment limitations, and rollback
instructions. Before coding, report exact files/invariants and request decisions
for extra GRN lines or short-close; do not silently choose business policy.

