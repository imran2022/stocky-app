<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BuildE2MetadataValidationContractTest extends TestCase
{
    public function test_sale_metadata_validation_and_option_b_permission_contracts_are_present(): void
    {
        $root = dirname(__DIR__, 2);
        $rules = file_get_contents($root.'/app/Support/SaleMetadataRules.php');
        $sales = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
        $shipments = file_get_contents($root.'/app/Http/Controllers/ShipmentController.php');
        $meta = file_get_contents($root.'/app/Http/Controllers/SaleMetaController.php');

        $this->assertStringContainsString("Rule::exists('sale_zones', 'id')->whereNull('deleted_at')", $rules);
        $this->assertStringContainsString("Rule::exists('sale_couriers', 'id')->whereNull('deleted_at')", $rules);
        $this->assertStringContainsString("'details.*.box_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999.99']", $rules);
        $this->assertStringContainsString('public const MAX_BULK_SALES = 1000;', $rules);
        $this->assertSame(2, substr_count($sales, 'SaleMetadataRules::saleFields()'));
        $this->assertStringContainsString('$request->validate(SaleMetadataRules::bulkFields());', $sales);
        $this->assertSame(2, substr_count($shipments, 'SaleMetadataRules::shipmentFields()'));
        $this->assertStringContainsString("\$user->can('Sales_pos', Sale::class)", $meta);
        $this->assertStringContainsString("\$user->can('create', Shipment::class)", $meta);
        $this->assertStringContainsString("whereRaw('LOWER(TRIM(name)) = LOWER(?)'", $meta);
        $this->assertStringContainsString('catch (QueryException $e)', $meta);
        $this->assertStringNotContainsString('->delete()', $meta);
    }
}
