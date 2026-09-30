<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AccessService;
use App\Services\HierarchyService;
use App\Support\OrganizationContext;
use App\Support\Permissions;
use Database\Factories\OrganizationFactory;
use Database\Factories\RoleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// who may see whom (docs/DEVELOPMENT_PLAN.md §9.1): a role reaches only itself, its team (everyone below in the
// reporting line, at any depth) or the whole organization; and nobody grants more than they hold.
class AccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pmA;

    private User $pmB;

    private User $tlA;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->oic()->create();
        $this->pmA = User::factory()->projectManager($this->admin)->create();
        $this->pmB = User::factory()->projectManager($this->admin)->create();
        $this->tlA = User::factory()->teamLeader($this->pmA)->create();
        $this->dev = User::factory()->individualContributor($this->tlA)->create();
        app(OrganizationContext::class)->set($this->admin->organization_id); // a request works inside one organization
    }

    private function access(): AccessService
    {
        return app(AccessService::class);
    }

    public function test_the_organization_scope_sees_everyone_in_the_organization(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->admin->id, $this->pmA->id, $this->pmB->id, $this->tlA->id, $this->dev->id],
            $this->access()->visibleUserIds($this->admin),
        );
    }

    public function test_the_team_scope_sees_themselves_and_everyone_below_at_any_depth(): void
    {
        $this->assertEqualsCanonicalizing([$this->pmA->id, $this->tlA->id, $this->dev->id], $this->access()->visibleUserIds($this->pmA));
        $this->assertEqualsCanonicalizing([$this->pmB->id], $this->access()->visibleUserIds($this->pmB));
    }

    public function test_a_manager_with_several_team_leaders_sees_every_employee_under_all_of_them(): void
    {
        $tlB = User::factory()->teamLeader($this->pmA)->create();
        $devB = User::factory()->individualContributor($tlB)->create();

        $this->assertEqualsCanonicalizing(
            [$this->pmA->id, $this->tlA->id, $this->dev->id, $tlB->id, $devB->id],
            $this->access()->visibleUserIds($this->pmA),
        );
    }

    public function test_the_self_scope_sees_only_themselves(): void
    {
        $this->assertSame([$this->dev->id], $this->access()->visibleUserIds($this->dev));
    }

    public function test_another_organizations_people_are_never_visible(): void
    {
        $other = OrganizationFactory::made('Other Office');
        $stranger = User::factory()->adminOf($other)->create();

        $this->assertFalse($this->access()->isVisible($this->admin, $stranger->id));
        $this->assertNotContains($stranger->id, $this->access()->visibleUserIds($this->admin));
    }

    public function test_seeing_someone_else_needs_the_permission_as_well_as_the_reach(): void
    {
        $noTimelines = User::factory()->withRole(RoleFactory::make2('Counter', 'organization', ['people.view']))->create();

        $this->assertTrue($this->access()->canSee($noTimelines, $noTimelines->id, 'timeline.view')); // always themselves
        $this->assertFalse($this->access()->canSee($noTimelines, $this->dev->id, 'timeline.view'));
        $this->assertTrue($this->access()->canSee($this->pmA, $this->dev->id, 'timeline.view'));
        $this->assertFalse($this->access()->canSee($this->pmB, $this->dev->id, 'timeline.view'));
    }

    public function test_a_role_may_only_be_given_by_someone_who_holds_all_of_it(): void
    {
        $access = $this->access();

        $this->assertTrue($access->canGrantRole($this->admin, RoleFactory::forTests('project_manager')));
        $this->assertTrue($access->canGrantRole($this->pmA, RoleFactory::forTests('team_leader')));
        $this->assertFalse($access->canGrantRole($this->pmA, RoleFactory::forTests('oic'))); // more permissions and a wider reach
        $this->assertTrue($access->canGrantRole($this->dev, RoleFactory::forTests('developer'))); // nothing to give beyond nothing
        $this->assertFalse($access->canGrant($this->dev, ['people.view'], 'team'));
    }

    public function test_superadmin_permissions_inside_an_office_follow_their_platform_permissions(): void
    {
        $context = app(OrganizationContext::class);
        $viewer = User::factory()->superadmin(['organizations.data.view'])->create();
        $manager = User::factory()->superadmin(['organizations.data.manage'])->create();
        $nothing = User::factory()->superadmin(['organizations.view'])->create();

        $context->clear();
        $this->assertSame([], $this->access()->permissions($manager)); // outside an office they hold no office permission

        $context->set($this->admin->organization_id);
        $this->assertEqualsCanonicalizing(Permissions::READ_ONLY, $this->access()->permissions($viewer));
        $this->assertEqualsCanonicalizing(Permissions::organizationKeys(), $this->access()->permissions($manager));
        $this->assertSame([], $this->access()->permissions($nothing));
        $this->assertSame('organization', $this->access()->scope($viewer));
        $this->assertCount(5, $this->access()->visibleUserIds($viewer));
    }

    public function test_the_hierarchy_walks_the_reporting_line_and_spots_loops(): void
    {
        $tree = app(HierarchyService::class);

        $this->assertEqualsCanonicalizing([$this->pmA->id, $this->pmB->id, $this->tlA->id, $this->dev->id], $tree->allDescendantIds($this->admin->id));
        $this->assertSame([], $tree->allDescendantIds($this->dev->id));
        $this->assertTrue($tree->wouldCreateLoop($this->pmA->id, $this->dev->id)); // under someone who is below them
        $this->assertTrue($tree->wouldCreateLoop($this->pmA->id, $this->pmA->id));
        $this->assertFalse($tree->wouldCreateLoop($this->dev->id, $this->pmB->id));
    }
}
