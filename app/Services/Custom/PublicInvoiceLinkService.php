<?php

namespace App\Services\Custom;

use App\Models\Sale;
use Illuminate\Support\Str;

/**
 * Public Invoice Link — token management.
 *
 * Kept deliberately tiny and separate from SalesController (per this
 * project's own stated preference for new customizations to live in their
 * own Service/Policy/Observer classes rather than inline in vendor-owned
 * controllers) — SalesController itself is not touched by this feature
 * except for the one-line inline-vs-download tweak to Sale_PDF (see that
 * method's own comment), which this service has no reason to duplicate.
 */
class PublicInvoiceLinkService
{
    /**
     * Returns the sale's existing public token, generating one on first
     * use. A sale nobody has ever asked to share has no token and no
     * public link — nothing to leak.
     */
    public static function getOrCreateToken(Sale $sale): string
    {
        if ($sale->public_token) {
            return $sale->public_token;
        }

        $sale->public_token = self::generateUniqueToken();
        $sale->saveQuietly(); // no reason for this to appear as a Sale "update" in the Activity Log

        return $sale->public_token;
    }

    /**
     * Invalidates the old link (if the token ever leaked) and returns the
     * new one.
     */
    public static function regenerateToken(Sale $sale): string
    {
        $sale->public_token = self::generateUniqueToken();
        $sale->saveQuietly();

        return $sale->public_token;
    }

    private static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while (Sale::where('public_token', $token)->exists());

        return $token;
    }
}
