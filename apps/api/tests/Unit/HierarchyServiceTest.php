<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/DEVELOPMENT_PLAN.md §9.1: an OIC sees everyone, a Project Manager sees
 * their Team Leaders and everyone under them, a Team Leader sees just their own team,
 * and individual contributors see only themselves.
 */
class HierarchyServiceTest extends TestCase
{
    use RefreshDatabase;

    private HierarchyService $hierarchy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hierarchy = new HierarchyService;
    }

    public function test_oic_sees_the_whole_office(): void
    {
        $oic = User::factory()->oic()->create();
        $pmA = User::factory()->projectManager($oic)->create();
        $pmB = User::factory()->projectManager($oic)->create();
        $tlA = User::factory()->teamLeader($pmA)->create();
        $devA = User::factory()->individualContributor($tlA)->create();
        $devB = User::factory()->individualContributor($pmB)->create();

        $visible = $this->hierarchy->visibleUserIds($oic);

        $this->assertEqualsCanonicalizing(
            [$oic->id, $pmA->id, $pmB->id, $tlA->id, $devA->id, $devB->id],
            $visible,
        );
    }

    public function test_project_manager_sees_their_team_leaders_and_reports_but_not_other_pms(): void
    {
        $oic = User::factory()->oic()->create();
        $pmA = User::factory()->projectManager($oic)->create();
        $pmB = User::factory()->projectManager($oic)->create();
        $tlA = User::factory()->teamLeader($pmA)->create();
        $devA = User::factory()->individualContributor($tlA)->create();
        $tlB = User::factory()->teamLeader($pmB)->create();
        $devB = User::factory()->individualContributor($tlB)->create();

        $visibleToA = $this->hierarchy->visibleUserIds($pmA);

        $this->assertEqualsCanonicalizing([$pmA->id, $tlA->id, $devA->id], $visibleToA);
        $this->assertFalse($this->hierarchy->isVisible($pmA, $pmB->id));
        $this->assertFalse($this->hierarchy->isVisible($pmA, $tlB->id));
        $this->assertFalse($this->hierarchy->isVisible($pmA, $devB->id));
    }

    public function test_team_leader_sees_only_their_own_team(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tlA = User::factory()->teamLeader($pm)->create();
        $tlB = User::factory()->teamLeader($pm)->create();
        $devA = User::factory()->individualContributor($tlA, 'developer')->create();
        $qaA = User::factory()->individualContributor($tlA, 'qa')->create();
        $devB = User::factory()->individualContributor($tlB)->create();

        $visibleToA = $this->hierarchy->visibleUserIds($tlA);

        $this->assertEqualsCanonicalizing([$tlA->id, $devA->id, $qaA->id], $visibleToA);
        $this->assertFalse($this->hierarchy->isVisible($tlA, $devB->id));
        $this->assertFalse($this->hierarchy->isVisible($tlA, $pm->id));
    }

    public function test_individual_contributor_sees_only_themselves(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();
        $dev = User::factory()->individualContributor($tl)->create();
        $sysAnalyst = User::factory()->individualContributor($tl, 'system_analyst')->create();

        $this->assertSame([$dev->id], $this->hierarchy->visibleUserIds($dev));
        $this->assertFalse($this->hierarchy->isVisible($dev, $sysAnalyst->id));
        $this->assertFalse($this->hierarchy->isVisible($dev, $tl->id));
        $this->assertFalse($dev->isManagerRole());
    }

    public function test_system_analyst_reports_to_a_team_leader_like_the_other_ic_roles(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();
        $sysAnalyst = User::factory()->individualContributor($tl, 'system_analyst')->create();

        $this->assertTrue($this->hierarchy->isVisible($tl, $sysAnalyst->id));
        $this->assertTrue($this->hierarchy->isVisible($pm, $sysAnalyst->id));
        $this->assertTrue($this->hierarchy->isVisible($oic, $sysAnalyst->id));
    }

    public function test_roles_one_tier_below(): void
    {
        $this->assertSame(['project_manager'], User::rolesOneTierBelow('oic'));
        $this->assertSame(['team_leader'], User::rolesOneTierBelow('project_manager'));
        $this->assertSame(User::INDIVIDUAL_CONTRIBUTOR_ROLES, User::rolesOneTierBelow('team_leader'));
        $this->assertSame([], User::rolesOneTierBelow('developer'));
    }
}
