<?php

declare(strict_types=1);

namespace Tests\Feature\Category;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Phase 20 — Category API contract and business-scoping guarantees.
 */
class CategoryTest extends TestCase
{
    use InteractsWithPosApi, RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Business $otherBusiness;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = $this->actingAsBusinessUser($this->business);

        $this->otherBusiness = Business::factory()->create();
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);
    }

    /** @test */
    public function category_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        $unauthenticated = [
            ['GET', '/api/v1/categories'],
            ['POST', '/api/v1/categories'],
            ['GET', '/api/v1/categories/1'],
            ['PUT', '/api/v1/categories/1'],
            ['PATCH', '/api/v1/categories/1'],
            ['DELETE', '/api/v1/categories/1'],
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
    public function authenticated_user_can_list_categories(): void
    {
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Drinks']);
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Snacks']);
        // Another tenant's category must never appear.
        Category::factory()->create(['business_id' => $this->otherBusiness->id, 'name' => 'Other Tenant']);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1]],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [[
                        'id',
                        'name',
                        'slug',
                        'description',
                        'status',
                        'products_count',
                        'created_at',
                        'updated_at',
                    ]],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                ],
            ]);

        $this->assertEqualsCanonicalizing(
            ['Drinks', 'Snacks'],
            array_column($response->json('data.items'), 'name'),
        );

        // Internal/tenant fields never leak.
        $this->assertArrayNotHasKey('business_id', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.items.0'));
    }

    /** @test */
    public function category_list_supports_search(): void
    {
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Hot Drinks']);
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Groceries']);

        $response = $this->getJson('/api/v1/categories?search=drink');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Hot Drinks', $response->json('data.items.0.name'));
    }

    /** @test */
    public function category_list_supports_status_filtering(): void
    {
        Category::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Active One',
            'status' => Category::STATUS_ACTIVE,
        ]);
        Category::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Inactive One',
            'status' => Category::STATUS_INACTIVE,
        ]);

        $response = $this->getJson('/api/v1/categories?status=inactive');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Inactive One', $response->json('data.items.0.name'));
    }

    /** @test */
    public function category_list_is_paginated(): void
    {
        Category::factory()->count(5)->create(['business_id' => $this->business->id]);

        $response = $this->getJson('/api/v1/categories?per_page=2&page=2');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['pagination' => [
                    'current_page' => 2,
                    'per_page' => 2,
                    'total' => 5,
                    'last_page' => 3,
                    'from' => 3,
                    'to' => 4,
                ]],
            ]);

        $this->assertCount(2, $response->json('data.items'));
    }

    /** @test */
    public function category_list_rejects_sort_columns_outside_the_whitelist(): void
    {
        $response = $this->getJson('/api/v1/categories?sort=password');

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['sort']]);
    }

    /** @test */
    public function category_list_sorts_by_whitelisted_columns(): void
    {
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Alpha']);
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Zeta']);

        $response = $this->getJson('/api/v1/categories?sort=name&direction=desc');

        $response->assertStatus(200);
        $this->assertSame('Zeta', $response->json('data.items.0.name'));
    }

    /** @test */
    public function authenticated_user_can_create_a_category(): void
    {
        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Beverages',
            'description' => 'Drinks and more',
            'status' => Category::STATUS_ACTIVE,
            // Hostile input: must be ignored, the tenant comes from the token.
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => ['name' => 'Beverages', 'description' => 'Drinks and more', 'status' => 'active'],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'slug', 'description', 'status', 'products_count', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('categories', [
            'id' => $response->json('data.id'),
            'business_id' => $this->business->id,
            'name' => 'Beverages',
        ]);

        // business_id is never part of the response payload.
        $this->assertArrayNotHasKey('business_id', $response->json('data'));
    }

    /** @test */
    public function creating_a_category_without_a_name_returns_the_validation_envelope(): void
    {
        $response = $this->postJson('/api/v1/categories', ['description' => 'name missing']);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);

        $this->assertStringContainsString('name', (string) $response->json('errors.name.0'));
        $this->assertDatabaseCount('categories', 0);
    }

    /** @test */
    public function duplicate_category_names_are_rejected_within_the_same_business(): void
    {
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Drinks']);

        $response = $this->postJson('/api/v1/categories', ['name' => 'Drinks']);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);

        $this->assertDatabaseCount('categories', 1);
    }

    /** @test */
    public function the_same_category_name_may_be_used_by_another_business(): void
    {
        Category::factory()->create(['business_id' => $this->otherBusiness->id, 'name' => 'Drinks']);

        $this->postJson('/api/v1/categories', ['name' => 'Drinks'])
            ->assertStatus(201)
            ->assertJson(['data' => ['name' => 'Drinks']]);
    }

    /** @test */
    public function authenticated_user_can_view_their_category(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->getJson('/api/v1/categories/'.$category->id)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Category retrieved successfully',
                'data' => ['id' => $category->id, 'name' => $category->name],
            ]);
    }

    /** @test */
    public function viewing_another_businesss_category_returns_404(): void
    {
        $category = Category::factory()->create(['business_id' => $this->otherBusiness->id]);

        $this->getJson('/api/v1/categories/'.$category->id)
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found',
                'errors' => [],
            ]);
    }

    /** @test */
    public function authenticated_user_can_update_their_category(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Old Name']);

        $response = $this->putJson('/api/v1/categories/'.$category->id, [
            'name' => 'New Name',
            'description' => 'Updated description',
            'status' => Category::STATUS_INACTIVE,
            // Hostile input: business_id can never change.
            'business_id' => $this->otherBusiness->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => ['name' => 'New Name', 'status' => 'inactive'],
            ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'business_id' => $this->business->id,
            'name' => 'New Name',
            'status' => 'inactive',
        ]);
    }

    /** @test */
    public function patch_endpoint_updates_a_category(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Before Patch']);

        $this->patchJson('/api/v1/categories/'.$category->id, ['name' => 'After Patch'])
            ->assertStatus(200)
            ->assertJson(['data' => ['name' => 'After Patch']]);
    }

    /** @test */
    public function updating_a_category_to_a_duplicate_name_returns_422(): void
    {
        Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Drinks']);
        $category = Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Food']);

        $response = $this->putJson('/api/v1/categories/'.$category->id, ['name' => 'Drinks']);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Food']);
    }

    /** @test */
    public function a_category_may_keep_its_own_name_on_update(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id, 'name' => 'Stable']);

        $this->putJson('/api/v1/categories/'.$category->id, ['name' => 'Stable'])
            ->assertStatus(200)
            ->assertJson(['data' => ['name' => 'Stable']]);
    }

    /** @test */
    public function updating_another_businesss_category_returns_404(): void
    {
        $category = Category::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Foreign Category',
        ]);

        $this->putJson('/api/v1/categories/'.$category->id, ['name' => 'Hijacked'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Foreign Category']);
    }

    /** @test */
    public function an_unused_category_can_be_deleted(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $response = $this->deleteJson('/api/v1/categories/'.$category->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Category deleted successfully',
            ])
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    /** @test */
    public function deleting_a_category_in_use_returns_a_conflict_and_keeps_it(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $response = $this->deleteJson('/api/v1/categories/'.$category->id);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete category because it is in use.',
                'errors' => [],
            ]);

        // Neither the category nor the referencing product is deleted.
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    /** @test */
    public function deleting_another_businesss_category_returns_404(): void
    {
        $category = Category::factory()->create(['business_id' => $this->otherBusiness->id]);

        $this->deleteJson('/api/v1/categories/'.$category->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    /** @test */
    public function error_responses_never_leak_internal_details(): void
    {
        $foreign = Category::factory()->create(['business_id' => $this->otherBusiness->id]);

        $body = (string) $this->getJson('/api/v1/categories/'.$foreign->id)->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }
}
