<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BuildFStockLookupCleanupContractTest extends TestCase
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

    public function test_stock_lookup_uses_active_variants_and_actual_stock_unit(): void
    {
        $controller = $this->source('app/Http/Controllers/ProductsController.php');

        $this->assertStringContainsString("'unit:id,name,ShortName'", $controller);
        $this->assertStringContainsString("'unit_label' => \$unitLabel", $controller);
        $this->assertStringContainsString("\$vq->whereNull('deleted_at')", $controller);
        $this->assertStringContainsString("->whereIn('product_variant_id', \$activeVariantIds)", $controller);
    }

    public function test_variant_header_uses_active_variant_price_range(): void
    {
        $controller = $this->source('app/Http/Controllers/ProductsController.php');
        $page = $this->source('resources/src/pages/products/StockLookup.vue');

        $this->assertStringContainsString("'price_min'", $controller);
        $this->assertStringContainsString("'price_max'", $controller);
        $this->assertStringContainsString('function priceLabel(product)', $page);
        $this->assertStringNotContainsString('Pcs', $page);
    }
}
