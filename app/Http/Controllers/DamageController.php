<?php

namespace App\Http\Controllers;

use App\Models\CombinedProduct;
use App\Models\Damage;
use App\Models\DamageDetail;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Support\StockGuard;
use App\Support\StockMutator;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PDF;

class DamageController extends BaseController
{
    /**
     * Security fix (Build N2 / audit C-02 + H-02): centralizes what used to
     * be a copy-pasted ->first() + if($product_warehouse) block at every
     * stock-mutation site in this controller. Always returns a row-locked
     * stock row, creating one at qte=0 first if none existed yet, so a
     * damage record for a product never stocked in this warehouse before
     * can no longer silently do nothing. See app/Support/StockMutator.php.
     *
     * Audit fix (MF-03): $clampFloor used to silently clamp an over-large damage down to zero — the document kept
     * the full submitted quantity (and every reader of that quantity: batch consumption, movement history, the
     * write-off expense report) while only the actually-available stock left the warehouse, so a damage recorded
     * as "10" could really only have removed 3. store()/update() now call assertDamageStockSufficient() BEFORE any
     * mutation, using the same StockGuard::assertAvailable() every other module already uses (so it respects the
     * global "Allow overselling" switch exactly like Sales/POS/Transfer do), and reject the whole request with a
     * clean 422 instead of silently truncating it. $clampFloor is therefore no longer used by this controller
     * (kept as a parameter, defaulted off, so nothing else calling this method changes behaviour).
     */
    private function applyStockDelta(int $warehouseId, int $productId, $variantId, $delta, bool $clampFloor = false): void
    {
        $product_warehouse = StockMutator::lockOrCreate($warehouseId, $productId, $variantId !== null ? $variantId : null);
        $product_warehouse->qte += $delta;
        if ($clampFloor && $product_warehouse->qte < 0) {
            $product_warehouse->qte = 0;
        }
        $product_warehouse->save();
    }

    /**
     * Audit fix (MF-03): every submitted damage line must be a finite, strictly-positive quantity. A negative
     * quantity used to literally INCREASE stock (the delta below is `-$value['quantity']`, and a negative
     * quantity flips that to a positive delta); zero was a silent no-op that still counted as "1 item" toward
     * `items`/history. Called BEFORE the transaction so a rejected request makes no changes at all.
     *
     * @return string|null  an error message, or null when every line is valid
     */
    private function firstInvalidDamageQuantity(array $details): ?string
    {
        foreach ($details as $value) {
            $qty = $value['quantity'] ?? null;
            if (! is_numeric($qty) || ! is_finite((float) $qty) || (float) $qty <= 0) {
                return 'Damage quantity must be a number greater than zero (product #'.($value['product_id'] ?? '?').').';
            }
        }

        return null;
    }

    /**
     * Audit fix (MF-03): expand damage lines into App\Support\StockGuard::need() entries — one entry for a single
     * product, or one for the combo's own row PLUS one for each of its components (mirrors the same dual
     * accounting applyStockDelta() already performs for a combo, so the availability check matches exactly what
     * actually gets deducted).
     */
    private function damageStockNeeds(iterable $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $qty = (float) (is_array($l) ? ($l['quantity'] ?? 0) : $l->quantity);
            if ($qty <= 0) {
                continue;
            }
            $productId = is_array($l) ? ($l['product_id'] ?? null) : $l->product_id;
            $variantId = is_array($l) ? ($l['product_variant_id'] ?? null) : $l->product_variant_id;
            if (! empty($variantId)) {
                $out[] = StockGuard::need($productId, $variantId, $qty);

                continue;
            }
            $product = Product::where('deleted_at', '=', null)->where('id', $productId)->first();
            if ($product && $product->type === 'is_combo') {
                foreach (CombinedProduct::where('product_id', $productId)->get() as $combined) {
                    $out[] = StockGuard::need($combined->combined_product_id, null, $combined->quantity * $qty);
                }
            }
            $out[] = StockGuard::need($productId, null, $qty);
        }

