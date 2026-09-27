<?php

declare(strict_types=1);

namespace App\Services\Category;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Business logic for product categories.
 *
 * Every method takes the authenticated user's `business_id` and resolves or
 * writes through {@see Category::scopeForBusiness()}, so a category from
 * another tenant is simply "not found". Controllers stay thin.
 */
final class CategoryService
{
    /** Columns a client may sort by — nothing else ever reaches `orderBy()`. */
    public const SORTABLE_COLUMNS = ['name', 'status', 'created_at', 'id'];

    public const DEFAULT_SORT_COLUMN = 'name';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /** Longest slug the `slug` column can hold. */
    private const MAX_SLUG_LENGTH = 100;

    /**
     * Paginated, tenant-scoped category list with search and status filtering.
     *
     * `products_count` is added by a correlated sub-query so the list stays a
     * single round trip and never triggers an N+1.
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, Category>
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
    public function find(int $businessId, int $categoryId): Category
    {
        return $this->query($businessId)->whereKey($categoryId)->firstOrFail();
    }

    /**
     * Create a category for the given business.
     *
     * The slug is derived from the name; the tenant comes from the caller, so
     * a client-supplied `business_id` can never take effect.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data): Category
    {
        $category = Category::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'slug' => $this->slugFor($data['name']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? Category::STATUS_ACTIVE,
        ]);

        return $this->find($businessId, $category->id);
    }

    /**
     * Update the editable master data of a category.
     *
     * `id` and `business_id` are never part of `$data`, so they cannot change.
     * Renaming regenerates the slug, keeping it consistent with the name.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
            $attributes['slug'] = $this->slugFor($data['name']);
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
     * Soft-delete a category that nothing depends on.
     *
     * Products keep pointing at their category, so removing one that is still
     * referenced would leave the catalogue — and, for a future hard delete, the
     * `nullOnDelete` foreign key — with orphaned rows. Instead of silently
     * breaking that history the caller receives a 409 business error; products
     * must be re-assigned or deleted first.
     *
     * Trashed products count as references as well: their history must stay
     * readable until the category is genuinely unused.
     *
     * @throws BusinessRuleException when the category is still referenced.
     */
    public function delete(Category $category): void
    {
        if ($category->products()->withTrashed()->exists()) {
            throw new BusinessRuleException('Cannot delete category because it is in use.');
        }

        $category->delete();
    }

    /**
     * Base query: one tenant, with the product count pre-selected.
     *
     * @return Builder<Category>
     */
    private function query(int $businessId): Builder
    {
        return Category::query()
            ->forBusiness($businessId)
            ->withCount('products');
    }

    /**
     * Apply the whitelisted ordering plus a stable tiebreaker so pagination is
     * deterministic even when the sort column has duplicates.
     *
     * @param  Builder<Category>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Category>
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

    /**
     * URL-friendly identifier derived from the category name.
     */
    private function slugFor(string $name): ?string
    {
        $slug = substr(Str::slug($name), 0, self::MAX_SLUG_LENGTH);

        return $slug === '' ? null : $slug;
    }
}
