<?php

namespace Tests\Unit;

use App\Support\SaleDocumentMath;
use PHPUnit\Framework\TestCase;

class SaleDocumentMathTest extends TestCase
{
    public function test_it_converts_money_using_the_document_rate(): void
    {
        $this->assertSame(125.0, SaleDocumentMath::convert(100, 1.25));
    }

    public function test_percent_discount_is_not_currency_converted(): void
    {
        $this->assertSame(10.0, SaleDocumentMath::discount(10, '1', 1.25));
    }

    public function test_fixed_discount_is_currency_converted(): void
    {
        $this->assertSame(12.5, SaleDocumentMath::discount(10, '2', 1.25));
    }

    public function test_cod_is_the_non_negative_outstanding_balance(): void
    {
        $this->assertSame(60.0, SaleDocumentMath::outstanding(100, 40));
        $this->assertSame(0.0, SaleDocumentMath::outstanding(100, 100));
        $this->assertSame(0.0, SaleDocumentMath::outstanding(100, 120));
    }
}
