<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleCourier;
use App\Models\SaleZone;
use App\Models\Shipment;
use App\Models\UserWarehouse;
use App\Support\SaleMetadataRules;
use App\utils\helpers;
use DB;
use Illuminate\Http\Request;

class ShipmentController extends BaseController
{
    /**
     * Canonical Sale visibility used by Shipment endpoints. Shipment records
     * inherit their access boundary from the Sale they belong to: the same
     * record_view ownership rule and warehouse assignments as SalesController.
     */
    private function visibleSalesQuery($user)
    {
        $query = Sale::query()->whereNull('deleted_at');

        if (! $user->hasRecordView()) {
            $query->where('user_id', $user->id);
        }

        if (! $user->is_all_warehouses) {
            $allowedWarehouseIds = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
            $query->whereIn('warehouse_id', $allowedWarehouseIds);
        }

        return $query;
    }

    /**
     * Scope Shipments through their visible Sale instead of trusting a
     * client-supplied shipment/sale id. The subquery stays in SQL and avoids
     * materializing a potentially large Sale id list in PHP.
     */
    private function visibleShipmentsQuery($user)
    {
        return Shipment::query()->whereIn(
            'sale_id',
            $this->visibleSalesQuery($user)->select('id')
        );
    }

    // ----------- Get ALL Shipments-------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Shipment::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        // Map UI aliases explicitly instead of passing arbitrary SortField
        // values to SQL. Relation-backed keys are handled by safe subqueries
        // below, preserving true server-side sorting across all pages.
        $sortableFields = [
            'id', 'date', 'shipment_ref', 'sale_ref', 'customer_name',
            'warehouse_name', 'status', 'delivered_to',
        ];
        $requestedOrder = (string) ($request->SortField ?: 'id');
        $order = in_array($requestedOrder, $sortableFields, true) ? $requestedOrder : 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        $data = [];

