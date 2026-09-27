<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Locks down the standard API envelope (see docs/API_ARCHITECTURE.md).
 */
class ApiResponseTest extends TestCase
{
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

    /** @test */
    public function paginated_response_shapes_items_through_a_resource(): void
    {
        $user = new User;
        $user->id = 42;
        $user->business_id = 7;
        $user->name = 'Ada Lovelace';
        $user->email = 'ada@example.com';

        $paginator = new LengthAwarePaginator([$user], 1, 20, 1);

        $response = ApiResponse::paginated($paginator, 'Users retrieved successfully', UserResource::class);

        $item = $response->getData(true)['data']['items'][0];

        $this->assertSame([
            'id' => 42,
            'business_id' => 7,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'created_at' => null,
        ], $item);

        // Sensitive attributes are never part of the payload.
        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('remember_token', $item);
    }
}
