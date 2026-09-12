<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BuildE3PosSalesParityContractTest extends TestCase
{
    public function test_sales_and_pos_sales_share_58_seller_currency_contract_without_merging_workflows(): void
    {
        $root = dirname(__DIR__, 2);
        $sales = file_get_contents($root.'/resources/src/pages/sales/Sales.vue');
        $posSales = file_get_contents($root.'/resources/src/pages/sales/PosSales.vue');

        $this->assertStringContainsString("{ title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' }", $sales);
        $this->assertStringContainsString("{ title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' }", $posSales);
        $this->assertStringContainsString("dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true", $sales);
        $this->assertStringContainsString("dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true", $posSales);
        $this->assertStringContainsString("column.key === 'currency_code'", $posSales);
        $this->assertStringContainsString('is_pos: 1', $posSales);
    }
}
