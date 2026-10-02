<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permission\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Locks down the standard API envelope (see docs/API_ARCHITECTURE.md).
 *
 * `RefreshDatabase` is required even though most of this class is pure response
 * shaping: the resource-shaping test below serialises a real `User` through
 * `UserResource`, and that resource resolves the user's capabilities through
 * `PermissionService` — which reads `user_permissions` and the role/permission
 * joins. Without the migrated schema the in-memory SQLite connection has no
 * tables at all and the serializer dies on `no such table: user_permissions`.
 * Running the real migrations is what keeps the test exercising the genuine
 * capability resolution instead of a stubbed one.
 */
class ApiResponseTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function success_response_uses_the_standard_envelope(): void
    {
        $response = ApiResponse::success(['id' => 1, 'name' => 'Product'], 'Product retrieved successfully');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Product retrieved successfully',
            'data' => ['id' => 1, 'name' => 'Product'],
        ], $response->getData(true));
    }

    /** @test */
    public function success_response_defaults_to_an_empty_data_payload(): void
    {
        $response = ApiResponse::success();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Success',
            'data' => null,
        ], $response->getData(true));
    }

    /** @test */
    public function success_response_accepts_a_created_status(): void
    {
        $response = ApiResponse::success(['id' => 7], 'Product created successfully', 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Product created successfully', $response->getData(true)['message']);
    }

    /** @test */
    public function error_response_uses_the_standard_envelope(): void
    {
        $response = ApiResponse::error('Conflict', ['sku' => ['The sku has already been taken.']], 409);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Conflict',
            'errors' => ['sku' => ['The sku has already been taken.']],
        ], $response->getData(true));
    }

    /** @test */
    public function error_response_defaults_to_a_generic_message_and_bad_request(): void
    {
        $response = ApiResponse::error();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Something went wrong',
            'errors' => [],
        ], $response->getData(true));
    }

    /** @test */
    public function error_response_serialises_an_empty_error_bag_as_an_object(): void
    {
        $response = ApiResponse::error('Unauthenticated', [], 401);

        $this->assertStringContainsString('"errors":{}', (string) $response->getContent());
        $this->assertSame([], $response->getData(true)['errors']);
    }

    /** @test */
    public function validation_response_returns_422_with_the_error_bag(): void
    {
        $errors = ['email' => ['The email field is required.']];

        $response = ApiResponse::validation($errors);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors,
        ], $response->getData(true));
    }

    /** @test */
    public function paginated_response_maps_items_and_pagination_metadata(): void
    {
        $paginator = new LengthAwarePaginator([['id' => 1], ['id' => 2]], 42, 20, 2);

        $response = ApiResponse::paginated($paginator, 'Products retrieved successfully');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Products retrieved successfully',
            'data' => [
                'items' => [['id' => 1], ['id' => 2]],
                'pagination' => [
                    'current_page' => 2,
                    'per_page' => 20,
                    'total' => 42,
                    'last_page' => 3,
                    'from' => 21,
                    'to' => 22,
                ],
            ],
        ], $response->getData(true));
    }

    /**
     * @test
     *
     * The paginated envelope must shape items through the real resource, so the
     * user is a genuine persisted row with a real role grant: the `capabilities`
     * key is then the one `PermissionService` actually resolves from
     * `role_user` / `permission_role` / `permissions`, which is what makes this
     * test a resource-transformation test rather than a snapshot of an array
     * literal. A synthetic `new User` cannot do that — resolving its
     * capabilities would query rows that do not exist.
     */
    public function paginated_response_shapes_items_through_a_resource(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create([
            'business_id' => $business->getKey(),
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        // Exactly one capability, so the resolved list is unambiguous and a
        // regression that grants everything (or nothing) is visible here.
        $permission = Permission::create([
            'name' => 'View products',
            'slug' => PermissionService::slugFor(PermissionService::PRODUCTS_VIEW),
        ]);

        $role = Role::create([
            'business_id' => $business->getKey(),
            'name' => 'Catalogue reader',
            'slug' => 'catalogue-reader',
            'description' => 'Fixture role granting a single capability.',
        ]);

        $role->permissions()->sync([$permission->getKey()]);
        $user->roles()->sync([$role->getKey()]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $paginator = new LengthAwarePaginator([$user], 1, 20, 1);

        $response = ApiResponse::paginated($paginator, 'Users retrieved successfully', UserResource::class);

        $item = $response->getData(true)['data']['items'][0];

        $this->assertSame([
            'id' => $user->getKey(),
            'business_id' => $business->getKey(),
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'created_at' => $user->created_at->toDateTimeString(),
            'capabilities' => [PermissionService::PRODUCTS_VIEW],
        ], $item);

        // Sensitive attributes are never part of the payload.
        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('remember_token', $item);

        // The same resolution the Gate enforces with, computed for the client.
        $this->assertSame(
            [PermissionService::PRODUCTS_VIEW],
            app(PermissionService::class)->resolvedCapabilities($user),
        );
    }
}
