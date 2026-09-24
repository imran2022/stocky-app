<?php

namespace App\Services;

use App\Models\SaleCourier;
use App\Models\SaleZone;
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
        return $this->paginate(SaleZone::query(), $filters);
    }

    public function couriers(array $filters = []): LengthAwarePaginator
    {
        return $this->paginate(SaleCourier::query(), $filters);
    }

    public function createZone(string $name): SaleZone
    {
        return $this->createOrRestore(SaleZone::class, $this->normalizeName($name));
    }

    public function createCourier(string $name): SaleCourier
    {
        return $this->createOrRestore(SaleCourier::class, $this->normalizeName($name));
    }

    public function updateZone(SaleZone $zone, string $name): SaleZone
    {
        return $this->update($zone, $this->normalizeName($name));
    }

    public function updateCourier(SaleCourier $courier, string $name): SaleCourier
    {
        return $this->update($courier, $this->normalizeName($name));
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

    private function createOrRestore(string $modelClass, string $name)
    {
        $lookup = $this->findLookup($modelClass, $name);
        if ($lookup) {
            if ($lookup->trashed()) {
                $lookup->restore();
            }

            return $lookup;
        }

        try {
            return $modelClass::create(['name' => $name]);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            $lookup = $this->findLookup($modelClass, $name);
            if ($lookup) {
                if ($lookup->trashed()) {
                    $lookup->restore();
                }

                return $lookup;
            }

            throw $e;
        }
    }

    private function update($lookup, string $name)
    {
        $duplicate = $this->findLookup(get_class($lookup), $name);
        if ($duplicate && (int) $duplicate->id !== (int) $lookup->id) {
            throw ValidationException::withMessages([
                'name' => 'Another active or archived record already uses this name.',
            ]);
        }

        try {
            $lookup->update(['name' => $name]);
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
