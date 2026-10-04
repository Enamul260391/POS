<?php

declare(strict_types=1);

namespace App\Http\Requests\SaleReturn;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of `POST /api/v1/sale-returns`.
 *
 * `refunds` is optional. Omitted, the service allocates the refund itself
 * against what the sale actually collected (see `SaleReturnService`), which is
 * what makes the common case one request with no settlement detail. Supplying it
 * lets the cashier say explicitly where a mixed refund goes — for example cash
 * ৳300 and card ৳400 of a ৳700 return.
 *
 * The sum is deliberately **not** required to equal the return amount: the
 * remainder a method cannot cover is settled on the customer's account instead
 * of being fabricated as a refund, so forcing a full match would make a credit
 * sale's return unrepresentable. What is enforced here is that every line is a
 * real method and a positive amount; the per-method ceiling is checked by the
 * service against the sale itself, not trusted from the request.
 */
class StoreSaleReturnRequest extends FormRequest
{
    use ResolvesBusiness;

    public const MAX_ITEMS = 500;

    /** Bound on the refund split, so one return cannot carry an unbounded list. */
    public const MAX_REFUNDS = 10;

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
            'sale_id' => ['required', 'integer', 'min:1'],
            'return_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'return_number' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.sale_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:'.Quantity::MAX, 'decimal:0,4'],
            'refunds' => ['nullable', 'array', 'max:'.self::MAX_REFUNDS],
            'refunds.*.payment_method' => ['required', 'string', Rule::in(Payment::REFUND_METHODS)],
            'refunds.*.amount' => ['required', 'numeric', 'gt:0', 'max:'.Money::MAX, 'decimal:0,2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
            'return_number' => $this->filled('return_number') ? trim((string) $this->input('return_number')) : null,
        ]);
    }
}
