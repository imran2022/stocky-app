<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Upgrade-contract tests for the six low-risk Stocky 5.8 Build A fixes.
 *
 * These are intentionally source-level guards. Their job is to catch a future
 * vendor merge silently reintroducing an invalid SQL sorter or dropping a
 * custom integration point before heavier database/E2E tests run.
 */
class BuildAIntegrationContractTest extends TestCase
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

    public function test_bulk_invoice_keeps_document_currency_integration(): void
    {
        $sales = $this->source('app/Http/Controllers/SalesController.php');

        $this->assertStringContainsString('$docCurrency = helpers::Get_Document_Currency($sale_data);', $sales);
        $this->assertStringContainsString('$symbol = $docCurrency[\'code\'];', $sales);
        $this->assertStringContainsString('SaleDocumentMath::discount(', $sales);
    }

    public function test_shipping_label_uses_outstanding_cod_not_grand_total(): void
    {
        $sales = $this->source('app/Http/Controllers/SalesController.php');
        $label = $this->source('resources/views/pdf/shipping_label.blade.php');

        $this->assertStringContainsString(
            'SaleDocumentMath::outstanding($sale_data->GrandTotal, $sale_data->paid_amount)',
            $sales
        );
        $this->assertStringContainsString("{{ \$sale['cod_amount'] }}", $label);
        $this->assertStringNotContainsString("Cash on Delivery: {{ \$symbol }}{{ \$sale['GrandTotal'] }}", $label);
    }

    public function test_product_computed_insights_never_reach_sql_order_by(): void
    {
        $controller = $this->source('app/Http/Controllers/ProductsController.php');

        $this->assertStringContainsString('$sortableFields = [', $controller);
        $this->assertStringContainsString("elseif (\$order === 'total_sold_30d')", $controller);
        $this->assertStringContainsString("elseif (\$order === 'last_sold_date')", $controller);
        $this->assertStringContainsString('sold30BaseQty = \\App\\Support\\UnitQuantityResolver::baseQuantityExpression(', $controller);
        $this->assertStringContainsString('MAX(sale_details.date)', $controller);
    }

    public function test_shipment_computed_fields_never_reach_sql_order_by(): void
    {
        $controller = $this->source('app/Http/Controllers/ShipmentController.php');

        $this->assertStringContainsString('$sortableFields = [', $controller);
        foreach (['shipment_ref', 'sale_ref', 'customer_name', 'warehouse_name'] as $field) {
            $this->assertStringContainsString("\$order === '{$field}'", $controller);
        }
    }

    public function test_shipment_create_persists_phone_number(): void
    {
        $controller = $this->source('app/Http/Controllers/ShipmentController.php');

        $this->assertStringContainsString('$shipment->phone_number = $request[\'phone_number\'] ?? null;', $controller);
    }

    public function test_sale_update_preserves_omitted_shipping_metadata(): void
    {
        $sales = $this->source('app/Http/Controllers/SalesController.php');

        foreach (['tracking_ref', 'consignment_id', 'zone_id', 'courier_id'] as $field) {
            $this->assertStringContainsString("\$request->has('{$field}')", $sales);
            $this->assertStringContainsString('$current_Sale->'.$field, $sales);
        }
    }
}
