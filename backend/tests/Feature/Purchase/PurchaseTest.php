<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Supplier $supplier;
    protected Product $product;
    protected Product $product2;
    protected Business $otherBusiness;
    protected User $otherUser;
    protected Supplier $otherSupplier;
    protected Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create(['business_id' => $this->business->id]);
        $this->product2 = Product::factory()->create(['business_id' => $this->business->id]);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherSupplier = Supplier::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherProduct = Product::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100],
            ],
        ], $over);
    }

    /** @test */
    public function purchase_endpoints_require_authentication(): void
    {
        $this->flushHeaders();
        $routes = [['GET', '/api/v1/purchases'], ['POST', '/api/v1/purchases'], ['GET', '/api/v1/purchases/1']];
        foreach ($routes as [$m, $u]) {
            $this->json($m, $u, ['supplier_id' => 1])->assertStatus(401);
        }
    }

    /** @test */
    public function can_list_purchases_scoped(): void
    {
        $this->postJson('/api/v1/purchases', $this->payload())->assertStatus(201);
        Purchase::factory()->create(['business_id' => $this->otherBusiness->id, 'supplier_id' => $this->otherSupplier->id]);
        $r = $this->getJson('/api/v1/purchases');
        $r->assertStatus(200);
        $this->assertSame(1, $r->json('data.pagination.total'));
    }

    /** @test */
    public function create_calculates_totals_server_side(): void
    {
        $r = $this->postJson('/api/v1/purchases', $this->payload([
            'discount' => 50, 'tax' => 10, 'paid_amount' => 100,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 100.50],
                ['product_id' => $this->product2->id, 'quantity' => 3, 'unit_cost' => 50, 'discount' => 10, 'tax' => 5],
            ],
        ]));
        $r->assertStatus(201);
        $r->assertJson(['data' => ['subtotal' => '346.00', 'grand_total' => '306.00', 'due_amount' => '206.00', 'payment_status' => 'partial']]);
    }

    /** @test */
    public function create_posts_stock_movements(): void
    {
        $r = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 10],
                ['product_id' => $this->product2->id, 'quantity' => 2.5, 'unit_cost' => 20],
            ],
        ]));
        $r->assertStatus(201);
        $pid = $r->json('data.id');
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => Purchase::class, 'reference_id' => $pid, 'type' => 'purchase']);
        $p = $this->getJson('/api/v1/products/'.$this->product->id);
        $this->assertSame('5', $p->json('data.current_stock'));
    }

    /** @test */
    public function failure_rolls_back(): void
    {
        $r = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 10],
                ['product_id' => $this->otherProduct->id, 'quantity' => 1, 'unit_cost' => 10],
            ],
        ]));
        $r->assertStatus(422);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @test */
    public function validation_rejects_bad(): void
    {
        $this->postJson('/api/v1/purchases', ['purchase_date' => now()->toDateString()])->assertStatus(422);
        $this->postJson('/api/v1/purchases', $this->payload(['items' => []]))->assertStatus(422);
        $this->postJson('/api/v1/purchases', $this->payload(['supplier_id' => $this->otherSupplier->id]))->assertStatus(422);
        $this->assertDatabaseCount('purchases', 0);
    }

    /** @test */
    public function show_and_isolation(): void
    {
        $id = $this->postJson('/api/v1/purchases', $this->payload())->json('data.id');
        $r = $this->getJson('/api/v1/purchases/'.$id);
        $r->assertStatus(200)->assertJsonStructure(['data' => ['id', 'supplier', 'items']]);
        $foreign = Purchase::factory()->create(['business_id' => $this->otherBusiness->id, 'supplier_id' => $this->otherSupplier->id]);
        $this->getJson('/api/v1/purchases/'.$foreign->id)->assertStatus(404);
        $this->deleteJson('/api/v1/purchases/'.$foreign->id)->assertStatus(404);
    }

    /** @test */
    public function update_guards_and_delete_guards(): void
    {
        $id = $this->postJson('/api/v1/purchases', $this->payload())->json('data.id');
        $this->putJson('/api/v1/purchases/'.$id, ['notes' => 'hi'])->assertStatus(200);
        $this->putJson('/api/v1/purchases/'.$id, ['items' => [['product_id' => $this->product->id, 'quantity' => 99, 'unit_cost' => 1]]])->assertStatus(409);
        $this->putJson('/api/v1/purchases/'.$id, ['grand_total' => 9999])->assertStatus(409);
        $this->deleteJson('/api/v1/purchases/'.$id)->assertStatus(409);
        $empty = Purchase::factory()->create(['business_id' => $this->business->id, 'supplier_id' => $this->supplier->id]);
        $this->deleteJson('/api/v1/purchases/'.$empty->id)->assertStatus(200);
    }

    /** @test */
    public function paid_update_recomputes(): void
    {
        $id = $this->postJson('/api/v1/purchases', $this->payload())->json('data.id');
        $r = $this->patchJson('/api/v1/purchases/'.$id, ['paid_amount' => 500]);
        $r->assertStatus(200)->assertJson(['data' => ['payment_status' => 'paid']]);
    }
}
