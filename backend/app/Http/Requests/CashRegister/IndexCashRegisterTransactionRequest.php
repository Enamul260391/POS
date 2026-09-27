<?php

declare(strict_types=1);

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\HandlesListQuery;
use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\CashRegisterTransaction;
use App\Services\CashRegister\CashRegisterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCashRegisterTransactionRequest extends FormRequest
{
    use HandlesListQuery, ResolvesBusiness;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(CashRegisterTransaction::TYPES)],
            ...$this->paginationRules(CashRegisterService::MAX_PER_PAGE),
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'type' => $this->input('type'),
            'per_page' => $this->perPage(CashRegisterService::DEFAULT_PER_PAGE),
        ];
    }
}
