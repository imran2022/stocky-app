<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * Audit fix (Batch 3, finding S8): a sale could carry `discount_from_points`
 * with no matching `used_points`, more points than the customer owns, or for a
 * customer who is not loyalty-eligible. The discount was applied but the points
 * were never deducted (deduction was silently skipped when the balance was too
 * low), so deleting the sale later "refunded" points that were never spent -
 * points could be minted from nothing.
 *
 * Call inside the sale transaction, before the totals are saved. Rules:
 *  - using points requires a loyalty-eligible customer;
 *  - used_points may not exceed the customer's balance (plus the points the
 *    sale being edited already holds);
 *  - the points discount may not exceed used_points x point_to_amount_rate
 *    (Settings), i.e. the same conversion the sale form uses.
 */
class LoyaltyRedemption
{
    private const TOLERANCE = 0.01;

    /**
     * @param  float  $alreadyHeld  points the sale being edited has already consumed for this customer
     *
     * @throws ValidationException
     */
    public static function assertValid($clientId, $usedPoints, $discountFromPoints, float $alreadyHeld = 0.0): void
    {
        $used = max(0.0, (float) $usedPoints);
        $discount = max(0.0, (float) $discountFromPoints);
        if ($used <= 0 && $discount <= 0) {
            return;
        }

        $client = $clientId ? Client::lockForUpdate()->find($clientId) : null;
        if (! $client || ! $client->is_royalty_eligible) {
            throw ValidationException::withMessages(['used_points' => ['Loyalty points can only be used by a loyalty-eligible customer.']]);
        }

        if ($used > (float) $client->points + $alreadyHeld + 1e-6) {
            throw ValidationException::withMessages(['used_points' => [sprintf(
                'The customer only has %s loyalty points.', rtrim(rtrim(number_format((float) $client->points + $alreadyHeld, 2, '.', ''), '0'), '.')
            )]]);
        }

        $rate = (float) (Setting::whereNull('deleted_at')->value('point_to_amount_rate') ?? 0);
        if ($discount > $used * $rate + self::TOLERANCE) {
            throw ValidationException::withMessages(['discount_from_points' => [
                'The points discount is larger than the used points are worth.',
            ]]);
        }
    }
}
