<?php

namespace Tests\Unit;

use App\Support\SaleTotalsGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SaleTotalsGuardTest extends TestCase
{
    public function test_it_accepts_sale_total_with_percentage_discount_order_tax_and_shipping(): void
    {
        // Screenshot regression: 2,997 - 299.70 + 269.73 + 10 = 2,977.03.
        SaleTotalsGuard::checkGrandTotal(2997, 10, 10, '1', 2977.03, 269.73);

        $this->addToAssertionCount(1);
    }

    public function test_it_accepts_loyalty_discount_before_order_tax(): void
    {
        // 1,000 - 10% - 50 points + 85 tax + 20 shipping = 955.
        SaleTotalsGuard::checkGrandTotal(1000, 20, 10, '1', 955, 85, 50);

        $this->addToAssertionCount(1);
    }

    public function test_it_still_rejects_a_tampered_grand_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SaleTotalsGuard::checkGrandTotal(2997, 10, 10, '1', 999999, 269.73);
    }
}
