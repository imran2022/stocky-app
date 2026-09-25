<?php

/**
 * Concurrency / batch-load stress test for SalesController::store() (2026-09-25).
 *
 * The business owner asked to simulate 200-300 simultaneous invoices and
 * check whether the app shows wrong numbers or loses data under real,
 * OS-level concurrency (not a sequential loop in one PHP process, which
 * would never hit an actual database race).
 *
 * This script is both the setup and the worker, selected by argv[1]:
 *
 *   php build_concurrency_stress.php setup
 *       Creates a dedicated test product + product_warehouse row (qte=500)
 *       so the run never touches real inventory data. Prints a JSON line
 *       with the ids the runner needs.
 *
 *   php build_concurrency_stress.php worker <workerId> <productId> \
 *       <warehouseId> <clientId> <userId> <creates> <qtyPerSale> <unitId>
 *       One parallel worker: boots its OWN full Laravel app/DB connection
 *       and creates <creates> completed sales sequentially via the real
 *       SalesController::store() code path, each selling <qtyPerSale> units
 *       of the shared product/warehouse. Launch many copies of this as
 *       separate OS processes (bash `&`) to create a genuine race on the
 *       same product_warehouse row. Prints one JSON line per attempt.
 *
 *   php build_concurrency_stress.php verify <productId> <warehouseId> <startingQte>
 *       Recomputes and checks all 5 invariants against the real DB state
 *       left behind by the worker runs (does NOT reset anything).
 *
 *   php build_concurrency_stress.php cleanup <productId> <warehouseId>
 *       Deletes the sales/details/product/stock row this test created, so
 *       the run leaves no trace in the real database.
 *
 * See run_concurrency_stress.sh for a ready-made orchestration (setup ->
 * N parallel workers -> verify -> cleanup).
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\SalesController;
use App\Models\Product;
use App\Models\product_warehouse as ProductWarehouse;
use App\Models\Warehouse;
use App\Models\User;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;

const NOTES_MARKER_PREFIX = 'Concurrency stress test worker';

function fail(string $msg): void
{
    fwrite(STDERR, $msg."\n");
    exit(1);
}

switch ($mode) {
    case 'setup':
        $warehouse = Warehouse::whereNull('deleted_at')->first();
        if (! $warehouse) {
            fail('No warehouse found — cannot run a real end-to-end sale test.');
        }

        $code = 'CONC-STRESS-'.time();
        $product = Product::create([
            'type' => 'is_single',
            'code' => $code,
            'Type_barcode' => $code,
            'name' => 'Concurrency Stress Test Product',
            'cost' => 10,
            'price' => 20,
            'wholesale_price' => 15,
            'min_price' => 10,
            'category_id' => 1,
            'unit_id' => 1,
            'unit_sale_id' => 1,
            'unit_purchase_id' => 1,
            'points' => 0,
        ]);

        $startingQte = 500;
        $pw = new ProductWarehouse();
        $pw->product_id = $product->id;
        $pw->warehouse_id = $warehouse->id;
        $pw->product_variant_id = null;
        $pw->qte = $startingQte;
        $pw->manage_stock = 1;
        $pw->save();

        $client = DB::table('clients')->whereNull('deleted_at')->orderBy('id')->first();
        $user = User::whereNull('deleted_at')->orderBy('id')->first();

        echo json_encode([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'client_id' => $client->id ?? null,
            'user_id' => $user->id ?? null,
            'unit_id' => $product->unit_sale_id,
            'starting_qte' => (float) $startingQte,
        ])."\n";
        break;

    case 'worker':
        [, , $workerId, $productId, $warehouseId, $clientId, $userId, $creates, $qtyPerSale, $unitId] = $argv;

        $productId = (int) $productId;
        $warehouseId = (int) $warehouseId;
        $clientId = (int) $clientId;
        $userId = (int) $userId;
        $creates = (int) $creates;
        $qtyPerSale = (float) $qtyPerSale;
        $unitId = (int) $unitId;

        $user = User::find($userId);
        if (! $user) {
            fail("worker {$workerId}: user {$userId} not found.");
        }
        Auth::guard('api')->setUser($user);
        Auth::login($user);

        for ($i = 0; $i < $creates; $i++) {
            $unitPrice = 20;
            $subtotal = $unitPrice * $qtyPerSale;

            $payload = [
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'date' => now()->toDateString(),
                'statut' => 'completed',
                'notes' => NOTES_MARKER_PREFIX." {$workerId} #{$i}",
                'tax_rate' => 0,
                'TaxNet' => 0,
                'discount' => 0,
                'discount_Method' => '2',
                'shipping' => 0,
                'GrandTotal' => $subtotal,
                'discount_from_points' => 0,
                'used_points' => 0,
                'details' => [[
                    'product_id' => $productId,
                    'quantity' => $qtyPerSale,
                    'Unit_price' => $unitPrice,
                    'tax_percent' => 0,
                    'tax_method' => '1',
                    'subtotal' => $subtotal,
                    'discount' => 0,
                    'discount_Method' => '2',
                    'product_variant_id' => null,
                    'serial_numbers' => [],
                    'sale_unit_id' => $unitId,
                ]],
                'payment' => ['status' => 'pending'],
                'amount' => 0,
            ];

            $req = Request::create('/api/sales', 'POST', $payload);
            $req->setUserResolver(fn ($guard = null) => $user);
            app()->instance('request', $req);

            $entry = ['worker' => $workerId, 'attempt' => $i];
            try {
                $controller = app(SalesController::class);
                $resp = $controller->store($req);
                $body = json_decode($resp->getContent(), true);
                $entry['http_status'] = method_exists($resp, 'getStatusCode') ? $resp->getStatusCode() : null;
                $entry['success'] = $body['success'] ?? null;
                $entry['sale_id'] = $body['sale_id'] ?? null;
                $entry['message'] = $body['message'] ?? null;
            } catch (\Throwable $e) {
                // A clean, expected rejection (e.g. StockGuard's
                // ValidationException) surfaces here because we call the
                // controller directly instead of through HTTP middleware —
                // over real HTTP this becomes a 422 JSON response instead.
                $entry['exception'] = get_class($e).': '.$e->getMessage();
            }

            echo json_encode($entry)."\n";
        }
        break;

    case 'verify':
        [, , $productId, $warehouseId, $startingQte] = $argv;
        $productId = (int) $productId;
        $warehouseId = (int) $warehouseId;
        $startingQte = (float) $startingQte;

        $endingQte = (float) (DB::table('product_warehouse')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->value('qte') ?? 0);

        $sales = DB::table('sales')->where('notes', 'like', NOTES_MARKER_PREFIX.'%')->get();
        $saleIds = $sales->pluck('id');

        $detailSum = (float) DB::table('sale_details')->whereIn('sale_id', $saleIds)->sum('quantity');
        $detailSaleCount = DB::table('sale_details')->whereIn('sale_id', $saleIds)->distinct('sale_id')->count('sale_id');

        $refCounts = DB::table('sales')->whereIn('id', $saleIds)
            ->select('Ref', DB::raw('count(*) as c'))->groupBy('Ref')->having('c', '>', 1)->get();

        $expectedEnding = $startingQte - $detailSum;

        $result = [
            'starting_qte' => $startingQte,
            'ending_qte' => $endingQte,
            'sum_sold_quantity' => $detailSum,
            'expected_ending_qte' => $expectedEnding,
            'stock_integrity_pass' => abs($endingQte - $expectedEnding) < 1e-6,
            'sales_created' => $saleIds->count(),
            'sales_with_details' => $detailSaleCount,
            'no_orphaned_sales' => $saleIds->count() === $detailSaleCount,
            'duplicate_refs' => $refCounts->toArray(),
            'no_duplicate_refs' => $refCounts->isEmpty(),
        ];

        echo json_encode($result, JSON_PRETTY_PRINT)."\n";
        break;

    case 'cleanup':
        [, , $productId, $warehouseId] = $argv;
        $productId = (int) $productId;
        $warehouseId = (int) $warehouseId;

        $saleIds = DB::table('sales')->where('notes', 'like', NOTES_MARKER_PREFIX.'%')->pluck('id');
        DB::table('sale_details')->whereIn('sale_id', $saleIds)->delete();
        DB::table('sales')->whereIn('id', $saleIds)->delete();
        DB::table('product_warehouse')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->delete();
        DB::table('products')->where('id', $productId)->delete();

        echo "Cleaned up {$saleIds->count()} test sales and the test product/stock row.\n";
        break;

    default:
        fail('Usage: php build_concurrency_stress.php {setup|worker|verify|cleanup} [args...]');
}
