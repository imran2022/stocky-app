<?php

namespace App\Services\Custom;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Build I1 — Activity Log writer.
 *
 * Called from two places:
 *  - ActivityLogServiceProvider's model-event closures (Sale, Purchase,
 *    Product, and Client's `created` event — these fire automatically)
 *  - A few explicit call sites added directly in ClientController for
 *    Customer update/delete, because those two specific actions use
 *    `Client::whereKey($id)->update([...])` (a query-builder bulk update),
 *    which does NOT fire Eloquent model events — see that controller's
 *    inline comments at the call sites for why.
 *
 * Every call is wrapped in try/catch by its caller (matching this
 * project's own AccountingV2ServiceProvider convention) — a logging
 * failure must never break the actual business action.
 */
class ActivityLogger
{
    public static function log(
        string $module,
        string $action,
        string $description,
        ?string $subjectType = null,
        $subjectId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $request = RequestFacade::instance();

        ActivityLog::create([
            'user_id' => Auth::guard('api')->id() ?? Auth::id(),
            'module' => $module,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Fields that never belong in old/new value diffs OR a full-row
     * snapshot: internal bookkeeping that changes on nearly every save
     * (noisy), already covered elsewhere (deleted_at is handled as its own
     * 'deleted' action), or a credential that must never be persisted here
     * even hashed (password) — a hash is still a secret worth protecting,
     * and this log has a wider admin readership than the users table itself.
     */
    private static array $ignoredDiffKeys = [
        'updated_at', 'created_at', 'deleted_at', 'remember_token',
        'password', 'NewPassword',
        // Build I3 (Activity Log extension): the Settings page's cloud-backup
        // section submits these credentials in the same request as ordinary
        // business settings (company name, VAT number, prefixes, etc.) — this
        // log has a wide admin readership (every role with activity_log_report),
        // so none of these may ever be persisted here, hashed or not.
        'backup_s3_access_key', 'backup_s3_secret_key',
        'backup_gdrive_access_token', 'backup_gdrive_refresh_token', 'backup_gdrive_client_secret',
        'backup_dropbox_access_token',
        'google_calendar_client_secret', 'google_calendar_refresh_token',
    ];

    /**
     * Prefixes for whole families of background-integration bookkeeping
     * fields — e.g. every 'quickbooks_*' column on Sale/Client/Product
     * (quickbooks_sync_error, quickbooks_synced_at, quickbooks_invoice_id,
     * quickbooks_realm_id, quickbooks_id). A save attempt is made after
     * every create/update regardless of whether QuickBooks is even
     * connected, so these change on nearly every save and are noise here —
     * a real QuickBooks audit trail is what QuickBooksAudit is for, not
     * this log. Prefix-matched (not a fixed list) so a future QuickBooks
     * column doesn't need this file touched again.
     */
    private static array $ignoredDiffKeyPrefixes = [
        'quickbooks_',
    ];

    private static function isIgnoredDiffKey(string $key): bool
    {
        if (in_array($key, self::$ignoredDiffKeys, true)) {
            return true;
        }

        foreach (self::$ignoredDiffKeyPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips the same ignored keys from a full attribute array (a
     * 'created' row's new_values is the model's whole getAttributes(), not
     * a diff) — every call site that logs a full snapshot MUST go through
     * this, not pass getAttributes() directly, or a password hash (User)
     * or QuickBooks noise ends up stored regardless of the diff() path
     * being clean.
     */
    public static function sanitize(array $attributes): array
    {
        $clean = [];
        foreach ($attributes as $key => $value) {
            if (self::isIgnoredDiffKey($key)) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Reduces a model's getChanges()/getOriginal() to just the fields that
     * actually changed and are worth showing, as two same-shaped arrays
     * (old, new) with matching keys — ready to store as old_values/new_values.
     */
    public static function diff($model): array
    {
        $changes = $model->getChanges();
        $old = [];
        $new = [];

        foreach ($changes as $key => $newValue) {
            if (self::isIgnoredDiffKey($key)) {
                continue;
            }
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $newValue;
        }

        return [$old, $new];
    }
}
