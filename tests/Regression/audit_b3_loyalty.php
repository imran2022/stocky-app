<?php
// Audit Batch 3 (S8): loyalty redemption is validated; points cannot be minted from nothing.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

DB::table('settings')->update(['point_to_amount_rate' => 1]);
try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
DB::table('clients')->where('id', 2)->update(['is_royalty_eligible' => 1, 'points' => 50]);
DB::table('clients')->where('id', 1)->update(['is_royalty_eligible' => 0, 'points' => 0]);

echo "== Redemption rules ==\n";
[$c, $b] = mkSale([line(1, 1, 100)], ['used_points' => 0, 'discount_from_points' => 20, 'GrandTotal' => 80]);
check('discount without used points rejected', $c == 422, "$c $b");
[$c, $b] = mkSale([line(1, 1, 100)], ['used_points' => 500, 'discount_from_points' => 500, 'GrandTotal' => 0]);
check('using more points than owned rejected', $c == 422, "$c $b");
check('rejected sales left balance alone', cl(2) == 50, cl(2));
[$c, $b] = mkSale([line(1, 1, 100)], ['client_id' => 1, 'used_points' => 10, 'discount_from_points' => 10, 'GrandTotal' => 90]);
check('non-eligible customer cannot use points', $c == 422, "$c $b");
[$c, $b] = mkSale([line(1, 1, 100)], ['used_points' => 10, 'discount_from_points' => 40, 'GrandTotal' => 60]);
check('discount worth more than used points rejected', $c == 422, "$c $b");

echo "== Valid redemption ==\n";
$before = cl(2);
[$c, $b, $sid] = mkSale([line(1, 1, 100)], ['used_points' => 20, 'discount_from_points' => 20, 'GrandTotal' => 80]);
$earned = (float) DB::table('sales')->where('id', $sid)->value('earned_points');
check('valid redemption accepted', $c == 200 && $sid, "$c $b");
check('points deducted (and earned added)', cl(2) == $before - 20 + $earned, cl(2));
[$c, $b] = updSale($sid, [line(1, 1, 100, ['id' => detIds($sid)[0], 'no_unit' => 1])], ['used_points' => 45, 'discount_from_points' => 45, 'GrandTotal' => 55]);
check('edit within held+balance accepted', $c == 200, "$c $b");
[$c, $b] = updSale($sid, [line(1, 1, 100, ['id' => detIds($sid)[0], 'no_unit' => 1])], ['used_points' => 500, 'discount_from_points' => 500, 'GrandTotal' => 0]);
check('edit beyond held+balance rejected', $c == 422, "$c $b");
[$c] = destroySale($sid);
check('delete restores exactly what was spent', $c == 200 && cl(2) == $before, cl(2).' vs '.$before);
finish('Audit B3 loyalty');
