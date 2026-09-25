<?php

namespace App\Support;

use App\Models\BdDistrict;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves a free-text Zone/Area name to a Bangladesh Division id, by matching it against every District's
 * canonical name and known alternate spellings (aliases), case- and punctuation-insensitively.
 *
 * Deliberately EXACT matching only (on the normalized string) — no "contains"/fuzzy matching. A Zone/Area is not
 * always a district name (it can be a city sub-area, a custom delivery zone, etc.), so a partial/fuzzy match risks
 * silently linking the wrong Division. Returning null (no match) is always safer than a wrong guess; a human can
 * always pick the Division manually when this returns null.
 */
class BdDistrictMatcher
{
    /** @return array<string,int> normalized name (district name or alias) => division_id */
    private static function map(): array
    {
        return Cache::rememberForever('bd_district_matcher_map', function () {
            $map = [];
            foreach (BdDistrict::query()->get(['division_id', 'name', 'aliases']) as $district) {
                $names = array_merge([$district->name], $district->aliases ?? []);
                foreach ($names as $name) {
                    $map[self::normalize($name)] = (int) $district->division_id;
                }
            }

            return $map;
        });
    }

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/[^a-z0-9]+/', '', $name) ?? '';
    }

    /** The Division id whose district list recognizes this Zone/Area name, or null if none does. */
    public static function matchDivisionId(string $zoneName): ?int
    {
        $key = self::normalize($zoneName);
        if ($key === '') {
            return null;
        }

        return self::map()[$key] ?? null;
    }

    /** Clears the cached name=>division map — call after seeding/editing bd_districts. */
    public static function forgetCache(): void
    {
        Cache::forget('bd_district_matcher_map');
    }
}
