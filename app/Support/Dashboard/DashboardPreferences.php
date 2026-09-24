<?php

namespace App\Support\Dashboard;

use App\Models\DashboardPreference;

/**
 * Modern Dashboard: which dashboard a user sees (Classic / Modern) and how the Modern sections are arranged.
 *
 * Resolution, most specific first:  the user's own choice -> the organisation default -> built-in default.
 * The section list below is the single source of truth: anything unknown is dropped and any section missing from a
 * saved layout is appended, so a stored layout can never hide or lose a section after an upgrade.
 */
class DashboardPreferences
{
    public const STYLES = ['classic', 'modern'];

    public const DEFAULT_STYLE = 'classic';

    /** Movable Modern sections, in the default order. Keep in sync with resources/src/pages/dashboard/modern/sections.js */
    public const SECTIONS = [
        'kpis',
        'insights',
        'attention',
        'sales_purchases',
        'top_products_donut',
        'sales_by_payment',
        'stock_value',
        'quick_actions',
        'payments_chart',
        'top_customers',
        'stock_alert',
        'top_products_list',
        'recent_activity',
        'hourly_sales',
        'sales_by_warehouse',
        'sales_map',
        'recent_sales',
    ];


    public static function normaliseStyle($value): ?string
    {
        return is_string($value) && in_array($value, self::STYLES, true) ? $value : null;
    }

    /**
     * @param  mixed  $layout  array, JSON string or null
     * @return array{order:string[],hidden:string[]}
     */
    public static function normaliseLayout($layout): array
    {
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        $layout = is_array($layout) ? $layout : [];

        $clean = function ($ids) {
            $out = [];
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (is_string($id) && in_array($id, self::SECTIONS, true) && ! in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }

            return $out;
        };

        $order = $clean($layout['order'] ?? []);
        foreach (self::SECTIONS as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return ['order' => $order, 'hidden' => $clean($layout['hidden'] ?? [])];
    }

    /** True when a layout carries no customisation (default order, nothing hidden). */
    public static function isDefaultLayout(array $layout): bool
    {
        return $layout['order'] === self::SECTIONS && $layout['hidden'] === [];
    }

    public static function orgRow(): ?DashboardPreference
    {
        return DashboardPreference::whereNull('user_id')->first();
    }

    public static function userRow(int $userId): ?DashboardPreference
    {
        return DashboardPreference::where('user_id', $userId)->first();
    }

    /** Everything the front end needs in one call. */
    public static function resolve(int $userId, bool $isAdmin = false): array
    {
        $org = self::orgRow();
        $mine = self::userRow($userId);

        $orgStyle = self::normaliseStyle($org->style ?? null);
        $allowSwitch = self::switchAllowed();
        // When the organisation turns switching off, people see the organisation default; administrators can still switch.
        $myStyle = ($allowSwitch || $isAdmin) ? self::normaliseStyle($mine->style ?? null) : null;

        $orgLayout = ($org && $org->layout) ? self::normaliseLayout($org->layout) : null;
        $myLayout = ($mine && $mine->layout) ? self::normaliseLayout($mine->layout) : null;

        return [
            'style' => $myStyle ?? $orgStyle ?? self::DEFAULT_STYLE,
            'my_style' => $myStyle,
            'default_style' => $orgStyle ?? self::DEFAULT_STYLE,
            'layout' => $myLayout ?? $orgLayout ?? self::normaliseLayout(null),
            'my_layout' => $myLayout,
            'default_layout' => $orgLayout ?? self::normaliseLayout(null),
            'sections' => self::SECTIONS,
            'allow_user_switch' => $allowSwitch,
            'can_switch' => $allowSwitch || $isAdmin,
        ];
    }

    /** Organisation setting; on when nothing has been saved. */
    public static function switchAllowed(): bool
    {
        $org = self::orgRow();

        return $org === null || $org->allow_user_switch === null || (bool) $org->allow_user_switch;
    }

    /** Save only what was sent. A key that is absent is left alone; an explicit null clears it back to "inherit". */
    public static function saveFor(?int $userId, array $input): void
    {
        $row = $userId === null ? (self::orgRow() ?? new DashboardPreference(['user_id' => null])) : (self::userRow($userId) ?? new DashboardPreference(['user_id' => $userId]));

        if (array_key_exists('style', $input)) {
            $row->style = self::normaliseStyle($input['style']);
        }
        if ($userId === null && array_key_exists('allow_user_switch', $input)) {
            $row->allow_user_switch = (bool) $input['allow_user_switch'];
        }
        if (array_key_exists('layout', $input)) {
            $row->layout = $input['layout'] === null ? null : json_encode(self::normaliseLayout($input['layout']));
        }
        $row->save();
    }
}
