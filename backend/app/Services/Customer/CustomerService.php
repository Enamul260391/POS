<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for the customer directory.
 *
 * Every method takes the authenticated user's `business_id` and resolves or
 * writes through {@see Customer::scopeForBusiness()}, so a customer from
 * another tenant is simply "not found". Controllers stay thin.
 *
 * Deletion is soft: `sales` and `sale_returns` keep pointing at the customer
 * so historical records are never destroyed, and a customer that is still
 * referenced is refused with a controlled 409 instead of a silent cascade.
 */
final class CustomerService
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

    public function __construct(
        private readonly CustomerLedgerService $ledger,
    ) {}

    /**
     * Paginated, tenant-scoped customer list.
     *
     * Search and the status filter run in SQL (no PHP-side filtering, no full
     * table loads), so the list is a constant number of queries per page.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, Customer>
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
     * Resolve one customer inside the tenant, or fail with a 404 envelope.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId, int $customerId): Customer
    {
        return $this->query($businessId)->whereKey($customerId)->firstOrFail();
    }

    /**
     * Create a customer for the given business.
     *
     * The tenant comes from the caller, so a client-supplied `business_id`
     * can never take effect. `status` and `opening_balance` fall back to the
     * column defaults when omitted.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data): Customer
    {
        $customer = Customer::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'status' => $data['status'] ?? Customer::STATUS_ACTIVE,
        ]);

        // The opening balance is written to the ledger once, so the account
        // starts from a real movement rather than from a column someone has to
        // know to read. The column itself is left as entered — it records how the
        // account was opened and is never rewritten to track the balance.
        //
        // Inside a transaction so the customer and the opening entry commit
        // together: a customer whose opening balance was never recorded would
        // report a balance short by exactly that amount.
        DB::transaction(function () use ($customer): void {
            $this->ledger->recordOpeningBalance($customer, null);
        });

        return $this->find($businessId, $customer->getKey());
    }

    /**
     * Update the editable master data of a customer (PUT and PATCH).
     *
     * `id` and `business_id` are never part of `$data`, so they cannot change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $attributes = Arr::only($data, self::EDITABLE_COLUMNS);

        if ($attributes !== []) {
            $customer->fill($attributes)->save();
        }

        return $this->find($customer->business_id, $customer->id);
    }

    /**
     * Soft-delete a customer that no historical record references.
     *
     * Sales and sale returns keep pointing at the customer, so removing one
     * that is in use would corrupt history (their `customer_id` is
     * `nullOnDelete`). Instead the caller receives a 409 business error.
     * Trashed rows still count as references: history stays readable.
     *
     * @throws BusinessRuleException when the customer is still referenced.
     */
    public function delete(Customer $customer): void
    {
        if ($customer->sales()->exists() || $customer->saleReturns()->exists()) {
            throw new BusinessRuleException('Cannot delete customer because it is in use.');
        }

        $customer->delete();
    }

    /**
     * Base query: one tenant.
     *
     * @return Builder<Customer>
     */
    private function query(int $businessId): Builder
    {
        return Customer::query()->forBusiness($businessId);
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
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
