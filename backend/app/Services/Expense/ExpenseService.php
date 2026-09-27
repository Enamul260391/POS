<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\CashRegister\CashRegisterService;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ExpenseService
{
    public const SORTABLE_COLUMNS = ['expense_date', 'amount', 'payment_method', 'reference', 'created_at', 'id'];
    public const DEFAULT_SORT_COLUMN = 'expense_date';
    public const DEFAULT_SORT_DIRECTION = 'desc';
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;
    public const FINALIZED_MESSAGE = 'Cash-affected expense financial fields cannot be modified.';
    public const DELETE_MESSAGE = 'Cannot delete an expense that has affected a cash register.';

    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = Expense::query()->forBusiness($businessId)->with([
            'expenseCategory' => static fn ($category) => $category->forBusiness($businessId),
            'creator:id,name',
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $nested) use ($term): void {
                $nested->where('reference', 'like', $term)->orWhere('description', 'like', $term);
            });
        }
        foreach (['expense_category_id', 'payment_method'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['date_from'] ?? null) !== null) {
            $query->where('expense_date', '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $query->where('expense_date', '<=', $filters['date_to']);
        }

        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }
        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage);
    }

    public function find(int $businessId, int $expenseId): Expense
    {
        return Expense::query()->forBusiness($businessId)
            ->with([
                'expenseCategory' => static fn ($category) => $category->forBusiness($businessId),
                'creator:id,name',
                'cashRegisterTransactions' => static fn ($transactions) => $transactions->forBusiness($businessId),
            ])
            ->whereKey($expenseId)->firstOrFail();
    }

    public function create(int $businessId, ?int $userId, array $data): Expense
    {
        return DB::transaction(function () use ($businessId, $userId, $data): Expense {
            $categoryId = $this->category($businessId, $data['expense_category_id'] ?? null)?->getKey();
            $expense = Expense::create([
                'business_id' => $businessId,
                'expense_category_id' => $categoryId,
                'amount' => Money::format($data['amount']),
                'expense_date' => $data['expense_date'],
                'payment_method' => $data['payment_method'] ?? Expense::PAYMENT_METHOD_CASH,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);
            $this->postCashExpense($businessId, $userId, $expense);
            return $this->find($businessId, (int) $expense->getKey());
        });
    }

    public function update(Expense $expense, array $data, ?int $userId = null): Expense
    {
        return DB::transaction(function () use ($expense, $data, $userId): Expense {
            $hasCashHistory = $expense->cashRegisterTransactions()->forBusiness((int) $expense->business_id)->exists();
            $financial = ['expense_category_id', 'amount', 'expense_date', 'payment_method'];
            if ($hasCashHistory && array_intersect($financial, array_keys($data)) !== []) {
                throw new BusinessRuleException(self::FINALIZED_MESSAGE);
            }
            $attributes = Arr::only($data, [...$financial, 'reference', 'description']);
            if (array_key_exists('amount', $attributes)) {
                $attributes['amount'] = Money::format($attributes['amount']);
            }
            if (array_key_exists('expense_category_id', $attributes)) {
                $attributes['expense_category_id'] = $this->category((int) $expense->business_id, $attributes['expense_category_id'])?->getKey();
            }
            if ($attributes !== []) {
                $expense->fill($attributes)->save();
            }
            // A cash expense is posted at creation time, but only to the
            // register open at that moment. Do not retroactively post it if a
            // register is opened later; only a non-cash -> cash transition
            // creates new drawer activity.
            if ($expense->wasChanged('payment_method') && $expense->payment_method === Expense::PAYMENT_METHOD_CASH) {
                $this->postCashExpense((int) $expense->business_id, $userId, $expense);
            }
            return $this->find((int) $expense->business_id, (int) $expense->getKey());
        });
    }

    public function delete(Expense $expense): void
    {
        if ($expense->cashRegisterTransactions()->forBusiness((int) $expense->business_id)->exists()) {
            throw new BusinessRuleException(self::DELETE_MESSAGE);
        }
        $expense->delete();
    }

    private function postCashExpense(int $businessId, ?int $userId, Expense $expense): void
    {
        if ($expense->payment_method === Expense::PAYMENT_METHOD_CASH && $userId !== null) {
            $this->cashRegisters->postAutomaticTransaction(
                $businessId,
                $userId,
                CashRegisterTransaction::TYPE_EXPENSE,
                Money::format($expense->amount),
                Expense::class,
                (int) $expense->getKey(),
                $expense->description,
            );
        }
    }

    private function category(int $businessId, ?int $categoryId): ?ExpenseCategory
    {
        if ($categoryId === null) {
            return null;
        }
        $category = ExpenseCategory::query()->forBusiness($businessId)->whereKey($categoryId)->first();
        if ($category === null) {
            throw new BusinessRuleException('Expense category is invalid.');
        }
        return $category;
    }
}
