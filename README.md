# Build K.4 — Activity Log Report missing after fresh install + custom permissions missing from Roles & Permissions

## What this fixes

**1. "Activity Log Report" disappeared from the menu after
`php artisan migrate:fresh --seed`.**
Root cause: the migration that creates this permission also tries to
grant it to the right role — but migrations run *before* seeders, so on a
completely fresh install, no roles exist yet at that moment and the grant
silently does nothing. The permission gets created but handed to nobody,
so it's invisible in the sidebar for every account, including the main
Owner account. This is the exact same bug that was already found and
fixed once before for the "Purchase Orders" feature — the fix for
Activity Log Report was simply never added when that feature was built
later.

**2. Neither "Activity Log Report" nor "Purchase Orders" show up
anywhere in Roles & Permissions when creating or editing a role.**
Root cause: the list of checkboxes shown on that screen is a fixed file,
not a live read of what's actually in the database — so anything added
after that file was last written just never appears as an option, no
matter how correctly it's set up everywhere else. Confirmed by checking
every permission in the database against that file: only these two
customization-related ones were missing (two other pre-existing,
unrelated ones were also missing, but those are handled a different way
on purpose and were left alone).

## What changed
- **New file:** `database/seeders/ActivityLogPermissionSeeder.php` —
  grants the Activity Log permission to the right role(s), safely and
  repeatably.
- **Changed:** `database/seeders/DatabaseSeeder.php` — one new line
  calling the seeder above, in the right order.
- **Changed:** `resources/src/config/permissions.js` — added both
  "Activity Log Report" and "Purchase Orders" as selectable checkboxes,
  in the Reports and Purchases sections respectively.
- **New file:** `tests/Regression/build_k4_activity_log_permission_and_config_gap.php`

No colors, no other menu items, no unrelated logic touched.

## Apply steps

### If you're setting this up on a database you're about to build fresh
(i.e. you're about to run `migrate:fresh --seed` anyway, like your test):

1. **Back up first**, as always.
2. Copy all 4 files from `overlay/` into your project at the same paths,
   overwriting where they already exist.
3. Run your normal fresh-install sequence:
   ```
   php artisan migrate:fresh --seed
   ```
4. Rebuild the frontend:
   ```
   npm run build
   ```
5. Clear caches:
   ```
   php artisan config:clear
   php artisan view:clear
   php artisan cache:clear
   ```
6. Log in as the Owner account and confirm **Activity Log Report** is
   back in the Reports menu.
7. Go to **Roles & Permissions**, open any role (or start a new one), and
   confirm you can now see and check **Activity Log Report** (under
   Reports) and **Purchase Orders** (under Purchases) as options.

### If you're applying this to your current live database WITHOUT wiping it
(recommended for your live site — this does not touch any existing data):

1. **Back up first**, as always.
2. Copy all 4 files from `overlay/` into your project at the same paths.
3. Run just the new seeder (safe to run any number of times, it only adds
   what's missing, never removes or duplicates anything):
   ```
   php artisan db:seed --class=ActivityLogPermissionSeeder
   ```
4. Rebuild the frontend:
   ```
   npm run build
   ```
5. Clear caches:
   ```
   php artisan config:clear
   php artisan view:clear
   php artisan cache:clear
   ```
6. Confirm the same two things as step 6–7 above.
7. **One extra manual step for live sites:** if you have OTHER roles
   besides Owner that should also see the Activity Log Report, go to
   Roles & Permissions and turn that checkbox on for them by hand — the
   automatic grant only covers whichever role(s) already had "Login
   Activity Report" (the closest existing equivalent), same as before.

## About the second missing report you mentioned
I couldn't find a second broken item through code review — the strongest
candidate I checked, "Zone / Courier Report", looks correctly wired end
to end (permission, route, and menu link all check out). Please look
again after applying this fix; if something is still missing from the
menu, let me know its exact name (or a screenshot of the menu) so I can
trace that one specifically — I can't fix what I can't identify from the
code alone.

## Rollback
Restore your backed-up files from step 1, or revert these 4 files via git
once committed. The new seeder never deletes anything, so there's nothing
to undo in the database itself — worst case, just don't grant the
permission to extra roles.
