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

class SalePhase22BTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Customer $customer;

    protected Product $product;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '500.00']);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherProduct = Product::factory()->create(['business_id' => $this->otherBusiness->id]);
        StockMovement::create(['business_id' => $this->product->business_id, 'product_id' => $this->product->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
    }

    /** @test */
    public function product_ownership_and_totals_are_server_side(): void
    {
        $payload = ['customer_id' => $this->customer->id, 'sale_date' => now()->toDateString()];
        $this->postJson('/api/v1/sales', array_merge($payload, [
            'items' => [['product_id' => $this->otherProduct->id, 'quantity' => 1, 'unit_price' => 10]],
        ]))->assertStatus(422);
        $this->assertDatabaseCount('sales', 0);

        $r = $this->postJson('/api/v1/sales', array_merge($payload, [
            'business_id' => $this->otherBusiness->id,
            'subtotal' => '1.00',
            'grand_total' => '1.00',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500]],
        ]));
        $r->assertStatus(201);
        $r->assertJson(['data' => ['subtotal' => '1000.00', 'grand_total' => '1000.00']]);
        $this->assertSame($this->business->id, Sale::findOrFail($r->json('data.id'))->business_id);
    }

    /** @test */
    public function list_pagination_search_and_customer_filter(): void
    {
        $base = ['customer_id' => $this->customer->id, 'sale_date' => now()->toDateString()];
        $this->postJson('/api/v1/sales', array_merge($base, ['invoice_number' => 'INV-ALPHA-1', 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500]]]))->assertStatus(201);
        $this->postJson('/api/v1/sales', array_merge($base, ['invoice_number' => 'INV-BETA-2', 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500]]]))->assertStatus(201);
        Sale::factory()->create(['business_id' => $this->otherBusiness->id]);

        $page = $this->getJson('/api/v1/sales?per_page=1&page=2');
        $page->assertStatus(200)->assertJsonStructure(['data' => ['items', 'pagination']]);
        $this->assertSame(2, $page->json('data.pagination.total'));

        $search = $this->getJson('/api/v1/sales?search=ALPHA');
        $this->assertSame(1, $search->json('data.pagination.total'));

        $other = Customer::factory()->create(['business_id' => $this->business->id]);
        $this->postJson('/api/v1/sales', ['customer_id' => $other->id, 'sale_date' => now()->toDateString(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500]]])->assertStatus(201);
        $this->assertSame(2, $this->getJson('/api/v1/sales?customer_id='.$this->customer->id)->json('data.pagination.total'));
        $this->getJson('/api/v1/sales?status=completed')->assertStatus(200);
        $this->getJson('/api/v1/sales?sort=nonexistent_column')->assertStatus(422);
    }

    /** @test */
    public function insufficient_stock_rejects_atomically(): void
    {
        $before = StockMovement::count();
        $r = $this->postJson('/api/v1/sales', [
            'customer_id' => $this->customer->id, 'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 9999, 'unit_price' => 500]],
        ]);
        $r->assertStatus(409)->assertJson(['success' => false]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertSame($before, StockMovement::count());
    }
}
