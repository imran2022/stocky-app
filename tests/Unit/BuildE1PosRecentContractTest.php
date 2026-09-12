<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BuildE1PosRecentContractTest extends TestCase
{
    public function test_pos_recent_invoices_scope_and_currency_contracts_are_present(): void
    {
        $root = dirname(__DIR__, 2);
        $sales = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
        $pos = file_get_contents($root.'/resources/src/pages/pos/PosPage.vue');

        $this->assertStringContainsString("authorizeForUser(\$request->user('api'), 'Sales_pos', Sale::class)", $sales);
        $this->assertStringContainsString("->where('is_pos', 1)", $sales);
        $this->assertStringContainsString("->where('statut', 'completed')", $sales);
        $this->assertStringContainsString('$viewRecords = $user->hasRecordView();', $sales);
        $this->assertStringContainsString("\$query->where('user_id', \$user->id);", $sales);
        $this->assertStringContainsString("\$query->whereIn('warehouse_id', \$allowedWarehouseIds);", $sales);
        $this->assertStringContainsString('helpers::Get_Document_Currency($sale)', $sales);
        $this->assertStringContainsString("'document_grand_total'", $sales);
        $this->assertStringContainsString("'currency_symbol'", $sales);
        $this->assertStringContainsString('formatPriceWithSymbol(s.currency_symbol, s.document_grand_total, 2)', $pos);
        $this->assertStringNotContainsString('formatPriceWithCurrentCurrency(s.GrandTotal, 2)', $pos);
    }
}
