<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseOrderDocument;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Support\SafeDocumentUpload;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDF;

/**
 * Purchase Order (PO) management — the "we intend to buy this" document
 * that precedes a GRN (Purchase). See PurchaseOrder's and the creating
 * migrations' docblocks for the full status lifecycle and how GRN receipts
 * feed back into a PO's received_quantity/status (that side lives in
 * PurchaseOrderReceiptService, called from PurchasesController).
 *
 * Deliberately its own controller rather than folded into
 * PurchasesController: a PO shares GRN's vocabulary (supplier, warehouse,
 * line items) but not its stock-affecting, batch/serial-tracking, or
 * payment behavior — keeping it separate avoids growing an already very
 * large controller and keeps this feature's merge-conflict surface
 * entirely within new files.
 */
class PurchaseOrderController extends Controller
{
    /**
     * Warehouse IDs the current user may see, or null when unrestricted.
     * Same convention used across Sales/Products/Shipment controllers.
     */
    private function allowedWarehouseIds(): ?array
    {
        $user = Auth::user();
        if ($user->is_all_warehouses) {
            return null;
        }

        return UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
    }

    // ----------- List -------------

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $user = Auth::user();
        $viewRecords = $user->hasRecordView();
        $allowedWarehouseIds = $this->allowedWarehouseIds();

        $perPage = (int) $request->limit ?: 15;
        $page = (int) $request->get('page', 1);
        $offset = $perPage > 0 ? ($page - 1) * $perPage : 0;

