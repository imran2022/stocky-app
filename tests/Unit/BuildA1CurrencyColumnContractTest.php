<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BuildA1CurrencyColumnContractTest extends TestCase
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

    public function test_sales_list_resolves_base_and_foreign_document_currency_code(): void
    {
        $controller = $this->source('app/Http/Controllers/SalesController.php');

        $this->assertStringContainsString('$documentCurrency = helpers::Get_Document_Currency($Sale);', $controller);
        $this->assertStringContainsString(
            '$item[\'currency_code\'] = strtoupper((string) ($documentCurrency[\'code\'] ?? \'\'));',
            $controller
        );
    }

    public function test_currency_column_is_optional_and_hidden_by_default(): void
    {
        $page = $this->source('resources/src/pages/sales/Sales.vue');

        $this->assertStringContainsString(
            "dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true",
            $page
        );
    }

    public function test_a1_bumps_pwa_cache_for_changed_sales_asset(): void
    {
        $serviceWorker = $this->source('public/sw.js');

        $this->assertMatchesRegularExpression(
            "/const VERSION = 'stocky-pwa-v(\d+)';/",
            $serviceWorker
        );
        preg_match("/const VERSION = 'stocky-pwa-v(\d+)';/", $serviceWorker, $m);
        $this->assertGreaterThanOrEqual(10, (int) $m[1]);
    }
}
