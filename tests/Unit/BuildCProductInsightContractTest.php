<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Upgrade-contract tests for Build C's Product Insights query extraction.
 */
class BuildCProductInsightContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    private function source(string $path): string
    {
        $contents = file_get_contents($this->root.'/'.$path);
        $this->assertNotFalse($contents, "Unable to read {$path}");

        return $contents;
    }

    public function test_controller_prefetches_custom_insights_once_and_maps_without_per_row_metric_queries(): void
    {
        $controller = $this->source('app/Http/Controllers/ProductsController.php');

        $this->assertStringContainsString('use App\\Services\\Custom\\ProductInsightService;', $controller);
        $this->assertStringContainsString('$productInsights = $productInsightService->forProducts(', $controller);

        $start = strpos($controller, '// ----- Extra business-insight fields (same for every product type) -----');
        $end = $start === false ? false : strpos($controller, '$data[] = $item;', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $block = substr($controller, $start, $end - $start);
        $this->assertStringNotContainsString('SaleDetail::join(', $block);
        $this->assertStringNotContainsString('PurchaseDetail::join(', $block);
        $this->assertStringNotContainsString('SaleReturnDetails::join(', $block);
        $this->assertStringNotContainsString('warehouseCountQuery', $block);
    }

    public function test_service_keeps_the_legacy_metric_contract_and_uses_set_based_sources(): void
    {
        $service = $this->source('app/Services/Custom/ProductInsightService.php');

        $this->assertSame(4, preg_match_all('/->get\s*\(/', $service));
        $this->assertStringContainsString("->where('s.statut', 'completed')", $service);
        $this->assertStringContainsString("->whereNull('sr.deleted_at')", $service);
        $this->assertStringContainsString("->where('latest_p.statut', 'received')", $service);
        $this->assertStringContainsString("selectRaw('MAX(latest_p.date) as last_purchase_date')", $service);
        $this->assertStringContainsString("selectRaw('MAX(candidate_pd.id) as last_purchase_detail_id')", $service);
        $this->assertStringContainsString('COUNT(DISTINCT pw.warehouse_id)', $service);
    }
}
