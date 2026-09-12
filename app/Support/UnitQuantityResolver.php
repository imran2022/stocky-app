<?php

namespace App\Support;

/**
 * Converts a Sale/Sale-Return line's stored quantity into base-unit quantity,
 * for use in SUM aggregates (Product Insights: Sold 30d, Previous 30d,
 * Lifetime Sold, Lifetime Returned, Return Rate).
 *
 * G1 scope note: this resolver is intentionally limited to the Product
 * Insight columns listed above. It does not touch POS, invoice rendering,
 * stock movement/deduction, purchasing, or any other quantity display in the
 * app — those already have their own, separately-vetted conversion logic
 * (see PosController's stock-deduction blocks) which this does not replace.
 *
 * Conversion rule — matches the exact convention already used for stock
 * deduction elsewhere in this codebase (see PosController::store()/update()):
 *   base_quantity = line_quantity
 *                   × pack_multiplier            (Multi-Pack Selling; 1 if unset/0)
 *                   × unit_operator(sale_unit)    ('*' → × operator_value,
 *                                                   '/' → ÷ operator_value,
 *                                                   no unit or unrecognized → × 1)
 *
 * A business that only ever sells in the product's own base unit (no Units,
 * no Multi-Pack Selling in use) will see pack_multiplier and the unit
 * operator both resolve to an effective multiplier of 1 for every line, so
 * this produces the exact same totals as a plain SUM(quantity) — nothing
 * changes for that business. The moment either feature is used for a
 * product, the metrics correctly reflect base-unit quantity instead of raw
 * line count.
 */
class UnitQuantityResolver
{
    /**
     * Build the raw SQL expression that converts a quantity column into base
     * units. The query this is used in MUST left-join the `units` table
     * (aliased to $unitAlias) on the row's sale_unit_id — see
     * joinSaleUnit()/joinReturnUnit() below, or join it manually with the
     * same alias for a different table shape.
     *
     * NULLIF/COALESCE guards mean a pack_multiplier or operator_value of 0
     * or NULL is treated as "no conversion" (multiplier 1) rather than
     * causing a divide-by-zero or zeroing out the line.
     */
    public static function baseQuantityExpression(
        string $quantityColumn,
        string $packMultiplierColumn,
        string $unitAlias
    ): string {
        return sprintf(
            '(%s * COALESCE(NULLIF(%s, 0), 1) * CASE '.
            "WHEN %s.operator = '/' THEN 1.0 / COALESCE(NULLIF(%s.operator_value, 0), 1) ".
            "WHEN %s.operator = '*' THEN COALESCE(NULLIF(%s.operator_value, 0), 1) ".
            'ELSE 1 END)',
            $quantityColumn,
            $packMultiplierColumn,
            $unitAlias,
            $unitAlias,
            $unitAlias,
            $unitAlias
        );
    }

    /**
     * Left-join `units` (aliased) onto a sale_details-based query so
     * baseQuantityExpression() can reference the sale line's own unit.
     */
    public static function joinSaleUnit($query, string $saleDetailAlias, string $unitAlias)
    {
        return $query->leftJoin("units as {$unitAlias}", "{$unitAlias}.id", '=', "{$saleDetailAlias}.sale_unit_id");
    }

    /**
     * Left-join `units` (aliased) onto a sale_return_details-based query so
     * baseQuantityExpression() can reference the return line's own unit.
     */
    public static function joinReturnUnit($query, string $returnDetailAlias, string $unitAlias)
    {
        return $query->leftJoin("units as {$unitAlias}", "{$unitAlias}.id", '=', "{$returnDetailAlias}.sale_unit_id");
    }
}
