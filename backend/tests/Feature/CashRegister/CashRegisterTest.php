<?php

declare(strict_types=1);

namespace Tests\Feature\CashRegister;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    private Business $business;
    private User $user;
    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->otherBusiness = Business::factory()->create();
    }

    /** @test */
    public function endpoints_require_authentication(): void
    {
        $this->flushHeaders();
        foreach (['/api/v1/cash-registers/current', '/api/v1/cash-registers/1', '/api/v1/cash-registers/1/summary'] as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 0])->assertStatus(401);
        $this->postJson('/api/v1/cash-registers/1/transactions', ['type' => 'cash_in', 'amount' => 1])->assertStatus(401);
    }

    /** @test */
    public function open_current_manual_transactions_and_close_are_atomic_and_derived_from_ledger(): void
    {
        $opened = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 100]);
        $opened->assertStatus(201)->assertJsonPath('data.current_balance', '100.00');
        $id = $opened->json('data.id');
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 200])->assertStatus(409);

        $this->getJson('/api/v1/cash-registers/current')->assertStatus(200)->assertJsonPath('data.id', $id);
        $this->postJson("/api/v1/cash-registers/{$id}/transactions", ['type' => 'cash_in', 'amount' => 20])->assertStatus(201);
        $this->postJson("/api/v1/cash-registers/{$id}/transactions", ['type' => 'cash_out', 'amount' => 15])->assertStatus(201);
        $this->postJson("/api/v1/cash-registers/{$id}/transactions", ['type' => 'sale', 'amount' => 1])->assertStatus(422);
        $this->getJson("/api/v1/cash-registers/{$id}")->assertJsonPath('data.current_balance', '105.00');
        $this->getJson("/api/v1/cash-registers/{$id}/transactions")->assertJsonPath('data.pagination.total', 3);

        $closed = $this->postJson("/api/v1/cash-registers/{$id}/close", ['closing_balance' => 100]);
        $closed->assertStatus(200)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_balance', '105.00')
            ->assertJsonPath('data.closing_balance', '100.00')
            ->assertJsonPath('data.difference', '-5.00');
        $this->postJson("/api/v1/cash-registers/{$id}/close", ['closing_balance' => 100])->assertStatus(409);
        $this->getJson('/api/v1/cash-registers/current')->assertStatus(404);
    }

    /** @test */
    public function expense_and_sale_cash_documents_reuse_automatic_posting(): void
    {
        $register = $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 50])->json('data.id');
        $this->postJson('/api/v1/expenses', ['amount' => 5, 'expense_date' => now()->toDateString()])->assertStatus(201);
        $this->assertDatabaseHas('cash_register_transactions', ['cash_register_id' => $register, 'type' => CashRegisterTransaction::TYPE_EXPENSE, 'reference_type' => Expense::class]);
    }

    /** @test */
    public function cross_business_register_history_is_never_exposed(): void
    {
        $foreignUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $foreign = CashRegister::factory()->create(['business_id' => $this->otherBusiness->id, 'opened_by' => $foreignUser->id, 'status' => CashRegister::STATUS_OPEN]);

        $this->getJson('/api/v1/cash-registers/'.$foreign->id)->assertStatus(404);
        $this->getJson('/api/v1/cash-registers/'.$foreign->id.'/transactions')->assertStatus(404);
        $this->postJson('/api/v1/cash-registers/'.$foreign->id.'/transactions', ['type' => 'cash_in', 'amount' => 1])->assertStatus(404);
        $this->postJson('/api/v1/cash-registers/'.$foreign->id.'/close', ['closing_balance' => 0])->assertStatus(404);
    }

    /** @test */
    public function opening_marker_failure_rolls_back_register_and_transaction(): void
    {
        Event::listen('eloquent.creating: '.CashRegisterTransaction::class, static function (): void {
            throw new RuntimeException('forced test failure');
        });

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 100])->assertStatus(500);
        $this->assertDatabaseCount('cash_registers', 0);
        $this->assertDatabaseCount('cash_register_transactions', 0);
    }
}
