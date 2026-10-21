<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Business;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Batch propagation across the sale and sale-return flows.
 *
 * A batch is an allocation layer on top of the stock ledger: the ledger says
 * how much of a product exists, the batch says which lot those units are in.
 * For the two to stay meaningful, three things must always agree — after a
 * sale and after a return:
 *
 *  1. the units leave **the batch they were sold from**, not an arbitrary
 *     batch and not merely "some" batch;
 *  2. the sale line remembers that batch, so the provenance of the units
 *     survives the sale;
 *  3. a return puts the units back into **that very batch**, read from the
 *     original sale line rather than from anything the client supplies.
 *
 * These tests are the regression net for that triangle. They are written
 * through the HTTP API on purpose: the fixtures are then sales and returns the
 * real system would have written, batch links and all, rather than
 * hand-assembled rows that could hide a wiring mistake.
 */
class BatchSaleReturnTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Supplier $supplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'unit' => 'pcs',
            'cost_price' => '60.00',
            'selling_price' => '100.00',
        ]);
    }

    /**
     * Buy `$quantity` units and return the batch that purchase created.
     *
     * Going through the purchase API is what makes the batch a real one: it
     * is the same row the stock ledger was replenished from, so the batch and
     * the ledger start out in agreement.
     */
    private function buy(string $quantity = '10'): InventoryBatch
    {
        $response = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_cost' => 60,
                'selling_price' => 100,
            ]],
        ]);

        $response->assertStatus(201);

        return InventoryBatch::query()
            ->forBusiness($this->business->id)
            ->where('product_id', $this->product->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * Sell `$quantity` units of this product out of `$batchId`.
     *
     * No `paid_amount` is sent, so the sale is a credit sale: these tests are
     * about stock and batches, and no drawer is involved.
     *
     * @return array<string, mixed>
     */
    private function sell(int $batchId, string $quantity = '4'): array
    {
        $response = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_price' => 100,
                'batch_id' => $batchId,
            ]],
        ]);

        $response->assertStatus(201);

        return $response->json('data');
    }

    /**
     * Return `$quantity` units of the given sale line.
     *
     * @return array<string, mixed>
     */
    private function returnLine(int $saleId, int $saleItemId, string $quantity = '2'): array
    {
        $response = $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $saleId,
            'items' => [[
                'sale_item_id' => $saleItemId,
                'quantity' => $quantity,
            ]],
        ]);

        $response->assertStatus(201);

        return $response->json('data');
    }

    /** The remaining quantity of a batch, read fresh from the database. */
    private function remainingOf(int $batchId): string
    {
        return Quantity::format(
            InventoryBatch::query()->whereKey($batchId)->value('remaining_quantity')
        );
    }

    /** The stock movement that references a given document. */
    private function movementFor(string $referenceType, int $referenceId): ?StockMovement
    {
        return StockMovement::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->first();
    }

    /** @test */
    public function a_sale_decrements_the_batch_it_selected(): void
    {
        $batch = $this->buy('10');

        $this->sell($batch->id, '4');

        // 10 bought − 4 sold = 6 left in that batch.
        $this->assertSame('6', $this->remainingOf($batch->id));
    }

    /** @test */
    public function a_sale_decrements_only_the_selected_batch(): void
    {
        $first = $this->buy('10');
        $second = $this->buy('10');

        $this->sell($second->id, '3');

        // The batch that was not selected keeps every one of its units.
        $this->assertSame('10', $this->remainingOf($first->id));
        $this->assertSame('7', $this->remainingOf($second->id));
    }

    /** @test */
    public function the_sale_line_remembers_the_batch_the_units_were_sold_from(): void
    {
        $batch = $this->buy('10');

        $sale = $this->sell($batch->id, '4');

        $this->assertSame($batch->id, (int) $sale['items'][0]['batch_id']);

        $saleItem = Sale::query()->findOrFail($sale['id'])->saleItems()->firstOrFail();
        $this->assertSame($batch->id, (int) $saleItem->batch_id);
    }

    /** @test */
    public function the_outbound_stock_movement_is_tagged_with_the_sold_batch(): void
    {
        $batch = $this->buy('10');

        $sale = $this->sell($batch->id, '4');

        $movement = $this->movementFor(Sale::class, (int) $sale['id']);

        $this->assertNotNull($movement);
        $this->assertSame(StockMovement::TYPE_SALE, $movement->type);
        $this->assertSame('-4', Quantity::format($movement->quantity));
        $this->assertSame($batch->id, (int) $movement->batch_id);
    }

    /** @test */
    public function a_return_restores_the_original_batch_of_the_sale_line(): void
    {
        $batch = $this->buy('10');

        $sale = $this->sell($batch->id, '4');
        $saleItemId = (int) $sale['items'][0]['id'];

        $this->assertSame('6', $this->remainingOf($batch->id));

        $this->returnLine((int) $sale['id'], $saleItemId, '2');

        // 6 left after the sale, 2 of them come back.
        $this->assertSame('8', $this->remainingOf($batch->id));
    }

    /** @test */
    public function a_return_restores_the_original_batch_even_when_another_batch_exists(): void
    {
        $sold = $this->buy('10');
        $other = $this->buy('10');

        $sale = $this->sell($sold->id, '4');
        $saleItemId = (int) $sale['items'][0]['id'];

        $this->returnLine((int) $sale['id'], $saleItemId, '2');

        // The units go back to the lot they came out of — not to the newest
        // batch, and not to whichever batch happens to be first.
        $this->assertSame('8', $this->remainingOf($sold->id));
        $this->assertSame('10', $this->remainingOf($other->id));
    }

    /** @test */
    public function the_inbound_return_movement_is_tagged_with_the_original_batch(): void
    {
        $batch = $this->buy('10');

        $sale = $this->sell($batch->id, '4');
        $saleItemId = (int) $sale['items'][0]['id'];

        $ret = $this->returnLine((int) $sale['id'], $saleItemId, '2');

        $movement = $this->movementFor(SaleReturn::class, (int) $ret['id']);

        $this->assertNotNull($movement);
        $this->assertSame(StockMovement::TYPE_SALE_RETURN, $movement->type);
        $this->assertSame('2', Quantity::format($movement->quantity));
        $this->assertSame($batch->id, (int) $movement->batch_id);
    }

    /** @test */
    public function the_batch_and_the_ledger_stay_in_step_across_a_sale_and_its_return(): void
    {
        $batch = $this->buy('10');

        $sale = $this->sell($batch->id, '4');
        $saleItemId = (int) $sale['items'][0]['id'];

        $this->returnLine((int) $sale['id'], $saleItemId, '2');

        // The ledger holds 10 bought − 4 sold + 2 returned = 8, and the batch
        // holds the same 8. One number, two views of it.
        $ledger = StockMovement::query()
            ->forBusiness($this->business->id)
            ->forProduct($this->product->id)
            ->sum('quantity');

        $this->assertSame('8', Quantity::format($ledger));
        $this->assertSame(Quantity::format($ledger), $this->remainingOf($batch->id));
    }

    /** @test */
    public function a_sale_cannot_spend_more_than_the_batch_holds(): void
    {
        $batch = $this->buy('5');

        $response = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 6,
                'unit_price' => 100,
                'batch_id' => $batch->id,
            ]],
        ]);

        $response->assertStatus(409);

        // Nothing was spent and no sale was written.
        $this->assertSame('5', $this->remainingOf($batch->id));
        $this->assertSame(0, Sale::query()->count());
    }

    /** @test */
    public function a_batch_of_another_business_cannot_be_sold(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignBatch = InventoryBatch::factory()->create([
            'business_id' => $otherBusiness->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
                'batch_id' => $foreignBatch->id,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }

    /** @test */
    public function a_batch_of_another_product_cannot_be_sold(): void
    {
        $otherProduct = Product::factory()->create(['business_id' => $this->business->id]);
        $foreignBatch = InventoryBatch::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $otherProduct->id,
        ]);

        $response = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
                'batch_id' => $foreignBatch->id,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }
}
