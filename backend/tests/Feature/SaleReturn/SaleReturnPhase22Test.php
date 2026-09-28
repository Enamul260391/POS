<?php

declare(strict_types=1);

namespace Tests\Feature\SaleReturn;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class SaleReturnPhase22Test extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Product $product;

    protected Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '100.00']);
        StockMovement::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
        $this->otherBusiness = Business::factory()->create();
    }

    /** @test */
    public function return_guards_restore_stock_transactionally(): void
    {
        $returnsBefore = SaleReturn::count();
        $sale = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]],
        ])->json('data');
        $itemId = (int) $sale['items'][0]['id'];

        $otherSale = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->json('data');
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [['sale_item_id' => (int) $otherSale['items'][0]['id'], 'quantity' => 1]],
        ])->assertStatus(409);
        $this->assertSame($returnsBefore, SaleReturn::count());

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [
                ['sale_item_id' => $itemId, 'quantity' => 1],
                ['sale_item_id' => $itemId, 'quantity' => 1],
            ],
        ])->assertStatus(409);
        $this->assertSame($returnsBefore, SaleReturn::count());

        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [['sale_item_id' => $itemId, 'quantity' => 0]],
        ])->assertStatus(422);

        $foreign = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $foreign->id,
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
        ])->assertStatus(404);

        $stockBefore = $this->getJson('/api/v1/products/'.$this->product->id)->json('data.current_stock');
        $ret = $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [['sale_item_id' => $itemId, 'quantity' => 4]],
        ]);
        $ret->assertStatus(201)->assertJson(['data' => ['refund_amount' => '400.00']]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => SaleReturn::class,
            'reference_id' => $ret->json('data.id'),
            'type' => 'sale_return',
            'quantity' => '4',
        ]);
        $stockAfter = $this->getJson('/api/v1/products/'.$this->product->id)->json('data.current_stock');
        $this->assertSame('4', \App\Support\Quantity::subtract($stockAfter, $stockBefore));

        $this->putJson('/api/v1/sale-returns/'.$ret->json('data.id'), ['reason' => 'x'])->assertStatus(405);
        $this->deleteJson('/api/v1/sale-returns/'.$ret->json('data.id'))->assertStatus(405);
    }

    /** @test */
    public function tenant_b_isolated_from_sales_payments_returns(): void
    {
        $sale = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->json('data');
        $bUser = \App\Models\User::factory()->create(['business_id' => $this->otherBusiness->id]);
        // Tenant B is fully authorized *in its own business*: it holds every
        // capability. The 404 asserted below must therefore come from tenant
        // scoping, not from a missing permission — a 403 here would prove
        // nothing about isolation.
        $this->grantAllCapabilities($bUser);
        \Laravel\Sanctum\Sanctum::actingAs($bUser);

        $this->getJson('/api/v1/sales/'.$sale['id'])->assertStatus(404);
        $this->getJson('/api/v1/sales')->assertJson(['data' => ['pagination' => ['total' => 0]]]);
        $this->postJson('/api/v1/sales/'.$sale['id'].'/payments', ['amount' => 10, 'payment_method' => 'cash'])->assertStatus(404);
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale['id'],
            'items' => [['sale_item_id' => (int) $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertStatus(404);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('sale_returns', 0);
    }
}
