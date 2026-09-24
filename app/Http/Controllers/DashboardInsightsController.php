<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Setting;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Support\Reporting\DashboardInsights;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Modern Dashboard: numbers on top of dashboard_data (see DashboardInsights). Same date range + warehouse filter as
 * dashboard_data; a warehouse outside the user's scope is ignored, exactly like there.
 */
class DashboardInsightsController extends BaseController
{
    public function index(Request $request)
    {
        $user = $request->user('api');

        // Same gate as the Dashboard page itself.
        $perm = Permission::where('name', 'dashboard')->first();
        if ($perm && ! $user->hasRole($perm->roles)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $from = $request->from ?: Carbon::today()->toDateString();
        $to = $request->to ?: $from;
        if (! strtotime($from) || ! strtotime($to) || $from > $to) {
            return response()->json(['message' => 'Invalid date range.'], 422);
        }

        if ($user->is_all_warehouses) {
            $ids = Warehouse::whereNull('deleted_at')->pluck('id')->all();
        } else {
            $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->all();
        }
        $warehouseId = (int) ($request->warehouse_id ?? 0);
        if ($warehouseId !== 0 && ! in_array($warehouseId, array_map('intval', $ids), true)) {
            $warehouseId = 0;
        }

        $data = DashboardInsights::build([
            'from' => Carbon::parse($from)->toDateString(), 'to' => Carbon::parse($to)->toDateString(),
            'warehouse_id' => $warehouseId, 'warehouse_ids' => $ids, 'all_warehouses' => (bool) $user->is_all_warehouses,
            'view_records' => (bool) $user->hasRecordView(), 'user_id' => (int) $user->id,
        ]);

        // The activity feed is the admin-facing Activity Log: only for people who may open that report.
        $data['activity'] = null;
        try {
            $data['activity_allowed'] = (bool) $user->can('activity_log_report', Setting::class);
        } catch (\Throwable $e) {   // the permission row is missing on an old database: treat as "not allowed"
            $data['activity_allowed'] = false;
        }
        if ($data['activity_allowed']) {
            $data['activity'] = DashboardInsights::activity(30);
        }

        return response()->json($data);
    }
}
