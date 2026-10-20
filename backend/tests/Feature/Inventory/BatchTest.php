<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Business;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class BatchTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Supplier $supplier;
    protected Product $stockProduct;
    protected Product $serviceProduct;
    protected Business $otherBusiness;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $this->stockProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => 'pcs',
        ]);
        $this->serviceProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => Product::UNIT_SERVICE,
        ]);
        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $this->stockProduct->id,
                    'quantity' => 5,
                    'unit_cost' => 100,
                    'mrp' => 160,
                    'selling_price' => 150,
                    'selling_code' => 'SKU-001',
                ],
            ],
        ], $over);
    }

    /** @test */
    public function purchase_creates_exactly_one_batch_per_eligible_item(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload());
        $response->assertStatus(201);

        $purchaseId = $response->json('data.id');
        $purchaseItem = PurchaseItem::where('purchase_id', $purchaseId)->first();

        $this->assertNotNull($purchaseItem);
        $this->assertDatabaseCount('inventory_batches', 1);
        $this->assertDatabaseHas('inventory_batches', [
            'purchase_item_id' => $purchaseItem->id,
            'product_id' => $this->stockProduct->id,
            'business_id' => $this->business->id,
            'batch_number' => 'BATCH-'.$purchaseItem->id,
            'received_quantity' => '5.0000',
            'remaining_quantity' => '5.0000',
            'cost_price' => '100.00',
            'selling_price' => '150.00',
            'mrp' => '160.00',
            'selling_code' => 'SKU-001',
        ]);
    }

    /** @test */
    public function batch_prices_and_codes_match_purchase_item_values(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                [
                    'product_id' => $this->stockProduct->id,
                    'quantity' => 3,
                    'unit_cost' => 42.50,
                    'mrp' => 99.99,
                    'selling_price' => 88.50,
                    'selling_code' => 'CODE-XYZ',
                ],
            ],
        ]));
        $response->assertStatus(201);

        $purchaseId = $response->json('data.id');
        $purchaseItem = PurchaseItem::where('purchase_id', $purchaseId)->first();

        $batch = InventoryBatch::where('purchase_item_id', $purchaseItem->id)->first();
        $this->assertNotNull($batch);
        $this->assertSame('42.50', $batch->cost_price);
        $this->assertSame('99.99', $batch->mrp);
        $this->assertSame('88.50', $batch->selling_price);
        $this->assertSame('CODE-XYZ', $batch->selling_code);
    }

    /** @test */
    public function purchase_stock_movement_references_the_batch(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload());
        $response->assertStatus(201);

        $purchaseId = $response->json('data.id');
        $batch = InventoryBatch::where('business_id', $this->business->id)->first();

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->business->id,
            'product_id' => $this->stockProduct->id,
            'type' => StockMovement::TYPE_PURCHASE,
            'reference_type' => Purchase::class,
            'reference_id' => $purchaseId,
            'batch_id' => $batch->id,
            'quantity' => '5.0000',
        ]);
    }

    /** @test */
    public function batch_creation_and_stock_movement_are_atomic_on_failure(): void
    {
        $foreignProduct = Product::factory()->create(['business_id' => $this->otherBusiness->id]);

        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                ['product_id' => $foreignProduct->id, 'quantity' => 1, 'unit_cost' => 10],
            ],
        ]));
        $response->assertStatus(422);

        $this->assertDatabaseCount('inventory_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchases', 0);
    }

    /** @test */
    public function service_products_do_not_create_stock_batches(): void
    {
        // Service products are rejected by the purchase service (they don't
        // track stock), so they can never create a batch or a movement.
        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                ['product_id' => $this->serviceProduct->id, 'quantity' => 1, 'unit_cost' => 10],
            ],
        ]));
        $response->assertStatus(409);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    /** @test */
    public function duplicate_processing_does_not_create_duplicate_batches(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload());
        $response->assertStatus(201);

        $purchaseId = $response->json('data.id');
        $purchaseItem = PurchaseItem::where('purchase_id', $purchaseId)->first();

        $batchCountBefore = InventoryBatch::where('purchase_item_id', $purchaseItem->id)->count();
        $this->assertSame(1, $batchCountBefore);

        $this->expectException(\Illuminate\Database\QueryException::class);
        InventoryBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $this->stockProduct->id,
            'purchase_item_id' => $purchaseItem->id,
            'batch_number' => 'BATCH-DUPLICATE',
            'received_quantity' => '1.0000',
            'remaining_quantity' => '1.0000',
            'cost_price' => '10.00',
        ]);
    }

    /** @test */
    public function batch_list_is_scoped_to_the_authenticated_business(): void
    {
        $this->postJson('/api/v1/purchases', $this->payload())->assertStatus(201);

        $foreignBatch = InventoryBatch::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'product_id' => $this->stockProduct->id,
        ]);

        $response = $this->getJson('/api/v1/inventory-batches');
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($this->stockProduct->id, $response->json('data.items.0.product_id'));

        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertFalse($ids->contains($foreignBatch->id));
    }

    /** @test */
    public function existing_aggregate_stock_totals_remain_unchanged(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                ['product_id' => $this->stockProduct->id, 'quantity' => 5, 'unit_cost' => 100],
                ['product_id' => $this->stockProduct->id, 'quantity' => 3, 'unit_cost' => 200],
            ],
        ]));
        $response->assertStatus(201);

        $batchCount = InventoryBatch::where('business_id', $this->business->id)->count();
        $this->assertSame(2, $batchCount);

        $stock = StockMovement::query()
            ->forBusiness($this->business->id)
            ->forProduct($this->stockProduct->id)
            ->sum('quantity');

        $this->assertSame('8', Quantity::format($stock));
    }

    /** @test */
    public function batch_list_filters_by_product(): void
    {
        $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                ['product_id' => $this->stockProduct->id, 'quantity' => 5, 'unit_cost' => 100],
            ],
        ]))->assertStatus(201);

        $otherProduct = Product::factory()->create(['business_id' => $this->business->id]);
        $this->postJson('/api/v1/purchases', array_merge($this->payload(), [
            'items' => [
                ['product_id' => $otherProduct->id, 'quantity' => 2, 'unit_cost' => 50],
            ],
        ]))->assertStatus(201);

        $response = $this->getJson('/api/v1/inventory-batches?product_id='.$this->stockProduct->id);
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame($this->stockProduct->id, $response->json('data.items.0.product_id'));
    }

    /** @test */
    public function batch_list_requires_authentication(): void
    {
        $this->flushHeaders();
        $this->getJson('/api/v1/inventory-batches')->assertStatus(401);
    }

    /** @test */
    public function backfill_does_not_duplicate_stock_movements_or_invent_quantities(): void
    {
        $movementsBefore = StockMovement::query()
            ->forBusiness($this->business->id)
            ->count();

        $this->postJson('/api/v1/purchases', $this->payload())->assertStatus(201);

        $movementsAfter = StockMovement::query()
            ->forBusiness($this->business->id)
            ->count();

        $this->assertSame(1, $movementsAfter - $movementsBefore);
        $this->assertDatabaseCount('inventory_batches', 1);

        $batch = InventoryBatch::where('business_id', $this->business->id)->first();
        $this->assertSame('5.0000', $batch->received_quantity);
        $this->assertSame('5.0000', $batch->remaining_quantity);
    }
}