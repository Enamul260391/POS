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

class SalePhase22CTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Customer $customer;

    protected Product $product;

    protected Product $product2;

    protected Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '500.00']);
        $this->product2 = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '100.00']);
        $this->otherBusiness = Business::factory()->create();
        StockMovement::create(['business_id' => $this->product->business_id, 'product_id' => $this->product->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
        StockMovement::create(['business_id' => $this->product2->business_id, 'product_id' => $this->product2->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
    }

    /** @test */
    public function movements_traceable_and_update_delete_guarded(): void
    {
        $r = $this->postJson('/api/v1/sales', [
            'customer_id' => $this->customer->id, 'sale_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500],
                ['product_id' => $this->product2->id, 'quantity' => 3, 'unit_price' => 100],
            ],
        ]);
        $r->assertStatus(201);
        $id = (int) $r->json('data.id');
        $this->assertDatabaseCount('sale_items', 2);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => Sale::class, 'reference_id' => $id, 'type' => 'sale', 'product_id' => $this->product->id, 'quantity' => '-2']);
        $this->assertSame('48', $this->getJson('/api/v1/products/'.$this->product->id)->json('data.current_stock'));

        $this->putJson('/api/v1/sales/'.$id, ['notes' => 'counter 3'])->assertStatus(200);
        $this->patchJson('/api/v1/sales/'.$id, ['sale_date' => now()->toDateString()])->assertStatus(200);
        $this->putJson('/api/v1/sales/'.$id, ['discount' => 10])->assertStatus(409);
        $this->patchJson('/api/v1/sales/'.$id, ['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500]]])->assertStatus(409);
        $foreign = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->putJson('/api/v1/sales/'.$foreign->id, ['notes' => 'x'])->assertStatus(404);
        $this->deleteJson('/api/v1/sales/'.$foreign->id)->assertStatus(404);
        $this->deleteJson('/api/v1/sales/'.$id)->assertStatus(409);
    }
}
