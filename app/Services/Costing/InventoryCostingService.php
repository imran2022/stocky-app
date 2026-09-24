<?php

namespace App\Services\Costing;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory costing orchestrator (Moving Weighted Average).
 *
 * The documents (purchases, sales, returns, adjustments, transfers, damages) stay the source of truth. This service
 * replays them per product through MovingAverageEngine and stores the result in the inventory_cost_* tables, so:
 *   - a sale line's COGS is its ledger row: master-cost edits can never move it;
 *   - stock value is qty x the running average, and can be asked "as of" any date;
 *   - editing/deleting/back-dating an older document re-costs only the affected product, and every changed sale or
 *     return cost is written to inventory_cost_corrections.
 *
 * Nothing here is called from the controllers that write documents: freshness is guaranteed by the readers
 * (see CostingReader) through four cheap checks: id high-water marks, per-window coverage, on-hand quantity
 * mismatch and a time-boxed fingerprint verification. Costing is OFF until an admin switches it on
 * (inventory_cost_meta.costing_method = 'moving_average'), so deploying this changes nothing by itself.
 */
class InventoryCostingService
{
    public const METHOD_LEGACY = 'legacy';
    public const METHOD_MOVING_AVERAGE = 'moving_average';

    /** detail tables whose new rows mean "new stock movements exist" */
    private const HW_TABLES = ['sale_details', 'sale_return_details', 'purchase_details', 'purchase_return_details',
        'adjustment_details', 'damage_details', 'transfer_details'];

    private static ?string $methodCache = null;

    // ---------------------------------------------------------------------------------------------------------------
    // settings
    // ---------------------------------------------------------------------------------------------------------------

    public static function tablesReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Schema::hasTable('inventory_cost_ledger') && Schema::hasTable('inventory_cost_meta');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    public static function method(): string
    {
        if (self::$methodCache !== null) {
            return self::$methodCache;
        }
        if (! self::tablesReady()) {
            return self::$methodCache = self::METHOD_LEGACY;
        }
        $m = self::meta('costing_method');

        return self::$methodCache = ($m === self::METHOD_MOVING_AVERAGE ? $m : self::METHOD_LEGACY);
    }

    public static function isActive(): bool
    {
        return self::method() === self::METHOD_MOVING_AVERAGE;
    }

    public static function setMethod(string $method): void
    {
        if (! in_array($method, [self::METHOD_LEGACY, self::METHOD_MOVING_AVERAGE], true)) {
            throw new \InvalidArgumentException('Unknown costing method: '.$method);
        }
        self::setMeta('costing_method', $method);
        self::$methodCache = $method;
    }

    public static function forgetMethodCache(): void
    {
        self::$methodCache = null;
    }

    public static function meta(string $key): ?string
    {
        $v = DB::table('inventory_cost_meta')->where('meta_key', $key)->value('meta_value');

        return $v === null ? null : (string) $v;
    }

