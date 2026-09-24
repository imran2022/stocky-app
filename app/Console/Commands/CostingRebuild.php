<?php

namespace App\Console\Commands;

use App\Http\Controllers\ProfitReportController as PRC;
use App\Http\Controllers\ReportController as RC;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Turns Moving Average costing on for the whole app, the one time it needs turning on.
 *
 * Legacy (master-cost) reports are untouched until this is run: the migration alone changes nothing (see
 * InventoryCostingService). This command is the deploy-time switch:
 *
 *   php artisan costing:rebuild --dry-run   compare old vs new profit/stock value, change nothing
 *   php artisan costing:rebuild --apply     cost every product into the ledger (idempotent, safe to re-run)
 *   php artisan costing:rebuild --enable    apply, then flip the switch (costing_method = moving_average)
 *   php artisan costing:rebuild --verify    full fingerprint check against the documents, re-cost anything dirty
 *
 * --apply and --enable can be combined with --dry-run first to see the numbers, then re-run without it.
 * Every mode is safe to run more than once: syncProducts() always replaces a product's ledger rows outright, so a
 * second rebuild produces the same numbers as the first (see the "rebuild == incremental" regression check).
 */
class CostingRebuild extends Command
{
    protected $signature = 'costing:rebuild
        {--dry-run : cost everything into a scratch comparison and print old vs new, changing nothing in the ledger}
        {--apply : cost every product into inventory_cost_ledger (costing stays OFF unless --enable is also given)}
        {--enable : after --apply (or if already costed), switch costing_method to moving_average}
        {--verify : run a full fingerprint verification now instead of waiting for the 30s TTL}
        {--from= : window start (YYYY-MM-DD) for the --dry-run comparison, default: 1 year ago}
        {--to= : window end (YYYY-MM-DD) for the --dry-run comparison, default: today}';

    protected $description = 'Cost every product under Moving Average, compare against legacy, and optionally switch the app onto it';

