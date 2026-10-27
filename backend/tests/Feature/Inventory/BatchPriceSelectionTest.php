<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Business;
use App\Models\InventoryBatch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

class BatchPriceSelectionTest extends TestCase
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
        ]);
    }

    private function buy(string $quantity, mixed $sellingPrice = 100, ?string $receivedDate = null): InventoryBatch
    {
        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => $receivedDate ?? now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_cost' => 60,
                'selling_price' => $sellingPrice,
            ]],
        ])->assertStatus(201);

        return InventoryBatch::query()
            ->forBusiness($this->business->id)
            ->where('product_id', $this->product->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function sell(array $items): TestResponse
    {
        return $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'items' => $items,
        ]);
    }

    /**
     * The price options the POS selector would show for this product.
     *
     * One option per distinct selling price, each carrying the *baseline*
     * batch of that price (the oldest eligible batch at it) and the combined
     * stock available at that price.
     *
     * @return array<int, array{selling_price: string, batch_id: int, batch_number: string, available_quantity: string}>
     */
    private function priceOptions(): array
    {
        $response = $this->getJson('/api/v1/inventory-batches/product/'.$this->product->id.'/price-options');
        $response->assertStatus(200);

        return $response->json('data.options');
    }

    private function remainingOf(int $batchId): string
    {
        return Quantity::format((string) InventoryBatch::query()->whereKey($batchId)->value('remaining_quantity'));
    }

    /** @test */
    public function price_options_expose_one_option_per_distinct_price(): void
    {
        $first = $this->buy('10', 100, now()->subDays(2)->toDateString());
        $second = $this->buy('10', 120, now()->subDay()->toDateString());
        $this->buy('10', 100, now()->toDateString());

        $response = $this->getJson('/api/v1/inventory-batches/product/'.$this->product->id.'/price-options');
        $response->assertStatus(200);

        $options = $response->json('data.options');
        $this->assertCount(2, $options);
        $this->assertSame('100.00', $options[0]['selling_price']);
        $this->assertSame($first->id, $options[0]['batch_id']);
        $this->assertSame('120.00', $options[1]['selling_price']);
        $this->assertSame($second->id, $options[1]['batch_id']);
        $this->assertSame('20', Quantity::format((string) $options[0]['available_quantity']));
    }

    /**
     * @test
     *
     * A depleted batch is excluded from the price options while a sibling batch
     * of the same price is still in stock.
     *
     * The options payload carries one entry per distinct selling price, and each
     * entry names the *baseline* batch of that price — the oldest eligible one —
     * together with the combined stock at that price. A batch that has been sold
     * out is not eligible any more, so it can neither keep the baseline nor
     * contribute to the available quantity.
     *
     * The scenario therefore depletes the *older* of two same-price lots, so the
     * surviving lot becomes the baseline. That makes the change in the payload
     * diagnostic: the baseline id moves, the available quantity drops to the
     * surviving lot's own stock, and the depleted lot's id is gone.
     */
    public function a_depleted_batch_is_excluded_from_the_price_options(): void
    {
        // Two lots of the same product at the same selling price, received on
        // different dates so the FIFO order is unambiguous.
        $older = $this->buy('4', 100, now()->subDays(2)->toDateString());
        $newer = $this->buy('6', 100, now()->subDay()->toDateString());

        // Before the sale the single price option points at the older lot and
        // reports the combined stock of both.
        $before = $this->priceOptions();
        $this->assertCount(1, $before);
        $this->assertSame($older->id, $before[0]['batch_id']);
        $this->assertSame('10', Quantity::format((string) $before[0]['available_quantity']));

        // Selling the older lot's exact stock empties it. No batch id is sent,
        // so the automatic price selection and the FIFO allocation pick the
        // lot: the oldest one, which is exactly what the options pointed at.
        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 4,
            'unit_price' => 100,
        ]]);
        $response->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($older->id, (int) $items[0]['batch_id']);
        $this->assertSame('4', Quantity::format((string) $items[0]['quantity']));

        $this->assertSame('0', $this->remainingOf($older->id));
        $this->assertSame('6', $this->remainingOf($newer->id));

        // The depleted lot is gone from the options: the price still has exactly
        // one option, but it now names the surviving lot and reports only that
        // lot's stock.
        $options = $this->priceOptions();
        $this->assertCount(1, $options);
        $this->assertSame($newer->id, $options[0]['batch_id']);
        $this->assertSame(
            $this->remainingOf($newer->id),
            Quantity::format((string) $options[0]['available_quantity']),
        );
        $this->assertNotContains($older->id, array_column($options, 'batch_id'));
    }

    /** @test */
    public function single_price_auto_selects_and_allocates_fifo_with_split_rows(): void
    {
        $first = $this->buy('3', 100, now()->subDays(2)->toDateString());
        $second = $this->buy('5', 100, now()->subDay()->toDateString());

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 100,
        ]]);
        $response->assertStatus(201);

        $saleId = $response->json('data.id');
        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame($first->id, $items[0]['batch_id']);
        $this->assertSame($second->id, $items[1]['batch_id']);
        $this->assertSame('100.00', $items[0]['unit_price']);
        $this->assertSame('100.00', $items[1]['unit_price']);
        $this->assertSame('500.00', $response->json('data.subtotal'));

        $this->assertSame('0', $this->remainingOf($first->id));
        $this->assertSame('3', $this->remainingOf($second->id));

        $sale = Sale::query()->with('saleItems.batch')->findOrFail($saleId);
        $this->assertSame($first->id, (int) $sale->saleItems[0]->batch_id);
        $this->assertSame($second->id, (int) $sale->saleItems[1]->batch_id);
    }

    /** @test */
    public function shortfall_at_selected_price_rejects_with_exact_message(): void
    {
        $cheap = $this->buy('2', 100, now()->subDays(2)->toDateString());
        $this->buy('50', 120, now()->subDay()->toDateString());

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'batch_id' => $cheap->id,
        ]]);

        $response->assertStatus(409);
        $responseMessage = (string) $response->json('message');
        $this->assertSame('please add another batch of this product', $responseMessage);
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('2', $this->remainingOf($cheap->id));
    }

    /** @test */
    public function single_price_omitted_batch_id_rejects_mismatched_unit_price(): void
    {
        $batch = $this->buy('10', 100);
        $saleMovementsBefore = StockMovement::query()
            ->where('type', StockMovement::TYPE_SALE)
            ->count();

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 120,
        ]]);

        $response->assertStatus(422);
        $this->assertSame(
            'The selling price does not match the selected batch price.',
            (string) $response->json('message'),
        );
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10', $this->remainingOf($batch->id));
        $this->assertSame(
            $saleMovementsBefore,
            StockMovement::query()->where('type', StockMovement::TYPE_SALE)->count(),
        );
    }

    /** @test */
    public function selected_price_is_never_silently_changed(): void
    {
        $cheap = $this->buy('10', 100);
        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 120,
            'batch_id' => $cheap->id,
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }

    /** @test */
    public function multiple_prices_require_explicit_batch_selection(): void
    {
        $this->buy('10', 100);
        $this->buy('10', 120);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }

    /** @test */
    public function legacy_sale_without_batch_history_keeps_null_batch(): void
    {
        $this->product->stockMovements()->create([
            'business_id' => $this->business->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '5.0000',
            'note' => 'legacy opening',
        ]);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.items.0.batch_id'));
    }

    /** @test */
    public function depleted_history_never_falls_back_to_legacy(): void
    {
        $batch = $this->buy('2', 100);

        $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100,
            'batch_id' => $batch->id,
        ]])->assertStatus(201);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]]);

        $response->assertStatus(409);
        $responseMessage = (string) $response->json('message');
        $this->assertSame('please add another batch of this product', $responseMessage);
    }

    /** @test */
    public function zero_ledger_stock_blocks_the_sale(): void
    {
        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]]);

        $response->assertStatus(409);
        $this->assertSame(0, Sale::query()->count());
    }

    /** @test */
    public function cashier_batch_read_does_not_need_broad_inventory_access(): void
    {
        $cashier = $this->createBusinessUser($this->business);
        $this->grantSlugs($cashier, ['sales.create', 'sales.view', 'inventory.batches.view']);
        $this->authenticate($cashier);

        $this->getJson('/api/v1/inventory-batches/product/'.$this->product->id.'/price-options')->assertStatus(200);
        $this->getJson('/api/v1/inventory-batches')->assertStatus(403);
    }

    /**
     * A price choice is a *price*, not a lot: the units are taken from every
     * eligible batch at that price, oldest first.
     *
     * The POS selector answers with one option per price and lets the cashier
     * pick a price; the `batch_id` it carries is that price's baseline batch.
     * A sale that names it therefore asks for "this price", and the stock at
     * that price is spent FIFO — even when the baseline batch alone cannot
     * cover the quantity.
     *
     * @test
     */
    public function a_price_choice_is_allocated_fifo_across_every_batch_at_that_price(): void
    {
        $first = $this->buy('3', 100, now()->subDays(2)->toDateString());
        $second = $this->buy('5', 100, now()->subDay()->toDateString());
        $this->buy('50', 120, now()->toDateString());

        $options = $this->priceOptions();
        $this->assertCount(2, $options);
        $this->assertSame('100.00', $options[0]['selling_price']);
        $this->assertSame($first->id, $options[0]['batch_id']);
        $this->assertSame('8', $options[0]['available_quantity']);

        // The cashier picked the 100.00 price. Its baseline batch holds only 3
        // units, so the line needs the sibling batch as well.
        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 6,
            'unit_price' => 100,
            'batch_id' => $options[0]['batch_id'],
        ]]);

        $response->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame($first->id, (int) $items[0]['batch_id']);
        $this->assertSame('3', $items[0]['quantity']);
        $this->assertSame($second->id, (int) $items[1]['batch_id']);
        $this->assertSame('3', $items[1]['quantity']);
        $this->assertSame('100.00', $items[0]['unit_price']);
        $this->assertSame('100.00', $items[1]['unit_price']);
        $this->assertSame('600.00', $response->json('data.subtotal'));

        $this->assertSame('0', $this->remainingOf($first->id));
        $this->assertSame('2', $this->remainingOf($second->id));
    }

    /**
     * The FIFO order is `received_date ASC, id ASC`: the oldest lot first, and
     * the lower id first when two lots share a received date.
     *
     * @test
     */
    public function allocation_follows_received_date_then_id(): void
    {
        // Two lots share a received date, a third is older than both.
        $sameDay = $this->buy('2', 100, now()->subDays(2)->toDateString());
        $sameDayLater = $this->buy('2', 100, now()->subDays(2)->toDateString());
        $older = $this->buy('2', 100, now()->subDays(5)->toDateString());

        $options = $this->priceOptions();
        $this->assertCount(1, $options);
        // The baseline of the price is the oldest eligible lot at it.
        $this->assertSame($older->id, $options[0]['batch_id']);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 4,
            'unit_price' => 100,
            'batch_id' => $options[0]['batch_id'],
        ]]);

        $response->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        // The older lot is emptied first, then the lower id of the two that
        // share a received date.
        $this->assertSame($older->id, (int) $items[0]['batch_id']);
        $this->assertSame('2', $items[0]['quantity']);
        $this->assertSame($sameDay->id, (int) $items[1]['batch_id']);
        $this->assertSame('2', $items[1]['quantity']);

        $this->assertSame('0', $this->remainingOf($older->id));
        $this->assertSame('0', $this->remainingOf($sameDay->id));
        $this->assertSame('2', $this->remainingOf($sameDayLater->id));
    }

    /**
     * A price choice never reaches across to another selling price: the units
     * come from the chosen price's stock or from nowhere.
     *
     * @test
     */
    public function a_price_choice_never_consumes_a_batch_at_another_price(): void
    {
        $cheap = $this->buy('2', 100, now()->subDays(2)->toDateString());
        $pricey = $this->buy('50', 120, now()->subDay()->toDateString());

        $options = $this->priceOptions();
        $this->assertSame('100.00', $options[0]['selling_price']);
        $this->assertSame($cheap->id, $options[0]['batch_id']);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100,
            'batch_id' => $options[0]['batch_id'],
        ]]);

        $response->assertStatus(201);
        $this->assertSame('0', $this->remainingOf($cheap->id));
        // The more expensive lot keeps every one of its units.
        $this->assertSame('50', $this->remainingOf($pricey->id));
    }

    /**
     * A price choice beyond the *combined* stock of that price is refused with
     * the basket-level shortfall message, and nothing is spent: the allocation
     * that had already started rolls back with the transaction.
     *
     * @test
     */
    public function a_price_choice_beyond_the_combined_stock_is_refused_and_rolls_back(): void
    {
        $first = $this->buy('2', 100, now()->subDays(2)->toDateString());
        $second = $this->buy('1', 100, now()->subDay()->toDateString());
        $pricey = $this->buy('50', 120, now()->toDateString());

        $options = $this->priceOptions();
        $this->assertSame('3', $options[0]['available_quantity']);

        $movementsBefore = StockMovement::query()->where('type', StockMovement::TYPE_SALE)->count();

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'batch_id' => $options[0]['batch_id'],
        ]]);

        $response->assertStatus(409);
        $this->assertSame('please add another batch of this product', (string) $response->json('message'));
        $this->assertSame(0, Sale::query()->count());

        // Every lot kept its units: the first one was already drawn down when
        // the shortfall was detected, and the rollback put it back.
        $this->assertSame('2', $this->remainingOf($first->id));
        $this->assertSame('1', $this->remainingOf($second->id));
        $this->assertSame('50', $this->remainingOf($pricey->id));
        $this->assertSame(
            $movementsBefore,
            StockMovement::query()->where('type', StockMovement::TYPE_SALE)->count(),
        );
    }

    /**
     * A batch of another business cannot be used to reach a price the cashier
     * never chose, and the price it carries is not honoured either.
     *
     * @test
     */
    public function a_batch_of_another_business_cannot_be_used_to_reach_a_price(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreign = InventoryBatch::factory()->create([
            'business_id' => $otherBusiness->id,
            'product_id' => $this->product->id,
            'selling_price' => '50.00',
        ]);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 50,
            'batch_id' => $foreign->id,
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * A batch of another product cannot be used to reach a price either.
     *
     * @test
     */
    public function a_batch_of_another_product_cannot_be_used_to_reach_a_price(): void
    {
        $otherProduct = Product::factory()->create(['business_id' => $this->business->id]);
        $foreign = InventoryBatch::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $otherProduct->id,
            'selling_price' => '50.00',
        ]);

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 50,
            'batch_id' => $foreign->id,
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * A return gives the units back to the batch they were actually taken
     * from — the lot the split row names, not the baseline of the price.
     *
     * @test
     */
    public function a_return_restores_the_batches_actually_consumed_by_a_price_choice(): void
    {
        $first = $this->buy('3', 100, now()->subDays(2)->toDateString());
        $second = $this->buy('5', 100, now()->subDay()->toDateString());

        $options = $this->priceOptions();

        $response = $this->sell([[
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 100,
            'batch_id' => $options[0]['batch_id'],
        ]]);
        $response->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame('0', $this->remainingOf($first->id));
        $this->assertSame('3', $this->remainingOf($second->id));

        // Returning the second split row gives its 2 units back to the batch
        // they came out of — not to the first one.
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $response->json('data.id'),
            'items' => [[
                'sale_item_id' => $items[1]['id'],
                'quantity' => 2,
            ]],
        ])->assertStatus(201);

        $this->assertSame('0', $this->remainingOf($first->id));
        $this->assertSame('5', $this->remainingOf($second->id));
    }

    /**
     * A line that is split across batches keeps its money intact: the line
     * discount and the line tax are divided between the split rows exactly
     * once, and the invoice discount is still charged once.
     *
     * @test
     */
    public function a_split_line_does_not_duplicate_its_discount_or_tax(): void
    {
        $first = $this->buy('3', 100, now()->subDays(2)->toDateString());
        $this->buy('5', 100, now()->subDay()->toDateString());

        $options = $this->priceOptions();

        $response = $this->postJson('/api/v1/sales', [
            'sale_date' => now()->toDateString(),
            'discount' => 50,
            'tax' => 0,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 5,
                'unit_price' => 100,
                'discount' => 20,
                'tax' => 10,
                'batch_id' => $options[0]['batch_id'],
            ]],
        ]);
        $response->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);

        // 5 x 100 = 500 gross, minus the 20 line discount, plus the 10 line tax.
        $this->assertSame('490.00', $response->json('data.subtotal'));
        $this->assertSame('50.00', $response->json('data.discount'));
        $this->assertSame('0.00', $response->json('data.tax'));
        $this->assertSame('440.00', $response->json('data.grand_total'));

        // The split rows carry 3/5 and 2/5 of the line's own discount and tax
        // between them — the line's 20 and 10, not twice that.
        $lineDiscount = 0.0;
        $lineTax = 0.0;
        $lineTotal = 0.0;
        foreach ($items as $item) {
            $lineDiscount += (float) $item['discount'];
            $lineTax += (float) $item['tax'];
            $lineTotal += (float) $item['total'];
        }

        $this->assertSame(20.0, $lineDiscount);
        $this->assertSame(10.0, $lineTax);
        $this->assertSame(490.0, $lineTotal);

        $this->assertSame('0', $this->remainingOf($first->id));
    }

    /** @param array<int, string> $slugs */
    private function grantSlugs(User $user, array $slugs): void
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $ids[] = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id;
        }

        $role = Role::firstOrCreate(
            ['business_id' => $user->business_id, 'slug' => 'test-cashier'],
            ['name' => 'Test Cashier', 'description' => 'Least-privilege fixture.'],
        );
        $role->permissions()->sync($ids);
        $user->roles()->syncWithoutDetaching([$role->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}