        $user = $request->user('api');
        $shipments = $this->visibleShipmentsQuery($user)
            ->with('sale', 'sale.client', 'sale.warehouse', 'sale.courier')

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('status', 'LIKE', "%{$request->search}%")
                        ->orWhere('delivered_to', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });

                });
            });
        $totalRows = $shipments->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $shipments->offset($offSet)->limit($perPage);

        if ($order === 'shipment_ref') {
            $shipments->orderBy('Ref', $dir);
        } elseif ($order === 'sale_ref') {
            $saleRefSub = DB::table('sales')
                ->select('Ref')
                ->whereColumn('sales.id', 'shipments.sale_id')
                ->limit(1);
            $shipments->orderBy($saleRefSub, $dir);
        } elseif ($order === 'customer_name') {
            $customerSub = DB::table('sales')
                ->join('clients', 'clients.id', '=', 'sales.client_id')
                ->select('clients.name')
                ->whereColumn('sales.id', 'shipments.sale_id')
                ->limit(1);
            $shipments->orderBy($customerSub, $dir);
        } elseif ($order === 'warehouse_name') {
            $warehouseSub = DB::table('sales')
                ->join('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')
                ->select('warehouses.name')
                ->whereColumn('sales.id', 'shipments.sale_id')
                ->limit(1);
            $shipments->orderBy($warehouseSub, $dir);
        } else {
            $shipments->orderBy($order, $dir);
        }

        $shipments_data = $shipments->get();

        foreach ($shipments_data as $shipment) {

            $item['id'] = $shipment['id'];
            $item['date'] = $shipment['date'];
            $item['shipment_ref'] = $shipment['Ref'];
            $item['status'] = $shipment['status'];
            $item['delivered_to'] = $shipment['delivered_to'];
            $item['shipping_address'] = $shipment['shipping_address'];
            $item['shipping_details'] = $shipment['shipping_details'];
            $item['sale_ref'] = $shipment['sale']['Ref'];
            $item['sale_id'] = $shipment['sale']['id'];
            $item['courier_name'] = optional($shipment['sale']['courier'])->name;
            $item['consignment_id'] = $shipment['sale']['consignment_id'];
            $item['tracking_ref'] = $shipment['sale']['tracking_ref'];
            $item['warehouse_name'] = $shipment['sale']['warehouse']->name;
            $item['customer_name'] = $shipment['sale']['client']->name;

            $data[] = $item;
        }

        // Global per-status counts for the summary cards (independent of
        // pagination/search so the tiles always show the full picture).
        $status_counts = $this->visibleShipmentsQuery($user)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'shipments' => $data,
            'totalRows' => $totalRows,
            'status_counts' => $status_counts,
        ]);
    }

    // ----------- Store new Shipment -------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Shipment::class);

        request()->validate(array_merge([
            'Ref' => 'required',
            'sale_id' => 'required|integer',
        ], SaleMetadataRules::shipmentFields()));

        $user = $request->user('api');

        \DB::transaction(function () use ($request, $user) {
            $sale = $this->visibleSalesQuery($user)
                ->lockForUpdate()
                ->findOrFail($request['sale_id']);

            // Preserve the existing Ref-based upsert used by Sales/PosSales,
            // but never allow a known shipment Ref to be rebound to another
            // Sale by changing a client-supplied sale_id.
            $shipment = Shipment::where('Ref', $request['Ref'])->lockForUpdate()->first();
            if ($shipment && (int) $shipment->sale_id !== (int) $sale->id) {
                abort(422, 'Shipment reference already belongs to another sale.');
            }
            if (! $shipment) {
                $shipment = new Shipment;
                $shipment->Ref = $request['Ref'];
            }

            $shipment->user_id = $user->id;
            $shipment->sale_id = $sale->id;
            $shipment->delivered_to = $request['delivered_to'];
            $shipment->phone_number = $request['phone_number'] ?? null;
            $shipment->shipping_address = $request['shipping_address'];
            $shipment->shipping_details = $request['shipping_details'];
            $shipment->status = $request['status'];
            $shipment->save();

            $sale->update([
                'shipping_status' => $request['status'],
            ]);

        }, 10);

        return response()->json(['success' => true]);

    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Shipment::class);

        $user = $request->user('api');
        $sale = $this->visibleSalesQuery($user)->with('client')->findOrFail($id);
        $get_shipment = $this->visibleShipmentsQuery($user)
            ->where('sale_id', $sale->id)
            ->first();

        if ($get_shipment) {

            $shipment_data['Ref'] = $get_shipment->Ref;
            $shipment_data['sale_id'] = $get_shipment->sale_id;
            $shipment_data['delivered_to'] = $get_shipment->delivered_to;
            $shipment_data['phone_number'] = $get_shipment->phone_number;
            $shipment_data['shipping_address'] = $get_shipment->shipping_address;
            $shipment_data['status'] = $get_shipment->status;
            $shipment_data['shipping_details'] = $get_shipment->shipping_details;

        } else {

            $shipment_data['Ref'] = $this->getNumberOrder();
            $shipment_data['sale_id'] = $id;
            $shipment_data['delivered_to'] = '';
            $shipment_data['phone_number'] = '';
            $shipment_data['shipping_address'] = '';
            $shipment_data['status'] = '';
            $shipment_data['shipping_details'] = '';
        }

        // Courier/Tracking live on the Sale (same fields used across the
        // rest of the app — see Sales list/Create Sale), not duplicated
        // onto shipments, so editing them here edits the Sale directly.
        $shipment_data['courier_id'] = optional($sale)->courier_id;
        $shipment_data['tracking_ref'] = optional($sale)->tracking_ref;
        $shipment_data['consignment_id'] = optional($sale)->consignment_id;
        $shipment_data['invoice_address'] = optional(optional($sale)->client)->adresse;

        return response()->json([
            'shipment' => $shipment_data,
            'zones' => SaleZone::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'couriers' => SaleCourier::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);

    }

    // ----------- Update Shipment-------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Shipment::class);

        request()->validate(array_merge([
            'sale_id' => 'required|integer',
        ], SaleMetadataRules::shipmentFields()));

        $user = $request->user('api');

        \DB::transaction(function () use ($request, $id, $user) {
            $shipment = $this->visibleShipmentsQuery($user)
                ->lockForUpdate()
                ->findOrFail($id);

            // A shipment edit is not a Sale-reassignment workflow. Keeping
            // this invariant prevents Shipment A from being used to modify
            // Sale B through a crafted sale_id.
            if ((int) $request['sale_id'] !== (int) $shipment->sale_id) {
                abort(422, 'Shipment cannot be reassigned to another sale.');
            }

            $shipment->update($request->only([
                'delivered_to', 'phone_number', 'shipping_address', 'status', 'shipping_details',
            ]));

            $sale = $this->visibleSalesQuery($user)
                ->lockForUpdate()
                ->findOrFail($shipment->sale_id);
            $salePayload = ['shipping_status' => $request['status']];
            if ($request->has('courier_id')) {
                $salePayload['courier_id'] = $request['courier_id'] ?: null;
            }
            if ($request->has('tracking_ref')) {
                $salePayload['tracking_ref'] = $request['tracking_ref'] !== '' ? $request['tracking_ref'] : null;
            }
            $sale->update($salePayload);

        }, 10);

        return response()->json(['success' => true]);

    }

    // ----------- delete Shipment-------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Shipment::class);

        $user = $request->user('api');

        \DB::transaction(function () use ($request, $id, $user) {

            $shipment = $this->visibleShipmentsQuery($user)
                ->lockForUpdate()
                ->findOrFail($id);

            $sale = $this->visibleSalesQuery($user)
                ->lockForUpdate()
                ->findOrFail($shipment->sale_id);

            $shipment->delete();
            $sale->update([
                'shipping_status' => $request['status'],
            ]);

        }, 10);

        return response()->json(['success' => true]);

    }

    // ------------- Reference Number Order SALE -----------\\

    public function getNumberOrder()
    {

        $last = DB::table('shipments')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = $nwMsg[1] + 1;
            $code = $nwMsg[0].'_'.$inMsg;
        } else {
            $code = 'SM_1111';
        }

        return $code;
    }
}
