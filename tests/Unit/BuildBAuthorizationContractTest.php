<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Upgrade-contract tests for Build B's narrow authorization hardening.
 * Database-backed feature tests should complement these contracts in a fully
 * bootstrapped Laravel test environment.
 */
class BuildBAuthorizationContractTest extends TestCase
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

    public function test_bulk_sale_update_reapplies_record_and_warehouse_scope(): void
    {
        $controller = $this->source('app/Http/Controllers/SalesController.php');

        $this->assertStringContainsString("\$salesToUpdate = Sale::whereIn('id', \$ids)->whereNull('deleted_at');", $controller);
        $this->assertStringContainsString('if (! $user->hasRecordView())', $controller);
        $this->assertStringContainsString("\$salesToUpdate->where('user_id', \$user->id);", $controller);
        $this->assertStringContainsString("\$salesToUpdate->whereIn('warehouse_id', \$allowedWarehouseIds);", $controller);
        $this->assertStringNotContainsString("Sale::whereIn('id', \$ids)->whereNull('deleted_at')->update(\$payload)", $controller);
    }

    public function test_shipment_routes_scope_through_visible_sale_and_block_reassignment(): void
    {
        $controller = $this->source('app/Http/Controllers/ShipmentController.php');

        $this->assertStringContainsString('private function visibleSalesQuery($user)', $controller);
        $this->assertStringContainsString('private function visibleShipmentsQuery($user)', $controller);
        $this->assertStringContainsString("\$this->authorizeForUser(\$request->user('api'), 'view', Shipment::class);", $controller);
        $this->assertStringContainsString('Shipment reference already belongs to another sale.', $controller);
        $this->assertStringContainsString('Shipment cannot be reassigned to another sale.', $controller);
        $this->assertStringContainsString('$status_counts = $this->visibleShipmentsQuery($user)', $controller);
    }
}
