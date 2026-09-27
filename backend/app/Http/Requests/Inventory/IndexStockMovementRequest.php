<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of `GET /api/v1/stock-movements`.
 *
 * The log is always scoped to the authenticated user's business and always
 * ordered newest first (an audit trail has one meaningful order), so only
 * filters and pagination are accepted.
 */
class IndexStockMovementRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', Rule::in(StockMovement::LOG_TYPES)],
            ...$this->paginationRules(InventoryService::MAX_PER_PAGE),
        ];
    }

    /**
     * Normalised filters for {@see InventoryService::paginate()}.
     *
     * A `product_id` from another business simply matches nothing — the
     * business scope is applied first, so the response never reveals whether
     * the product exists elsewhere.
     *
     * @return array{product_id: int|null, type: string|null, per_page: int}
     */
    public function filters(): array
    {
        return [
            'product_id' => $this->filled('product_id') ? (int) $this->input('product_id') : null,
            'type' => $this->input('type'),
            'per_page' => $this->perPage(InventoryService::DEFAULT_PER_PAGE),
        ];
    }
}
