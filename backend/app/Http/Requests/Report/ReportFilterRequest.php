<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'customer_id' => ['nullable', 'integer', $this->existsForBusiness('customers')],
            'supplier_id' => ['nullable', 'integer', $this->existsForBusiness('suppliers')],
            'expense_category_id' => ['nullable', 'integer', $this->existsForBusiness('expense_categories')],
            'payment_method' => ['nullable', 'string', Rule::in(Payment::PAYMENT_METHODS)],
            'status' => ['nullable', 'string', Rule::in(Sale::STATUSES)],
        ];
    }

    /**
     * A filter may only reference a related record owned by this business.
     * This also prevents a client from using a foreign id as a tenant probe.
     *
     * @return \Illuminate\Validation\Rules\Exists
     */
    protected function existsForBusiness(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where('business_id', $this->businessId());
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = [];
        foreach (['date_from', 'date_to', 'customer_id', 'supplier_id', 'expense_category_id', 'payment_method', 'status'] as $key) {
            $filters[$key] = $this->filled($key) ? $this->input($key) : null;
        }
        foreach (['customer_id', 'supplier_id', 'expense_category_id'] as $key) {
            if ($filters[$key] !== null) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }
}
