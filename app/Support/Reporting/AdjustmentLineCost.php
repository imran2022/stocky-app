<?php

namespace App\Support\Reporting;

use App\Services\Costing\CostingReader;
use Illuminate\Support\Facades\DB;

/**
 * Cost of an Adjustment line, in the SAME dual-mode way `ReportController::stockAdjustmentReport()` already prices
 * the identical documents: the Moving Average ledger cost (|value_delta|) when costing is on, the flat
 * master/variant cost when it is off. `AdjustmentController::adjustment_pdf()` used to always use the flat cost,
 * even when Moving Average was active, so the printed PDF and the report screen could show two different numbers
 * for the same Adjustment. Routing both through this one class removes that gap.
 */
class AdjustmentLineCost
{
    /**
     * @param  int[]  $detailIds  adjustment_details.id values belonging to ONE adjustment
     * @return array<int,float> detailId => absolute ledger cost of that line. Empty when costing is off — the
     *                          caller keeps its own legacy master/variant cost math in that case.
     */
    public static function forDetails(array $detailIds): array
    {
        if ($detailIds === [] || ! CostingReader::active()) {
            return [];
        }

        CostingReader::prepare();

        $out = [];
        foreach (
            DB::table('inventory_cost_ledger')
                ->where('source_type', 'adjustment')
                ->whereIn('source_id', $detailIds)
                ->selectRaw('source_id, SUM(ABS(value_delta)) as cost')
                ->groupBy('source_id')
                ->get() as $r
        ) {
            $out[(int) $r->source_id] = (float) $r->cost;
        }

        return $out;
    }
}