    public static function setMeta(string $key, ?string $value): void
    {
        DB::table('inventory_cost_meta')->updateOrInsert(['meta_key' => $key], ['meta_value' => $value, 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // freshness
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Cheap, called by every reader. Picks up (1) documents created since the last sync (id high-water marks) and
     * (2) at most once per $ttl seconds, a full fingerprint verification (edits, deletes, status flips).
     */
    public static function ensureFresh(int $ttlSeconds = 30): void
    {
        if (! self::isActive()) {
            return;
        }
        $svc = new self;
        $svc->syncNewDocuments();

        $last = self::meta('last_verified_at');
        if ($last === null || Carbon::parse($last)->diffInSeconds(now(), false) >= $ttlSeconds) {
            $svc->verify(true);
        }
    }

    /** @return array<string,int> */
    public function currentHighWater(): array
    {
        $hw = [];
        foreach (self::HW_TABLES as $t) {
            $hw[$t] = (int) DB::table($t)->max('id');
        }

        return $hw;
    }

    /** Sync every product that has a document newer than the stored high-water mark. @return int products synced */
    public function syncNewDocuments(): int
    {
        $stored = json_decode((string) self::meta('hw'), true) ?: [];
        $now = $this->currentHighWater();
        if ($stored === []) {
            // never synced: everything is new
            $pids = $this->allProductIdsWithMovements();
        } else {
            $pids = [];
            foreach ($now as $t => $max) {
                if ($max > (int) ($stored[$t] ?? 0)) {
                    $pids = array_merge($pids, DB::table($t)->where('id', '>', (int) ($stored[$t] ?? 0))->distinct()->pluck('product_id')->all());
                }
            }
            $pids = array_values(array_unique(array_map('intval', $pids)));
        }
        if ($pids !== []) {
            $this->syncProducts($pids);
        }
        self::setMeta('hw', json_encode($now));

        return count($pids);
    }

    /**
     * Fingerprint verification: recompute every product's document fingerprint and re-cost the ones that changed.
     *
     * @return array{checked:int, dirty:int[]}
     */
    public function verify(bool $apply = true, ?array $onlyProducts = null): array
    {
        $current = $this->signatures($onlyProducts);
        $stored = [];
        foreach (DB::table('inventory_cost_keys')
            ->when($onlyProducts !== null, fn ($q) => $q->whereIn('product_id', $onlyProducts))
            ->get(['product_id', 'variant_key', 'signature']) as $r) {
            $stored[$r->product_id.':'.$r->variant_key] = $r->signature;
        }

        $dirty = [];
        foreach ($current as $k => $sig) {
            if (($stored[$k] ?? null) !== $sig) {
                $dirty[(int) explode(':', $k)[0]] = true;
            }
        }
        foreach ($stored as $k => $sig) {
            if (! isset($current[$k])) {
                $dirty[(int) explode(':', $k)[0]] = true;      // all of its documents are gone
            }
        }
        $dirty = array_keys($dirty);

        // on-hand quantity that the ledger does not explain
        foreach ($this->quantityMismatchProducts($onlyProducts) as $pid) {
            $dirty[] = $pid;
        }
        $dirty = array_values(array_unique($dirty));

        if ($apply) {
            if ($dirty !== []) {
                $this->syncProducts($dirty);
            }
            if ($onlyProducts === null) {
                self::setMeta('last_verified_at', now()->toDateTimeString());
            }
        }

        return ['checked' => count($current), 'dirty' => $dirty];
    }

    /** Products whose product_warehouse.qte differs from the costed balance. @return int[] */
    public function quantityMismatchProducts(?array $onlyProducts = null): array
    {
        $q = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->leftJoin('inventory_cost_balances as b', fn ($j) => $j->on('b.product_id', '=', 'pw.product_id')
                ->whereRaw('b.variant_key = IFNULL(pw.product_variant_id, 0)')->on('b.warehouse_id', '=', 'pw.warehouse_id'))
            ->whereNull('pw.deleted_at')->where('pw.manage_stock', 1)->where('p.type', '!=', 'is_service')
            ->whereRaw('ABS(pw.qte - IFNULL(b.qty, 0)) > 0.0005');
        if ($onlyProducts !== null) {
            $q->whereIn('pw.product_id', $onlyProducts);
        }

        return $q->distinct()->pluck('pw.product_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int[] */
    private function allProductIdsWithMovements(): array
    {
        $ids = [];
        foreach (self::HW_TABLES as $t) {
            $ids = array_merge($ids, DB::table($t)->distinct()->pluck('product_id')->all());
        }
        $ids = array_merge($ids, DB::table('product_warehouse')->whereNull('deleted_at')->where('qte', '!=', 0)->distinct()->pluck('product_id')->all());

        return array_values(array_unique(array_map('intval', $ids)));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // fingerprints
    // ---------------------------------------------------------------------------------------------------------------

    /** @return array<string,string> "productId:variantKey" => md5 */
    public function signatures(?array $pids = null): array
    {
        $in = $pids === null ? '' : ($pids === [] ? ' AND 1=0 ' : ' AND d.product_id IN ('.implode(',', array_map('intval', $pids)).') ');
        $crc = fn (string $fields) => "SUM(CRC32(CONCAT_WS('|', {$fields})))";
        $q = [
            'pu' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,d.cost,IFNULL(d.discount,0),IFNULL(d.discount_method,''),IFNULL(d.tax_method,''),IFNULL(d.TaxNet,0),IFNULL(d.purchase_unit_id,0),h.warehouse_id,h.date,IFNULL(h.time,'')")} s
                FROM purchase_details d JOIN purchases h ON h.id=d.purchase_id WHERE h.deleted_at IS NULL AND h.statut='received' {$in} GROUP BY d.product_id, vk",
            'pr' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,d.cost,IFNULL(d.discount,0),IFNULL(d.discount_method,''),IFNULL(d.tax_method,''),IFNULL(d.TaxNet,0),IFNULL(d.purchase_unit_id,0),h.warehouse_id,h.date,IFNULL(h.time,'')")} s
                FROM purchase_return_details d JOIN purchase_returns h ON h.id=d.purchase_return_id WHERE d.deleted_at IS NULL AND h.deleted_at IS NULL AND h.statut='completed' {$in} GROUP BY d.product_id, vk",
            'sa' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,IFNULL(d.sale_unit_id,0),IFNULL(d.pack_multiplier,1),h.warehouse_id,h.date,IFNULL(h.time,'')")} s
                FROM sale_details d JOIN sales h ON h.id=d.sale_id WHERE h.deleted_at IS NULL AND h.statut='completed' {$in} GROUP BY d.product_id, vk",
            'sr' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,IFNULL(d.sale_unit_id,0),IFNULL(d.pack_multiplier,1),IFNULL(h.sale_id,0),h.warehouse_id,h.date,IFNULL(h.time,'')")} s
                FROM sale_return_details d JOIN sale_returns h ON h.id=d.sale_return_id WHERE h.deleted_at IS NULL AND h.statut='received' {$in} GROUP BY d.product_id, vk",
            'tr' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,IFNULL(d.purchase_unit_id,0),h.from_warehouse_id,h.to_warehouse_id,h.statut,h.date,IFNULL(h.time,'')")} s
                FROM transfer_details d JOIN transfers h ON h.id=d.transfer_id WHERE h.deleted_at IS NULL AND (h.approval_status='approved' OR h.approval_status IS NULL) AND h.statut IN ('completed','sent') {$in} GROUP BY d.product_id, vk",
            'ad' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,d.type,h.warehouse_id,h.date,IFNULL(h.time,'')")} s
                FROM adjustment_details d JOIN adjustments h ON h.id=d.adjustment_id WHERE h.deleted_at IS NULL {$in} GROUP BY d.product_id, vk",
            'da' => "SELECT d.product_id, IFNULL(d.product_variant_id,0) vk, COUNT(*) c, {$crc("d.id,d.quantity,h.warehouse_id,h.date,IFNULL(h.time,'')")} s
                FROM damage_details d JOIN damages h ON h.id=d.damage_id WHERE h.deleted_at IS NULL {$in} GROUP BY d.product_id, vk",
        ];

        $parts = [];
        foreach ($q as $name => $sql) {
            foreach (DB::select($sql) as $r) {
                $parts[$r->product_id.':'.$r->vk][] = $name.'='.$r->c.'/'.$r->s;
            }
        }
        $out = [];
        foreach ($parts as $k => $list) {
            sort($list);
            $out[$k] = md5(implode(';', $list));
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // sync
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Replay and persist the given products.
     *
     * @param  int[]  $productIds
     * @return array{products:int, ledger_rows:int, seeds:int, stamps:int, corrections:int}
     */
    public function syncProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $stats = ['products' => 0, 'ledger_rows' => 0, 'seeds' => 0, 'stamps' => 0, 'corrections' => 0];
        if ($productIds === []) {
            return $stats;
        }

        $lock = DB::selectOne("SELECT GET_LOCK('inventory_costing_sync', 60) AS l");
        if (! $lock || (int) $lock->l !== 1) {
            return $stats;                      // another process is syncing; its result is what readers will see
        }

        try {
            foreach (array_chunk($productIds, 200) as $chunk) {
                $sigs = $this->signatures($chunk);
                $movementsByKey = MovementSource::load($chunk);
                $master = $this->masterCosts($chunk);

                foreach ($chunk as $pid) {
                    $r = $this->syncOne($pid, $movementsByKey, $master, $sigs);
                    $stats['products']++;
                    foreach (['ledger_rows', 'seeds', 'stamps', 'corrections'] as $k) {
                        $stats[$k] += $r[$k];
                    }
                }
            }
        } finally {
            DB::select("SELECT RELEASE_LOCK('inventory_costing_sync')");
        }

        return $stats;
    }

    /** @return array<string,float> "productId:variantKey" => master cost (variant cost wins) */
    private function masterCosts(array $pids): array
    {
        $out = [];
        foreach (DB::table('products')->whereIn('id', $pids)->get(['id', 'cost']) as $p) {
            $out[$p->id.':0'] = (float) $p->cost;
        }
        foreach (DB::table('product_variants')->whereIn('product_id', $pids)->get(['id', 'product_id', 'cost']) as $v) {
            $out[$v->product_id.':'.$v->id] = (float) $v->cost;
        }

        return $out;
    }

    private function syncOne(int $pid, array $movementsByKey, array $master, array $sigs): array
    {
        $res = ['ledger_rows' => 0, 'seeds' => 0, 'stamps' => 0, 'corrections' => 0];

        $actual = [];      // "pid:vk" => [warehouse => qte]
        foreach (DB::table('product_warehouse')->where('product_id', $pid)->whereNull('deleted_at')->where('manage_stock', 1)
            ->get(['warehouse_id', 'product_variant_id', 'qte', 'created_at']) as $pw) {
            $actual[$pid.':'.(int) $pw->product_variant_id][(int) $pw->warehouse_id] = ['qte' => (float) $pw->qte, 'created' => $pw->created_at];
        }
        $existingKeys = DB::table('inventory_cost_balances')->where('product_id', $pid)->pluck('variant_key')->all();
        $keys = [];
        foreach ($movementsByKey as $k => $_) {
            if (str_starts_with($k, $pid.':')) {
                $keys[$k] = true;
            }
        }
        foreach (array_keys($actual) as $k) {
            $keys[$k] = true;
        }
        foreach ($existingKeys as $vk) {
            $keys[$pid.':'.$vk] = true;
        }

        $oldLines = DB::table('inventory_cost_ledger')->where('product_id', $pid)->whereIn('source_type', ['sale', 'sale_return'])
            ->get(['source_type', 'source_id', 'warehouse_id', 'variant_key', 'value_delta'])
            ->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);

        $newLedger = [];
        $newBalances = [];
        $newLines = [];

        foreach (array_keys($keys) as $k) {
            [, $vk] = explode(':', $k);
            $vk = (int) $vk;
            $mvs = $movementsByKey[$k] ?? [];
            $fallbackCost = $master[$k] ?? ($master[$pid.':0'] ?? 0.0);

            // write-once cost stamps for adjustments that add stock but carry no cost
            foreach ($mvs as $i => $m) {
                if (! empty($m['needs_stamp'])) {
                    DB::table('inventory_cost_stamps')->insertOrIgnore(['source_type' => 'adjustment', 'source_id' => $m['source_id'],
                        'unit_cost' => $fallbackCost, 'basis' => 'master_cost', 'created_at' => now(), 'updated_at' => now()]);
                    $mvs[$i]['unit_cost'] = $fallbackCost;
                    $mvs[$i]['estimated'] = true;
                    unset($mvs[$i]['needs_stamp']);
                    $res['stamps']++;
                }
            }

            $out = MovingAverageEngine::replay($mvs);

            // stock that exists on the shelf but that no document explains -> write-once seeds, then re-replay
            $seeded = false;
            $wids = array_unique(array_merge(array_keys($out['balances']), array_keys($actual[$k] ?? [])));
            foreach ($wids as $wid) {
                $want = $actual[$k][$wid]['qte'] ?? null;
                if ($want === null) {
                    continue;                       // no managed stock row: nothing to reconcile against
                }
                $have = $out['balances'][$wid]['qty'] ?? 0.0;
                $diff = round($want - $have, 4);
                if (abs($diff) < 0.0005) {
                    continue;
                }
                $hasSeed = DB::table('inventory_cost_seeds')->where('product_id', $pid)->where('variant_key', $vk)->where('warehouse_id', $wid)->exists();
                if ($diff > 0) {
                    // first-ever unexplained stock (opening / imported) is valued at the master cost, exactly as the opening
                    // adjustment is; later unexplained increases are valued at the running average
                    $avg = $out['balances'][$wid]['avg_cost'] ?? 0.0;
                    $cost = (! $hasSeed && $fallbackCost > 0) ? $fallbackCost : ($avg > 0 ? $avg : $fallbackCost);
                    if (! $hasSeed && $mvs !== []) {
                        $at = Carbon::parse(min(array_column($mvs, 'occurred_at')))->subSecond()->toDateTimeString();
                    } elseif (! $hasSeed && ! empty($actual[$k][$wid]['created'])) {
                        $at = Carbon::parse($actual[$k][$wid]['created'])->toDateTimeString();
                    } else {
                        $at = now()->toDateTimeString();
                    }
                    $reason = $hasSeed ? 'unexplained_increase' : 'opening_unexplained';
                } else {
                    $cost = null;
                    $at = now()->toDateTimeString();
                    $reason = 'unexplained_decrease';
                }
                DB::table('inventory_cost_seeds')->insert(['product_id' => $pid, 'variant_key' => $vk, 'product_variant_id' => $vk ?: null,
                    'warehouse_id' => $wid, 'occurred_at' => $at, 'qty_delta' => $diff, 'unit_cost' => $cost, 'reason' => $reason,
                    'created_at' => now(), 'updated_at' => now()]);
                $res['seeds']++;
                $seeded = true;
            }
            if ($seeded) {
                $mvs = MovementSource::load([$pid])[$k] ?? [];
                foreach ($mvs as $i => $m) {          // stamps written above are picked up by the reload
                    if (! empty($m['needs_stamp'])) {
                        $mvs[$i]['unit_cost'] = $fallbackCost;
                        $mvs[$i]['estimated'] = true;
                    }
                }
                $out = MovingAverageEngine::replay($mvs);
            }

            foreach ($out['rows'] as $row) {
                $newLedger[] = [
                    'product_id' => $pid, 'variant_key' => $vk, 'product_variant_id' => $vk ?: null,
                    'warehouse_id' => $row['warehouse_id'], 'occurred_at' => $row['occurred_at'], 'source_type' => $row['source_type'],
                    'source_id' => $row['source_id'], 'reference_id' => $row['reference_id'],
                    'qty_delta' => round($row['qty_delta'], 4), 'unit_cost' => round($row['unit_cost'], 8), 'value_delta' => round($row['value_delta'], 4),
                    'balance_qty' => round($row['balance_qty'], 4), 'balance_value' => round($row['balance_value'], 4),
                    'avg_cost' => round($row['avg_cost'], 8), 'is_estimated' => $row['is_estimated'] ? 1 : 0,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                if (in_array($row['source_type'], ['sale', 'sale_return'], true)) {
                    $newLines[$row['source_type'].':'.$row['source_id']] = [$row['warehouse_id'], $vk, round($row['value_delta'], 4)];
                }
            }
            foreach ($out['balances'] as $wid => $b) {
                $newBalances[] = ['product_id' => $pid, 'variant_key' => $vk, 'product_variant_id' => $vk ?: null, 'warehouse_id' => $wid,
                    'qty' => round($b['qty'], 4), 'value' => round($b['value'], 4), 'avg_cost' => round($b['avg_cost'], 8),
                    'is_estimated' => $b['is_estimated'] ? 1 : 0, 'created_at' => now(), 'updated_at' => now()];
            }
        }

        DB::transaction(function () use ($pid, $newLedger, $newBalances, $newLines, $oldLines, $sigs, $keys, &$res) {
            DB::table('inventory_cost_ledger')->where('product_id', $pid)->delete();
            DB::table('inventory_cost_balances')->where('product_id', $pid)->delete();
            foreach (array_chunk($newLedger, 500) as $c) {
                DB::table('inventory_cost_ledger')->insert($c);
            }
            foreach (array_chunk($newBalances, 500) as $c) {
                DB::table('inventory_cost_balances')->insert($c);
            }
            $res['ledger_rows'] = count($newLedger);

            foreach ($newLines as $k => [$wid, $vk, $val]) {
                $old = $oldLines[$k] ?? null;
                if ($old !== null && abs((float) $old->value_delta - $val) > 0.005) {
                    [$type, $sid] = explode(':', $k);
                    DB::table('inventory_cost_corrections')->insert(['source_type' => $type, 'source_id' => (int) $sid, 'product_id' => $pid,
                        'variant_key' => $vk, 'warehouse_id' => $wid, 'old_value' => $old->value_delta, 'new_value' => $val, 'created_at' => now()]);
                    $res['corrections']++;
                }
            }

            DB::table('inventory_cost_keys')->where('product_id', $pid)->delete();
            $rows = [];
            foreach (array_keys($keys) as $k) {
                if (isset($sigs[$k])) {
                    [, $vk] = explode(':', $k);
                    $rows[] = ['product_id' => $pid, 'variant_key' => (int) $vk, 'signature' => $sigs[$k], 'synced_at' => now()];
                }
            }
            if ($rows !== []) {
                DB::table('inventory_cost_keys')->insert($rows);
            }
        });

        return $res;
    }
}
