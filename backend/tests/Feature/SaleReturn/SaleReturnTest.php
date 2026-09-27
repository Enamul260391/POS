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

class SaleReturnTest extends TestCase
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
        $this->product = Product::factory()->create(['business_id' => $this->business->id, 'selling_price' => '100.00']);
        StockMovement::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'type' => 'receive', 'quantity' => '50', 'created_by' => $this->user->id]);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    private function sale(): array
    {
        $r = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $r->assertStatus(201);

        return $r->json('data');
    }

    /** @test */
    public function return_flow(): void
    {
        $this->flushHeaders();
        $this->postJson('/api/v1/sale-returns', [])->assertStatus(401);
        $this->actingAsBusinessUser($this->business);
        $sale = $this->sale();
        $saleItemId = $sale['items'][0]['id'];
        // partial 3
        $r = $this->postJson('/api/v1/sale-returns', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $saleItemId, 'quantity' => 3]]]);
        $r->assertStatus(201);
        $this->assertSame('300.00', $r->json('data.refund_amount'));
        // stock 50-10+3=43
        $p = $this->getJson('/api/v1/products/'.$this->product->id);
        $this->assertSame('43', $p->json('data.current_stock'));
        // over-return 8 > remaining 7 rejected
        $this->postJson('/api/v1/sale-returns', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $saleItemId, 'quantity' => 8]]])->assertStatus(409);
        // full remaining 7 ok
        $this->postJson('/api/v1/sale-returns', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $saleItemId, 'quantity' => 7]]])->assertStatus(201);
        $this->assertSame('50', $this->getJson('/api/v1/products/'.$this->product->id)->json('data.current_stock'));
        // duplicate after full rejected
        $this->postJson('/api/v1/sale-returns', ['sale_id' => $sale['id'], 'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1]]])->assertStatus(409);
        // foreign sale blocked
        $foreign = Sale::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->postJson('/api/v1/sale-returns', ['sale_id' => $foreign->id, 'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1]]])->assertStatus(404);
        // list/show scoped
        $this->assertSame(2, $this->getJson('/api/v1/sale-returns')->json('data.pagination.total'));
        $this->getJson('/api/v1/sale-returns/'.$r->json('data.id'))->assertStatus(200);
        $fr = SaleReturn::factory()->create(['business_id' => $this->otherBusiness->id, 'sale_id' => $foreign->id]);
        $this->getJson('/api/v1/sale-returns/'.$fr->id)->assertStatus(404);
    }
}
