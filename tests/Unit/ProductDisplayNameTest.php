<?php

namespace Tests\Unit;

use App\Support\ProductDisplayName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductDisplayNameTest extends TestCase
{
    public static function labels(): array
    {
        return [
            'variant product' => ['Apple Macbook 2026', 'Grey', 'Apple Macbook 2026 - Variant: Grey'],
            'simple product' => ['Apple Macbook 2026', null, 'Apple Macbook 2026'],
            'blank variant' => ['Apple Macbook 2026', '   ', 'Apple Macbook 2026'],
            'multi-option variant' => ['T-Shirt', 'Blue / XL', 'T-Shirt - Variant: Blue / XL'],
            'trimmed input' => ['  Phone  ', '  256 GB  ', 'Phone - Variant: 256 GB'],
            'missing product fallback' => [null, 'Grey', 'Variant: Grey'],
            'empty input' => [null, null, ''],
        ];
    }

    #[DataProvider('labels')]
    public function test_it_builds_the_canonical_display_label(mixed $product, mixed $variant, string $expected): void
    {
        $this->assertSame($expected, ProductDisplayName::format($product, $variant));
    }
}
