<?php

declare(strict_types=1);

namespace App\Services\CashRegister;

use App\Exceptions\BusinessRuleException;
use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CashRegisterService
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;
    public const ALREADY_OPEN_MESSAGE = 'A cash register is already open for this user.';
    public const ALREADY_CLOSED_MESSAGE = 'Cash register is already closed.';

    public function open(int $businessId, int $userId, array $data): CashRegister
    {
        return DB::transaction(function () use ($businessId, $userId, $data): CashRegister {
            $this->assertUser($businessId, $userId);
            if ($this->openQuery($businessId, $userId)->lockForUpdate()->exists()) {
                throw new BusinessRuleException(self::ALREADY_OPEN_MESSAGE);
            }
            $register = CashRegister::create([
                'business_id' => $businessId,
                'opened_by' => $userId,
                'opened_at' => now(),
                'opening_balance' => Money::format($data['opening_balance']),
                'status' => CashRegister::STATUS_OPEN,
                'notes' => $data['notes'] ?? null,
            ]);
            CashRegisterTransaction::create([
                'business_id' => $businessId,
                'cash_register_id' => $register->getKey(),
                'type' => CashRegisterTransaction::TYPE_OPENING,
                'amount' => Money::format($data['opening_balance']),
                'note' => 'Register opened',
                'created_by' => $userId,
            ]);
            return $this->present($this->find($businessId, (int) $register->getKey()));
        });
    }

    public function current(int $businessId, int $userId): CashRegister
    {
        $register = $this->currentOrNull($businessId, $userId);
        if ($register === null) {
            throw new BusinessRuleException('No cash register is currently open.', 404);
        }
        return $register;
    }

    /**
     * Current register for a user, or null when the drawer is closed.
     * Dashboard reporting uses this instead of treating a normal closed-drawer
     * state as an API error.
     */
    public function currentOrNull(int $businessId, int $userId): ?CashRegister
    {
        $register = $this->openQuery($businessId, $userId)->first();

        return $register === null ? null : $this->present($register);
    }

    public function find(int $businessId, int $registerId): CashRegister
    {
        $register = CashRegister::query()->forBusiness($businessId)
            ->with(['openedBy:id,name', 'closedBy:id,name'])->whereKey($registerId)->firstOrFail();

        return $this->present($register);
    }

    public function findOwned(int $businessId, int $userId, int $registerId): CashRegister
    {
        $register = $this->find($businessId, $registerId);
        if ((int) $register->opened_by !== $userId) {
            throw new BusinessRuleException('Cash register is not owned by this user.', 404);
        }
        return $register;
    }

    public function transactions(int $businessId, int $userId, int $registerId, array $filters): LengthAwarePaginator
    {
        $this->findOwned($businessId, $userId, $registerId);
        $query = CashRegisterTransaction::query()->forBusiness($businessId)
            ->where('cash_register_id', $registerId)->with('creator:id,name');
        if (($filters['type'] ?? null) !== null) {
            $query->where('type', $filters['type']);
        }
        $perPage = max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function recordManual(int $businessId, int $userId, int $registerId, array $data): CashRegisterTransaction
    {
        return DB::transaction(function () use ($businessId, $userId, $registerId, $data): CashRegisterTransaction {
            $this->assertOpenAndOwned($businessId, $userId, $registerId);
            return CashRegisterTransaction::create([
                'business_id' => $businessId,
                'cash_register_id' => $registerId,
                'type' => $data['type'],
                'amount' => Money::format($data['amount']),
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /** Append to the caller's current register when one exists. */
    public function postAutomaticTransaction(
        int $businessId,
        int $userId,
        string $type,
        string $amount,
        string $referenceType,
        int $referenceId,
        ?string $note = null,
    ): ?CashRegisterTransaction {
        if (! in_array($type, [CashRegisterTransaction::TYPE_SALE, CashRegisterTransaction::TYPE_EXPENSE], true)) {
            throw new \InvalidArgumentException('Unsupported automatic cash transaction type.');
        }
        $register = $this->openQuery($businessId, $userId)->lockForUpdate()->first();
        if ($register === null) {
            return null;
        }
        return CashRegisterTransaction::create([
            'business_id' => $businessId,
            'cash_register_id' => $register->getKey(),
            'type' => $type,
            'amount' => Money::format($amount),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    public function close(int $businessId, int $userId, int $registerId, array $data): CashRegister
    {
        return DB::transaction(function () use ($businessId, $userId, $registerId, $data): CashRegister {
            $register = $this->assertOpenAndOwned($businessId, $userId, $registerId);
            $expected = $this->balance($register);
            $closing = Money::format($data['closing_balance']);
            CashRegisterTransaction::create([
                'business_id' => $businessId,
                'cash_register_id' => $register->getKey(),
                'type' => CashRegisterTransaction::TYPE_CLOSING,
                'amount' => $closing,
                'note' => 'Register closed',
                'created_by' => $userId,
            ]);
            $register->fill([
                'closed_by' => $userId,
                'closed_at' => now(),
                'closing_balance' => $closing,
                'expected_balance' => $expected,
                'difference' => Money::subtract($closing, $expected),
                'status' => CashRegister::STATUS_CLOSED,
                'notes' => ($data['notes'] ?? null) !== null && ($data['notes'] ?? '') !== '' ? $data['notes'] : $register->notes,
            ])->save();
            return $this->present($this->find($businessId, $registerId));
        });
    }

    public function summary(int $businessId, int $userId, int $registerId, array $filters = []): array
    {
        // `findOwned()` already presents the register, so `current_balance` is
        // the unfiltered lifetime balance. Phase 27 reused it instead of
        // calling `balance()` again, which repeated an aggregate that had just
        // been executed. The figures below stay date-windowed.
        $register = $this->findOwned($businessId, $userId, $registerId);
        $query = CashRegisterTransaction::query()->forBusiness($businessId)->where('cash_register_id', $registerId);
        $filters['business_id'] = $businessId;
        $this->applyDates($query, $filters);
        $row = $query->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) as total_in', CashRegisterTransaction::INFLOW_TYPES)
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) as total_out', CashRegisterTransaction::OUTFLOW_TYPES)
            ->first();
        return [
            'cash_register_id' => $register->getKey(),
            'opening_balance' => Money::format($register->opening_balance),
            'total_in' => Money::format($row->total_in),
            'total_out' => Money::format($row->total_out),
            'transaction_count' => (int) $row->transaction_count,
            'current_balance' => $register->current_balance,
        ];
    }

    public function balance(CashRegister $register): string
    {
        $totals = $this->totals($register);

        return Money::add(
            Money::format($register->opening_balance),
            Money::subtract($totals->total_in, $totals->total_out),
        );
    }

    /**
     * Single inflow/outflow aggregate for one register.
     *
     * Phase 27: `present()` used to call `balance()` and then re-run a
     * byte-identical `SUM(...)` query of its own, so every register read issued
     * the same aggregate twice, and `summary()` issued it a third time. Both
     * callers now share this one method, so a register read costs exactly one
     * aggregate regardless of how many figures the response needs.
     */
    private function totals(CashRegister $register): object
    {
        return CashRegisterTransaction::query()
            ->forBusiness((int) $register->business_id)
            ->where('cash_register_id', $register->getKey())
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) as total_in', CashRegisterTransaction::INFLOW_TYPES)
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) as total_out', CashRegisterTransaction::OUTFLOW_TYPES)
            ->first();
    }

    private function present(CashRegister $register): CashRegister
    {
        $totals = $this->totals($register);
        $balance = Money::add(
            Money::format($register->opening_balance),
            Money::subtract($totals->total_in, $totals->total_out),
        );

        $register->setAttribute('current_balance', $balance);
        $register->setAttribute('transaction_summary', [
            'total_in' => Money::format($totals->total_in),
            'total_out' => Money::format($totals->total_out),
            'current_balance' => Money::format($balance),
        ]);

        return $register;
    }

    private function assertOpenAndOwned(int $businessId, int $userId, int $registerId): CashRegister
    {
        $register = CashRegister::query()->forBusiness($businessId)->whereKey($registerId)->lockForUpdate()->firstOrFail();
        if ((int) $register->opened_by !== $userId) {
            throw new BusinessRuleException('Cash register is not owned by this user.', 404);
        }
        if ($register->status !== CashRegister::STATUS_OPEN) {
            throw new BusinessRuleException(self::ALREADY_CLOSED_MESSAGE);
        }
        return $register;
    }

    private function assertUser(int $businessId, int $userId): void
    {
        $exists = DB::table('users')
            ->where('id', $userId)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            throw new BusinessRuleException('Authenticated user is invalid.', 403);
        }
    }

    private function openQuery(int $businessId, int $userId)
    {
        return CashRegister::query()->forBusiness($businessId)->where('opened_by', $userId)->where('status', CashRegister::STATUS_OPEN);
    }

    private function applyDates(Builder $query, array $filters): void
    {
        $timezone = Business::query()->whereKey($filters['business_id'] ?? 0)->value('timezone') ?? config('app.timezone');
        if (($filters['date_from'] ?? null) !== null) {
            $from = Carbon::createFromFormat('Y-m-d', $filters['date_from'], $timezone)->startOfDay()->utc();
            $query->where('created_at', '>=', $from);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $to = Carbon::createFromFormat('Y-m-d', $filters['date_to'], $timezone)->endOfDay()->utc();
            $query->where('created_at', '<=', $to);
        }
    }
}
