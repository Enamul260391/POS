<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class SalePaymentTest extends TestCase
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
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '200.00']);
        StockMovement::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    private function saleId(): int
    {
        return (int) $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 200]],
        ])->json('data.id');
    }

    /** @test */
    public function payment_flow(): void
    {
        $this->flushHeaders();
        $this->postJson('/api/v1/sales/1/payments', ['amount' => 100, 'payment_method' => 'cash'])->assertStatus(401);
        $this->actingAsBusinessUser($this->business);
        $id = $this->saleId();
        $r = $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 100, 'payment_method' => 'cash']);
        $r->assertStatus(201);
        $d = $this->getJson('/api/v1/sales/'.$id);
        $d->assertJson(['data' => ['paid_amount' => '100.00', 'due_amount' => '300.00', 'payment_status' => 'partial']]);
        $this->assertCount(1, $d->json('data.payments'));
        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 9999, 'payment_method' => 'cash'])->assertStatus(409);
        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 0, 'payment_method' => 'cash'])->assertStatus(422);
        $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 10, 'payment_method' => 'gold'])->assertStatus(422);
        $r2 = $this->postJson('/api/v1/sales/'.$id.'/payments', ['amount' => 300, 'payment_method' => 'card']);
        $r2->assertStatus(201);
        $this->assertSame('paid', $this->getJson('/api/v1/sales/'.$id)->json('data.payment_status'));
        $foreign = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->postJson('/api/v1/sales/'.$foreign->id.'/payments', ['amount' => 10, 'payment_method' => 'cash'])->assertStatus(404);
    }
}
