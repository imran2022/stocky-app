<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Validation contract for the custom Sale shipping metadata.
 *
 * Keep these rules centralized because the same Sale fields can be changed
 * through normal Sale create/edit, the Sales bulk action, and Shipment edit.
 * A future vendor update should change this contract in one place rather than
 * letting those entry points drift apart.
 */
final class SaleMetadataRules
{
    /**
     * Existing shipping-status vocabulary used by Sales and Shipments UIs.
     */
    public const SHIPPING_STATUSES = [
        'ordered',
        'packed',
        'shipped',
        'delivered',
        'cancelled',
    ];

    /**
     * Prevent pathological bulk payloads without affecting normal page-sized
     * selections. This is deliberately generous and can be raised later if a
     * documented business workflow legitimately needs larger batches.
     */
    public const MAX_BULK_SALES = 1000;

    /**
     * Rules shared by normal Sale create/update requests.
     */
    public static function saleFields(): array
    {
        return [
            'zone_id' => [
                'nullable',
                'integer',
                Rule::exists('sale_zones', 'id')->whereNull('deleted_at'),
            ],
            'courier_id' => [
                'nullable',
                'integer',
                Rule::exists('sale_couriers', 'id')->whereNull('deleted_at'),
            ],
            // Both columns are Laravel string() columns (VARCHAR 255).
            'tracking_ref' => ['nullable', 'string', 'max:255'],
            'consignment_id' => ['nullable', 'string', 'max:255'],
            // sale_details.box_qty is DECIMAL(10,2) and informational. Keep
            // fractional values valid; only reject negative/out-of-range data.
            'details.*.box_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * Rules for the Sales-list bulk metadata action.
     */
    public static function bulkFields(): array
    {
        return [
            // `sometimes` preserves the existing custom "No rows selected"
            // response when the client omits the field entirely.
            'selectedIds' => ['sometimes', 'array', 'max:'.self::MAX_BULK_SALES],
            'selectedIds.*' => [
                'integer',
                'distinct',
                Rule::exists('sales', 'id')->whereNull('deleted_at'),
            ],
            'shipping_status' => ['sometimes', 'nullable', Rule::in(self::SHIPPING_STATUSES)],
            'zone_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sale_zones', 'id')->whereNull('deleted_at'),
            ],
            'courier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sale_couriers', 'id')->whereNull('deleted_at'),
            ],
            'tracking_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'consignment_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Metadata/status fields editable from the Shipment modal.
     */
    public static function shipmentFields(): array
    {
        return [
            'status' => ['required', Rule::in(self::SHIPPING_STATUSES)],
            'courier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sale_couriers', 'id')->whereNull('deleted_at'),
            ],
            'tracking_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
