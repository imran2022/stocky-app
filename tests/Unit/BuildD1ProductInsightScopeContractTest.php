<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Upgrade-contract tests for Build D1 Product Insight visibility semantics.
 */
class BuildD1ProductInsightScopeContractTest extends TestCase
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

    public function test_insight_service_scopes_transaction_metrics_and_excludes_deleted_parents(): void
    {
        $service = $this->source('app/Services/Custom/ProductInsightService.php');

        $this->assertStringContainsString("->whereNull('s.deleted_at')", $service);
        $this->assertStringContainsString("'s.warehouse_id'", $service);
        $this->assertStringContainsString("->whereNull('sr.deleted_at')", $service);
        $this->assertStringContainsString("'sr.warehouse_id'", $service);
        $this->assertStringContainsString("->whereNull('latest_p.deleted_at')", $service);
        $this->assertStringContainsString("'latest_p.warehouse_id'", $service);
        $this->assertStringContainsString("->whereNull('candidate_p.deleted_at')", $service);
        $this->assertStringContainsString("'candidate_p.warehouse_id'", $service);
        $this->assertStringContainsString('private function applyWarehouseScope(', $service);
        $this->assertSame(4, preg_match_all('/->get\s*\(/', $service));
    }

    public function test_product_sorting_uses_the_same_sale_visibility_population(): void
    {
        $controller = $this->source('app/Http/Controllers/ProductsController.php');

        $this->assertStringContainsString("->whereNull('sales.deleted_at')", $controller);
        $this->assertStringContainsString("->where('sales.warehouse_id', \$warehouseId)", $controller);
        $this->assertStringContainsString("whereIntegerInRaw('sales.warehouse_id', \$allowedWarehouseIds)", $controller);
    }

    public function test_unit_and_multi_pack_normalization_uses_the_isolated_resolver(): void
    {
        $service = $this->source('app/Services/Custom/ProductInsightService.php');

        $this->assertStringContainsString('UnitQuantityResolver::baseQuantityExpression(', $service);
    }
}
