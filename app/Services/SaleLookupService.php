<?php

namespace App\Services;

use App\Models\BdDivision;
use App\Models\SaleCourier;
use App\Models\SaleZone;
use App\Support\BdDistrictMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Zone/Area and Courier lookup management.
 *
 * Both the inline CreatableSelect and the dedicated management pages use this
 * service, so whitespace normalization, case-insensitive duplicate handling,
 * restore behavior and update validation cannot drift between workflows.
 */
class SaleLookupService
{
    public function zones(array $filters = []): LengthAwarePaginator
    {
        return $this->paginate(SaleZone::query()->with('division'), $filters);
    }

    public function couriers(array $filters = []): LengthAwarePaginator
    {
        return $this->paginate(SaleCourier::query(), $filters);
    }

    /** The Bangladesh Divisions, for the Zone/Area form's Division dropdown. */
    public function divisions()
    {
        return BdDivision::orderBy('sort_order')->get(['id', 'name']);
    }

    /** Paginated Division list for the Divisions management page (district/zone counts, search, sort). */
    public function divisionsPaginated(array $filters = []): LengthAwarePaginator
    {
        $query = BdDivision::query()->withCount(['districts', 'zones']);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $allowedSorts = ['id', 'name', 'sort_order', 'districts_count', 'zones_count', 'created_at', 'updated_at'];
        $sortField = in_array($filters['sort_field'] ?? '', $allowedSorts, true) ? $filters['sort_field'] : 'sort_order';
        $sortType = strtolower((string) ($filters['sort_type'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = max(1, min(100000, (int) ($filters['limit'] ?? 10)));

        return $query->orderBy($sortField, $sortType)->orderBy('id')->paginate($limit);
    }

    /** Divisions are plain reference data (no soft delete), unlike Zone/Courier -- a simple unique-name create. */
    public function createDivision(string $name): BdDivision
    {
        $name = $this->normalizeName($name);
        if ($this->divisionNameTaken($name)) {
            throw ValidationException::withMessages(['name' => 'A Division with this name already exists.']);
        }

        $nextOrder = ((int) BdDivision::max('sort_order')) + 1;

        return BdDivision::create(['name' => $name, 'sort_order' => $nextOrder]);
    }

    public function updateDivision(BdDivision $division, string $name): BdDivision
    {
        $name = $this->normalizeName($name);
        if ($this->divisionNameTaken($name, $division->id)) {
            throw ValidationException::withMessages(['name' => 'Another Division already uses this name.']);
        }

        $division->update(['name' => $name]);

        return $division->fresh();
    }

    /**
     * @throws ValidationException when the Division still has Zones/Areas linked to it. Its reference Districts
     *                              (if any) are allowed to cascade-delete with it -- they only exist to power the
     *                              name-matching suggestion, never a Sale/report dependency by themselves.
     */
    public function destroyDivision(BdDivision $division): void
    {
        if ($division->zones()->count() > 0) {
            throw ValidationException::withMessages([
                'division' => 'This Division still has Zones/Areas linked to it and cannot be deleted.',
            ]);
        }

        $division->delete();
        BdDistrictMatcher::forgetCache();
    }

    private function divisionNameTaken(string $name, ?int $excludeId = null): bool
    {
        $query = BdDivision::whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name]);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /** Live suggestion while typing a Zone/Area name — never authoritative, always overridable. */
    public function suggestDivisionId(string $name): ?int
    {
        return BdDistrictMatcher::matchDivisionId($name);
    }

    /**
     * @param  bool  $divisionProvided  true when the caller explicitly chose (or explicitly cleared) a Division —
     *                                  that choice always wins. False means auto-resolve from the name (used by
     *                                  the quick "+ add new" picker on the Sale form, which only ever sends a name).
     */
    public function createZone(string $name, bool $divisionProvided = false, ?int $divisionId = null): SaleZone
    {
        $name = $this->normalizeName($name);
        $resolvedDivisionId = $divisionProvided ? $divisionId : BdDistrictMatcher::matchDivisionId($name);

        return $this->createOrRestore(SaleZone::class, $name, ['division_id' => $resolvedDivisionId]);
    }

    public function createCourier(string $name): SaleCourier
    {
        return $this->createOrRestore(SaleCourier::class, $this->normalizeName($name));
    }

    public function updateZone(SaleZone $zone, string $name, bool $divisionProvided = false, ?int $divisionId = null): SaleZone
    {
        $name = $this->normalizeName($name);
        // Symmetric with createZone(): an explicit choice (even an explicit clear) always wins; otherwise
        // re-resolve from the (possibly just-edited) name rather than silently leaving the old division_id in
        // place, so a rename that now matches a district still gets linked.
        $resolvedDivisionId = $divisionProvided ? $divisionId : BdDistrictMatcher::matchDivisionId($name);

        return $this->update($zone, $name, ['division_id' => $resolvedDivisionId]);
    }

    public function updateCourier(SaleCourier $courier, string $name): SaleCourier
    {
        return $this->update($courier, $this->normalizeName($name));
    }

    /**
     * @throws ValidationException when the zone still has sales linked to it.
     */
    public function destroyZone(SaleZone $zone): void
    {
        if ($zone->sales()->count() > 0) {
            throw ValidationException::withMessages([
                'zone' => 'This Zone/Area still has sales linked to it and cannot be deleted.',
            ]);
        }

        $zone->delete();
    }

    private function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $allowedSorts = ['id', 'name', 'sales_count', 'created_at', 'updated_at'];
        $sortField = in_array($filters['sort_field'] ?? '', $allowedSorts, true)
            ? $filters['sort_field']
            : 'name';
        $sortType = strtolower((string) ($filters['sort_type'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = max(1, min(100000, (int) ($filters['limit'] ?? 10)));

        return $query
            ->withCount('sales')
            ->orderBy($sortField, $sortType)
            ->orderBy('id')
            ->paginate($limit);
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if ($name === '' || mb_strlen($name) > 191) {
            throw ValidationException::withMessages([
                'name' => $name === ''
                    ? 'The name field is required.'
                    : 'The name may not be greater than 191 characters.',
            ]);
        }

        return $name;
    }

    private function findLookup(string $modelClass, string $name)
    {
        return $modelClass::withTrashed()
            ->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name])
            ->first();
    }

    private function createOrRestore(string $modelClass, string $name, array $extra = [])
    {
        $lookup = $this->findLookup($modelClass, $name);
        if ($lookup) {
            if ($lookup->trashed()) {
                $lookup->restore();
            }
            if ($extra !== []) {
                $lookup->update($extra);
            }

            return $lookup;
        }

        try {
            return $modelClass::create(['name' => $name] + $extra);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            $lookup = $this->findLookup($modelClass, $name);
            if ($lookup) {
                if ($lookup->trashed()) {
                    $lookup->restore();
                }
                if ($extra !== []) {
                    $lookup->update($extra);
                }

                return $lookup;
            }

            throw $e;
        }
    }

    private function update($lookup, string $name, array $extra = [])
    {
        $duplicate = $this->findLookup(get_class($lookup), $name);
        if ($duplicate && (int) $duplicate->id !== (int) $lookup->id) {
            throw ValidationException::withMessages([
                'name' => 'Another active or archived record already uses this name.',
            ]);
        }

        try {
            $lookup->update(['name' => $name] + $extra);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'name' => 'Another record already uses this name.',
                ]);
            }
            throw $e;
        }

        return $lookup->fresh();
    }
}
