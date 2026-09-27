<?php

declare(strict_types=1);

namespace App\Services\ExpenseCategory;

use App\Exceptions\BusinessRuleException;
use App\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Business logic for expense categories.
 *
 * Mirrors CategoryService conventions: tenant-scoped resolution through
 * forBusiness(), DB-level search/status filtering, whitelisted sorting and a
 * guarded delete that refuses to orphan historical expenses.
 */
final class ExpenseCategoryService
{
    /** Columns a client may sort by — nothing else ever reaches orderBy(). */
    public const SORTABLE_COLUMNS = ['name', 'status', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Paginated, tenant-scoped expense-category list.
     *
     * `expenses_count` is added by a correlated sub-query so the list stays a
     * single round trip with no N+1.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, ExpenseCategory>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($businessId);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('name', 'like', $term)->orWhere('description', 'like', $term);
            });
        }

        $status = $filters['status'] ?? null;
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    /**
     * Resolve one category inside the tenant, or fail with a 404 envelope.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId, int $categoryId): ExpenseCategory
    {
        return $this->query($businessId)->whereKey($categoryId)->firstOrFail();
    }

    /**
     * Create an expense category for the given business.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data): ExpenseCategory
    {
        $category = ExpenseCategory::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? ExpenseCategory::STATUS_ACTIVE,
        ]);

        return $this->find($businessId, $category->id);
    }

    /**
     * Update the editable master data of a category.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseCategory $category, array $data): ExpenseCategory
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $data['description'];
        }

        if (array_key_exists('status', $data)) {
            $attributes['status'] = $data['status'];
        }

        if ($attributes !== []) {
            $category->fill($attributes)->save();
        }

        return $this->find($category->business_id, $category->id);
    }

    /**
     * Soft-delete a category that no expense depends on.
     *
     * Expenses keep pointing at their category (`nullOnDelete` on the FK), so
     * removing one that is still referenced would silently break the expense
     * history. The caller receives a 409 business error instead; expenses must
     * be re-assigned or deleted first.
     *
     * @throws BusinessRuleException when the category is still referenced.
     */
    public function delete(ExpenseCategory $category): void
    {
        if ($category->expenses()->forBusiness((int) $category->business_id)->exists()) {
            throw new BusinessRuleException('Cannot delete expense category because it is in use.');
        }

        $category->delete();
    }

    /**
     * Base query: one tenant, with the expense count pre-selected.
     *
     * @return Builder<ExpenseCategory>
     */
    private function query(int $businessId): Builder
    {
        return ExpenseCategory::query()->forBusiness($businessId)->withCount('expenses');
    }

    /**
     * @param  Builder<ExpenseCategory>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ExpenseCategory>
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
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
