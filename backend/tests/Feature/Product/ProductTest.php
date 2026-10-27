<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 20 — Product API contract, filtering and business scoping.
 *
 * Products never expose or accept a stock field: on-hand quantity always
 * comes from the `stock_movements` ledger.
 */
class ProductTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Category $category;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected Category $otherCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->otherCategory = Category::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    /** @test */
    public function product_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        $unauthenticated = [
            ['GET', '/api/v1/products'],
            ['POST', '/api/v1/products'],
            ['GET', '/api/v1/products/1'],
            ['PUT', '/api/v1/products/1'],
            ['PATCH', '/api/v1/products/1'],
            ['DELETE', '/api/v1/products/1'],
        ];

        foreach ($unauthenticated as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'Nope'])
                ->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'errors' => [],
                ]);
        }
    }

    /** @test */
    public function authenticated_user_can_list_products(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'name' => 'Coffee Beans',
        ]);
        // Another tenant's product must never appear.
        Product::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'category_id' => $this->otherCategory->id,
            'name' => 'Foreign Product',
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'data' => ['pagination' => ['current_page' => 1, 'total' => 1, 'last_page' => 1]],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [[
                        'id',
                        'category_id',
                        'name',
                        'sku',
                        'barcode',
                        'unit',
                        'low_stock_threshold',
                        'status',
                        'current_stock',
                        'category',
                        'created_at',
                        'updated_at',
                    ]],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                ],
            ]);

        $this->assertSame('Coffee Beans', $response->json('data.items.0.name'));

        // Eager-loaded, controlled category information is nested.
        $this->assertSame($this->category->id, $response->json('data.items.0.category.id'));

        // Internal/tenant fields never leak.
        $this->assertArrayNotHasKey('business_id', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.items.0'));
    }

    /** @test */
    public function product_list_supports_search(): void
    {
        Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Arabica Coffee']);
        Product::factory()->create(['business_id' => $this->business->id, 'name' => 'Green Tea']);

        $response = $this->getJson('/api/v1/products?search=coffee');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Arabica Coffee', $response->json('data.items.0.name'));
    }

    /** @test */
    public function product_list_filters_by_category(): void
    {
        $otherCategory = Category::factory()->create(['business_id' => $this->business->id]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $otherCategory->id,
        ]);

        $response = $this->getJson('/api/v1/products?category_id='.$this->category->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($this->category->id, $response->json('data.items.0.category_id'));
    }

    /** @test */
    public function product_list_filters_by_sku_and_barcode(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'sku' => 'ABC123',
            'barcode' => '1234567890123',
        ]);
        Product::factory()->create(['business_id' => $this->business->id]);

        $bySku = $this->getJson('/api/v1/products?sku=ABC123');
        $bySku->assertStatus(200);
        $this->assertCount(1, $bySku->json('data.items'));
        $this->assertSame($product->id, $bySku->json('data.items.0.id'));

        $byBarcode = $this->getJson('/api/v1/products?barcode=1234567890123');
        $byBarcode->assertStatus(200);
        $this->assertCount(1, $byBarcode->json('data.items'));
        $this->assertSame($product->id, $byBarcode->json('data.items.0.id'));
    }

    /** @test */
    public function product_list_filters_by_status(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Active Product',
            'status' => Product::STATUS_ACTIVE,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Inactive Product',
            'status' => Product::STATUS_INACTIVE,
        ]);

        $response = $this->getJson('/api/v1/products?status=inactive');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Inactive Product', $response->json('data.items.0.name'));
    }

    /** @test */
    public function product_list_is_paginated(): void
    {
        Product::factory()->count(5)->create(['business_id' => $this->business->id]);

        $response = $this->getJson('/api/v1/products?per_page=2&page=3');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['pagination' => [
                    'current_page' => 3,
                    'per_page' => 2,
                    'total' => 5,
                    'last_page' => 3,
                    'from' => 5,
                    'to' => 5,
                ]],
            ]);

        $this->assertCount(1, $response->json('data.items'));
    }

    /** @test */
    public function product_list_rejects_sort_columns_outside_the_whitelist(): void
    {
        $response = $this->getJson('/api/v1/products?sort=business_id');

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['sort']]);
    }

    /** @test */
    public function product_list_does_not_run_per_row_queries(): void
    {
        // Warm up: authentication guard caching and route resolution must not
        // be counted as per-row work.
        $this->getJson('/api/v1/products?per_page=1')->assertStatus(200);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Product::factory()->count(2)->create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);
        $queries = [];

        $this->getJson('/api/v1/products?per_page=1')->assertStatus(200);
        $smallPage = count($queries);

        Product::factory()->count(8)->create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);
        $queries = [];

        $this->getJson('/api/v1/products?per_page=10')->assertStatus(200);
        $largePage = count($queries);

        // A page of 10 products must cost exactly the same number of queries
        // as a page of 2 — the ledger sum is a correlated sub-query and the
        // category relation is eager loaded, so nothing scales with rows.
        $this->assertSame($smallPage, $largePage);
    }

    /** @test */
    public function authenticated_user_can_create_a_product(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'name' => 'Espresso Mix',
            'sku' => 'ESP-001',
            'barcode' => '4006381333931',
            'unit' => 'pcs',
            'low_stock_threshold' => 3,
            'status' => Product::STATUS_ACTIVE,
            // Hostile input: must be ignored, the tenant comes from the token.
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => [
                    'name' => 'Espresso Mix',
                    'sku' => 'ESP-001',
                    'status' => 'active',
                    'status' => 'active',
                    // Ledger is empty at creation without opening stock.
                    'current_stock' => '0',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'sku', 'barcode', 'category_id', 'current_stock', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $response->json('data.id'),
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);

        // business_id is never part of the response payload.
        $this->assertArrayNotHasKey('business_id', $response->json('data'));
    }

    /**
     * @test
     *
     * Creating a product creates **no stock at all**.
     *
     * A product is master data. On-hand quantity lives exclusively in the
     * `stock_movements` ledger, and every unit enters the system through the
     * Purchase workflow — which records the real purchase date and the known
     * purchase cost and creates the batch the lot is tracked in. A product
     * creation therefore writes no movement, no batch and no quantity.
     */
    public function creating_a_product_creates_no_stock_movement_batch_or_quantity(): void
    {
        $response = $this->postJson('/api/v1/products', ['name' => 'Rice Bag']);

        $response->assertStatus(201)
            ->assertJson(['data' => ['current_stock' => '0']]);

        $productId = $response->json('data.id');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 0);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $productId]);
        $this->assertDatabaseMissing('inventory_batches', ['product_id' => $productId]);
    }

    /**
     * @test
     *
     * A product that carries a threshold but no stock is still a product with
     * no stock: the threshold is an alert level, never a quantity.
     */
    public function a_low_stock_threshold_does_not_create_stock(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Rice Bag',
            'low_stock_threshold' => 25,
        ]);

        $response->assertStatus(201)
            ->assertJson(['data' => ['low_stock_threshold' => 25, 'current_stock' => '0']]);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    /**
     * @test
     *
     * `opening_stock` is rejected outright rather than silently dropped.
     *
     * A client that still sends the field gets a clear `422` instead of a
     * success it would read as "the stock was created" — the difference
     * between a loud failure and a silent loss of stock.
     */
    public function opening_stock_is_rejected_on_creation(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Rice Bag',
            'opening_stock' => 10,
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['opening_stock']]);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    /**
     * @test
     *
     * A zero opening stock is rejected too: the field is not part of the
     * contract at all, whatever value it carries.
     */
    public function a_zero_opening_stock_is_rejected_as_well(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Rice Bag',
            'opening_stock' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['opening_stock']]);

        $this->assertDatabaseCount('products', 0);
    }

    /**
     * @test
     *
     * The edit flow cannot add stock either: an update carries no quantity
     * field, so a client that posts one changes nothing.
     */
    public function updating_a_product_cannot_add_stock(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $response = $this->patchJson('/api/v1/products/'.$product->id, [
            'name' => 'Rice Bag',
            'opening_stock' => 25,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 0);
        $this->assertSame(
            '0',
            Quantity::format((string) StockMovement::query()
                ->where('product_id', $product->id)
                ->sum('quantity')),
        );
    }

    /** @test */
    public function creating_a_product_without_a_name_returns_the_validation_envelope(): void
    {
        $response = $this->postJson('/api/v1/products', ['sku' => 'NO-NAME']);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);

        $this->assertDatabaseCount('products', 0);
    }

    /** @test */
    public function a_product_cannot_be_assigned_to_another_businesss_category(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Cross Tenant Product',
            'category_id' => $this->otherCategory->id,
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['category_id']]);

        $this->assertDatabaseCount('products', 0);
    }

    /** @test */
    public function duplicate_sku_and_barcode_are_rejected_within_the_same_business(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'sku' => 'DUP-1',
            'barcode' => '1111111111111',
        ]);

        $bySku = $this->postJson('/api/v1/products', ['name' => 'Dup SKU', 'sku' => 'DUP-1']);
        $bySku->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['sku']]);

        $byBarcode = $this->postJson('/api/v1/products', [
            'name' => 'Dup Barcode',
            'barcode' => '1111111111111',
        ]);
        $byBarcode->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['barcode']]);

        $this->assertDatabaseCount('products', 1);
    }

    /** @test */
    public function the_same_sku_may_be_used_by_another_business(): void
    {
        Product::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'sku' => 'SHARED-1',
        ]);

        $this->postJson('/api/v1/products', ['name' => 'My Product', 'sku' => 'SHARED-1'])
            ->assertStatus(201)
            ->assertJson(['data' => ['sku' => 'SHARED-1']]);
    }

    /** @test */
    public function a_service_product_is_created_without_any_stock(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Haircut',
            'unit' => Product::UNIT_SERVICE,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    /** @test */
    public function authenticated_user_can_view_their_product_with_ledger_stock(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '10',
            'created_by' => $this->user->id,
        ]);
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_REMOVE,
            'quantity' => '-3',
            'created_by' => $this->user->id,
        ]);

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product retrieved successfully',
                'data' => [
                    'id' => $product->id,
                    'current_stock' => '7',
                ],
            ]);
    }

    /** @test */
    public function viewing_another_businesss_product_returns_404(): void
    {
        $product = Product::factory()->create(['business_id' => $this->otherBusiness->id]);

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found',
                'errors' => [],
            ]);
    }

    /** @test */
    public function authenticated_user_can_update_their_product(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Old Product',
        ]);

        $response = $this->putJson('/api/v1/products/'.$product->id, [
            'name' => 'Renamed Product',
            'selling_price' => 19.99,
            'status' => Product::STATUS_INACTIVE,
            // Hostile input: business_id can never change.
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => [
                    'name' => 'Renamed Product',
                    'name' => 'Renamed Product',
                    'status' => 'inactive',
                ],
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'business_id' => $this->business->id,
            'name' => 'Renamed Product',
            'status' => 'inactive',
        ]);
    }

    /** @test */
    public function patch_endpoint_updates_a_product(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Before Patch',
        ]);

        $this->patchJson('/api/v1/products/'.$product->id, ['name' => 'After Patch'])
            ->assertStatus(200)
            ->assertJson(['data' => ['name' => 'After Patch']]);
    }

    /** @test */
    public function posting_a_stock_field_on_update_does_not_change_inventory(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '4',
            'created_by' => $this->user->id,
        ]);

        // A client that posts a `stock` field changes nothing: it is not part
        // of the validated payload and no stock column exists to write.
        $this->patchJson('/api/v1/products/'.$product->id, [
            'name' => 'Still The Same',
            'stock' => 999,
        ])
            ->assertStatus(200)
            ->assertJson(['data' => ['current_stock' => '4']]);

        // The ledger is untouched — no movement was created by the update.
        $this->assertDatabaseCount('stock_movements', 1);
    }

    /** @test */
    public function updating_a_product_to_a_duplicate_sku_returns_422(): void
    {
        Product::factory()->create(['business_id' => $this->business->id, 'sku' => 'TAKEN']);
        $product = Product::factory()->create(['business_id' => $this->business->id, 'sku' => 'MINE']);

        $response = $this->putJson('/api/v1/products/'.$product->id, ['sku' => 'TAKEN']);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['sku']]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'MINE']);
    }

    /** @test */
    public function updating_a_product_with_another_businesss_category_returns_422(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $response = $this->putJson('/api/v1/products/'.$product->id, [
            'category_id' => $this->otherCategory->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['category_id']]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => null]);
    }

    /** @test */
    public function updating_another_businesss_product_returns_404(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign Product',
        ]);

        $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Hijacked'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Foreign Product']);
    }

    /** @test */
    public function deleting_a_product_soft_deletes_it_and_preserves_history(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        StockMovement::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'quantity' => '6',
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson('/api/v1/products/'.$product->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Product deleted successfully',
            ])
            ->assertJsonPath('data', null);

        // The row disappears from the catalogue but stays in the database...
        $this->assertSoftDeleted('products', ['id' => $product->id]);

        // ...and the inventory ledger (and any other history) is untouched.
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id]);
    }

    /** @test */
    public function a_deleted_product_disappears_from_the_list(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->deleteJson('/api/v1/products/'.$product->id)->assertStatus(200);

        $list = $this->getJson('/api/v1/products');
        $list->assertStatus(200);
        $this->assertCount(0, $list->json('data.items'));
    }

    /** @test */
    public function deleting_another_businesss_product_returns_404(): void
    {
        $product = Product::factory()->create(['business_id' => $this->otherBusiness->id]);

        $this->deleteJson('/api/v1/products/'.$product->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }
}
