# Live ↔ GitHub reconciliation — 2026-09-24

Inputs reviewed: live installation zip ("Latest - 2"), ChatGPT bundle
`STOCKY_FINAL_CUSTOMIZATIONS_2026-09-23.bundle` (31 commits over GitHub Build
N5 `8ca9b05`), overlay zip, manifests, CUSTOMIZATIONS.md / CHECKLIST.

## Verdict on the ChatGPT bundle (31 commits, O1–O9)

- Bundle verifies; base tag `handoff-base-build-n5` = the current GitHub master.
  Overlay zip is byte-identical to the bundle HEAD.
- All changed PHP lints clean; `migrate:fresh --seed` passes; the two new
  migrations are additive with `down()`.
- Backend behaviour verified on real rows: Live Sales Display (token lifecycle,
  scoping, revoke/regenerate/expiry all 403 correctly, payload maths),
  Movement Ledger (all 8 sources; per-warehouse balances reconcile with
  `product_warehouse`; date windows/opening balances correct; pending/unapproved
  documents ignored), Product Insights ordering, supplier PDF/Excel/ledger
  render. Regression suite: 44/50 pass on the unmodified bundle with fixtures;
  the 6 failures were 1 test-fixture issue, 1 pinned-hash test, 1 N4-only table
  in a cleanup step, 2 stale PO/GRN wording tests and 1 broken manifest (below).
- Source in the live installation == bundle HEAD for `app/`, `routes/` and
  `resources/src/` (differences are line endings only), and the
  live compiled assets were built from that same source (506 of 510 compiled
  units identical; the rest are hash-only or the xlsx chunk).

## Defects found and fixed here

1. Supplier statement date-range opening balance (P1).
2. POS Enter adds a stale first suggestion (P2) — reproduced in a real browser
   on the previous build.
3. POS changed without service-worker version bump (P2).
4. Committed `public/js` inconsistent: manifest referenced 486 files absent from
   git; 7,123 orphaned hashed files (150 MB). Rebuilt consistently (21 MB).
5. GitHub carried 14 test-only edits to vendor files (see P0).
6. Four live-only PDF templates were missing from the repo (see P0).

## Observations that need an owner decision / were left unchanged

- Live `public/js` has no `storefront.css` / `storefront.min.js` (a
  `public/storefront bk/` copy from 2026-09-10 exists). `layouts/store.blade.php`
  requires both — Online Store pages would be unstyled. The P build restores them.
- `real_time_sales_displays.expires_at` is a plain `timestamp` column; verify on
  the live database it has no `ON UPDATE CURRENT_TIMESTAMP` (see checklist §32).
- Live Sales Display counts sales of every status (same as the vendor
  dashboard's "Sales" card); pending sales are included in today's total.
- System Settings' dashboard section list does not map legacy section ids the
  way Dashboard.vue does, so a customised old order can look different in
  Settings than on the dashboard until it is saved once (cosmetic).
- `ReportController::download_report_provider_pdf` now counts only `received`
  purchases (consistent with the new statement); it previously included every
  status.
- Mixed CRLF/LF line endings remain in a few vendor-owned files.
- Repository visibility was reported Public — set to Private.

## Not verifiable in this environment

MySQL specifics (only sqlite was available; MySQL-only SQL in new code was
reviewed by reading: `HOUR()`, `CONCAT()`, `MAX(CONCAT())` guarded by driver
checks), real payment/print hardware, mobile devices, the live database.
