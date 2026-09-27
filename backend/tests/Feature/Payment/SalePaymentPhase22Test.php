<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Business;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class SalePaymentPhase22Test extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Product $product;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '500.00']);
        StockMovement::create(['business_id' => $this->product->business_id, 'product_id' => $this->product->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    /** @test */
    public function payment_validation_overpayment_and_sync(): void
    {
        $id = (int) $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500]],
        ])->json('data.id');

        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 0, 'payment_method' => 'cash'])->assertStatus(422);
        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 10, 'payment_method' => 'bitcoin'])->assertStatus(422);

        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 250, 'payment_method' => 'cash'])->assertStatus(201);
        $this->getJson('/api/v1/sales/'.$id)->assertJson(['data' => ['paid_amount' => '250.00', 'due_amount' => '750.00', 'payment_status' => 'partial']]);

        $before = Payment::where('sale_id', $id)->count();
        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 9999, 'payment_method' => 'cash'])->assertStatus(409);
        $this->assertSame($before, Payment::where('sale_id', $id)->count());
        $this->getJson('/api/v1/sales/'.$id)->assertJson(['data' => ['paid_amount' => '250.00', 'due_amount' => '750.00']]);

        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 750, 'payment_method' => 'card'])->assertStatus(201);
        $this->getJson('/api/v1/sales/'.$id)->assertJson(['data' => ['payment_status' => 'paid']]);
        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 1, 'payment_method' => 'cash'])->assertStatus(409);

        $foreign = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->postJson('/api/v1/sales/'.$foreign->id.'/payments', ['amount' => 10, 'payment_method' => 'cash'])->assertStatus(404);
    }
}
