<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use App\Services\Permission\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Concerns\InteractsWithPermissions;
use Tests\Feature\Concerns\InteractsWithPosApi;
use Tests\TestCase;

/**
 * Stage 0 — the **Reports permission contract**.
 *
 * Reports are read-only, so the whole suite shares exactly one capability,
 * `reports.view`. Three things have to stay true, and are asserted together here
 * because each can hold while the others are broken:
 *
 *  - no token is **401**, never 403 — a 403 would confirm the endpoint exists and
 *    report an authentication failure under the wrong code;
 *  - a valid token **without** `reports.view` is **403** with the project's
 *    standard envelope, and nothing leaked inside it;
 *  - a valid token **with** `reports.view` gets through, and the domain rules —
 *    not authorization — decide the rest.
 *
 * `reports.manage` is asserted **absent**. It is a historical alias listed as
 * satisfying `reports.view` but never seeded, and re-introducing it would give a
 * business two names for the same read-only capability.
 */
class ReportPermissionTest extends TestCase
{
    use InteractsWithPermissions, InteractsWithPosApi, RefreshDatabase;

    /**
     * Every Reports route, in the order `routes/api.php` declares them.
     *
     * Stage 7's `customer-outstanding` and Stage 8's `supplier-outstanding` are
     * listed here rather than tested only in their own files, because this class
     * is what proves the *whole* suite shares one capability. A report added
     * outside this list would be the one endpoint nobody had checked for 401 /
     * 403 behaviour.
     */
    private const REPORTS = ['sales', 'purchases', 'expenses', 'profit-loss', 'inventory', 'dashboard', 'customer-outstanding', 'supplier-outstanding'];

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
    }

    /** @return array<int, string> */
    private function urls(): array
    {
        return array_map(
            static fn (string $report): string => '/api/v1/reports/'.$report,
            self::REPORTS,
        );
    }

    /**
     * No token at all: 401 on every report, and never 403.
     *
     * @test
     */
    public function every_report_requires_authentication(): void
    {
        $this->flushHeaders();

        foreach ($this->urls() as $url) {
            $this->assertSame(401, $this->getJson($url)->getStatusCode(), "{$url} must answer 401.");
        }
    }

    /**
     * A valid token without the capability: 403 on every report.
     *
     * @test
     */
    public function every_report_requires_the_reports_view_permission(): void
    {
        $this->actingAsUnprivilegedUser($this->business);

        foreach ($this->urls() as $url) {
            $this->getJson($url)->assertStatus(403)->assertJson(['success' => false]);
        }
    }

    /**
     * Holding `reports.view` — and only that — is enough.
     *
     * @test
     */
    public function reports_view_alone_authorises_every_report(): void
    {
        $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);

        foreach ($this->urls() as $url) {
            $this->getJson($url)->assertStatus(200);
        }
    }

    /**
     * A capability from another module does not open Reports.
     *
     * Reports read sales, purchases and expenses, so it is tempting to conclude
     * that any of those should be enough. It is not: `reports.view` is its own
     * capability and the gate checks exactly that.
     *
     * @test
     */
    public function an_unrelated_capability_does_not_authorise_reports(): void
    {
        $this->userWithPermissions(
            [PermissionService::SALES_VIEW, PermissionService::PURCHASES_VIEW, PermissionService::EXPENSES_VIEW],
            $this->business->id,
        );

        foreach ($this->urls() as $url) {
            $this->assertSame(
                403,
                $this->getJson($url)->getStatusCode(),
                "{$url} must stay closed without reports.view.",
            );
        }
    }

    /**
     * A denial is a denial, whatever else the caller happens to hold.
     *
     * @test
     */
    public function a_denial_stays_a_denial_alongside_other_permissions(): void
    {
        $this->userWithPermissions(
            [
                PermissionService::SALES_VIEW,
                PermissionService::SALES_CREATE,
                PermissionService::INVENTORY_VIEW,
                PermissionService::CUSTOMERS_VIEW,
            ],
            $this->business->id,
        );

        foreach ($this->urls() as $url) {
            $this->assertSame(403, $this->getJson($url)->getStatusCode(), "{$url} must be 403.");
        }
    }

    /**
     * The refusal says nothing about permissions or the database.
     *
     * @test
     */
    public function a_reports_denial_never_leaks_permission_or_database_internals(): void
    {
        $this->actingAsUnprivilegedUser($this->business);

        $body = (string) $this->getJson('/api/v1/reports/sales')->getContent();

        foreach (['SQLSTATE', 'Exception', base_path(), 'permission_id', 'permission_role', 'reports.view'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    /**
     * `reports.manage` must not come back.
     *
     * It was a dead alias — recognised as satisfying `reports.view` but never
     * seeded — and the coarse-permission removal migration treats it as such. A
     * report test that let it authorise anything would silently re-open the door
     * that migration closed.
     *
     * @test
     */
    public function reports_manage_is_not_a_permission_and_authorises_nothing(): void
    {
        $this->assertNotContains(
            'reports.manage',
            PermissionService::catalogueSlugs(),
            'reports.manage was never a real permission and must stay out of the catalogue.',
        );

        $this->assertFalse(Gate::has('reports.manage'), 'No gate ability may be named reports.manage.');

        // Planted literally, it still authorises nothing.
        $this->userWithLiteralPermissions(['reports.manage'], $this->business->id);

        foreach ($this->urls() as $url) {
            $this->assertSame(403, $this->getJson($url)->getStatusCode(), "{$url} must stay closed.");
        }
    }

    /**
     * `reports.view` is the one and only slug behind the capability.
     *
     * @test
     */
    public function reports_view_resolves_to_exactly_its_own_slug(): void
    {
        $this->assertSame(
            [PermissionService::REPORTS_VIEW],
            PermissionService::slugsFor(PermissionService::REPORTS_VIEW),
        );

        $this->assertTrue(Gate::has(PermissionService::REPORTS_VIEW));
    }

    /**
     * A suspended account is 401 on Reports too, never 403.
     *
     * @test
     */
    public function an_inactive_user_is_unauthenticated_on_reports_not_forbidden(): void
    {
        $user = $this->userWithPermissions([PermissionService::REPORTS_VIEW], $this->business->id);
        $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->getJson('/api/v1/reports/sales')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    /**
     * Holding the permission is enough however it was granted.
     *
     * A user-permission override is the other route to a capability, and Reports
     * must honour it exactly as it honours the role grant — otherwise removing the
     * role would not actually remove access.
     *
     * @test
     */
    public function a_user_permission_override_grants_reports_too(): void
    {
        $user = $this->userWithoutPermissions($this->business->id);

        $permission = Permission::firstOrCreate(
            ['slug' => PermissionService::REPORTS_VIEW],
            ['name' => 'View Reports'],
        );

        $user->permissionOverrides()->create([
            'business_id' => $this->business->id,
            'permission_id' => $permission->getKey(),
            'effect' => 'allow',
        ]);

        // The relation was cached by the earlier permission check.
        $user->unsetRelation('permissions');

        foreach ($this->urls() as $url) {
            $this->getJson($url)->assertStatus(200);
        }
    }

    /**
     * Another tenant's grant is not this tenant's grant.
     *
     * Permissions are global but roles and overrides are tenant-owned, so a
     * business can only grant what it owns. Asserted through Reports because a
     * cross-tenant Reports grant would expose the most.
     *
     * @test
     */
    public function another_businesss_role_does_not_authorise_this_businesss_reports(): void
    {
        $theirs = Business::factory()->create();
        $foreign = $this->userWithPermissions([PermissionService::REPORTS_VIEW], $theirs->id);

        // A user of *this* business, holding nothing of their own.
        $mine = $this->userWithoutPermissions($this->business->id);

        $this->getJson('/api/v1/reports/sales')->assertStatus(403);

        // …and is still refused after the foreign user has proven the route
        // opens, so the denial was about this user's grants rather than a route
        // that never opens for anyone.
        Sale::factory()->create(['business_id' => $theirs->id]);
        $this->authenticate($foreign);
        $this->getJson('/api/v1/reports/sales')
            ->assertStatus(200)
            ->assertJsonPath('data.metrics.sale_count', 1);

        $this->authenticate($mine);
        $this->assertSame(403, $this->getJson('/api/v1/reports/sales')->getStatusCode());
    }
}
