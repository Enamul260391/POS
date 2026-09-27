<?php

declare(strict_types=1);

namespace App\Http\Requests\Category;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Payload of `PUT|PATCH /api/v1/categories/{category}`.
 *
 * Fields are `sometimes`, so both verbs behave like a partial update and a
 * client may send only what changed. `id` and `business_id` are never
 * validated, which means they cannot be changed even if they are sent.
 */
class UpdateCategoryRequest extends FormRequest
{
    use ResolvesBusiness;

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
            'name' => ['sometimes', 'required', 'string', 'max:100', $this->uniqueNameRule()],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Category::STATUSES)],
        ];
    }

    /**
     * Trim the incoming strings and normalise an empty description to `null`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        if ($this->has('description')) {
            $this->merge([
                'description' => $this->filled('description')
                    ? trim((string) $this->input('description'))
                    : null,
            ]);
        }
    }

    /**
     * Name uniqueness inside the authenticated business, ignoring the category
     * being updated and including soft-deleted rows (see
     * {@see StoreCategoryRequest::uniqueNameRule()}).
     */
    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('categories', 'name')
            ->where('business_id', $this->businessId())
            ->ignore((int) $this->route('category'));
    }
}
