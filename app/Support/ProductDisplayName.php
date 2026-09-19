<?php

namespace App\Support;

final class ProductDisplayName
{
    /**
     * Build the canonical user-facing product label.
     *
     * Product and variant names stay as separate database fields. This method
     * only controls how the pair is presented in lists, forms, documents,
     * reports, portals, and integrations.
     */
    public static function format(mixed $productName, mixed $variantName = null): string
    {
        $product = trim((string) ($productName ?? ''));
        $variant = trim((string) ($variantName ?? ''));

        if ($variant === '') {
            return $product;
        }

        if ($product === '') {
            return 'Variant: '.$variant;
        }

        return $product.' - Variant: '.$variant;
    }
}
