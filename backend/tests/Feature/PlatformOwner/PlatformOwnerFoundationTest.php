<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformOwner;

use App\Models\Business;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Step 1 — database-level foundation for the single Platform Owner identity.
 *
 * The Platform Owner is deliberately *not* a tenant role: the identity lives
 * only in `users.platform_role` and must never be written to `roles`,
 * `permissions`, `permission_role`, `role_user` or `user_permissions`. What
 * this suite proves:
 *
 *  1. A normal user carries `platform_role = NULL`, and a database with zero
 *     owners (the bootstrap state) is valid.
 *  2. The Platform Owner is `platform_role = owner` with `business_id = NULL`.
 *  3. The *database* — not application code — rejects a second `owner` row.
 *     The rejection is proven through the raw query builder, which never runs
 *     Eloquent, so only the unique index can be refusing.
 *  4. Creating the owner leaves no tenant role/permission rows behind.
 *  5. Tenant users keep resolving their permissions exactly as before, both at
 *     the model layer and end-to-end through the `can:` route middleware.
 *
 * The suite runs on the shared SQLite test database. SQLite shares MySQL's
 * "every NULL is distinct inside a unique index" semantics, so the constraint
 * exercised here is the same one production MySQL enforces over the same
 * column.
 */
class PlatformOwnerFoundationTest extends TestCase
{
    use InteractsWithPermissions;
    use InteractsWithPosApi;
    use RefreshDatabase;

    /**
     * NULL is the marker for "not a platform identity". Every existing row
     * receives it during the migration, and a fresh database with zero owners
     * is a legitimate bootstrap state — nothing demands an owner to exist.
     */
    public function test_normal_user_has_null_platform_role_by_default(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->assertNull($user->platform_role);
        $this->assertFalse($user->isPlatformOwner());
        $this->assertSame(
            0,
            User::query()->where('platform_role', User::PLATFORM_ROLE_OWNER)->count(),
            'Zero platform owners must be a valid state.',
        );
    }

    public function test_platform_owner_is_created_without_a_business(): void
    {
        $owner = User::factory()->platformOwner()->create();

        $this->assertSame(User::PLATFORM_ROLE_OWNER, $owner->platform_role);
        $this->assertNull($owner->business_id);
        $this->assertTrue($owner->isPlatformOwner());

        // Assert the persisted row, not only the in-memory model.
        $row = DB::table('users')->where('id', $owner->getKey())->first();
        $this->assertSame('owner', $row->platform_role);
        $this->assertNull($row->business_id);
    }

