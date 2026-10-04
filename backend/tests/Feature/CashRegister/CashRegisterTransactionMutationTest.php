<?php

declare(strict_types=1);

namespace Tests\Feature\CashRegister;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Update and delete of a *manual* cash in / cash out.
 *
 * The two new capabilities, `cash_registers.transactions.update` and
 * `cash_registers.transactions.delete`, are narrow on purpose, and these tests
 * hold them to that: they must work on the rows a human typed into the drawer
 * and must refuse every row another flow wrote, however much authority the
 * caller holds.
 *
 * Three groups, matching the three things that could go wrong:
 *
 *  1. **Permission.** Holding the slug is necessary; not holding it is a 403.
 *  2. **Classification.** `type` plus the absence of a reference is what makes a
 *     movement manual. A sale or expense row shares the drawer and the tenant
 *     but not that classification, and is refused with the ordinary business
 *     rule rather than a 403 â€” the caller *was* authorized; the row simply is
 *     not theirs to touch.
 *  3. **Integrity.** Every figure asserted here is read back from the API
 *     rather than recomputed locally, so the ledger aggregate is proved to
 *     remain the single source of balance truth after a write.
 */
class CashRegisterTransactionMutationTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();
    }

// ------------------------------------------------------------------
    // Permission
    // ------------------------------------------------------------------

    /** @test */
    public function a_user_with_the_update_capability_can_update_a_manual_transaction(): void
    {
        $user = $this->userWithPermissions(
            [PermissionService::CASH_REGISTERS_CREATE, PermissionService::CASH_REGISTERS_VIEW, PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE],
            $this->business->id,
        );
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '50.00');

        $this->putJson($this->transactionUri($register, $transaction), ['amount' => 75.5])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.amount', '75.50');

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $transaction->id,
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => '75.50',
        ]);
    }

    /** @test */
    public function a_user_without_the_update_capability_is_rejected(): void
    {
        $user = $this->userWithPermissions([PermissionService::CASH_REGISTERS_CREATE], $this->business->id);
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '50.00');

        $this->putJson($this->transactionUri($register, $transaction), ['amount' => 75.5])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $transaction->id, 'amount' => '50.00']);
    }

    /** @test */
    public function a_user_with_the_delete_capability_can_delete_a_manual_transaction(): void
    {
        $user = $this->userWithPermissions(
            [PermissionService::CASH_REGISTERS_CREATE, PermissionService::CASH_REGISTERS_VIEW, PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE],
            $this->business->id,
        );
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_OUT, '20.00');

        $this->deleteJson($this->transactionUri($register, $transaction))->assertStatus(200);

        $this->assertDatabaseMissing('cash_register_transactions', ['id' => $transaction->id]);
    }

    /** @test */
    public function a_user_without_the_delete_capability_is_rejected(): void
    {
        $user = $this->userWithPermissions([PermissionService::CASH_REGISTERS_CREATE], $this->business->id);
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_OUT, '20.00');

        $this->deleteJson($this->transactionUri($register, $transaction))->assertStatus(403);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $transaction->id]);
    }