        // Explicit sort whitelist — an unrecognized/joined-field SortField
        // must fall back to id, never reach raw SQL. See the Products/
        // Shipments list fixes earlier in this codebase's history for why
        // this matters (an unguarded SortField crashed both of those).
        $allowedSortColumns = ['id', 'Ref', 'date', 'expected_delivery_date', 'status', 'GrandTotal'];
        $order = in_array($request->SortField, $allowedSortColumns, true) ? $request->SortField : 'id';
        $dir = strtolower($request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = PurchaseOrder::with(['provider', 'warehouse', 'currency', 'user'])
            ->whereNull('deleted_at')
            ->when(! $viewRecords, fn ($q) => $q->where('user_id', Auth::user()->id))
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $allowedWarehouseIds));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('Ref', 'like', "%{$search}%")
                    ->orWhereHas('provider', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }
        if ($request->boolean('overdue_only')) {
            $query->whereIn('status', ['ordered', 'partially_received'])
                ->whereNotNull('expected_delivery_date')
                ->where('expected_delivery_date', '<', now()->format('Y-m-d'));
        }

        $totalRows = (clone $query)->count();

        // Fulfillment summary stats — computed over the same filtered
        // (but not yet paginated) query, matching the exact convention
        // PurchasesController::index() already uses for its own 'stats'
        // block. "Open" = ordered/partially_received (still expecting
        // delivery); "Overdue" = open AND past its expected_delivery_date.
        // Today's date is compared as a plain string (YYYY-MM-DD) against
        // the stored date column, avoiding a timezone-sensitive Carbon
        // comparison for a same-day-granularity field.
        $today = now()->format('Y-m-d');
        $openStatuses = ['ordered', 'partially_received'];
        $openQuery = (clone $query)->whereIn('status', $openStatuses);
        $overdueQuery = (clone $openQuery)->whereNotNull('expected_delivery_date')->where('expected_delivery_date', '<', $today);

        $stats = [
            'open_count' => (clone $openQuery)->count(),
            'open_value' => (float) ((clone $openQuery)->sum('GrandTotal')),
            'overdue_count' => (clone $overdueQuery)->count(),
            'overdue_value' => (float) ((clone $overdueQuery)->sum('GrandTotal')),
        ];

        $orders = $query->orderBy($order, $dir)
            ->offset($offset)
            ->limit($perPage > 0 ? $perPage : $totalRows)
            ->get();

        // Received % per PO — computed from the same lines already needed
        // for the status badge, one grouped query for the whole page rather
        // than one query per row (the exact N+1 shape fixed earlier in the
        // Products list; not repeating it here).
        $poIds = $orders->pluck('id')->all();
        $lineTotals = [];
        $lastReceiptDates = [];
        $hasDocumentsMap = [];
        if (! empty($poIds)) {
            $lineTotals = PurchaseOrderDetail::whereIn('purchase_order_id', $poIds)
                ->selectRaw('purchase_order_id, SUM(quantity) as ordered_qty, SUM(received_quantity) as received_qty')
                ->groupBy('purchase_order_id')
                ->get()
                ->keyBy('purchase_order_id');

            // Last GRN Date — most recent receipt date per PO, one grouped
            // query for the page (not per row).
            $lastReceiptDates = DB::table('purchases')
                ->whereIn('purchase_order_id', $poIds)
                ->whereNull('deleted_at')
                ->selectRaw('purchase_order_id, MAX(date) as last_date')
                ->groupBy('purchase_order_id')
                ->pluck('last_date', 'purchase_order_id');

            // Attachment indicator — same grouped-not-per-row shape.
            $hasDocumentsMap = DB::table('purchase_order_documents')
                ->whereIn('purchase_order_id', $poIds)
                ->whereNull('deleted_at')
                ->selectRaw('purchase_order_id, COUNT(*) as doc_count')
                ->groupBy('purchase_order_id')
                ->pluck('doc_count', 'purchase_order_id');
        }

        $data = $orders->map(function (PurchaseOrder $po) use ($lineTotals, $lastReceiptDates, $hasDocumentsMap) {
            $totals = $lineTotals->get($po->id);
            $orderedQty = $totals ? (float) $totals->ordered_qty : 0.0;
            $receivedQty = $totals ? (float) $totals->received_qty : 0.0;

            return [
                'id' => $po->id,
                'Ref' => $po->Ref,
                'date' => $po->date,
                'expected_delivery_date' => $po->expected_delivery_date,
                'provider_id' => $po->provider_id,
                'provider_name' => optional($po->provider)->name,
                'warehouse_id' => $po->warehouse_id,
                'warehouse_name' => optional($po->warehouse)->name,
                'created_by_name' => optional($po->user)->username ?? optional($po->user)->firstname,
                'status' => $po->status,
                'GrandTotal' => number_format($po->GrandTotal, helpers::price_decimals(), '.', ''),
                'currency_code' => optional($po->currency)->code,
                'received_percent' => $orderedQty > 0 ? round(($receivedQty / $orderedQty) * 100, 1) : null,
                'last_grn_date' => $lastReceiptDates[$po->id] ?? null,
                'has_documents' => ($hasDocumentsMap[$po->id] ?? 0) > 0,
            ];
        });

        return response()->json([
            'purchase_orders' => $data,
            'totalRows' => $totalRows,
            'stats' => $stats,
            'suppliers' => Provider::whereNull('deleted_at')->get(['id', 'name']),
            'warehouses' => $allowedWarehouseIds !== null
                ? Warehouse::whereNull('deleted_at')->whereIn('id', $allowedWarehouseIds)->get(['id', 'name'])
                : Warehouse::whereNull('deleted_at')->get(['id', 'name']),
        ]);
    }

    // ----------- Create -------------

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PurchaseOrder::class);

        $request->validate([
            'provider_id' => 'required|exists:providers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'status' => 'nullable|in:'.implode(',', PurchaseOrder::MANUAL_STATUSES),
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.cost' => 'required|numeric|min:0',
        ]);

        // The `exists:warehouses,id` rule above only confirms the warehouse
        // is real — not that this user is allowed to create documents for
        // it. Same check used on every other single-record action here.
        $this->abortIfWarehouseDenied((int) $request->warehouse_id);

        $po = DB::transaction(function () use ($request) {
            $docCurrency = helpers::resolve_request_currency($request);

            $order = new PurchaseOrder([
                'Ref' => $this->getNumberOrder(),
                'date' => $request->date,
                'expected_delivery_date' => $request->expected_delivery_date ?: null,
                'provider_id' => $request->provider_id,
                'warehouse_id' => $request->warehouse_id,
                'status' => $request->status ?: 'ordered',
                'tax_rate' => $request->tax_rate ?: 0,
                'TaxNet' => $request->TaxNet ?: 0,
                'discount' => $request->discount ?: 0,
                'shipping' => $request->shipping ?: 0,
                'GrandTotal' => $request->GrandTotal ?: 0,
                'notes' => $request->notes,
                'currency_id' => $docCurrency['currency_id'],
                'exchange_rate' => $docCurrency['exchange_rate'],
            ]);
            $order->user_id = Auth::user()->id;
            $order->save();

            foreach ($request->details as $line) {
                PurchaseOrderDetail::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'cost' => $line['cost'],
                    'TaxNet' => $line['tax_percent'] ?? 0,
                    'tax_method' => $line['tax_method'] ?? '1',
                    'discount' => $line['discount'] ?? 0,
                    'discount_method' => $line['discount_Method'] ?? '2',
                    'quantity' => $line['quantity'],
                    'total' => $line['subtotal'] ?? ($line['cost'] * $line['quantity']),
                ]);
            }

            return $order;
        }, 3);

        return response()->json(['success' => true, 'message' => 'Purchase Order Created', 'id' => $po->id]);
    }

    // ----------- Show (for edit form / GRN pre-fill) -------------

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $po = PurchaseOrder::with(['provider', 'warehouse', 'currency', 'documents'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $this->abortIfWarehouseDenied($po->warehouse_id);

        $details = PurchaseOrderDetail::with('product', 'variant')
            ->where('purchase_order_id', $po->id)
            ->get()
            ->map(function (PurchaseOrderDetail $d) {
                return [
                    'id' => $d->id,
                    'product_id' => $d->product_id,
                    'product_variant_id' => $d->product_variant_id,
                    'name' => \App\Support\ProductDisplayName::format(optional($d->product)->name, optional($d->variant)->name),
                    'code' => optional($d->variant)->code ?? optional($d->product)->code,
                    'cost' => $d->cost,
                    'tax_percent' => $d->TaxNet,
                    'tax_method' => $d->tax_method,
                    'discount' => $d->discount,
                    'discount_Method' => $d->discount_method,
                    'quantity' => $d->quantity,
                    'received_quantity' => $d->received_quantity,
                    'remaining_quantity' => $d->remaining_quantity,
                    'total' => $d->total,
                ];
            });

        return response()->json([
            'purchase_order' => [
                'id' => $po->id,
                'Ref' => $po->Ref,
                'date' => $po->date,
                'expected_delivery_date' => $po->expected_delivery_date,
                'provider_id' => $po->provider_id,
                // Added for the read-only View page (PurchaseOrderDetails.vue) —
                // purely additive; the existing edit form only ever read the
                // *_id fields above, so this cannot affect it.
                'provider_name' => optional($po->provider)->name,
                'provider_phone' => optional($po->provider)->phone,
                'provider_email' => optional($po->provider)->email,
                'provider_address' => optional($po->provider)->adresse,
                'warehouse_id' => $po->warehouse_id,
                'warehouse_name' => optional($po->warehouse)->name,
                'status' => $po->status,
                'tax_rate' => $po->tax_rate,
                'TaxNet' => $po->TaxNet,
                'discount' => $po->discount,
                'shipping' => $po->shipping,
                'GrandTotal' => $po->GrandTotal,
                'notes' => $po->notes,
                'currency_id' => $po->currency_id,
                'exchange_rate' => $po->exchange_rate,
                'is_editable' => in_array($po->status, ['draft', 'ordered'], true),
            ],
            'details' => $details,
            'documents' => $po->documents,
            // Same company block shape PurchaseDetails.vue already consumes
            // from GET purchases/{id} — reused here for visual/field parity
            // between the two view pages.
            'company' => Setting::whereNull('deleted_at')->first(),
        ]);
    }

    // ----------- Update (header + lines) — only while draft/ordered -------------

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PurchaseOrder::class);

        $po = PurchaseOrder::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);

        // Editing is intentionally blocked once any GRN has touched this PO
        // (partially_received/received) or it's cancelled — the ordered
        // quantities a supplier has already started fulfilling against must
        // stay stable. This mirrors why the frontend also disables the form
        // in that state (see `is_editable` in show()); this check is the
        // authoritative one, not just a UI courtesy.
        if (! in_array($po->status, PurchaseOrder::MANUAL_STATUSES, true) || $po->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This Purchase Order can no longer be edited (it has receipts against it, or is cancelled).',
            ], 422);
        }

        $request->validate([
            'provider_id' => 'required|exists:providers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'status' => 'nullable|in:'.implode(',', PurchaseOrder::MANUAL_STATUSES),
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.cost' => 'required|numeric|min:0',
        ]);

        // Re-check in case the request is trying to move this PO to a
        // different (unauthorized) warehouse — the check above only
        // covered the PO's warehouse as it already was.
        $this->abortIfWarehouseDenied((int) $request->warehouse_id);

        DB::transaction(function () use ($request, $po) {
            $po->update([
                'date' => $request->date,
                'expected_delivery_date' => $request->expected_delivery_date ?: null,
                'provider_id' => $request->provider_id,
                'warehouse_id' => $request->warehouse_id,
                'status' => $request->status ?: $po->status,
                'tax_rate' => $request->tax_rate ?: 0,
                'TaxNet' => $request->TaxNet ?: 0,
                'discount' => $request->discount ?: 0,
                'shipping' => $request->shipping ?: 0,
                'GrandTotal' => $request->GrandTotal ?: 0,
                'notes' => $request->notes,
            ] + helpers::resolve_request_currency($request, $po->currency_id, $po->exchange_rate));

            // Lines are fully replaced on edit (no partial receipts can
            // exist while status is draft/ordered, so there is nothing to
            // preserve/realign — unlike PurchasesController::update()'s
            // much more careful batch/serial re-alignment for an
            // already-received GRN).
            PurchaseOrderDetail::where('purchase_order_id', $po->id)->delete();
            foreach ($request->details as $line) {
                PurchaseOrderDetail::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'cost' => $line['cost'],
                    'TaxNet' => $line['tax_percent'] ?? 0,
                    'tax_method' => $line['tax_method'] ?? '1',
                    'discount' => $line['discount'] ?? 0,
                    'discount_method' => $line['discount_Method'] ?? '2',
                    'quantity' => $line['quantity'],
                    'total' => $line['subtotal'] ?? ($line['cost'] * $line['quantity']),
                ]);
            }
        }, 3);

        return response()->json(['success' => true, 'message' => 'Purchase Order Updated']);
    }

    // ----------- Delete -------------

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PurchaseOrder::class);

        $po = PurchaseOrder::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);

        if ($po->receipts()->whereNull('deleted_at')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This Purchase Order has GRN receipts against it and cannot be deleted.',
            ], 422);
        }

        $po->deleted_at = now();
        $po->save();

        return response()->json(['success' => true, 'message' => 'Purchase Order Deleted']);
    }

    // ----------- GRN support endpoints -------------

    /**
     * Open POs (ordered/partially_received) for a supplier, for the GRN
     * form's "select a PO" dropdown once a supplier is chosen. Deliberately
     * excludes draft (not yet sent, nothing to receive against), received
     * (nothing left to receive), and cancelled.
     */
    public function openForProvider(Request $request, $providerId)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $allowedWarehouseIds = $this->allowedWarehouseIds();

        $orders = PurchaseOrder::whereNull('deleted_at')
            ->where('provider_id', $providerId)
            ->whereIn('status', ['ordered', 'partially_received'])
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $allowedWarehouseIds))
            ->orderByDesc('date')
            ->get(['id', 'Ref', 'date', 'status', 'warehouse_id']);

        return response()->json($orders);
    }

    /**
     * A PO's lines formatted for the GRN form's "auto-load items" action —
     * only the remaining (not-yet-received) quantity per line, since that's
     * what a GRN should default to receiving. A line already fully received
     * is omitted entirely (nothing left to receive on it).
     */
    public function linesForGrn(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $po = PurchaseOrder::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);

        $lines = PurchaseOrderDetail::with('product', 'variant')
            ->where('purchase_order_id', $po->id)
            ->get()
            ->filter(fn (PurchaseOrderDetail $d) => ! $d->is_fully_received)
            ->values()
            ->map(function (PurchaseOrderDetail $d) {
                return [
                    'purchase_order_detail_id' => $d->id,
                    'product_id' => $d->product_id,
                    'product_variant_id' => $d->product_variant_id,
                    'name' => \App\Support\ProductDisplayName::format(optional($d->product)->name, optional($d->variant)->name),
                    'code' => optional($d->variant)->code ?? optional($d->product)->code,
                    'ordered_quantity' => $d->quantity,
                    'received_quantity' => $d->received_quantity,
                    'remaining_quantity' => $d->remaining_quantity,
                    'po_cost' => $d->cost,
                ];
            });

        return response()->json([
            'warehouse_id' => $po->warehouse_id,
            'lines' => $lines,
        ]);
    }

    // ----------- Numbering -------------

    public function getNumberOrder(): string
    {
        $setting = Setting::whereNull('deleted_at')->first();
        $prefix = ! empty($setting->po_prefix) ? $setting->po_prefix : 'PO';

        $last = DB::table('purchase_orders')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $parts = explode('_', $last->Ref);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                return $parts[0].'_'.str_pad((int) $parts[1] + 1, 4, '0', STR_PAD_LEFT);
            }
        }

        return $prefix.'_0001';
    }

    // ----------- Attachments (mirrors PurchasesController's document endpoints) -------------

    public function getDocuments(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $po = PurchaseOrder::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);

        $documents = DB::table('purchase_order_documents')
            ->where('purchase_order_id', $id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['documents' => $documents, 'status' => true]);
    }

    public function uploadDocuments(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PurchaseOrder::class);

        $po = PurchaseOrder::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);

        $request->validate([
            // Security fix (Build N1 / audit C-03): allow-list of safe
            // document types only — was previously unrestricted.
            'documents.*' => SafeDocumentUpload::validationRule(),
        ]);

        $uploaded = [];
        if ($request->hasFile('documents')) {
            $uploadPath = public_path('images/purchase_order_documents');
            if (! file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            foreach ($request->file('documents') as $file) {
                $originalName = $file->getClientOriginalName();
                $mime = $file->getClientMimeType();
                $size = $file->getSize();
                // Security fix: filename built from the VALIDATED extension
                // only, never trusting the client-supplied original name/
                // extension directly.
                $storedName = SafeDocumentUpload::safeFilename($file, 'po_'.$po->id);
                $file->move($uploadPath, $storedName);

                $documentId = DB::table('purchase_order_documents')->insertGetId([
                    'purchase_order_id' => $po->id,
                    'name' => $originalName,
                    'path' => 'images/purchase_order_documents/'.$storedName,
                    'size' => $size,
                    'mime_type' => $mime,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $uploaded[] = $documentId;
            }
        }

        return response()->json(['status' => true, 'uploaded' => $uploaded]);
    }

    public function downloadDocument(Request $request, $documentId)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $document = DB::table('purchase_order_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            return response()->json(['message' => 'Document not found', 'status' => false], 404);
        }

        $po = PurchaseOrder::whereNull('deleted_at')->find($document->purchase_order_id);
        if ($po) {
            $this->abortIfWarehouseDenied($po->warehouse_id);
        }

        $filePath = public_path($document->path);
        if (! file_exists($filePath)) {
            return response()->json(['message' => 'Physical file not found on server', 'status' => false], 404);
        }

        return response()->download($filePath, $document->name);
    }

    public function deleteDocument(Request $request, $documentId)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PurchaseOrder::class);

        $document = DB::table('purchase_order_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            return response()->json(['message' => 'Document not found', 'status' => false], 404);
        }

        $po = PurchaseOrder::whereNull('deleted_at')->find($document->purchase_order_id);
        if ($po) {
            $this->abortIfWarehouseDenied($po->warehouse_id);
        }

        DB::table('purchase_order_documents')->where('id', $documentId)->update(['deleted_at' => now()]);

        return response()->json(['status' => true]);
    }

    // ----------- PDF & Email -------------

    /**
     * Purchase Order PDF — deliberately its own simple template (pdf.po_pdf)
     * rather than reusing pdf.purchase_pdf: a PO has no payment/paid/due
     * state (it hasn't been received yet), so purchase_pdf's prominent
     * Paid/Due totals would be actively misleading here.
     */
    public function pdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $po = PurchaseOrder::with(['provider', 'warehouse', 'currency'])->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);
        $docCurrency = helpers::Get_Document_Currency($po);
        $settings = Setting::whereNull('deleted_at')->first();

        $lines = PurchaseOrderDetail::with('product', 'variant')
            ->where('purchase_order_id', $po->id)
            ->get()
            ->map(function (PurchaseOrderDetail $d) use ($docCurrency) {
                $cost = (float) $d->cost * $docCurrency['rate'];

                return [
                    'name' => \App\Support\ProductDisplayName::format(optional($d->product)->name, optional($d->variant)->name),
                    'code' => optional($d->variant)->code ?? optional($d->product)->code,
                    'quantity' => number_format($d->quantity, helpers::price_decimals(), '.', ''),
                    'cost' => number_format($cost, helpers::price_decimals(), '.', ''),
                    'total' => number_format($cost * (float) $d->quantity, helpers::price_decimals(), '.', ''),
                ];
            });

        $statusColors = [
            'draft' => ['bg' => '#e5e7eb', 'color' => '#374151'],
            'ordered' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
            'partially_received' => ['bg' => '#fef3c7', 'color' => '#92400e'],
            'received' => ['bg' => '#d1fae5', 'color' => '#065f46'],
            'cancelled' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
        ];

        $logoSrc = null;
        if (! empty($settings->logo)) {
            $logoPath = public_path('images/'.$settings->logo);
            if (file_exists($logoPath) && is_readable($logoPath)) {
                $logoData = @file_get_contents($logoPath);
                if ($logoData !== false) {
                    $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                    $mime = $ext === 'svg' ? 'image/svg+xml' : ('image/'.($ext === 'jpg' ? 'jpeg' : $ext));
                    $logoSrc = 'data:'.$mime.';base64,'.base64_encode($logoData);
                }
            }
        }

        $html = view('pdf.po_pdf', [
            'symbol' => $docCurrency['code'],
            'logoSrc' => $logoSrc,
            'company' => [
                'name' => $settings->CompanyName,
                'phone' => $settings->CompanyPhone,
                'address' => $settings->CompanyAdress,
                'vat_number' => $settings->vat_number,
                'website' => $settings->website,
            ],
            'po' => [
                'Ref' => $po->Ref,
                'date' => $po->date,
                'expected_delivery_date' => $po->expected_delivery_date,
                'status' => $po->status,
                'supplier_name' => optional($po->provider)->name,
                'supplier_phone' => optional($po->provider)->phone,
                'supplier_email' => optional($po->provider)->email,
                'supplier_address' => optional($po->provider)->adresse,
                'warehouse_name' => optional($po->warehouse)->name,
                'TaxNet' => number_format($po->TaxNet * $docCurrency['rate'], helpers::price_decimals(), '.', ''),
                'discount' => number_format($po->discount * $docCurrency['rate'], helpers::price_decimals(), '.', ''),
                'shipping' => number_format($po->shipping * $docCurrency['rate'], helpers::price_decimals(), '.', ''),
                'GrandTotal' => number_format($po->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', ''),
                'notes' => $po->notes,
            ],
            'lines' => $lines,
            'statusStyle' => $statusColors[$po->status] ?? ['bg' => '#e5e7eb', 'color' => '#374151'],
        ])->render();

        return PDF::loadHTML($html)->download($po->Ref.'.pdf');
    }

    /**
     * Emails the PO PDF's download link to the supplier — same
     * link-in-body pattern PurchasesController::Send_Email already uses
     * for GRN receipts (see that method), not an attached binary.
     */
    public function sendEmail(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PurchaseOrder::class);

        $po = PurchaseOrder::with('provider')->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfWarehouseDenied($po->warehouse_id);
        $supplierEmail = optional($po->provider)->email;

        if (! $supplierEmail) {
            return response()->json(['success' => false, 'message' => 'This supplier has no email address on file.'], 422);
        }

        $settings = Setting::whereNull('deleted_at')->first();
        $pdfUrl = url('/api/purchase_orders/'.$po->id.'/pdf');
        $businessName = $settings->CompanyName;

        $body = "<p>Dear {$po->provider->name},</p>".
            "<p>Please find our Purchase Order <strong>{$po->Ref}</strong> attached via the link below.</p>".
            "<p><a href=\"{$pdfUrl}\">Download Purchase Order PDF</a></p>".
            '<p>Please confirm receipt and expected delivery date at your earliest convenience.</p>'.
            "<p>Thank you,<br>{$businessName}</p>";

        $email = [
            'subject' => "Purchase Order {$po->Ref} from {$businessName}",
            'body' => $body,
            'company_name' => $businessName,
        ];

        \Mail::to($supplierEmail)->send(new \App\Mail\CustomEmail($email));

        $po->sent_at = now();
        if ($po->status === 'draft') {
            $po->status = 'ordered';
        }
        $po->save();

        return response()->json(['success' => true, 'message' => 'Purchase Order emailed to supplier.']);
    }

    // ----------- Reports -------------

    /**
     * Price Variance Report — compares each GRN line's actual cost against
     * the cost that was agreed on its originating PO line, for every GRN
     * line that has a purchase_order_detail_id (i.e. was received against
     * a PO — direct/no-PO purchases have nothing to compare against and
     * are correctly absent from this report, not an oversight).
     *
     * A single join across purchase_details -> purchase_order_details ->
     * purchase_orders -> purchases -> providers -> products; no per-row
     * queries regardless of how many lines match.
     */
    public function priceVarianceReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseOrder::class);

        $allowedWarehouseIds = $this->allowedWarehouseIds();

        $query = DB::table('purchase_details as pd')
            ->join('purchase_order_details as pod', 'pod.id', '=', 'pd.purchase_order_detail_id')
            ->join('purchase_orders as po', 'po.id', '=', 'pod.purchase_order_id')
            ->join('purchases as p', 'p.id', '=', 'pd.purchase_id')
            ->join('providers as prov', 'prov.id', '=', 'p.provider_id')
            ->join('products as prod', 'prod.id', '=', 'pd.product_id')
            ->whereNull('p.deleted_at')
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('p.warehouse_id', $allowedWarehouseIds));

        if ($request->filled('provider_id')) {
            $query->where('p.provider_id', $request->provider_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('p.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('date_from')) {
            $query->where('p.date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('p.date', '<=', $request->date_to);
        }

        $rows = $query->select([
            'pd.id as line_id',
            'po.Ref as po_ref',
            'p.Ref as grn_ref',
            'p.date as grn_date',
            'prov.name as supplier_name',
            'prod.name as product_name',
            'pod.cost as po_cost',
            'pd.cost as grn_cost',
        ])->orderByDesc('p.date')->get();

        $data = $rows->map(function ($row) {
            $poCost = (float) $row->po_cost;
            $grnCost = (float) $row->grn_cost;
            $variance = $grnCost - $poCost;
            // A PO line agreed at cost 0 (data-entry edge case, not expected
            // in normal use) would make percentage undefined — reported as
            // null rather than a divide-by-zero/Inf value reaching the
            // frontend.
            $variancePercent = $poCost != 0.0 ? round(($variance / $poCost) * 100, 2) : null;

            return [
                'line_id' => $row->line_id,
                'po_ref' => $row->po_ref,
                'grn_ref' => $row->grn_ref,
                'grn_date' => $row->grn_date,
                'supplier_name' => $row->supplier_name,
                'product_name' => $row->product_name,
                'po_cost' => round($poCost, 2),
                'grn_cost' => round($grnCost, 2),
                'variance' => round($variance, 2),
                'variance_percent' => $variancePercent,
            ];
        });

        if ($request->filled('min_variance_percent')) {
            $threshold = (float) $request->min_variance_percent;
            $data = $data->filter(fn ($row) => $row['variance_percent'] !== null && abs($row['variance_percent']) >= $threshold)->values();
        }

        return response()->json([
            'rows' => $data,
            'totalRows' => $data->count(),
            'summary' => [
                'total_lines' => $data->count(),
                'total_variance' => round($data->sum('variance'), 2),
                'overcharged_count' => $data->filter(fn ($r) => $r['variance'] > 0)->count(),
            ],
        ]);
    }

    // ----------- Shared helpers -------------

    /** Same warehouse-restriction check used across Sales/Shipment/Products. */
    private function abortIfWarehouseDenied(?int $warehouseId): void
    {
        $user = Auth::user();
        if ($user->is_all_warehouses || ! $warehouseId) {
            return;
        }
        $allowed = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
        if (! in_array($warehouseId, $allowed, true)) {
            abort(403, 'This Purchase Order is outside your assigned warehouses.');
        }
    }
}
