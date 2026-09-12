<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BuildA1CurrencyBackendContractTest extends TestCase
{
    public function test_sales_list_resolves_base_and_foreign_document_currency_code(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
        $this->assertNotFalse($controller);
        $this->assertStringContainsString('$documentCurrency = helpers::Get_Document_Currency($Sale);', $controller);
        $this->assertStringContainsString(
            '$item[\'currency_code\'] = strtoupper((string) ($documentCurrency[\'code\'] ?? \'\'));',
            $controller
        );
    }
}
