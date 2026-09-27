<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Shared query-string rules for paginated list endpoints.
 *
 * The sortable whitelist is supplied by the owning request from the service's
 * own `SORTABLE_COLUMNS` constant, so client input can never reach `orderBy()`
 * unless the service is willing to order by that column.
 */
trait HandlesListQuery
{
    /**
     * Validation rules for the pagination parameters.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function paginationRules(int $maxPerPage): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$maxPerPage],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Validation rules for the ordering parameters.
     *
     * @param  array<int, string>  $sortableColumns
     * @return array<string, array<int, mixed>>
     */
    protected function orderingRules(array $sortableColumns): array
    {
        return [
            'sort' => ['nullable', 'string', Rule::in($sortableColumns)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Treat empty query-string values (`?status=`) as "not provided" instead of
     * failing validation, which is what a browser/UI sends for an unset filter.
     */
    protected function prepareForValidation(): void
    {
        foreach (array_keys($this->rules()) as $parameter) {
            if ($this->input($parameter) === '') {
                $this->merge([$parameter => null]);
            }
        }
    }

    /**
     * Requested page size, already clamped by the validated `max` rule.
     */
    protected function perPage(int $default): int
    {
        $value = $this->input('per_page');

        return $value === null ? $default : (int) $value;
    }
}
