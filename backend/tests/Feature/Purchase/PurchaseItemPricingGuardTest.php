<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Models\Business;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Edit protection for the per-item resale fields (Step 2).
 *
 * Historic purchases (all three fields `NULL`) stay compatible: re-sending
 * an unchanged purchase without the new keys is a no-op, and re-sending the
 * exact stored values is idempotent. Any change to MRP, selling price or
 * selling code on a finalized item is structural and rejected with the
 * existing 409 behaviour.
 */
class PurchaseItemPricingGuardTest extends TestCase
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
        $this->product = Product::factory()->create(['business_id' => $this->business->id]);
    }

    /** @test */
    public function historic_null_items_stay_compatible(): void
    {
        $id = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100],
            ],
        ])->json('data.id');

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100],
            ],
        ])->assertStatus(200);
    }

    /** @test */
    public function resending_stored_values_is_idempotent(): void
    {
        $id = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_cost' => 50,
                    'discount' => 5,
                    'mrp' => 80,
                    'selling_price' => 75.50,
                    'selling_code' => 'KEEP-ME',
                ],
            ],
        ])->json('data.id');

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_cost' => 50,
                    'discount' => 5,
                    'mrp' => 80,
                    'selling_price' => 75.50,
                    'selling_code' => 'KEEP-ME',
                ],
            ],
        ])->assertStatus(200);

        // Scale and whitespace variants of the same values also match.
        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_cost' => 50,
                    'discount' => 5,
                    'mrp' => '80.00',
                    'selling_price' => '75.50',
                    'selling_code' => '  KEEP-ME  ',
                ],
            ],
        ])->assertStatus(200);
    }

    /** @test */
    public function changing_new_fields_is_rejected(): void
    {
        $id = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_cost' => 50,
                    'mrp' => 80,
                    'selling_price' => 75,
                    'selling_code' => 'ORIGINAL',
                ],
            ],
        ])->json('data.id');

        $base = [
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_cost' => 50,
            'mrp' => 80,
            'selling_price' => 75,
            'selling_code' => 'ORIGINAL',
        ];

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [[...$base, 'mrp' => 81]],
        ])->assertStatus(409);

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [[...$base, 'selling_price' => 76]],
        ])->assertStatus(409);

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [[...$base, 'selling_code' => 'CHANGED']],
        ])->assertStatus(409);
    }

    /** @test */
    public function adding_values_where_null_was_stored_is_rejected(): void
    {
        $id = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100],
            ],
        ])->json('data.id');

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_cost' => 100,
                    'mrp' => 120,
                ],
            ],
        ])->assertStatus(409);

        $this->putJson('/api/v1/purchases/'.$id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_cost' => 100,
                    'selling_code' => 'NEW-CODE',
                ],
            ],
        ])->assertStatus(409);
    }
}
