<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\CashRegisterTransaction;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ReportFilterRequest extends FormRequest
{
    use ResolvesBusiness;

    public function authorize(): bool
    {
        return true;
    }

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
            // Stage 9 — cash-register movement report. A register may only be
            // named one this business owns, on the same `existsForBusiness` rule
            // every other id filter uses, so a foreign register id is a 422 rather
            // than a tenant probe.
            //
            // `transaction_type` is validated against the canonical
            // `CashRegisterTransaction::TYPES` — not a hand-restated subset — so an
            // unknown type is refused instead of silently matching no rows. The
            // whole canonical list is accepted, including the `opening` /
            // `closing` markers: filtering a period down to "the drawer was opened"
            // is a legitimate question, and those rows are real history. They are
            // still never counted as a cash in or a cash out.
            'cash_register_id' => ['nullable', 'integer', $this->existsForBusiness('cash_registers')],
            'transaction_type' => ['nullable', 'string', Rule::in(CashRegisterTransaction::TYPES)],
        ];
    }

    /**
     * A filter may only reference a related record owned by this business.
     * This also prevents a client from using a foreign id as a tenant probe.
     */
    protected function existsForBusiness(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('business_id', $this->businessId());
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = [];
        foreach (['date_from', 'date_to', 'customer_id', 'supplier_id', 'expense_category_id', 'payment_method', 'status', 'cash_register_id', 'transaction_type'] as $key) {
            $filters[$key] = $this->filled($key) ? $this->input($key) : null;
        }
        foreach (['customer_id', 'supplier_id', 'expense_category_id', 'cash_register_id'] as $key) {
            if ($filters[$key] !== null) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }
}
