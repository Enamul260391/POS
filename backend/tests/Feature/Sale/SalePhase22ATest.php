<?php

declare(strict_types=1);

namespace Tests\Feature\Sale;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class SalePhase22ATest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Customer $customer;

    protected Product $product;

    protected Product $product2;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected Customer $otherCustomer;

    protected Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '500.00']);
        $this->product2 = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '100.00']);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherCustomer = Customer::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherProduct = Product::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->stock($this->product, '50');
        $this->stock($this->product2, '50');
    }

    private function stock(Product $p, string $qty): void
    {
        StockMovement::create(['business_id' => $p->business_id, 'product_id' => $p->id, 'type' => 'receive', 'quantity' => $qty, 'created_by' => $this->user->id]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500]],
        ], $over);
    }

    /** @test */
    public function validation_failures_return_422_envelope(): void
    {
        $this->postJson('/api/v1/sales', $this->payload(['items' => []]))->assertStatus(422);
        $this->postJson('/api/v1/sales', ['sale_date' => now()->toDateString()])->assertStatus(422);
        $this->postJson('/api/v1/sales', $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 0, 'unit_price' => 10]]]))->assertStatus(422);
        $this->postJson('/api/v1/sales', $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => -5]]]))->assertStatus(422);
        $r = $this->postJson('/api/v1/sales', $this->payload(['items' => []]));
        $r->assertJson(['success' => false]);
        $this->assertArrayHasKey('errors', $r->json());
        $this->assertDatabaseCount('sales', 0);
    }
}