    /**
     * The core invariant, proven against the schema itself: the raw query
     * builder bypasses Eloquent entirely — including the model's saving hook —
     * so a refused insert can only be the unique index doing its job. Both the
     * raw path and the Eloquent path must be refused, and exactly one owner
     * may remain.
     */
    public function test_database_rejects_a_second_platform_owner(): void
    {
        User::factory()->platformOwner()->create();

        try {
            DB::table('users')->insert([
                'name' => 'Second Owner',
                'email' => 'second-owner@example.com',
                'password' => 'irrelevant-hash',
                'platform_role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A second platform owner must be rejected by the database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('platform_role', $exception->getMessage());
        }

        try {
            User::factory()->platformOwner()->create(['email' => 'eloquent-second-owner@example.com']);
            $this->fail('A second platform owner must be rejected on the Eloquent path too.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('platform_role', $exception->getMessage());
        }

        $this->assertSame(
            1,
            User::query()->where('platform_role', User::PLATFORM_ROLE_OWNER)->count(),
        );
        $this->assertSame(1, User::query()->count(), 'Both refused inserts must have left no rows.');
    }

    /**
     * The counterpart of the single-owner rule: the unique index must not
     * throttle ordinary users. Every non-owner row is NULL, and NULLs never
     * collide — the property MySQL and SQLite share.
     */
    public function test_unlimited_non_owner_users_can_share_a_null_platform_role(): void
    {
        $business = Business::factory()->create();

        foreach (range(1, 3) as $i) {
            User::factory()->create([
                'business_id' => $business->id,
                'email' => "staff-{$i}@example.com",
            ]);
        }

        $this->assertSame(3, User::query()->whereNull('platform_role')->count());
        $this->assertSame(0, User::query()->whereNotNull('platform_role')->count());
    }

    /**
     * The Platform Owner is an identity, not a tenant principal: creating one
     * must not materialise a single row in the tenant authorization tables.
     */
    public function test_platform_owner_is_not_assigned_tenant_roles_or_permissions(): void
    {
        $owner = User::factory()->platformOwner()->create();

        $this->assertSame([], $owner->roles->all());
        $this->assertSame([], $owner->permissionSlugs());
        $this->assertSame([], $owner->effectivePermissionSlugs());
        $this->assertFalse($owner->hasPermission(PermissionService::USERS_VIEW));

        $this->assertSame(0, DB::table('role_user')->where('user_id', $owner->getKey())->count());
        $this->assertSame(0, DB::table('user_permissions')->where('user_id', $owner->getKey())->count());
    }

    public function test_owner_cannot_be_created_inside_a_business(): void
    {
        $business = Business::factory()->create();

        $this->expectException(ValidationException::class);

        User::factory()->create([
            'business_id' => $business->id,
            'platform_role' => User::PLATFORM_ROLE_OWNER,
        ]);
    }

    /**
     * Promoting an existing tenant user to Platform Owner without first
     * detaching their business must be refused on save, not silently accepted.
     */
    public function test_promoting_a_business_user_to_owner_is_rejected_on_save(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $user->platform_role = User::PLATFORM_ROLE_OWNER;

        $this->expectException(ValidationException::class);

        $user->save();
    }

    /**
     * Only `owner` may ever be written to the column; a junk value would
     * otherwise consume the unique index slot or fail with a confusing
     * duplicate-key error instead of a validation message.
     */
    public function test_unrecognised_platform_role_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $user->platform_role = 'admin';

        $this->expectException(ValidationException::class);

        $user->save();
    }

    /**
     * Regression guard: a tenant user with `platform_role = NULL` behaves
     * exactly as before this step — model-level capability resolution and the
     * `can:` route middleware both keep working, and the refused module stays
     * refused.
     */
    public function test_existing_tenant_users_keep_their_authorization_behaviour(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermissions([PermissionService::USERS_VIEW], $business->id);

        $this->assertNull($user->platform_role);
        $this->assertFalse($user->isPlatformOwner());

        $this->assertTrue($user->hasPermission(PermissionService::USERS_VIEW));
        $this->assertFalse($user->hasPermission(PermissionService::PRODUCTS_VIEW));
        $this->assertContains(PermissionService::USERS_VIEW, $user->effectivePermissionSlugs());

        $this->getJson('/api/v1/users')->assertOk();
        $this->getJson('/api/v1/products')->assertForbidden();
    }

    /**
     * Static schema verification: the column is nullable and carries a
     * single-column unique index — the artefact production MySQL relies on to
     * reject a second owner.
     */
    public function test_platform_role_column_is_nullable_and_uniquely_indexed(): void
    {
        $column = collect(Schema::getColumns('users'))
            ->firstWhere('name', 'platform_role');

        $this->assertNotNull($column, 'The platform_role column must exist.');
        $this->assertTrue($column['nullable'], 'platform_role must be nullable.');

        $uniqueOnPlatformRole = collect(Schema::getIndexes('users'))
            ->first(
                fn (array $index): bool => $index['unique']
                    && $index['columns'] === ['platform_role'],
            );

        $this->assertNotNull(
            $uniqueOnPlatformRole,
            'A single-column unique index on platform_role is the single-owner guarantee.',
        );
    }
}
