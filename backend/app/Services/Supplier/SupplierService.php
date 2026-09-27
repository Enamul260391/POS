<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Exceptions\BusinessRuleException;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Business logic for the supplier directory.
 *
 * Every method takes the authenticated user's `business_id` and resolves or
 * writes through {@see Supplier::scopeForBusiness()}, so a supplier from
 * another tenant is simply "not found". Controllers stay thin.
 *
 * Deletion is soft: `purchases` keep pointing at the supplier so historical
 * records are never destroyed, and a supplier that still has purchases is
 * refused with a controlled 409 instead of a silent cascade.
 */
final class SupplierService
{
    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = [
        'name', 'phone', 'email', 'opening_balance', 'status', 'created_at', 'id',
    ];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /** Master-data columns a client may change. `id`/`business_id` are never among them. */
    private const EDITABLE_COLUMNS = ['name', 'phone', 'email', 'address', 'opening_balance', 'status'];

    /**
     * Paginated, tenant-scoped supplier list.
     *
     * Search and the status filter run in SQL (no PHP-side filtering, no full
     * table loads), so the list is a constant number of queries per page.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($businessId);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    /**
     * Resolve one supplier inside the tenant, or fail with a 404 envelope.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId, int $supplierId): Supplier
    {
        return $this->query($businessId)->whereKey($supplierId)->firstOrFail();
    }

    /**
     * Create a supplier for the given business.
     *
     * The tenant comes from the caller, so a client-supplied `business_id`
     * can never take effect.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data): Supplier
    {
        $supplier = Supplier::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'status' => $data['status'] ?? Supplier::STATUS_ACTIVE,
        ]);

        return $this->find($businessId, $supplier->getKey());
    }

    /**
     * Update the editable master data of a supplier (PUT and PATCH).
     *
     * `id` and `business_id` are never part of `$data`, so they cannot change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $attributes = Arr::only($data, self::EDITABLE_COLUMNS);

        if ($attributes !== []) {
            $supplier->fill($attributes)->save();
        }

        return $this->find($supplier->business_id, $supplier->id);
    }

    /**
     * Soft-delete a supplier that no purchase references.
     *
     * Purchases keep pointing at the supplier, so removing one that is in
     * use would corrupt history (`purchases.supplier_id` is `nullOnDelete`).
     * Instead the caller receives a 409 business error. Trashed suppliers
     * still count as references: history stays readable.
     *
     * @throws BusinessRuleException when the supplier is still referenced.
     */
    public function delete(Supplier $supplier): void
    {
        if ($supplier->purchases()->exists()) {
            throw new BusinessRuleException('Cannot delete supplier because it is in use.');
        }

        $supplier->delete();
    }

    /**
     * Base query: one tenant.
     *
     * @return Builder<Supplier>
     */
    private function query(int $businessId): Builder
    {
        return Supplier::query()->forBusiness($businessId);
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<Supplier>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Supplier>
     */
    private function applyOrdering(Builder $query, array $filters): Builder
    {
        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }

        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'desc'
            ? 'desc'
            : 'asc';

        return $query->orderBy($column, $direction)->orderBy('id');
    }

    /**
     * Page size, clamped to the documented maximum.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