        return $out;
    }

    // ------------ Show All Damages  -----------\\
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        $columns = [0 => 'Ref', 1 => 'warehouse_id', 2 => 'date'];
        $param = [0 => 'like', 1 => '=', 2 => '='];
        $data = [];

        $Damages = Damage::with('warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Damages->whereIn('warehouse_id', $warehouse_ids);
        }

        $Filtred = $helpers->filter($Damages, $columns, $param, $request)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($q) use ($request) {
                            return $q->whereHas('warehouse', function ($q2) use ($request) {
                                $q2->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Damages = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Damages as $DamageRow) {
            $item['id'] = $DamageRow->id;
            $item['date'] = $DamageRow['date'].' '.$DamageRow['time'];
            $item['Ref'] = $DamageRow->Ref;
            $item['warehouse_name'] = $DamageRow['warehouse']->name;
            $item['items'] = $DamageRow->items;
            $data[] = $item;
        }

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'damages' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $warehouses,
        ]);
    }

    // ------------ Store New Damage -----------\\
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Damage::class);

        $productionRules = [
            'warehouse_id' => 'required',
            'details' => 'required',
        ];
        $request->validate($productionRules, [
            'warehouse_id.required' => 'Warehouse is required',
        ]);

        // Audit fix (MF-03): reject before any write — a negative/zero/non-numeric quantity must never reach the
        // stock-mutation code below.
        if ($invalid = $this->firstInvalidDamageQuantity((array) $request['details'])) {
            return response()->json(['success' => false, 'message' => $invalid], 422);
        }

        // Audit fix (MF-03 + MF-16 parity): a restricted user could previously submit any warehouse_id here — the
        // create/update paths for every other stock-changing module already authorize the submitted warehouse.
        $this->abortIfWarehouseDenied($request->warehouse_id);

        \DB::transaction(function () use ($request) {
            // Audit fix (MF-03): the authoritative, locked availability check — respects the same "Allow
            // overselling" switch every other module already honours, instead of silently clamping the mutation
            // below to whatever was actually on hand.
            StockGuard::assertAvailable($request->warehouse_id, $this->damageStockNeeds((array) $request['details']));

            $order = new Damage;
            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->warehouse_id = $request->warehouse_id;
            $order->notes = $request->notes;
            $order->items = count($request['details']);
            $order->user_id = Auth::user()->id;
            $order->save();

            $data = $request['details'];
            $persistedDetails = [];
            foreach ($data as $key => $value) {
                $persistedDetails[$key] = DamageDetail::create([
                    'damage_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'] ?? null,
                ]);

                // Always subtract for damage. Security fix (Build N2 /
                // audit C-02 + H-02): see class docblock above.
                if (! empty($value['product_variant_id'])) {
                    $this->applyStockDelta($order->warehouse_id, $value['product_id'], $value['product_variant_id'], -$value['quantity']);
                } else {
                    $product_detail = Product::where('deleted_at', '=', null)
                        ->where('id', $value['product_id'])
                        ->first();

                    if ($product_detail && $product_detail->type == 'is_single') {
                        $this->applyStockDelta($order->warehouse_id, $value['product_id'], null, -$value['quantity']);
                    } elseif ($product_detail && $product_detail->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();

                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $value['quantity'];
                            $this->applyStockDelta($order->warehouse_id, $combined_product->combined_product_id, null, -$qty_combined);
                        }

                        $this->applyStockDelta($order->warehouse_id, $value['product_id'], null, -$value['quantity']);
                    }
                }
            }

            // Pharmacy: debit user-picked batches alongside the warehouse-stock change
            // we just made above so the per-batch ledger stays consistent.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->applyForDamageWithAutoFallback(
                    $order,
                    array_values($data),
                    $persistedDetails
                );
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        // not used
    }

    // --------------- Update Damage ----------------------\\
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $current_damage = Damage::findOrFail($id);

        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($current_damage);

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $current_damage);
        }

        request()->validate([
            'warehouse_id' => 'required',
        ]);

        // Audit fix (MF-03): reject before any write.
        if ($invalid = $this->firstInvalidDamageQuantity((array) $request['details'])) {
            return response()->json(['success' => false, 'message' => $invalid], 422);
        }

        // Audit fix (MF-03 + MF-16 parity): authorize the (possibly new) target warehouse too.
        $this->abortIfWarehouseDenied($request->warehouse_id);

        \DB::transaction(function () use ($request, $id, $current_damage) {
            $old_details = DamageDetail::where('damage_id', $id)->get();
            $new_details = $request['details'];
            $length = count($new_details);

            $new_ids = [];
            foreach ($new_details as $new_detail) {
                $new_ids[] = $new_detail['id'];
            }

            // Audit fix (MF-03): the authoritative, locked availability check, run before any reversal/reapply
            // below. The old lines are only a credit when the warehouse isn't changing — if it is, the old
            // document's stock impact stays in the OLD warehouse and cannot offset a need in a different one.
            $sameWarehouse = (int) $current_damage->warehouse_id === (int) $request->warehouse_id;
            StockGuard::assertAvailable(
                $request->warehouse_id,
                $this->damageStockNeeds($new_details),
                $sameWarehouse ? $this->damageStockNeeds($old_details) : []
            );

            // Pharmacy: reverse old batch debits before warehouse-stock reversal so
            // the per-batch ledger mirrors the warehouse change.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->reverseForDamageDetails($old_details);
            }

            $old_ids = [];
            foreach ($old_details as $key => $value) {
                $old_ids[] = $value->id;

                // Reverse previous subtraction. Security fix (Build N2 /
                // audit C-02 + H-02): see class docblock above.
                if ($value['product_variant_id'] !== null) {
                    $this->applyStockDelta($current_damage->warehouse_id, $value['product_id'], $value['product_variant_id'], $value['quantity']);
                } else {
                    $product_detail = Product::where('deleted_at', '=', null)
                        ->where('id', $value['product_id'])
                        ->first();

                    if ($product_detail && $product_detail->type == 'is_single') {
                        $this->applyStockDelta($current_damage->warehouse_id, $value['product_id'], null, $value['quantity']);
                    } elseif ($product_detail && $product_detail->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();
                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $value['quantity'];
                            $this->applyStockDelta($current_damage->warehouse_id, $combined_product->combined_product_id, null, $qty_combined);
                        }

                        $this->applyStockDelta($current_damage->warehouse_id, $value['product_id'], null, $value['quantity']);
                    }
                }

                if (! in_array($old_ids[$key], $new_ids)) {
                    $detail = DamageDetail::findOrFail($value->id);
                    $detail->delete();
                }
            }

            $newPersistedDetails = [];
            foreach ($new_details as $key => $product_detail) {
                // Apply new subtraction. Security fix (Build N2 / audit
                // C-02 + H-02): see class docblock above.
                if (! empty($product_detail['product_variant_id'])) {
                    $this->applyStockDelta($request->warehouse_id, $product_detail['product_id'], $product_detail['product_variant_id'], -$product_detail['quantity']);
                } else {
                    $prod = Product::where('deleted_at', '=', null)
                        ->where('id', $product_detail['product_id'])
                        ->first();

                    if ($prod && $prod->type == 'is_single') {
                        $this->applyStockDelta($request->warehouse_id, $product_detail['product_id'], null, -$product_detail['quantity']);
                    } elseif ($prod && $prod->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $product_detail['product_id'])->with('product')->get();
                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $product_detail['quantity'];
                            $this->applyStockDelta($request->warehouse_id, $combined_product->combined_product_id, null, -$qty_combined);
                        }

                        $this->applyStockDelta($request->warehouse_id, $product_detail['product_id'], null, -$product_detail['quantity']);
                    }
                }

                $orderDetails['damage_id'] = $id;
                $orderDetails['quantity'] = $product_detail['quantity'];
                $orderDetails['product_id'] = $product_detail['product_id'];
                $orderDetails['product_variant_id'] = $product_detail['product_variant_id'] ?? null;

                if (! in_array($product_detail['id'], $old_ids)) {
                    $persistedDetail = DamageDetail::Create($orderDetails);
                } else {
                    DamageDetail::where('id', $product_detail['id'])->update($orderDetails);
                    $persistedDetail = DamageDetail::find($product_detail['id']);
                }
                $newPersistedDetails[$key] = $persistedDetail;
            }

            // Pharmacy: re-apply per-batch debits now that DamageDetail rows exist.
            // Lockstep alignment so any skipped rows don't misalign indices.
            if ($batchService->isSupported()) {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($new_details as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $current_damage->warehouse_id = (int) $request['warehouse_id'];
                $batchService->applyForDamageWithAutoFallback(
                    $current_damage,
                    $alignedInput,
                    $alignedPersisted
                );
            }

            $current_damage->update([
                'warehouse_id' => $request['warehouse_id'],
                'notes' => $request['notes'],
                'date' => $request['date'],
                'items' => $length,
            ]);
        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Damage -----------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Damage::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_damage = Damage::findOrFail($id);
            $old_details = DamageDetail::where('damage_id', $id)->get();

            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($current_damage);

            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $current_damage);
            }

            // Pharmacy: reverse batch debits before warehouse-stock reversal so the
            // per-batch ledger mirrors the warehouse change.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->reverseForDamageDetails($old_details);
            }

            foreach ($old_details as $key => $value) {
                // Reverse subtraction (add back). Security fix (Build N2 /
                // audit C-02 + H-02): see class docblock above.
                if ($value['product_variant_id'] !== null) {
                    $this->applyStockDelta($current_damage->warehouse_id, $value['product_id'], $value['product_variant_id'], $value['quantity']);
                } else {
                    $product_detail = Product::where('deleted_at', '=', null)
                        ->where('id', $value['product_id'])
                        ->first();

                    if ($product_detail && $product_detail->type == 'is_single') {
                        $this->applyStockDelta($current_damage->warehouse_id, $value['product_id'], null, $value['quantity']);
                    } elseif ($product_detail && $product_detail->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();
                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $value['quantity'];
                            $this->applyStockDelta($current_damage->warehouse_id, $combined_product->combined_product_id, null, $qty_combined);
                        }

                        $this->applyStockDelta($current_damage->warehouse_id, $value['product_id'], null, $value['quantity']);
                    }
                }
            }
            $current_damage->details()->delete();

            $current_damage->update([
                'deleted_at' => Carbon::now(),
            ]);
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // -------------Show Form Create Damage-----------\\
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Damage::class);

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json(['warehouses' => $warehouses]);
    }

    // -------------Show Form Edit Damage-----------\\
    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Damage_data = Damage::with('details.product')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = [];

        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($Damage_data);

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $Damage_data);
        }

        if ($Damage_data->warehouse_id) {
            if (Warehouse::where('id', $Damage_data->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $damage['warehouse_id'] = $Damage_data->warehouse_id;
            } else {
                $damage['warehouse_id'] = '';
            }
        } else {
            $damage['warehouse_id'] = '';
        }

        $damage['notes'] = $Damage_data->notes;
        $damage['date'] = $Damage_data->date;

        $batchesByDetail = app(BatchService::class)->batchesForDamageDetails($Damage_data['details']);

        $detail_id = 0;
        foreach ($Damage_data['details'] as $detail) {
            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Damage_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['product_variant_id'] = $detail->product_variant_id;
                $data['code'] = $productsVariants->code;
                $data['name'] = \App\Support\ProductDisplayName::format($detail['product']['name'], $productsVariants->name);
                $data['current'] = $item_product ? $item_product->qte : 0;
                $data['type'] = 'sub';
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['del'] = $item_product ? 0 : 1;
                $data['product_type'] = $detail['product']['type'] ?? 'is_single';
            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('warehouse_id', $Damage_data->warehouse_id)
                    ->where('product_variant_id', '=', null)
                    ->first();

                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['current'] = $item_product ? $item_product->qte : 0;
                $data['type'] = 'sub';
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['del'] = $item_product ? 0 : 1;
                $data['product_type'] = $detail['product']['type'] ?? 'is_single';
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'details' => $details,
            'damage' => $damage,
            'warehouses' => $warehouses,
        ]);
    }

    // ---------------- Get Details Damage-----------------\\
    public function Damage_detail(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Damage_data = Damage::with('details.product.unit')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = [];

        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($Damage_data);

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $Damage_data);
        }

        $DamageArr['Ref'] = $Damage_data->Ref;
        $DamageArr['date'] = $Damage_data->date;
        $DamageArr['note'] = $Damage_data->notes;
        $DamageArr['warehouse'] = $Damage_data['warehouse']->name;

        $batchesByDetail = app(BatchService::class)->batchesForDamageDetails($Damage_data['details']);

        foreach ($Damage_data['details'] as $detail) {
            if ($detail->product_variant_id) {
                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                $data['quantity'] = $detail->quantity;
                $data['code'] = $productsVariants->code;
                $data['name'] = \App\Support\ProductDisplayName::format($detail['product']['name'], $productsVariants->name);
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['type'] = 'sub';
            } else {
                $data['quantity'] = $detail->quantity;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['type'] = 'sub';
                $data['unit'] = $detail['product']['unit']->ShortName;
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'damage' => $DamageArr,
        ]);
    }

    // -------------- damage_pdf -----------\\
    public function damage_pdf(Request $request, $id)
    {
        // Security fix (Build N1 / audit C-05)
        $this->authorizeForUser($request->user('api'), 'view', Damage::class);

        $details = [];
        $helpers = new helpers;
        $damage_data = Damage::with('details.product.unit')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $adjustment['warehouse_name'] = $damage_data['warehouse']->name;
        $adjustment['Ref'] = $damage_data->Ref;
        $adjustment['date'] = $damage_data->date.' '.$damage_data->time;

        $detail_id = 0;
        foreach ($damage_data['details'] as $detail) {
            $data['detail_id'] = $detail_id += 1;

            if ($detail->product_variant_id) {
                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                $data['quantity'] = '-'.' '.number_format($detail->quantity, 2, '.', '');
                $data['code'] = $productsVariants->code;
                $data['name'] = \App\Support\ProductDisplayName::format($detail['product']['name'], $productsVariants->name);
                $data['unit'] = $detail['product']['unit']->ShortName;
            } else {
                $data['quantity'] = '-'.' '.number_format($detail->quantity, 2, '.', '');
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;
            }
            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $Html = view('pdf.adjustment_pdf', [
            'setting' => $settings,
            'adjustment' => $adjustment,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);
        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Damage.pdf');
    }

    // ------------- Delete by selection  ---------------\\
    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Damage::class);

        foreach ($request->selectedIds as $id) {
            $this->destroy($request, $id);
        }

        return response()->json(['success' => true]);
    }

    // ------ batches_for_damage ---------------\\
    //
    // Returns FEFO-ordered active batches at the source warehouse for batch-tracked
    // products. Mirrors batches_for_sale but authorized via the Damage policy.
    public function batches_for_damage(Request $request, $product_id, $warehouse_id, $variant_id = null)
    {
        $this->authorizeForUser($request->user('api'), 'create', Damage::class);

        $productId = (int) $product_id;
        $warehouseId = (int) $warehouse_id;
        $variantId = ($variant_id !== null && $variant_id !== '' && $variant_id !== 'null' && (int) $variant_id > 0)
            ? (int) $variant_id
            : null;

        $batchService = app(BatchService::class);

        return response()->json([
            'supported' => $batchService->isSupported(),
            'batches' => $batchService->availableBatchesForSale($productId, $variantId, $warehouseId),
        ]);
    }

    // ------------ Reference Number of Damage  -----------\\
    public function getNumberOrder()
    {
        $last = DB::table('damages')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = isset($nwMsg[1]) ? ($nwMsg[1] + 1) : 1112;
            $code = 'DM_'.$inMsg;
        } else {
            $code = 'DM_1111';
        }

        return $code;
    }
}