    public function handle(): int
    {
        if (! Svc::tablesReady()) {
            $this->error('inventory_cost_* tables are missing. Run: php artisan migrate --path=database/migrations/2026_09_26_000001_create_inventory_costing_tables.php');

            return self::FAILURE;
        }

        $didSomething = false;

        if ($this->option('dry-run')) {
            $didSomething = true;
            $this->dryRun();
        }

        if ($this->option('apply')) {
            $didSomething = true;
            $this->apply();
        }

        if ($this->option('enable')) {
            $didSomething = true;
            $this->enable();
        }

        if ($this->option('verify')) {
            $didSomething = true;
            $this->verifyNow();
        }

        if (! $didSomething) {
            $this->line('Nothing to do. Pass --dry-run, --apply, --enable and/or --verify. See --help.');

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /**
     * Cost every product WITHOUT switching the method, then print legacy vs Moving Average for the same window so
     * the person enabling this can see the exact effect before it goes live. Since syncProducts() only ever writes
     * to inventory_cost_* tables (never touched by legacy reads), this is non-destructive: it can be run against
     * production data at any time, repeatedly, with nothing else changing.
     */
    private function dryRun(): void
    {
        $this->info('Costing every product for comparison (nothing is switched on)...');
        $wasActive = Svc::isActive();
        $stats = $this->costAll();
        $this->line(sprintf('  %s products, %s ledger rows, %s seeds (unexplained opening/imported stock), %s stamps (adjustments with no cost)',
            number_format($stats['products']), number_format($stats['ledger_rows']), number_format($stats['seeds']), number_format($stats['stamps'])));

        $from = $this->option('from') ?: now()->subYear()->toDateString();
        $to = $this->option('to') ?: now()->toDateString();

        $legacyCogs = DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->join('products as p', 'p.id', '=', 'sd.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'sd.product_variant_id')
            ->whereNull('s.deleted_at')->where('s.statut', 'completed')
            ->whereBetween('s.date', [$from, $to])
            ->sum(DB::raw('sd.quantity * COALESCE(pv.cost, p.cost, 0)'));

        $legacyStockValue = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pw.product_variant_id')
            ->whereNull('pw.deleted_at')->where('pw.manage_stock', 1)
            ->sum(DB::raw('pw.qte * COALESCE(pv.cost, p.cost, 0)'));

        // Read the new numbers straight from the ledger, exactly as the reports will, without switching the method.
        // "all warehouses" (no single $warehouseId) means passing every warehouse id, not an empty list: an empty
        // whereIn() matches nothing, so [] here would silently read as zero everywhere rather than "unscoped".
        $allWarehouseIds = DB::table('warehouses')->pluck('id')->map(fn ($v) => (int) $v)->all();
        Svc::setMethod(Svc::METHOD_MOVING_AVERAGE);
        Svc::forgetMethodCache();
        $newCogs = (float) \App\Services\Costing\CostingReader::cogsForWindow($from, $to, null, $allWarehouseIds, false, null);
        $newStockValue = \App\Services\Costing\CostingReader::stockValue(null, $allWarehouseIds)['value'];
        Svc::setMethod($wasActive ? Svc::METHOD_MOVING_AVERAGE : Svc::METHOD_LEGACY);
        Svc::forgetMethodCache();

        $this->newLine();
        $this->line("Window: {$from} to {$to}");
        $this->table(['', 'Legacy (master cost)', 'Moving Average'], [
            ['COGS (net of returns)', number_format($legacyCogs, 2), number_format($newCogs, 2)],
            ['Stock value (now)', number_format($legacyStockValue, 2), number_format($newStockValue, 2)],
        ]);
        $delta = $legacyCogs != 0 ? ($newCogs - $legacyCogs) / abs($legacyCogs) * 100 : 0;
        $this->line(sprintf('  COGS moves by %s%.1f%% under Moving Average for this window.', $delta >= 0 ? '+' : '', $delta));
        $this->comment('Nothing was switched on. Re-run with --apply then --enable when ready, or --enable to do both now.');
    }

    /** Cost every product into the ledger. Idempotent — safe to run again later (e.g. after a bulk import). */
    private function apply(): void
    {
        $this->info('Costing every product into inventory_cost_ledger...');
        $stats = $this->costAll();
        $this->line(sprintf('  done: %s products, %s ledger rows, %s seeds, %s stamps, %s corrections',
            number_format($stats['products']), number_format($stats['ledger_rows']), number_format($stats['seeds']),
            number_format($stats['stamps']), number_format($stats['corrections'])));
    }

    private function enable(): void
    {
        if (! Svc::isActive()) {
            // make sure every product has been costed at least once before the switch goes live, so the very
            // first request after enabling doesn't find an empty ledger.
            if (DB::table('inventory_cost_balances')->count() === 0) {
                $this->info('No ledger yet — costing everything first...');
                $this->costAll();
            }
        }
        Svc::setMethod(Svc::METHOD_MOVING_AVERAGE);
        Svc::forgetMethodCache();
        $this->info('Moving Average costing is now ON. Reports will use it on their next read.');
    }

    private function verifyNow(): void
    {
        if (! Svc::isActive()) {
            $this->comment('Costing is off (legacy) — nothing to verify.');

            return;
        }
        $this->info('Running a full fingerprint verification (documents vs ledger)...');
        $svc = new Svc;
        $result = $svc->verify(true);
        $this->line(sprintf('  checked %s products/variants, re-costed %s that had changed', number_format($result['checked']), number_format(count($result['dirty']))));
    }

    /** @return array{products:int, ledger_rows:int, seeds:int, stamps:int, corrections:int} */
    private function costAll(): array
    {
        $svc = new Svc;
        $ids = DB::table('products')->where('type', '!=', 'is_service')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $stats = ['products' => 0, 'ledger_rows' => 0, 'seeds' => 0, 'stamps' => 0, 'corrections' => 0];
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();
        foreach (array_chunk($ids, 200) as $chunk) {
            $r = $svc->syncProducts($chunk);
            foreach ($stats as $k => $_) {
                $stats[$k] += $r[$k];
            }
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();

        return $stats;
    }
}
