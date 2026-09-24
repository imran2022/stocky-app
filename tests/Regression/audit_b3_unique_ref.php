<?php
// Audit Batch 3 (H6): a Ref collision inside a transaction is resolved by stepping past the taken value,
// even when the generator keeps returning the same stale "last Ref" (what a transaction snapshot does).
require __DIR__.'/_audit_lib.php';
use App\Support\UniqueRefGenerator;
use Illuminate\Support\Facades\DB;

check('increment keeps padding', UniqueRefGenerator::increment('SL_0099') === 'SL_0100', UniqueRefGenerator::increment('SL_0099'));
check('increment plain number', UniqueRefGenerator::increment('SL-9') === 'SL-10');
check('increment without number', UniqueRefGenerator::increment('ABC') === 'ABC_1');

[$c, $b, $s1] = mkSale([line(1, 1, 100)]);
$taken = DB::table('sales')->where('id', $s1)->value('Ref');
DB::table('sales')->where('id', $s1)->update(['Ref' => 'SL_500']);
$stale = fn () => 'SL_500';               // always the value that is already taken
DB::table('sales')->insert(['Ref' => 'SL_501', 'date' => date('Y-m-d'), 'client_id' => 2, 'warehouse_id' => 1, 'user_id' => 2, 'statut' => 'pending', 'GrandTotal' => 0, 'created_at' => now(), 'updated_at' => now()]);

$sale = new App\Models\Sale();
$sale->date = date('Y-m-d'); $sale->client_id = 2; $sale->warehouse_id = 1; $sale->user_id = 2; $sale->statut = 'pending'; $sale->GrandTotal = 0;
DB::beginTransaction();
$ok = true; $err = '';
try { UniqueRefGenerator::save($sale, $stale); } catch (Throwable $e) { $ok = false; $err = substr($e->getMessage(), 0, 120); }
DB::commit();
check('save survives a stale generator', $ok, $err);
check('new sale got the next free Ref (SL_502), skipping the taken SL_500 and SL_501', $ok && $sale->Ref === 'SL_502', $sale->Ref ?? '');
check('no duplicate Refs among live sales', DB::table('sales')->select('Ref')->whereNull('deleted_at')->groupBy('Ref')->havingRaw('COUNT(*)>1')->count() === 0);
finish('Audit B3 unique ref');
