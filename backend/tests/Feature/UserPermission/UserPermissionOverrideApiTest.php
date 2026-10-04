<?php

declare(strict_types=1);

namespace Tests\Feature\UserPermission;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * STEP 4 — the user permission override management API.
 *
 * Covers the four operations (list, upsert, delete, full replace), the tenant
 * boundary, validation of the global permission catalogue, transactional safety
 * of the replace, and the anti-self-escalation rule. Every scenario is driven
 * through real HTTP requests so the `auth:sanctum` + `active` + `can:` chain is
 * part of what is under test.
 */
class UserPermissionOverrideApiTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    private Business $business;

    private User $admin;

    private User $member;

    private Business $otherBusiness;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();

        $this->member = User::factory()->create(['business_id' => $this->business->id]);
        $this->otherUser = User::factory()->create(['business_id' => $this->otherBusiness->id]);

        // Exactly the one capability the routes require — no blanket admin.
        $this->admin = $this->userWithPermissions(
            [PermissionService::USERS_ROLES_UPDATE],
            $this->business->id,
        );
    }

    // ------------------------------------------------------------------
    // A. List
    // ------------------------------------------------------------------

    /** @test */
    public function an_authorised_admin_can_list_a_users_overrides(): void
    {
        $productsDelete = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $salesView = $this->permissionBySlug(PermissionService::SALES_VIEW);

        $this->seedOverride($this->business, $this->member, $productsDelete, UserPermission::EFFECT_DENY);
        $this->seedOverride($this->business, $this->member, $salesView, UserPermission::EFFECT_ALLOW);

        $response = $this->getJson($this->uri($this->member));

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User permission overrides retrieved successfully'])
            ->assertJsonPath('data.user_id', $this->member->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonStructure([
                'data' => ['items' => [['id', 'permission_id', 'effect', 'permission' => ['id', 'slug', 'name']]]],
            ]);

        $items = collect($response->json('data.items'))->keyBy('permission.slug');

        $this->assertSame('deny', $items[PermissionService::PRODUCTS_DELETE]['effect']);
        $this->assertSame('allow', $items[PermissionService::SALES_VIEW]['effect']);
    }

    /** @test */
    public function the_list_never_exposes_another_users_overrides(): void
    {
        $this->seedOverride(
            $this->business,
            $this->member,
            $this->permissionBySlug(PermissionService::PRODUCTS_DELETE),
            UserPermission::EFFECT_DENY,
        );

        $this->seedOverride(
            $this->business,
            User::factory()->create(['business_id' => $this->business->id]),
            $this->permissionBySlug(PermissionService::SALES_VIEW),
            UserPermission::EFFECT_ALLOW,
        );

        $response = $this->getJson($this->uri($this->member));

        $response->assertStatus(200)->assertJsonCount(1, 'data.items');

        $this->assertSame(
            [PermissionService::PRODUCTS_DELETE],
            collect($response->json('data.items'))->pluck('permission.slug')->all(),
        );
    }

    /** @test */
    public function the_list_omits_overrides_that_belong_to_another_business(): void
    {
        // A forged cross-business row for this very user: the resolver ignores
        // it, so the management screen must not list it either.
        $this->seedOverride(
            $this->otherBusiness,
            $this->member,
            $this->permissionBySlug(PermissionService::PRODUCTS_DELETE),
            UserPermission::EFFECT_DENY,
        );

        $this->getJson($this->uri($this->member))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');
    }

    // ------------------------------------------------------------------
    // B. Create / update one override
    // ------------------------------------------------------------------

    /** @test */
    public function an_authorised_admin_can_create_an_allow_override(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $response = $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_ALLOW,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'User permission override saved successfully'])
            ->assertJsonPath('data.items.0.permission_id', $permission->id)
            ->assertJsonPath('data.items.0.effect', 'allow')
            ->assertJsonPath('data.items.0.permission.slug', PermissionService::PRODUCTS_DELETE);

        // The row is written for the right user, the right permission, and —
        // critically — the authenticated user's business, never a client-supplied
        // one.
        $this->assertDatabaseHas('user_permissions', [
            'business_id' => $this->business->id,
            'user_id' => $this->member->id,
            'permission_id' => $permission->id,
            'effect' => 'allow',
        ]);
    }

    /** @test */
    public function an_authorised_admin_can_create_a_deny_override(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_DENY,
        ])->assertStatus(201);

        $this->assertDatabaseHas('user_permissions', [
            'business_id' => $this->business->id,
            'user_id' => $this->member->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
    }

    /** @test */
    public function an_existing_override_is_updated_rather_than_duplicated(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_ALLOW,
        ])->assertStatus(201);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_DENY,
        ])->assertStatus(201);

        $this->assertSame(1, DB::table('user_permissions')
            ->where('user_id', $this->member->id)
            ->where('permission_id', $permission->id)
            ->count());

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
    }

    /** @test */
    public function the_endpoint_ignores_a_client_supplied_business_id(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => UserPermission::EFFECT_ALLOW,
            'business_id' => $this->otherBusiness->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('user_permissions', [
            'business_id' => $this->business->id,
            'user_id' => $this->member->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertDatabaseMissing('user_permissions', [
            'business_id' => $this->otherBusiness->id,
        ]);
    }

    // ------------------------------------------------------------------
    // C. Delete one override
    // ------------------------------------------------------------------

    /** @test */
    public function an_override_can_be_deleted(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->seedOverride($this->business, $this->member, $permission, UserPermission::EFFECT_DENY);

        $this->deleteJson($this->uri($this->member).'/'.$permission->id)
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User permission override deleted successfully']);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $this->member->id,
            'permission_id' => $permission->id,
        ]);
    }

    /** @test */
    public function deleting_an_override_the_user_does_not_hold_is_a_404(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->deleteJson($this->uri($this->member).'/'.$permission->id)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);
    }

    // ------------------------------------------------------------------
    // D. Sync / replace the complete set
    // ------------------------------------------------------------------

    /** @test */
    public function sync_makes_the_database_match_the_submitted_set(): void
    {
        $a = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $b = $this->permissionBySlug(PermissionService::SALES_VIEW);
        $c = $this->permissionBySlug(PermissionService::SALES_CREATE);

        // Existing: A allow, B deny.
        $this->seedOverride($this->business, $this->member, $a, UserPermission::EFFECT_ALLOW);
        $this->seedOverride($this->business, $this->member, $b, UserPermission::EFFECT_DENY);

        // Submitted: B allow, C deny.
        $response = $this->putJson($this->uri($this->member), [
            'overrides' => [
                ['permission_id' => $b->id, 'effect' => 'allow'],
                ['permission_id' => $c->id, 'effect' => 'deny'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User permission overrides updated successfully'])
            ->assertJsonCount(2, 'data.items');

        // Exactly the submitted set: A is gone, B flipped, C created. Keyed by
        // permission id (not collapsed — `Collection::collapse()` renumbers
        // integer keys) and ordered by id, exactly as the response is.
        $rows = DB::table('user_permissions')
            ->where('business_id', $this->business->id)
            ->where('user_id', $this->member->id)
            ->orderBy('permission_id')
            ->pluck('effect', 'permission_id')
            ->map(static fn ($effect): string => (string) $effect)
            ->all();

        $this->assertSame([$b->id => 'allow', $c->id => 'deny'], $rows);
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $this->member->id,
            'permission_id' => $a->id,
        ]);
    }

    /** @test */
    public function patch_syncs_identically_to_put(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->seedOverride($this->business, $this->member, $permission, UserPermission::EFFECT_ALLOW);

        $this->patchJson($this->uri($this->member), [
            'overrides' => [['permission_id' => $permission->id, 'effect' => 'deny']],
        ])->assertStatus(200);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
    }

    /** @test */
    public function an_empty_sync_removes_every_override(): void
    {
        $this->seedOverride(
            $this->business,
            $this->member,
            $this->permissionBySlug(PermissionService::PRODUCTS_DELETE),
            UserPermission::EFFECT_DENY,
        );

        $this->putJson($this->uri($this->member), ['overrides' => []])->assertStatus(200);

        $this->assertSame(0, DB::table('user_permissions')->where('user_id', $this->member->id)->count());
    }

    // ------------------------------------------------------------------
    // Transactional safety
    // ------------------------------------------------------------------

    /** @test */
    public function a_sync_rejected_by_validation_leaves_the_existing_set_untouched(): void
    {
        $kept = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $dropped = $this->permissionBySlug(PermissionService::SALES_VIEW);

        $this->seedOverride($this->business, $this->member, $kept, UserPermission::EFFECT_ALLOW);
        $this->seedOverride($this->business, $this->member, $dropped, UserPermission::EFFECT_DENY);

        // A mix of one valid entry and one unknown permission id: the whole
        // request is refused, so the valid half must not be applied either.
        $this->putJson($this->uri($this->member), [
            'overrides' => [
                ['permission_id' => $kept->id, 'effect' => 'deny'],
                ['permission_id' => 999999, 'effect' => 'allow'],
            ],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['overrides.1.permission_id']]);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id, 'permission_id' => $kept->id, 'effect' => 'allow',
        ]);
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id, 'permission_id' => $dropped->id, 'effect' => 'deny',
        ]);
    }

    /** @test */
    public function a_database_failure_mid_sync_rolls_the_whole_set_back(): void
    {
        $a = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $b = $this->permissionBySlug(PermissionService::SALES_VIEW);
        $c = $this->permissionBySlug(PermissionService::SALES_CREATE);

        $this->seedOverride($this->business, $this->member, $a, UserPermission::EFFECT_ALLOW);
        $this->seedOverride($this->business, $this->member, $b, UserPermission::EFFECT_DENY);

        // Blow up *after* the reconciliation DELETE has already run, i.e. at the
        // exact point where a non-transactional implementation would leave the
        // user with only part of the submitted set.
        Event::listen('eloquent.creating: '.UserPermission::class, static function (): void {
            throw new \RuntimeException('forced failure inside the transaction');
        });

        $this->withoutExceptionHandling();

        try {
            $this->putJson($this->uri($this->member), [
                'overrides' => [
                    ['permission_id' => $b->id, 'effect' => 'allow'],
                    ['permission_id' => $c->id, 'effect' => 'deny'],
                ],
            ])->assertStatus(500);
        } catch (\Throwable $e) {
            // `withoutExceptionHandling()` rethrows instead of rendering the 500.
            $this->assertStringContainsString('forced failure', $e->getMessage());
        }

        $this->withExceptionHandling();

        // Rollback: the pre-sync set is intact — A still present, B still DENY,
        // and the new C was never committed.
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id, 'permission_id' => $a->id, 'effect' => 'allow',
        ]);
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id, 'permission_id' => $b->id, 'effect' => 'deny',
        ]);
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $this->member->id, 'permission_id' => $c->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /** @test */
    public function an_unknown_permission_id_is_rejected_and_writes_nothing(): void
    {
        $this->postJson($this->uri($this->member), [
            'permission_id' => 999999,
            'effect' => 'allow',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['permission_id']]);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function a_non_integer_permission_id_is_rejected(): void
    {
        $this->postJson($this->uri($this->member), [
            'permission_id' => 'products.delete',
            'effect' => 'allow',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['permission_id']]);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function a_missing_permission_id_is_rejected(): void
    {
        $this->postJson($this->uri($this->member), ['effect' => 'allow'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['permission_id']]);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function only_allow_and_deny_are_accepted_as_an_effect(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $invalid = [
            'foo',
            '',
            null,
        ];

        foreach ($invalid as $effect) {
            $this->postJson($this->uri($this->member), [
                'permission_id' => $permission->id,
                'effect' => $effect,
            ])->assertStatus(422)->assertJsonStructure(['errors' => ['effect']]);
        }

        // A missing effect is rejected too.
        $this->postJson($this->uri($this->member), ['permission_id' => $permission->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['effect']]);

        $this->assertSame(0, DB::table('user_permissions')->count());

        // ...and the two valid states are accepted.
        foreach (UserPermission::EFFECTS as $effect) {
            $this->postJson($this->uri($this->member), [
                'permission_id' => $permission->id,
                'effect' => $effect,
            ])->assertStatus(201);
        }
    }

    /** @test */
    public function a_duplicate_permission_id_in_a_sync_is_rejected(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->putJson($this->uri($this->member), [
            'overrides' => [
                ['permission_id' => $permission->id, 'effect' => 'allow'],
                ['permission_id' => $permission->id, 'effect' => 'deny'],
            ],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['overrides.1.permission_id']]);

        // The message names the offending entry, and nothing was written.
        $response = $this->putJson($this->uri($this->member), [
            'overrides' => [
                ['permission_id' => $permission->id, 'effect' => 'allow'],
                ['permission_id' => $permission->id, 'effect' => 'deny'],
            ],
        ])->assertStatus(422);

        // The message names the offending entry, and nothing was written. The
        // error bag is keyed by the literal attribute path, so it is read
        // directly rather than through `json()`'s dot notation.
        $errors = (array) $response->json('errors');

        $this->assertStringContainsString(
            'more than once',
            (string) ($errors['overrides.1.permission_id'][0] ?? ''),
        );
        $this->assertStringContainsString(
            'more than once',
            (string) ($errors['overrides.0.permission_id'][0] ?? ''),
        );

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function a_sync_entry_without_an_effect_is_rejected(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->putJson($this->uri($this->member), [
            'overrides' => [['permission_id' => $permission->id]],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['overrides.0.effect']]);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function a_sync_payload_that_is_not_an_array_is_rejected(): void
    {
        $this->putJson($this->uri($this->member), ['overrides' => 'products.delete'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['overrides']]);

        $this->putJson($this->uri($this->member), [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['overrides']]);
    }

    // ------------------------------------------------------------------
    // Cross-business protection
    // ------------------------------------------------------------------

    /** @test */
    public function business_a_cannot_manage_the_overrides_of_a_business_b_user(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        // A pre-existing override on the foreign user, to prove it is untouched.
        $foreign = $this->seedOverride(
            $this->otherBusiness,
            $this->otherUser,
            $permission,
            UserPermission::EFFECT_DENY,
        );

        $uri = $this->uri($this->otherUser);

        // 404 (not 403) everywhere: the caller must not learn that the account
        // exists in a business that is not theirs.
        $this->getJson($uri)->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Resource not found']);

        $this->postJson($uri, ['permission_id' => $permission->id, 'effect' => 'allow'])
            ->assertStatus(404);

        $this->putJson($uri, ['overrides' => []])->assertStatus(404);

        $this->deleteJson($uri.'/'.$permission->id)->assertStatus(404);

        // No row created, none changed, none deleted.
        $this->assertSame(1, DB::table('user_permissions')->count());
        $this->assertDatabaseHas('user_permissions', [
            'id' => $foreign->id,
            'business_id' => $this->otherBusiness->id,
            'user_id' => $this->otherUser->id,
            'effect' => 'deny',
        ]);
    }

    /** @test */
    public function an_override_row_owned_by_another_business_cannot_be_manipulated(): void
    {
        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        // A forged row: correct user, but owned by a foreign business. The
        // `(business_id, user_id, permission_id)` key makes it a *different* row
        // from the tenant-scoped one, so it is invisible to every operation.
        $forged = $this->seedOverride(
            $this->otherBusiness,
            $this->member,
            $permission,
            UserPermission::EFFECT_ALLOW,
        );

        // A legitimate row in the caller's own business for the same permission.
        $owned = $this->seedOverride(
            $this->business,
            $this->member,
            $permission,
            UserPermission::EFFECT_DENY,
        );

        // Delete targets the business-scoped row only.
        $this->deleteJson($this->uri($this->member).'/'.$permission->id)->assertStatus(200);

        $this->assertDatabaseMissing('user_permissions', ['id' => $owned->id]);
        $this->assertDatabaseHas('user_permissions', ['id' => $forged->id, 'effect' => 'allow']);

        // Upserting the same permission again must not adopt or overwrite the
        // foreign row either.
        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ])->assertStatus(201);

        $this->assertDatabaseHas('user_permissions', ['id' => $forged->id, 'effect' => 'allow']);
        $this->assertDatabaseHas('user_permissions', [
            'business_id' => $this->business->id,
            'user_id' => $this->member->id,
            'effect' => 'deny',
        ]);
    }

    // ------------------------------------------------------------------
    // Authorization of the management API
    // ------------------------------------------------------------------

    /** @test */
    public function the_endpoints_require_authentication(): void
    {
        $this->flushHeaders();

        $uri = $this->uri($this->member);

        foreach ([
            ['get', $uri, []],
            ['post', $uri, ['permission_id' => 1, 'effect' => 'allow']],
            ['put', $uri, ['overrides' => []]],
            ['delete', $uri.'/1', []],
        ] as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)
                ->assertStatus(401)
                ->assertJson(['success' => false, 'message' => 'Unauthenticated', 'errors' => []]);
        }

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function a_user_without_the_capability_receives_403_on_every_operation(): void
    {
        $this->userWithoutPermissions($this->business->id);

        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $uri = $this->uri($this->member);

        foreach ([
            ['get', $uri, []],
            ['post', $uri, ['permission_id' => $permission->id, 'effect' => 'allow']],
            ['put', $uri, ['overrides' => [['permission_id' => $permission->id, 'effect' => 'allow']]]],
            ['delete', $uri.'/'.$permission->id, []],
        ] as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)
                ->assertStatus(403)
                ->assertJson(['success' => false, 'message' => 'Forbidden', 'errors' => []]);
        }

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function the_canonical_permission_authorises_override_management(): void
    {
        // The routes are gated on the explicit `users.roles.update` permission, so
        // a role holding exactly that one can drive the whole override API.
        $this->userWithPermissions([PermissionService::USERS_ROLES_UPDATE], $this->business->id);

        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->getJson($this->uri($this->member))->assertStatus(200);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => 'allow',
        ])->assertStatus(201);
    }

    /** @test */
    public function a_retired_manage_slug_no_longer_authorises_override_management(): void
    {
        // The retired `users.manage` row is no longer an alias for
        // `users.roles.update`. Until the cleanup migration removes it, a role
        // that still holds it must be refused — proof that the alias is gone from
        // the runtime rather than merely from the seeder.
        $coarse = $this->userWithLiteralPermissions(['users.manage'], $this->business->id);

        $this->assertFalse(
            $coarse->hasPermission(PermissionService::USERS_ROLES_UPDATE),
        );

        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->getJson($this->uri($this->member))->assertStatus(403);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => 'allow',
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function an_inactive_administrator_cannot_manage_overrides(): void
    {
        $this->admin->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $permission = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->getJson($this->uri($this->member))->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);

        $this->postJson($this->uri($this->member), [
            'permission_id' => $permission->id,
            'effect' => 'allow',
        ])->assertStatus(401);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    // ------------------------------------------------------------------
    // Self-escalation
    // ------------------------------------------------------------------

    /** @test */
    public function an_administrator_cannot_grant_themselves_an_allow_override(): void
    {
        // `users.roles.update` is the authorisation slug; a user holding it
        // must still not be able to hand *itself* a capability it lacks.
        $productsDelete = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $this->assertFalse($this->admin->hasPermission(PermissionService::PRODUCTS_DELETE));

        $self = $this->uri($this->admin);

        $this->postJson($self, [
            'permission_id' => $productsDelete->id,
            'effect' => UserPermission::EFFECT_ALLOW,
        ])->assertStatus(403)->assertJson(['success' => false, 'message' => 'Forbidden']);

        $this->assertSame(0, DB::table('user_permissions')->count());
        $this->assertFalse($this->admin->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
    }

    /** @test */
    public function an_administrator_cannot_sync_or_delete_their_own_overrides(): void
    {
        $productsDelete = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $self = $this->uri($this->admin);

        $this->putJson($self, [
            'overrides' => [['permission_id' => $productsDelete->id, 'effect' => 'allow']],
        ])->assertStatus(403);

        $this->deleteJson($self.'/'.$productsDelete->id)->assertStatus(403);

        $this->getJson($self)->assertStatus(403);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function self_management_is_refused_even_with_every_capability(): void
    {
        // A "super admin" fixture: every capability in the catalogue. There is
        // deliberately no owner/super-admin bypass, so this must still be 403.
        $superAdmin = $this->actingAsBusinessUser($this->business);

        $productsDelete = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        $self = $this->uri($superAdmin);

        $this->postJson($self, [
            'permission_id' => $productsDelete->id,
            'effect' => UserPermission::EFFECT_ALLOW,
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('user_permissions')->count());
    }

    /** @test */
    public function an_override_is_not_itself_authorisation_to_manage_overrides(): void
    {
        // The `users.roles.update` gate is evaluated on the actor's *effective*
        // permissions, before the request is applied. A user who was granted an
        // ALLOW override for `users.manage` afterwards would pass the gate next
        // time — so prove the ordering instead of assuming it: within the request
        // that creates the override, the actor's newly requested state is never
        // in effect, and the target (not the actor) is the one who changes.
        $this->assertFalse($this->admin->hasPermission(PermissionService::USERS_UPDATE));

        $productsDelete = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $usersUpdate = $this->permissionBySlug(PermissionService::USERS_UPDATE);

        // Granting a colleague `users.update` succeeds...
        $this->postJson($this->uri($this->member), [
            'permission_id' => $usersUpdate->id,
            'effect' => UserPermission::EFFECT_ALLOW,
        ])->assertStatus(201);

        $this->assertTrue($this->member->fresh()->hasPermission(PermissionService::USERS_UPDATE));

        // ...and the admin's own permissions are untouched by that write.
        $this->assertFalse($this->admin->fresh()->hasPermission(PermissionService::USERS_UPDATE));
        $this->assertFalse($this->admin->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $this->member->id,
            'permission_id' => $usersUpdate->id,
        ]);
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $this->admin->id]);
    }

    // ------------------------------------------------------------------
    // Role grants stay untouched
    // ------------------------------------------------------------------

    /** @test */
    public function managing_overrides_never_touches_roles_or_role_permissions(): void
    {
        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Cashier',
            'slug' => 'cashier',
        ]);

        $permissionIds = $this->permissionsBySlug([
            PermissionService::SALES_VIEW,
            PermissionService::PRODUCTS_DELETE,
        ])->pluck('id')->all();

        $role->permissions()->sync($permissionIds);
        $this->member->roles()->attach($role);

        $pivotBefore = DB::table('permission_role')->orderBy('id')->get()->toArray();
        $roleUserBefore = DB::table('role_user')->orderBy('id')->get()->toArray();

        $allow = $this->permissionBySlug(PermissionService::SALES_VIEW);
        $deny = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);

        // Exercise all three write verbs. The delete comes last, on a row the
        // sync has just created, so each call has something real to act on.
        $this->postJson($this->uri($this->member), [
            'permission_id' => $allow->id, 'effect' => 'allow',
        ])->assertStatus(201);

        $this->putJson($this->uri($this->member), [
            'overrides' => [['permission_id' => $deny->id, 'effect' => 'deny']],
        ])->assertStatus(200);

        $this->deleteJson($this->uri($this->member).'/'.$deny->id)->assertStatus(200);

        // `permission_role` and `role_user` are byte-for-byte what they were.
        $this->assertEquals($pivotBefore, DB::table('permission_role')->orderBy('id')->get()->toArray());
        $this->assertEquals($roleUserBefore, DB::table('role_user')->orderBy('id')->get()->toArray());

        // The role ↔ permission relationship still works on its own.
        $this->assertEqualsCanonicalizing(
            $permissionIds,
            $role->fresh()->permissions->pluck('id')->all(),
        );
        $this->assertTrue($this->member->fresh()->hasPermission(PermissionService::SALES_VIEW));
    }

    // ------------------------------------------------------------------
    // Integration with STEP 3 effective resolution
    // ------------------------------------------------------------------

    /** @test */
    public function written_overrides_drive_effective_authorisation_end_to_end(): void
    {
        $member = $this->userWithPermissions([PermissionService::SALES_VIEW], $this->business->id);

        // `userWithPermissions()` re-points the token at the new member; the
        // override API must be exercised as the administrator, so restore them.
        $this->authenticate($this->admin);

        $productsDelete = $this->permissionBySlug(PermissionService::PRODUCTS_DELETE);
        $uri = $this->uri($member);

        $this->assertFalse($member->hasPermission(PermissionService::PRODUCTS_DELETE));

        // 1. ALLOW -> the target can now delete products (an absent record yields
        //    404, never the 403 the gate would return).
        $this->postJson($uri, [
            'permission_id' => $productsDelete->id, 'effect' => 'allow',
        ])->assertStatus(201);

        $this->assertTrue($member->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->authenticate($member);
        $this->deleteJson('/api/v1/products/999999')->assertStatus(404);

        // 2. DENY -> the role-independent ALLOW is revoked. Act as the admin
        //    again: the override API refuses to manage its own caller's rows.
        $this->authenticate($this->admin);
        $this->postJson($uri, [
            'permission_id' => $productsDelete->id, 'effect' => 'deny',
        ])->assertStatus(201);

        $this->assertFalse($member->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
        $this->authenticate($member);
        $this->deleteJson('/api/v1/products/999999')->assertStatus(403);

        // 3. Deleted -> falls back to the role-based result. The role holds no
        //    `products.delete`, so the user is still refused and the row is gone.
        $this->authenticate($this->admin);
        $this->deleteJson($uri.'/'.$productsDelete->id)->assertStatus(200);

        $this->assertSame(0, DB::table('user_permissions')->where('user_id', $member->id)->count());
        $this->assertFalse($member->fresh()->hasPermission(PermissionService::PRODUCTS_DELETE));
    }

    /** @test */
    public function deleting_a_deny_override_restores_the_role_grant(): void
    {
        $member = $this->userWithPermissions([PermissionService::SALES_CREATE], $this->business->id);

        // `userWithPermissions()` re-points the token; act as the administrator.
        $this->authenticate($this->admin);

        $salesCreate = $this->permissionBySlug(PermissionService::SALES_CREATE);
        $uri = $this->uri($member);

        $this->assertTrue($member->hasPermission(PermissionService::SALES_CREATE));

        $this->postJson($uri, ['permission_id' => $salesCreate->id, 'effect' => 'deny'])
            ->assertStatus(201);
        $this->assertFalse($member->fresh()->hasPermission(PermissionService::SALES_CREATE));

        $this->authenticate($member);
        $this->postJson('/api/v1/sales', [])->assertStatus(403);

        // Removing the override hands the capability back to the role.
        $this->authenticate($this->admin);
        $this->deleteJson($uri.'/'.$salesCreate->id)->assertStatus(200);

        $this->assertTrue($member->fresh()->hasPermission(PermissionService::SALES_CREATE));
    }

    /** @test */
    public function a_sync_response_reflects_the_state_just_written(): void
    {
        $a = $this->permissionBySlug(PermissionService::SALES_VIEW);
        $b = $this->permissionBySlug(PermissionService::SALES_CREATE);

        $response = $this->putJson($this->uri($this->member), [
            'overrides' => [
                ['permission_id' => $a->id, 'effect' => 'deny'],
                ['permission_id' => $b->id, 'effect' => 'allow'],
            ],
        ])->assertStatus(200);

        // The service drops the memoised `permissionOverrides` relation before
        // reloading, so the response is built from the committed rows and never
        // from a stale per-instance memo.
        $bySlug = collect($response->json('data.items'))->keyBy('permission.slug');

        $this->assertSame('deny', $bySlug[PermissionService::SALES_VIEW]['effect']);
        $this->assertSame('allow', $bySlug[PermissionService::SALES_CREATE]['effect']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The override collection URI for a user.
     */
    private function uri(User $user): string
    {
        return '/api/v1/users/'.$user->getKey().'/permission-overrides';
    }

    /**
     * A catalogue permission, created on first use.
     */
    private function permissionBySlug(string $slug): Permission
    {
        return Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucwords(str_replace(['.', '_'], ' ', $slug))],
        );
    }

    /**
     * Insert an override directly, with an explicit `business_id` so a test can
     * forge the cross-tenant row the API must ignore.
     */
    private function seedOverride(Business $business, User $user, Permission $permission, string $effect): UserPermission
    {
        return UserPermission::create([
            'business_id' => $business->id,
            'user_id' => $user->getKey(),
            'permission_id' => $permission->getKey(),
            'effect' => $effect,
        ]);
    }
}
