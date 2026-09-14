# Baseline Audit — 12 September 2026

## Result

The uploaded ZIP is structurally usable as the Build F baseline. Archive
integrity passed, expected Laravel/Vue source areas are present, `vendor/` and
`node_modules/` are absent, and E1/E2/E3 source, documentation, regression, and
active compiled-asset markers were found.

The package contains 12,099 archive entries and includes the main application
paths (`app`, `bootstrap`, `config`, `database`, `Modules`, `public`, `resources`,
`routes`, `storage`, and `tests`) plus Composer/npm lock files.

## Verified continuity markers

- `CUSTOMIZATIONS.md` records active builds through E3.
- E1, E2, and E3 regression gates exist.
- Sales/POS Seller/Currency parity and default-hidden Currency source markers are
  present.
- POS Sales retains `is_pos: 1`.
- The service worker was at `stocky-pwa-v11` before Build F.
- The Vite manifest identifies `StockLookup.D-UahGfI.js` as the active Stock
  Lookup lazy chunk.

## Environment limitation

This review environment did not provide a PHP CLI, so the PHP regression scripts
could not be executed here. Their files and required source contracts were
inspected. Run the cumulative PHP commands on the server or a matching staging
environment before production deployment.

## Sensitive/runtime content found in the upload

- `storage/oauth-private.key` and `storage/oauth-public.key`
- 73 runtime session files under `storage/framework/sessions/`
- `storage/logs/laravel.log`
- a zero-byte `database/database.sqlite`

These are not required for source review and are excluded from all deliverables.
If the OAuth private key came from a live installation, treat it as disclosed and
rotate/regenerate the relevant Passport keys after confirming the application's
token-impact plan. Runtime sessions and logs should not be included in future
source uploads.

## Source-control limitation

The ZIP contains no `.git` directory. This is acceptable for patching but means
commit ancestry cannot be verified from the upload. Preserve the external Git
repository if one exists; otherwise the customization log, manifests, and
sanitized source snapshots are the continuity record.