/**
     * @test
     *
     * The three movement capabilities are independent decisions.
     *
     * Without this, "the seeder happens to grant all three" would be the only
     * thing keeping them apart, and a future alias collapsing them would pass
     * every other test here.
     */
    public function create_update_and_delete_capabilities_are_not_interchangeable(): void
    {
        $creator = $this->userWithPermissions(
            [PermissionService::CASH_REGISTERS_CREATE, PermissionService::CASH_REGISTERS_MANAGE],
            $this->business->id,
        );
        $register = $this->openRegisterFor($creator);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '10.00');

        // Recording a movement is not authority to change or remove one.
        $this->putJson($this->transactionUri($register, $transaction), ['amount' => 99])->assertStatus(403);
        $this->deleteJson($this->transactionUri($register, $transaction))->assertStatus(403);

        // Correcting one is not authority to remove it. A *fresh* identity, so
        // the two grants are never both held at once.
        $editor = $this->userWithPermissions(
            [
                PermissionService::CASH_REGISTERS_CREATE,
                PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE,
            ],
            $this->business->id,
        );
        $editorRegister = $this->openRegisterFor($editor);
        $editorTransaction = $this->manualTransaction($editorRegister, CashRegisterTransaction::TYPE_CASH_IN, '10.00');

        $this->putJson($this->transactionUri($editorRegister, $editorTransaction), ['amount' => 99])->assertStatus(200);
        $this->deleteJson($this->transactionUri($editorRegister, $editorTransaction))->assertStatus(403);

        // Nor is removing one authority to change it.
        $remover = $this->userWithPermissions(
            [
                PermissionService::CASH_REGISTERS_CREATE,
                PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE,
            ],
            $this->business->id,
        );
        $removerRegister = $this->openRegisterFor($remover);
        $removerTransaction = $this->manualTransaction($removerRegister, CashRegisterTransaction::TYPE_CASH_IN, '10.00');

        $this->putJson($this->transactionUri($removerRegister, $removerTransaction), ['amount' => 99])->assertStatus(403);

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $transaction->id,
            'amount' => '10.00',
        ]);
        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $removerTransaction->id,
            'amount' => '10.00',
        ]);
    }

    // ------------------------------------------------------------------
    // Manual classification
    // ------------------------------------------------------------------

    /** @test */
    public function a_manual_cash_in_can_be_updated_and_deleted(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '30.00');

        $this->putJson($this->transactionUri($register, $transaction), [
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'amount' => 45,
            'note' => 'Corrected change fund',
        ])->assertStatus(200)->assertJsonPath('data.note', 'Corrected change fund');

        $this->deleteJson($this->transactionUri($register, $transaction))->assertStatus(200);

        $this->assertDatabaseMissing('cash_register_transactions', ['id' => $transaction->id]);
    }

    /** @test */
    public function a_manual_cash_out_can_be_updated_and_deleted(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_OUT, '30.00');

        $this->putJson($this->transactionUri($register, $transaction), [
            'type' => CashRegisterTransaction::TYPE_CASH_OUT,
            'amount' => 12.25,
        ])->assertStatus(200)->assertJsonPath('data.amount', '12.25');

        $this->deleteJson($this->transactionUri($register, $transaction))->assertStatus(200);

        $this->assertDatabaseMissing('cash_register_transactions', ['id' => $transaction->id]);
    }

    /** @test */
    public function a_manual_transaction_cannot_become_a_reference_linked_movement(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '30.00');

        // `sale` is not a manual type, so it never validates.
        $this->putJson($this->transactionUri($register, $transaction), ['type' => CashRegisterTransaction::TYPE_SALE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        // Nor may the caller attach a reference by hand: the column is not in the
        // validated payload, so the row keeps its null reference.
        $this->putJson($this->transactionUri($register, $transaction), [
            'reference_type' => Expense::class,
            'reference_id' => 1,
            'business_id' => $this->otherBusiness->id,
            'cash_register_id' => 9999,
        ])->assertStatus(200);

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $transaction->id,
            'business_id' => $this->business->id,
            'cash_register_id' => $register->id,
            'type' => CashRegisterTransaction::TYPE_CASH_IN,
            'reference_type' => null,
            'reference_id' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // Automatic movements
    // ------------------------------------------------------------------

    /** @test */
    public function a_sale_generated_movement_is_refused_even_with_the_update_capability(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user);
        $movement = $this->automaticTransaction($register, CashRegisterTransaction::TYPE_SALE, '120.00');

        $this->putJson($this->transactionUri($register, $movement), ['amount' => 1])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => CashRegisterService::NOT_MANUAL_MESSAGE]);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $movement->id, 'amount' => '120.00']);
    }

    /** @test */
    public function a_sale_generated_movement_is_refused_even_with_the_delete_capability(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user);
        $movement = $this->automaticTransaction($register, CashRegisterTransaction::TYPE_SALE, '120.00');

        $this->deleteJson($this->transactionUri($register, $movement))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => CashRegisterService::NOT_MANUAL_MESSAGE]);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $movement->id]);
    }

    /** @test */
    public function an_expense_generated_movement_cannot_be_updated_or_deleted(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user);
        $movement = $this->automaticTransaction($register, CashRegisterTransaction::TYPE_EXPENSE, '35.00');

        $this->putJson($this->transactionUri($register, $movement), ['amount' => 1])->assertStatus(409);
        $this->deleteJson($this->transactionUri($register, $movement))->assertStatus(409);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $movement->id, 'amount' => '35.00']);
    }

    /** @test */
    public function the_opening_marker_is_not_manual_and_is_protected(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);

        $marker = CashRegisterTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashRegisterTransaction::TYPE_OPENING)
            ->firstOrFail();

        $this->putJson($this->transactionUri($register, $marker), ['amount' => 1])->assertStatus(409);
        $this->deleteJson($this->transactionUri($register, $marker))->assertStatus(409);

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $marker->id,
            'type' => CashRegisterTransaction::TYPE_OPENING,
            'amount' => '100.00',
        ]);
    }

    /**
     * @test
     *
     * Not a hand-planted row: the expense endpoint writes it, which is the path
     * a real installation uses, and it must be just as protected.
     */
    public function a_movement_written_by_the_real_expense_flow_stays_protected(): void
    {
        $user = $this->actingAsBusinessUser($this->business);
        $register = $this->openRegisterFor($user);
        $this->grantCapabilitiesTo($user, [
            PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE,
            PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE,
        ]);

        $this->postJson('/api/v1/expenses', [
            'amount' => 5,
            'expense_date' => now()->toDateString(),
        ])->assertStatus(201);

        $movement = CashRegisterTransaction::query()
            ->where('cash_register_id', $register->id)
            ->where('type', CashRegisterTransaction::TYPE_EXPENSE)
            ->firstOrFail();

        $this->putJson($this->transactionUri($register, $movement), ['amount' => 1])->assertStatus(409);
        $this->deleteJson($this->transactionUri($register, $movement))->assertStatus(409);

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $movement->id,
            'type' => CashRegisterTransaction::TYPE_EXPENSE,
            'amount' => '5.00',
            'reference_type' => Expense::class,
        ]);
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    /** @test */
    public function a_transaction_of_another_business_can_neither_be_updated_nor_deleted(): void
    {
        $foreignUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $foreignRegister = CashRegister::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'opened_by' => $foreignUser->id,
            'status' => CashRegister::STATUS_OPEN,
        ]);
        $foreign = $this->manualTransaction($foreignRegister, CashRegisterTransaction::TYPE_CASH_IN, '25.00');

        $this->userWithPermissions([
            PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE,
            PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE,
        ], $this->business->id);

        $this->putJson($this->transactionUri($foreignRegister, $foreign), ['amount' => 1])->assertStatus(404);
        $this->deleteJson($this->transactionUri($foreignRegister, $foreign))->assertStatus(404);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $foreign->id, 'amount' => '25.00']);
    }

    /** @test */
    public function a_transaction_cannot_be_reached_through_another_cash_register(): void
    {
        $user = $this->actingAsUserWithBoth();
        $mine = $this->openRegisterFor($user);
        $transaction = $this->manualTransaction($mine, CashRegisterTransaction::TYPE_CASH_IN, '40.00');

        // A second drawer the same user owns, so `assertOpenAndOwned()` passes and
        // only the transaction/register pairing can refuse the request. The row
        // exists, belongs to this tenant, and the caller may update and delete —
        // naming the wrong register is the only remaining thing to catch.
        $other = CashRegister::factory()->create([
            'business_id' => $this->business->id,
            'opened_by' => $user->id,
            'status' => CashRegister::STATUS_OPEN,
        ]);

        $this->putJson($this->transactionUri($other, $transaction), ['amount' => 1])->assertStatus(404);
        $this->deleteJson($this->transactionUri($other, $transaction))->assertStatus(404);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $transaction->id, 'amount' => '40.00']);
    }

    // ------------------------------------------------------------------
    // Ledger integrity
    // ------------------------------------------------------------------

    /** @test */
    public function updating_an_amount_moves_the_balance_through_the_server_aggregate(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '20.00');

        $this->assertSame('120.00', $this->balanceOf($register));

        $this->putJson($this->transactionUri($register, $transaction), ['amount' => 60])
            ->assertStatus(200)
            ->assertJsonPath('data.amount', '60.00');

        $this->getJson("/api/v1/cash-registers/{$register->id}")
            ->assertJsonPath('data.current_balance', '160.00')
            ->assertJsonPath('data.transaction_summary.total_in', '60.00');

        $this->getJson("/api/v1/cash-registers/{$register->id}/summary")
            ->assertJsonPath('data.total_in', '60.00')
            ->assertJsonPath('data.current_balance', '160.00');
    }

    /** @test */
    public function flipping_a_cash_in_to_a_cash_out_moves_the_money_the_other_way(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '20.00');

        $this->assertSame('120.00', $this->balanceOf($register));

        $this->putJson($this->transactionUri($register, $transaction), [
            'type' => CashRegisterTransaction::TYPE_CASH_OUT,
        ])->assertStatus(200)->assertJsonPath('data.type', CashRegisterTransaction::TYPE_CASH_OUT);

        $this->getJson("/api/v1/cash-registers/{$register->id}")
            ->assertJsonPath('data.current_balance', '80.00')
            ->assertJsonPath('data.transaction_summary.total_in', '0.00')
            ->assertJsonPath('data.transaction_summary.total_out', '20.00');
    }

    /** @test */
    public function amending_only_the_note_leaves_the_money_untouched(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '20.00');

        $this->putJson($this->transactionUri($register, $transaction), ['note' => 'Recounted'])
            ->assertStatus(200)
            ->assertJsonPath('data.note', 'Recounted');

        $this->assertSame('120.00', $this->balanceOf($register));
    }

    /** @test */
    public function deleting_a_manual_movement_removes_it_from_the_aggregate(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);
        $in = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '30.00');
        $out = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_OUT, '10.00');

        $this->assertSame('120.00', $this->balanceOf($register));

        $this->deleteJson($this->transactionUri($register, $in))->assertStatus(200);
        $this->getJson("/api/v1/cash-registers/{$register->id}")
            ->assertJsonPath('data.current_balance', '90.00')
            ->assertJsonPath('data.transaction_summary.total_in', '0.00');

        $this->deleteJson($this->transactionUri($register, $out))->assertStatus(200);
        $this->getJson("/api/v1/cash-registers/{$register->id}")
            ->assertJsonPath('data.current_balance', '100.00');

        // Only the `opening` marker survives; the history reflects both deletions.
        $this->getJson("/api/v1/cash-registers/{$register->id}/transactions")
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson("/api/v1/cash-registers/{$register->id}/summary")
            ->assertJsonPath('data.transaction_count', 1)
            ->assertJsonPath('data.current_balance', '100.00');
    }

    /**
     * @test
     *
     * A refused write must leave the row exactly as it was.
     *
     * The service raises before saving, so a rejection cannot half-apply. This
     * pins that for a validation failure and a business-rule refusal alike, and
     * checks the balance afterwards rather than only the row.
     */
    public function a_rejected_write_changes_neither_the_transaction_nor_the_balance(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);
        $movement = $this->automaticTransaction($register, CashRegisterTransaction::TYPE_EXPENSE, '40.00');

        $before = $this->balanceOf($register);

        $this->putJson($this->transactionUri($register, $movement), ['amount' => 5])->assertStatus(409);
        $this->putJson($this->transactionUri($register, $movement), ['amount' => -5])->assertStatus(422);
        $this->deleteJson($this->transactionUri($register, $movement))->assertStatus(409);

        $this->assertDatabaseHas('cash_register_transactions', [
            'id' => $movement->id,
            'amount' => '40.00',
            'type' => CashRegisterTransaction::TYPE_EXPENSE,
        ]);
        $this->assertSame($before, $this->balanceOf($register));
    }

    /** @test */
    public function a_closed_register_refuses_updates_and_deletes(): void
    {
        $user = $this->actingAsUserWithBoth();
        $register = $this->openRegisterFor($user, 100.0);
        $transaction = $this->manualTransaction($register, CashRegisterTransaction::TYPE_CASH_IN, '10.00');

        $this->postJson("/api/v1/cash-registers/{$register->id}/close", ['closing_balance' => 110])
            ->assertStatus(200);

        // The shift is counted: its figures are persisted, so rewriting a row
        // behind them would leave the closing record contradicting the ledger.
        $this->putJson($this->transactionUri($register, $transaction), ['amount' => 999])->assertStatus(409);
        $this->deleteJson($this->transactionUri($register, $transaction))->assertStatus(409);

        $this->assertDatabaseHas('cash_register_transactions', ['id' => $transaction->id, 'amount' => '10.00']);
    }

    /** @test */
    public function the_update_and_delete_routes_require_authentication(): void
    {
        $this->flushHeaders();

        $this->putJson('/api/v1/cash-registers/1/transactions/1', ['amount' => 1])->assertStatus(401);
        $this->deleteJson('/api/v1/cash-registers/1/transactions/1')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Authenticate as a user holding both new capabilities. */
    private function actingAsUserWithBoth(): User
    {
        return $this->userWithPermissions([
            PermissionService::CASH_REGISTERS_CREATE,
            PermissionService::CASH_REGISTERS_VIEW,
            PermissionService::CASH_REGISTERS_UPDATE,
            PermissionService::CASH_REGISTERS_TRANSACTIONS_UPDATE,
            PermissionService::CASH_REGISTERS_TRANSACTIONS_DELETE,
        ], $this->business->id);
    }

    /**
     * Open a register as `$user` and authenticate as them.
     *
     * Registers are per-user (`openQuery()` scopes on `opened_by`) and
     * `assertOpenAndOwned()` enforces it, so every identity that is to act on a
     * drawer has to have opened that drawer itself.
     */
    private function openRegisterFor(User $user, float $opening = 0.0): CashRegister
    {
        $this->authenticate($user->fresh());

        $response = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => $opening]);
        $response->assertStatus(201);

        return CashRegister::findOrFail($response->json('data.id'));
    }

    /** A cash in / cash out written the way `recordManual()` writes one. */
    private function manualTransaction(CashRegister $register, string $type, string $amount): CashRegisterTransaction
    {
        return CashRegisterTransaction::factory()->create([
            'business_id' => $register->business_id,
            'cash_register_id' => $register->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => null,
            'reference_id' => null,
            'created_by' => $register->opened_by,
        ]);
    }

    /** A movement another flow wrote: an automatic type *and* a reference. */
    private function automaticTransaction(CashRegister $register, string $type, string $amount): CashRegisterTransaction
    {
        return CashRegisterTransaction::factory()->create([
            'business_id' => $register->business_id,
            'cash_register_id' => $register->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $type === CashRegisterTransaction::TYPE_EXPENSE
                ? Expense::class
                : Payment::class,
            'reference_id' => 1,
            'created_by' => $register->opened_by,
        ]);
    }

    /** Add capabilities to the role the given user already holds. */
    private function grantCapabilitiesTo(User $user, array $capabilities): void
    {
        $user->roles()->first()->permissions()->syncWithoutDetaching(
            $this->permissionsBySlug($capabilities)->pluck('id')->all()
        );

        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->authenticate($user->fresh());
    }

    /** The balance the API itself reports for a register. */
    private function balanceOf(CashRegister $register): string
    {
        return (string) $this->getJson("/api/v1/cash-registers/{$register->id}")
            ->assertStatus(200)
            ->json('data.current_balance');
    }

    private function transactionUri(CashRegister $register, CashRegisterTransaction $transaction): string
    {
        return "/api/v1/cash-registers/{$register->id}/transactions/{$transaction->id}";
    }
}
