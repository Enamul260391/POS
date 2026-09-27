<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class ExpenseTest extends TestCase
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
    public function category_crud_is_authenticated_scoped_and_validated(): void
    {
        $this->flushHeaders();
        $this->getJson('/api/v1/expense-categories')->assertStatus(401);
        $this->actingAsBusinessUser($this->business);
        $created = $this->postJson('/api/v1/expense-categories', ['name' => 'Rent']);
        $created->assertStatus(201)->assertJsonPath('data.name', 'Rent');
        $id = $created->json('data.id');
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent'])->assertStatus(422);
        $this->patchJson('/api/v1/expense-categories/'.$id, ['status' => 'inactive'])->assertStatus(200);

        $foreign = ExpenseCategory::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->getJson('/api/v1/expense-categories/'.$foreign->id)->assertStatus(404);
        $this->deleteJson('/api/v1/expense-categories/'.$foreign->id)->assertStatus(404);
        $this->deleteJson('/api/v1/expense-categories/'.$id)->assertStatus(200);
        $this->assertSoftDeleted('expense_categories', ['id' => $id]);
    }

    /** @test */
    public function category_list_filters_and_refuses_to_delete_referenced_history(): void
    {
        $rent = ExpenseCategory::factory()->create(['business_id' => $this->business->id, 'name' => 'Office rent']);
        ExpenseCategory::factory()->create(['business_id' => $this->business->id, 'name' => 'Office internet', 'status' => 'inactive']);
        ExpenseCategory::factory()->create(['business_id' => $this->otherBusiness->id, 'name' => 'Foreign office']);
        Expense::factory()->create(['business_id' => $this->business->id, 'expense_category_id' => $rent->id]);

        $this->getJson('/api/v1/expense-categories?search=office&status=active&per_page=1')
            ->assertStatus(200)->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.name', 'Office rent');
        $this->deleteJson('/api/v1/expense-categories/'.$rent->id)->assertStatus(409);
        $this->assertDatabaseHas('expenses', ['expense_category_id' => $rent->id]);
    }

    /** @test */
    public function expenses_validate_category_ownership_and_ignore_business_id(): void
    {
        $foreignCategory = ExpenseCategory::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->postJson('/api/v1/expenses', ['amount' => 10, 'expense_date' => now()->toDateString(), 'expense_category_id' => $foreignCategory->id])->assertStatus(422);
        $this->postJson('/api/v1/expenses', ['amount' => 0, 'expense_date' => 'bad'])->assertStatus(422);
        $response = $this->postJson('/api/v1/expenses', ['amount' => '12.50', 'expense_date' => now()->toDateString(), 'business_id' => $this->otherBusiness->id]);
        $response->assertStatus(201)->assertJsonPath('data.amount', '12.50');
        $this->assertDatabaseHas('expenses', ['id' => $response->json('data.id'), 'business_id' => $this->business->id]);
    }

    /** @test */
    public function expenses_can_be_listed_filtered_updated_and_safely_deleted(): void
    {
        $category = ExpenseCategory::factory()->create(['business_id' => $this->business->id]);
        $expense = Expense::factory()->create(['business_id' => $this->business->id, 'expense_category_id' => $category->id, 'expense_date' => '2026-09-10', 'payment_method' => 'card', 'reference' => 'CARD-10']);
        Expense::factory()->create(['business_id' => $this->business->id, 'expense_date' => '2026-10-01']);
        Expense::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->getJson('/api/v1/expenses?expense_category_id='.$category->id.'&date_from=2026-09-01&date_to=2026-09-30&search=CARD')->assertStatus(200)->assertJsonPath('data.pagination.total', 1);
        $this->patchJson('/api/v1/expenses/'.$expense->id, ['description' => 'Updated'])->assertStatus(200);
        $this->deleteJson('/api/v1/expenses/'.$expense->id)->assertStatus(200);
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }


    /** @test */
    public function cash_expense_is_posted_once_and_then_financial_history_is_immutable(): void
    {
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => 100])->assertStatus(201);
        $expense = $this->postJson('/api/v1/expenses', ['amount' => 25, 'expense_date' => now()->toDateString(), 'payment_method' => 'cash'])->assertStatus(201);
        $this->getJson('/api/v1/cash-registers/current')->assertJsonPath('data.current_balance', '75.00');
        $this->patchJson('/api/v1/expenses/'.$expense->json('data.id'), ['amount' => 30])->assertStatus(409);
        $this->deleteJson('/api/v1/expenses/'.$expense->json('data.id'))->assertStatus(409);
        $this->assertDatabaseHas('expenses', ['id' => $expense->json('data.id'), 'amount' => '25.00']);
    }

    /** @test */
    public function foreign_expenses_are_not_visible_or_mutable(): void
    {
        $foreign = Expense::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->getJson('/api/v1/expenses/'.$foreign->id)->assertStatus(404);
        $this->patchJson('/api/v1/expenses/'.$foreign->id, ['description' => 'hijack'])->assertStatus(404);
        $this->deleteJson('/api/v1/expenses/'.$foreign->id)->assertStatus(404);
    }
}

