<?php

namespace Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade-contract tests for Build D2 Product Analytics date/status semantics.
 */
class BuildD2ProductAnalyticsContractTest extends TestCase
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

    public function test_rolling_windows_are_exact_non_overlapping_thirty_day_periods(): void
    {
        $anchor = new DateTimeImmutable('2026-09-11 00:00:00');
        $currentStart = $anchor->modify('-29 days');
        $currentEndExclusive = $anchor->modify('+1 day');
        $previousStart = $anchor->modify('-59 days');
        $previousEndExclusive = $currentStart;

        $this->assertSame('2026-08-13', $currentStart->format('Y-m-d'));
        $this->assertSame('2026-09-12', $currentEndExclusive->format('Y-m-d'));
        $this->assertSame('2026-07-14', $previousStart->format('Y-m-d'));
        $this->assertSame('2026-08-13', $previousEndExclusive->format('Y-m-d'));
        $this->assertSame(30, (int) $currentStart->diff($currentEndExclusive)->format('%a'));
        $this->assertSame(30, (int) $previousStart->diff($previousEndExclusive)->format('%a'));
    }

    public function test_service_uses_shared_half_open_windows_and_received_returns(): void
    {
        $service = $this->source('app/Services/Custom/ProductInsightService.php');

        $this->assertStringContainsString('public function rolling30DayWindows(): array', $service);
        $this->assertStringContainsString('subDays(29)->toDateString()', $service);
        $this->assertStringContainsString('addDay()->toDateString()', $service);
        $this->assertStringContainsString('subDays(59)->toDateString()', $service);
        $this->assertStringContainsString('sd.date >= ? AND sd.date < ?', $service);
        $this->assertStringContainsString("->where('sr.statut', 'received')", $service);
        $this->assertStringContainsString('UnitQuantityResolver::baseQuantityExpression(', $service);
        $this->assertSame(4, preg_match_all('/->get\s*\(/', $service));
    }

    public function test_product_sort_uses_the_same_current_window(): void
    {
        $controller = $this->source('app/Http/Controllers/ProductsController.php');

        $this->assertStringContainsString('$salesWindows = $productInsightService->rolling30DayWindows();', $controller);
        $this->assertStringContainsString("->where('sale_details.date', '>=', \$salesWindows['current_start'])", $controller);
        $this->assertStringContainsString("->where('sale_details.date', '<', \$salesWindows['current_end_exclusive'])", $controller);
    }

    public function test_received_is_stockys_existing_stock_affecting_sale_return_status(): void
    {
        $returns = $this->source('app/Http/Controllers/SalesReturnController.php');

        $this->assertStringContainsString("if (\$order->statut == 'received')", $returns);
        $this->assertStringContainsString("if (\$current_SaleReturn->statut == 'received')", $returns);
    }
}
