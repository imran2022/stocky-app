# Latest Delivery Index

## Current: Audit Batches 1-6 (2026-09-24)

Delivered as git bundles `STOCKY_AUDIT_FIX_B1..B5_2026-09-24.bundle` (each on top of the previous) plus live-deploy zips
`LIVE_DEPLOY_AUDIT_B4/B5_2026-09-24.zip`. Batch 5 needs `php artisan migrate` (indexes) and `php artisan optimize:clear`.
Batch 6 (Dashboard hourly charts) adds rebuilt `public/js` admin assets, no migration.
Description: `CUSTOMIZATIONS.md` "Audit Batches 1-5" and "Audit Batch 6", checklist section 36, merge procedure
`docs/AUDIT_MERGE_GUIDE.md`, tests `tests/Regression/audit_*.php` (`tests/run_regression.sh`).

## Current: PO+GRN Phase 1.4 — supplier match, over-receipt lock, unsafe-edit block

Applied directly to source (no new overlay zip was packaged for this pass).
Full description, verification performed, and known remaining limitations are
in `CUSTOMIZATIONS.md`'s "PO+GRN Phase 1.4" section and
`docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`. Static
regression gate: `tests/Regression/build_po_grn_phase1_4.php`. Real-database
verification steps (not yet run in this engineering environment — see that
handoff doc's environment note): `tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md`.

## Earlier: PO+GRN Phase 1.2 / Phase 1.3

Phase 1.2 (real English labels, actionable list errors) and Phase 1.3 (real
read-only PO view, PO columns/attachments, transactional cumulative GRN
deletion safety) are described in `PO_GRN_PHASE1_2_UI_ERROR_FIX_SAFE_OVERLAY_README.md`,
`PO_GRN_PHASE1_3_SAFE_OVERLAY_README.md`, `PO_GRN_PHASE1_3_FILE_MANIFEST.txt`,
and `CLAUDE_PO_GRN_ZIP_AUDIT.md`, and are already reflected in the live
codebase.

## Earlier: PO+GRN Registration Hotfix

## Deployment file

`PO_GRN_REGISTRATION_HOTFIX_SAFE_OVERLAY.zip` is the latest patch. Apply it only
after the PO+GRN feature overlay. It packages the API, SPA router, and sidebar
menu registrations that the first package left as manual instructions. It has
no migration or compiled asset replacement.

The exact content and exclusions are in
`PO_GRN_REGISTRATION_HOTFIX_FILE_MANIFEST.txt`; checksums are in
`PO_GRN_REGISTRATION_HOTFIX_SHA256SUMS.txt`.

## Earlier Build F artifacts

## 1. STOCKY_BUILD_F_SAFE_OVERLAY.zip

Use this file for deployment. It contains only Build F runtime/source changes,
cumulative test maintenance, and release/handoff documentation. Extract it into
the existing active Stocky application root and overwrite matching files. Do not
delete the application first.

The exact contents and exclusions are in `BUILD_F_FILE_MANIFEST.txt`; runtime
checksums are in `BUILD_F_SHA256SUMS.txt`.

## 2. STOCKY_5.8_BUILD_F_DEVELOPER_SOURCE_SANITIZED.zip

Use this file for future AI/human development and code review. It is a complete
sanitized source snapshot of the uploaded active application after Build F. It is
not a production replacement package.

Excluded for security or reproducibility:

- `.env` and Git metadata
- OAuth/private keys
- session, cache, log, and updater runtime files
- live/local database files
- `vendor/` and `node_modules/`

Dependencies are reproducible from `composer.lock` and `package-lock.json`.
Vendor end-user documentation remains available in `documentation.zip`.

## Canonical continuity files

- `docs/AI_HUMAN_DEVELOPER_HANDOFF.md`
- `docs/ARCHITECTURE_AND_CHANGE_CONTROL.md`
- `docs/RELEASE_AND_ROLLBACK_RUNBOOK.md`
- `docs/BASELINE_AUDIT_2026-09-12.md`
- `docs/CLAUDE_PO_GRN_LIFECYCLE_AUDIT_AND_PHASE1_4_HANDOFF.md`
- `CUSTOMIZATIONS.md`
- `README_VENDOR_UPDATE_BN.md`
- latest `BUILD_*_SAFE_OVERLAY_README.md` and `BUILD_*_FILE_MANIFEST.txt`
