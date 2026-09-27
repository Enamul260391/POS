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

class SaleTest extends TestCase
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
    public function auth_required(): void
    {
        $this->flushHeaders();
        $this->getJson('/api/v1/sales')->assertStatus(401);
        $this->postJson('/api/v1/sales', [])->assertStatus(401);
        $this->getJson('/api/v1/sales/1')->assertStatus(401);
    }

    /** @test */
    public function create_totals_and_stock_out(): void
    {
        $r = $this->postJson('/api/v1/sales', $this->payload([
            'discount' => 100, 'tax' => 50, 'paid_amount' => 200,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500],
                ['product_id' => $this->product2->id, 'quantity' => 3, 'unit_price' => 100, 'discount' => 10, 'tax' => 5],
            ],
        ]));
        $r->assertStatus(201);
        $r->assertJson(['data' => ['subtotal' => '1295.00', 'grand_total' => '1245.00', 'due_amount' => '1045.00']]);
        $p = $this->getJson('/api/v1/products/'.$this->product->id);
        $this->assertSame('48', $p->json('data.current_stock'));
    }

    /** @test */
    public function price_defaults_and_guards(): void
    {
        $r = $this->postJson('/api/v1/sales', ['sale_date' => now()->toDateString(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);
        $r->assertStatus(201);
        $this->assertSame('500.00', $r->json('data.items.0.unit_price'));
        $bad = $this->postJson('/api/v1/sales', $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 999, 'unit_price' => 10]]]));
        $bad->assertStatus(409);
        $this->assertDatabaseCount('sales', 1);
        $this->postJson('/api/v1/sales', $this->payload(['customer_id' => $this->otherCustomer->id]))->assertStatus(422);
        $w = $this->postJson('/api/v1/sales', ['sale_date' => now()->toDateString(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10]]]);
        $w->assertStatus(201);
        $this->assertNull($w->json('data.customer_id'));
    }

    /** @test */
    public function list_show_update_delete(): void
    {
        $id = $this->postJson('/api/v1/sales', $this->payload(['invoice_number' => 'S-1']))->json('data.id');
        \App\Models\Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->assertSame(1, $this->getJson('/api/v1/sales')->json('data.pagination.total'));
        $this->getJson('/api/v1/sales/'.$id)->assertStatus(200)->assertJsonStructure(['data' => ['id', 'items', 'payments']]);
        $f = \App\Models\Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->getJson('/api/v1/sales/'.$f->id)->assertStatus(404);
        $this->putJson('/api/v1/sales/'.$id, ['notes' => 'ok'])->assertStatus(200);
        $this->putJson('/api/v1/sales/'.$id, ['items' => [['product_id' => $this->product->id, 'quantity' => 9]]])->assertStatus(409);
        $this->deleteJson('/api/v1/sales/'.$id)->assertStatus(409);
        $empty = \App\Models\Sale::factory()->create(['business_id' => $this->business->id]);
        $this->deleteJson('/api/v1/sales/'.$empty->id)->assertStatus(200);
    }

}
