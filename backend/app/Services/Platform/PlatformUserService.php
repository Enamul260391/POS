<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\CustomerLedgerEntry;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\User\UserService;
use App\Services\UserRole\UserRoleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level business-user (staff account) management.
 *
 * Platform counterpart to the tenant staff directory (UserService):
 * the Platform Owner manages users in any business without becoming a
 * tenant user. The route business is the tenant boundary — every method
 * takes the business id resolved from {business} and scopes user access
 * to it, so a user of Business A is a plain 404 through Business B.
 *
 * Pagination, creation, updates and role syncs delegate to the tenant
 * services (UserService, UserRoleService). This service adds only the
 * platform-specific parts: business resolution, the status writer and
 * the conservative delete guard. Platform identity is never writable:
 * business_id / platform_role / is_platform_owner are prohibited inputs.
 */
final class PlatformUserService
{
    public function __construct(
        private readonly BusinessService $businesses,
        private readonly UserService $users,
        private readonly UserRoleService $userRoles,
    ) {}

    /**
     * Resolve the route business, or 404 (soft-deleted reads as missing).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function business(int $businessId): Business
    {
        return $this->businesses->find($businessId);
    }

    /**
     * Paginated, business-scoped staff list (roles eager-loaded, no N+1).
     *
     * @param  array<string, mixed>  $filters  Normalised by the index request.
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        return $this->users->paginate($businessId, $filters);
    }

    /**
     * Resolve one user inside the route business, or 404.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $businessId, int $userId): User
    {
        return $this->users->find($businessId, $userId);
    }

    /**
     * Create a staff account inside the route business, with one role.
     *
     * Row + pivot are written in one transaction by the tenant service.
     * The row always carries the route business id and NULL platform_role;
     * only a role owned by that business may be attached.
     *
     * @param  array<string, mixed>  $data  Validated platform payload.
     *
     * @throws \Illuminate\Validation\ValidationException when the role is not owned by the business.
     */
    public function create(int $businessId, array $data): User
    {
        return $this->users->create($businessId, $data);
    }

    /**
     * Update a staff account inside the route business.
     *
     * Only tenant profile columns (+ optional same-business role_id) reach
     * the model; the tenant service owns the allow-list.
     *
     * @param  array<string, mixed>  $data  Validated platform payload.
     *
     * @throws \Illuminate\Validation\ValidationException when the role is not owned by the business.
     */
    public function update(int $businessId, User $user, array $data): User
    {
        return $this->users->update($businessId, $user, $data);
    }

    /**
     * Activate/deactivate using the existing status model.
     *
     * Only `status` is written; the row and its history are never deleted.
     */
    public function setStatus(int $businessId, User $user, string $status): User
    {
        $user->fill(['status' => $status])->save();

        return $this->find($businessId, (int) $user->getKey());
    }

    /**
     * Replace the roles held by a staff account (sync semantics).
     *
     * User and roles are resolved inside the route business only.
     *
     * @param  array<int, int>  $roleIds
     *
     * @throws \Illuminate\Validation\ValidationException when a role is unknown or foreign.
     */
    public function syncRoles(int $businessId, User $user, array $roleIds): User
    {
        return $this->userRoles->syncRoles($businessId, $user, $roleIds);
    }

    /**
     * Delete a staff account that holds no transactional history.
     *
     * `users` has no deleted_at / SoftDeletes, so deactivation — not
     * deletion — suspends an account. A hard delete would orphan every
     * sale, purchase and cash-register row referencing the account (those
     * FKs are nullOnDelete, so the DB would silently null the audit
     * trail); any reference in transactional/operational tables therefore
     * refuses the delete with a 409 business error. An unreferenced
     * account is removed with its pivots, overrides and tokens in one
     * transaction. The Platform Owner can never be a target: it belongs
     * to no business and is never resolved inside one.
     *
     * @throws BusinessRuleException when the user is referenced by protected records.
     */
    public function delete(Business $business, User $user): void
    {
        $businessId = (int) $business->getKey();

        // Re-resolve inside the route business: a valid id from another
        // tenant reads as 404, never as a deletable row.
        $target = $this->find($businessId, (int) $user->getKey());

        if ($this->hasProtectedReferences($target)) {
            throw new BusinessRuleException('Cannot delete user because it has related records.');
        }

        DB::transaction(function () use ($target): void {
            $target->roles()->detach();
            $target->permissionOverrides()->delete();
            $target->tokens()->delete();
            $target->delete();
        });
    }

    /**
     * Is the user referenced by transactional/operational records?
     *
     * role_user / user_permissions / personal_access_tokens are excluded:
     * they are cleaned up with the user rather than protecting it.
     */
    public function hasProtectedReferences(User $user): bool
    {
        $userId = (int) $user->getKey();

        return Sale::query()->where('cashier_id', $userId)->exists()
            || Purchase::query()->where('created_by', $userId)->exists()
            || Payment::query()->where('received_by', $userId)->exists()
            || StockMovement::query()->where('created_by', $userId)->exists()
            || SaleReturn::query()->where('created_by', $userId)->exists()
            || Expense::query()->where('created_by', $userId)->exists()
            || CashRegister::query()
                ->where('opened_by', $userId)
                ->orWhere('closed_by', $userId)
                ->exists()
            || CashRegisterTransaction::query()->where('created_by', $userId)->exists()
            || CustomerLedgerEntry::query()->where('created_by', $userId)->exists();
    }
}
