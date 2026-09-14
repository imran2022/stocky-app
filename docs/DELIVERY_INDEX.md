# Delivery Index (current as of the PO+GRN / Reports / post-release-fixes era)

## Two delivery artifacts per release, always paired

Every release since "Sync: actual production state through Build G1" ships as:

1. **A ZIP** (`STOCKY_<NAME>.zip` or `STOCKY_<NAME>_SAFE_OVERLAY.zip`) — for
   deploying to the live application. Contains only the changed/new runtime
   files for that release, a `*_FILE_MANIFEST.txt` listing exact
   inclusions/exclusions, and a `*_README.md` with apply steps, what was
   verified, and rollback instructions. A release touching genuine new Vue
   logic ships a full pre-built `public/js/`; a release with no frontend
   logic change (pure PHP, e.g. a migration or a backend-only bugfix) does
   not touch `public/js` at all.

2. **A git bundle** (`stocky-repo-<name>.bundle`) — for keeping the actual
   version-control history in sync with what's deployed. This is the
   **canonical continuity record going forward**, superseding the
   file-manifest-only approach this project used before a real Git
   repository existed for it (see `BASELINE_AUDIT_2026-09-12.md`'s
   "Source-control limitation" note — that limitation no longer applies).
   Each bundle contains the full commit history up to and including that
   release; cloning it or fetching it into the existing local repo and
   force-pushing to the remote keeps GitHub as the single source of truth.

**Large, shared routing/config files** (`routes/api.php`,
`resources/src/router/index.js`, `resources/src/config/menu.js`) are
delivered as an exact, minimal diff/patch in the release README rather than
a full-file copy in the ZIP, specifically to avoid a full-file overwrite
silently discarding some other change made to those files since the last
sync — unless a specific release's README says otherwise (e.g., when the
git history confirms only one party has ever touched those exact files,
a full-file copy may be offered as a lower-friction alternative on request).

## Canonical continuity files (read in this order for a new session/handoff)

1. `docs/AI_HUMAN_DEVELOPER_HANDOFF.md` — start here; active release chain,
   non-negotiable contracts, verification discipline, working areas.
2. `docs/ARCHITECTURE_AND_CHANGE_CONTROL.md` — system shape, repository map,
   data/authorization conventions, customization domains, **the "Hard
   lessons from real incidents" section — read this before touching stock,
   money, permissions, migrations, or any `useCrudTable`-based Vue page.**
3. `CUSTOMIZATIONS.md` — the full chronological, numbered log of every
   customization: what changed, why, and what was verified. This is the
   single most detailed record; when in doubt about whether something is
   original-vendor or custom behavior, check here first.
4. `docs/RELEASE_AND_ROLLBACK_RUNBOOK.md` — deployment and rollback
   procedure, plus release-specific smoke-test checklists.
5. The latest release's own `*_README.md` and `*_FILE_MANIFEST.txt`.
6. `README_VENDOR_UPDATE_BN.md` — deployment history and Bangla operator
   notes (pre-existing, from earlier vendor-update work).
7. Relevant `tests/Regression/build_*.php` files for the area being changed.

`README.md` is the vendor's own changelog, not a record of this project's
customizations. `documentation.zip` (if present) is the vendor's end-user
documentation, not a developer reference.
