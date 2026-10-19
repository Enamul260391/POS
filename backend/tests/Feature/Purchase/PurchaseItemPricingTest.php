<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Per-item resale fields on `purchase_items` (Step 2).
 *
 * `mrp`, `selling_price` and `selling_code` belong to the individual
 * purchase item record — never to the global product, the purchase totals,
 * or the stock ledger. They are optional (`NULL` stays valid), validated
 * like the existing money columns, and participate in the finalized-item
 * signature so changing them after creation is a structural change (409).
 */
class PurchaseItemPricingTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Supplier $supplier;

    protected Product $product;

    protected Product $product2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->supplier = Supplier::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create(['business_id' => $this->business->id]);
        $this->product2 = Product::factory()->create(['business_id' => $this->business->id]);
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
    public function new_values_persist_and_appear_in_responses(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_cost' => 100,
                    'mrp' => 150.50,
                    'selling_price' => 140,
                    'selling_code' => '  SELL-001  ',
                ],
            ],
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.items.0.mrp', '150.50');
        $response->assertJsonPath('data.items.0.selling_price', '140.00');
        $response->assertJsonPath('data.items.0.selling_code', 'SELL-001');

        // Totals still come from cost/discount/tax only: 2 x 100 = 200.
        $response->assertJsonPath('data.subtotal', '200.00');
        $response->assertJsonPath('data.grand_total', '200.00');

        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $response->json('data.id'),
            'product_id' => $this->product->id,
            'mrp' => '150.50',
            'selling_price' => '140.00',
            'selling_code' => 'SELL-001',
        ]);

        $detail = $this->getJson('/api/v1/purchases/'.$response->json('data.id'));
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.items.0.mrp', '150.50');
        $detail->assertJsonPath('data.items.0.selling_price', '140.00');
        $detail->assertJsonPath('data.items.0.selling_code', 'SELL-001');
    }

    /** @test */
    public function each_item_stores_its_own_values(): void
    {
        // Two lines for the SAME product must keep independent values —
        // the fields live on the item row, not on the product.
        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_cost' => 10,
                    'mrp' => 20,
                    'selling_price' => 18,
                    'selling_code' => 'FIRST',
                ],
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_cost' => 11,
                    'mrp' => 30,
                    'selling_price' => 28,
                    'selling_code' => 'SECOND',
                ],
            ],
        ]));

        $response->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);

        $first = collect($items)->firstWhere('selling_code', 'FIRST');
        $second = collect($items)->firstWhere('selling_code', 'SECOND');

        $this->assertSame($this->product->id, $first['product_id']);
        $this->assertSame($this->product->id, $second['product_id']);
        $this->assertSame('20.00', $first['mrp']);
        $this->assertSame('18.00', $first['selling_price']);
        $this->assertSame('30.00', $second['mrp']);
        $this->assertSame('28.00', $second['selling_price']);

        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $response->json('data.id'),
            'product_id' => $this->product->id,
            'quantity' => '1.0000',
            'mrp' => '20.00',
            'selling_code' => 'FIRST',
        ]);
        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $response->json('data.id'),
            'product_id' => $this->product->id,
            'quantity' => '2.0000',
            'mrp' => '30.00',
            'selling_code' => 'SECOND',
        ]);
    }

    /** @test */
    public function null_values_remain_valid_for_new_and_historic_records(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload());
        $response->assertStatus(201);
        $response->assertJsonPath('data.items.0.mrp', null);
        $response->assertJsonPath('data.items.0.selling_price', null);
        $response->assertJsonPath('data.items.0.selling_code', null);

        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $response->json('data.id'),
            'product_id' => $this->product->id,
            'mrp' => null,
            'selling_price' => null,
            'selling_code' => null,
        ]);

        // A historic row written without the new keys (raw insert) still
        // reads back as null through the model.
        $historic = Purchase::factory()->create([
            'business_id' => $this->business->id,
            'supplier_id' => $this->supplier->id,
        ]);
        $itemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $historic->id,
            'product_id' => $this->product->id,
            'quantity' => '1.0000',
            'unit_cost' => '10.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'total' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = PurchaseItem::find($itemId);
        $this->assertNotNull($item);
        $this->assertNull($item->mrp);
        $this->assertNull($item->selling_price);
        $this->assertNull($item->selling_code);
    }

    /** @test */
    public function blank_selling_code_normalises_to_null(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_cost' => 10,
                    'selling_code' => '   ',
                ],
            ],
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.items.0.selling_code', null);
    }

    /** @test */
    public function invalid_values_are_rejected(): void
    {
        $base = [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_cost' => 10,
        ];

        $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [[...$base, 'mrp' => -1]],
        ]))->assertStatus(422);

        $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [[...$base, 'selling_price' => -0.01]],
        ]))->assertStatus(422);

        $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [[...$base, 'mrp' => 10.123]],
        ]))->assertStatus(422);

        $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [[...$base, 'selling_price' => 10.999]],
        ]))->assertStatus(422);

        $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [[...$base, 'selling_code' => str_repeat('A', 51)]],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('purchases', 0);
    }

    /** @test */
    public function new_fields_do_not_change_totals_or_stock(): void
    {
        $withPricing = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 4,
                    'unit_cost' => 25,
                    'discount' => 10,
                    'mrp' => 9999.99,
                    'selling_price' => 8888.88,
                    'selling_code' => 'EXPENSIVE',
                ],
            ],
        ]));

        $withoutPricing = $this->postJson('/api/v1/purchases', $this->payload([
            'items' => [
                [
                    'product_id' => $this->product2->id,
                    'quantity' => 4,
                    'unit_cost' => 25,
                    'discount' => 10,
                ],
            ],
        ]));

        $withPricing->assertStatus(201);
        $withoutPricing->assertStatus(201);

        // 4 x 25 - 10 = 90 regardless of MRP / selling price / code.
        $withPricing->assertJsonPath('data.subtotal', '90.00');
        $withPricing->assertJsonPath('data.grand_total', '90.00');
        $withoutPricing->assertJsonPath('data.subtotal', '90.00');
        $withoutPricing->assertJsonPath('data.grand_total', '90.00');

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => Purchase::class,
            'reference_id' => $withPricing->json('data.id'),
            'product_id' => $this->product->id,
            'quantity' => '4.0000',
        ]);
    }
}
