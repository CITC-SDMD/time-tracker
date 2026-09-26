<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Support\Permissions;
use Database\Factories\OrganizationFactory;
use Database\Factories\RoleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.4, §10: the platform layer. Superadmins create organizations and their admins, look
// inside an office when their permissions allow it, and have permissions of their own that hide what they may not use.
class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->superadmin(null, true)->create(['name' => 'Owner']);
    }

    private function limited(array $permissions): User
    {
        return User::factory()->superadmin($permissions)->create(['name' => 'Limited']);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    // ---- organizations ------------------------------------------------------------------------

    public function test_a_superadmin_creates_an_organization_with_its_settings_and_only_the_admin_role(): void
    {
        $response = $this->as($this->owner)->postJson('/api/v1/platform/organizations', ['name' => 'City Assessor', 'timezone' => 'Asia/Singapore'])
            ->assertCreated()
            ->assertJsonPath('name', 'City Assessor')
            ->assertJsonPath('slug', 'city-assessor')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('timezone', 'Asia/Singapore')
            ->assertJsonPath('peopleCount', 0);

        $organization = Organization::findOrFail($response->json('id'));
        $roles = Role::withoutGlobalScopes()->where('organization_id', $organization->id)->get();
        $this->assertCount(1, $roles);
        $this->assertTrue($roles[0]->is_system);
        $this->assertSame('organization', $roles[0]->scope);
        $this->assertEqualsCanonicalizing(Permissions::organizationKeys(), $roles[0]->permissions);
        $this->assertSame('Asia/Singapore', OrganizationSetting::withoutGlobalScopes()->where('organization_id', $organization->id)->value('timezone'));

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'organization.created')->firstOrFail();
        $this->assertNull($entry->organization_id);
        $this->assertSame($this->owner->id, $entry->actor_user_id);
    }

    public function test_organization_names_are_unique(): void
    {
        $this->as($this->owner)->postJson('/api/v1/platform/organizations', ['name' => 'City Assessor'])->assertCreated();
        $this->as($this->owner)->postJson('/api/v1/platform/organizations', ['name' => 'city assessor'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_NAME_TAKEN');
    }

    public function test_the_list_and_the_profile_show_numbers_only(): void
    {
        $org = OrganizationFactory::made('Office A');
        User::factory()->adminOf($org)->create();
        User::factory()->withRole(RoleFactory::make2('Staff', 'self', [], $org))->create();

        $row = collect($this->as($this->owner)->getJson('/api/v1/platform/organizations')->assertOk()->json())->firstWhere('name', 'Office A');
        $this->assertSame(2, $row['peopleCount']);
        $this->assertSame(1, $row['adminCount']);
        $this->assertArrayNotHasKey('people', $row);

        $this->as($this->owner)->getJson("/api/v1/platform/organizations/{$org->id}")
            ->assertOk()->assertJsonPath('name', 'Office A')->assertJsonPath('peopleCount', 2);
    }

    public function test_an_organization_can_be_renamed_suspended_and_reactivated_and_it_is_audited(): void
    {
        $org = OrganizationFactory::made('Office A');
        $url = "/api/v1/platform/organizations/{$org->id}";

        $this->as($this->owner)->patchJson($url, ['name' => 'Office Alpha'])->assertOk()->assertJsonPath('name', 'Office Alpha');
        $this->as($this->owner)->patchJson($url, ['status' => 'suspended'])->assertOk()->assertJsonPath('status', 'suspended');
        $this->as($this->owner)->patchJson($url, ['status' => 'active'])->assertOk()->assertJsonPath('status', 'active');

        $this->assertEqualsCanonicalizing(
            ['organization.renamed', 'organization.suspended', 'organization.reactivated'],
            AuditLog::withoutGlobalScopes()->whereNull('organization_id')->pluck('action')->all(),
        );
    }

    public function test_a_missing_organization_is_a_404(): void
    {
        $this->as($this->owner)->getJson('/api/v1/platform/organizations/9999')->assertNotFound();
    }

    // ---- what a superadmin may do follows their permissions -----------------------------------

    public function test_each_platform_route_needs_its_own_permission(): void
    {
        $org = OrganizationFactory::made('Office A');
        $nothing = $this->limited([]);

        foreach ([
            ['GET', '/api/v1/platform/organizations'],
            ['POST', '/api/v1/platform/organizations'],
            ['GET', "/api/v1/platform/organizations/{$org->id}"],
            ['PATCH', "/api/v1/platform/organizations/{$org->id}"],
            ['GET', "/api/v1/platform/organizations/{$org->id}/admins"],
            ['GET', "/api/v1/platform/organizations/{$org->id}/office/employees"],
            ['GET', '/api/v1/platform/settings'],
            ['GET', '/api/v1/platform/superadmins'],
            ['GET', '/api/v1/platform/audit'],
        ] as [$method, $url]) {
            $this->as($nothing)->json($method, $url, ['name' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }

        $this->as($this->limited(['organizations.view']))->getJson('/api/v1/platform/organizations')->assertOk();
        $this->as($this->limited(['organizations.view']))->postJson('/api/v1/platform/organizations', ['name' => 'X'])->assertForbidden();
    }

    public function test_me_lists_the_platform_permissions_so_the_dashboard_can_hide_the_rest(): void
    {
        $limited = $this->limited(['organizations.view', 'organizations.data.manage']);

        $me = $this->as($limited)->getJson('/api/v1/me')->assertOk();

        $me->assertJsonPath('isSuperadmin', true)->assertJsonPath('organization', null)->assertJsonPath('role', null)->assertJsonPath('permissions', []);
        // managing inside an office includes looking, so both show
        $this->assertEqualsCanonicalizing(['organizations.view', 'organizations.data.manage', 'organizations.data.view'], $me->json('platformPermissions'));
        $this->as($this->owner)->getJson('/api/v1/me')->assertJsonPath('isOwner', true)->assertJsonCount(count(Permissions::superadminKeys()), 'platformPermissions');
    }

    public function test_a_superadmin_has_no_organization_so_the_ordinary_routes_give_nothing(): void
    {
        $this->as($this->owner)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(0); // no one: it works inside an office only through the office routes
        $this->as($this->owner)->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->as($this->owner)->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_a_superadmin_does_not_use_the_desktop_app(): void
    {
        $owner = User::factory()->superadmin(null, true)->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'secret-pass'])->assertForbidden();
    }

    // ---- the admins of an organization --------------------------------------------------------

    public function test_a_superadmin_adds_admins_to_an_organization_and_they_are_invited(): void
    {
        Notification::fake();
        $org = OrganizationFactory::made('Office A');

        $response = $this->as($this->owner)->postJson("/api/v1/platform/organizations/{$org->id}/admins", ['name' => 'Ada Admin', 'email' => 'ada@a.test'])
            ->assertCreated()->assertJsonPath('emailSent', true)->assertJsonPath('status', 'active');

        $admin = User::withoutGlobalScopes()->findOrFail($response->json('id'));
        $this->assertSame($org->id, $admin->organization_id);
        $this->assertTrue($admin->role->is_system);
        $this->assertNull($admin->manager_id);
        Notification::assertSentTo($admin, WelcomeNotification::class);

        $this->as($this->owner)->postJson("/api/v1/platform/organizations/{$org->id}/admins", ['name' => 'Bo Admin', 'email' => 'bo@a.test'])->assertCreated();
        $this->as($this->owner)->getJson("/api/v1/platform/organizations/{$org->id}/admins")->assertOk()->assertJsonCount(2);
    }

    public function test_the_admin_list_holds_only_that_organizations_admins(): void
    {
        $a = OrganizationFactory::made('Office A');
        $b = OrganizationFactory::made('Office B');
        User::factory()->adminOf($a)->create(['name' => 'Admin A']);
        User::factory()->adminOf($b)->create(['name' => 'Admin B']);

        $names = collect($this->as($this->owner)->getJson("/api/v1/platform/organizations/{$a->id}/admins")->assertOk()->json())->pluck('name')->all();

        $this->assertSame(['Admin A'], $names);
    }

    public function test_an_admin_email_must_be_new_on_the_whole_platform(): void
    {
        $a = OrganizationFactory::made('Office A');
        $b = OrganizationFactory::made('Office B');
        User::factory()->adminOf($b)->create(['email' => 'taken@x.test']);

        $this->as($this->owner)->postJson("/api/v1/platform/organizations/{$a->id}/admins", ['name' => 'X', 'email' => 'Taken@x.test'])
            ->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_TAKEN');
    }

    public function test_the_last_active_admin_stays_unless_the_organization_is_suspended(): void
    {
        $org = OrganizationFactory::made('Office A');
        $only = User::factory()->adminOf($org)->create();
        $url = "/api/v1/platform/organizations/{$org->id}/admins/{$only->id}";

        $this->as($this->owner)->patchJson($url, ['status' => 'inactive'])->assertStatus(400)->assertJsonPath('error.code', 'LAST_ADMIN');

        $second = User::factory()->adminOf($org)->create();
        $this->as($this->owner)->patchJson($url, ['status' => 'inactive'])->assertOk()->assertJsonPath('status', 'inactive');
        $this->as($this->owner)->patchJson($url, ['status' => 'active'])->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame('active', $second->fresh()->status);

        // a suspended office is being wound down: the guard steps aside
        $second->forceFill(['status' => 'inactive'])->save();
        $org->forceFill(['status' => 'suspended'])->save();
        $this->as($this->owner)->patchJson($url, ['status' => 'inactive'])->assertOk();
    }

    public function test_an_invite_can_be_sent_again_and_only_for_an_admin_of_that_organization(): void
    {
        Notification::fake();
        $a = OrganizationFactory::made('Office A');
        $b = OrganizationFactory::made('Office B');
        $adminA = User::factory()->adminOf($a)->create();
        $adminB = User::factory()->adminOf($b)->create();

        $this->as($this->owner)->postJson("/api/v1/platform/organizations/{$a->id}/admins/{$adminA->id}/resend-invite")->assertOk()->assertJsonPath('emailSent', true);
        Notification::assertSentToTimes($adminA, WelcomeNotification::class, 1);

        $this->as($this->owner)->postJson("/api/v1/platform/organizations/{$a->id}/admins/{$adminB->id}/resend-invite")->assertNotFound();
    }

    public function test_managing_admins_needs_its_own_permission(): void
    {
        $org = OrganizationFactory::made('Office A');
        $viewer = $this->limited(['organizations.view', 'organizations.data.manage']);

        $this->as($viewer)->postJson("/api/v1/platform/organizations/{$org->id}/admins", ['name' => 'X', 'email' => 'x@a.test'])->assertForbidden();
        $this->as($this->limited(['organizations.admins.manage']))->postJson("/api/v1/platform/organizations/{$org->id}/admins", ['name' => 'X', 'email' => 'x@a.test'])->assertCreated();
    }

    // ---- suspension ---------------------------------------------------------------------------

    public function test_a_suspended_organization_is_shut_out_everywhere_and_nothing_is_deleted(): void
    {
        $org = OrganizationFactory::made('Office A');
        $admin = User::factory()->adminOf($org)->create(['password' => bcrypt('secret-pass')]);
        $dev = User::factory()->withRole(RoleFactory::make2('Staff', 'self', [], $org), $admin)->create(['password' => bcrypt('secret-pass')]);
        $token = $dev->createToken('agent-x')->plainTextToken;
        $this->as($this->owner)->patchJson("/api/v1/platform/organizations/{$org->id}", ['status' => 'suspended'])->assertOk();

        // the dashboard sign-in and the desktop sign-in
        $this->postJson('/auth/login', ['email' => $admin->email, 'password' => 'secret-pass'])->assertForbidden()->assertJsonPath('error.code', 'ORG_SUSPENDED');
        $this->postJson('/api/v1/auth/login', ['email' => $dev->email, 'password' => 'secret-pass'])->assertForbidden()->assertJsonPath('error.code', 'ORG_SUSPENDED');
        // an already signed-in person
        $this->as($admin)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'ORG_SUSPENDED');
        auth()->forgetGuards();
        // the desktop app is told to sign out
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Agent-Version' => '0.1.0'])
            ->postJson('/api/v1/agent/sync', ['deviceId' => (string) Str::uuid(), 'clientTime' => now()->toIso8601String(), 'status' => ['state' => 'active'], 'sessions' => []])
            ->assertForbidden()->assertJsonPath('error.code', 'ORG_SUSPENDED')->assertJsonPath('commands.signOut', true);

        $this->assertSame(2, User::withoutGlobalScopes()->where('organization_id', $org->id)->count());

        // reactivating brings everything back
        $this->as($this->owner)->patchJson("/api/v1/platform/organizations/{$org->id}", ['status' => 'active'])->assertOk();
        $this->postJson('/auth/login', ['email' => $admin->email, 'password' => 'secret-pass'])->assertOk();
    }

    // ---- looking inside an office -------------------------------------------------------------

    private function officeWithPeople(): array
    {
        $org = OrganizationFactory::made('Office A');
        $admin = User::factory()->adminOf($org)->create(['name' => 'Admin A']);
        $dev = User::factory()->withRole(RoleFactory::make2('Staff', 'self', [], $org), $admin)->create(['name' => 'Dev A']);

        return [$org, $admin, $dev];
    }

    public function test_view_only_lets_a_superadmin_read_an_office_but_not_change_it(): void
    {
        [$org, , $dev] = $this->officeWithPeople();
        $viewer = $this->limited(['organizations.data.view']);
        $base = "/api/v1/platform/organizations/{$org->id}/office";

        $names = collect($this->as($viewer)->getJson("{$base}/employees")->assertOk()->json())->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Admin A', 'Dev A'], $names);
        $this->as($viewer)->getJson("{$base}/employees/{$dev->id}/timeline?day=2026-09-20")->assertOk();
        $this->as($viewer)->getJson("{$base}/reports/daily?from=2026-09-01&to=2026-09-02")->assertOk();
        $this->as($viewer)->getJson("{$base}/admin/audit")->assertOk();

        // no writes: no people.create, no roles.manage, no settings.manage in the read-only set
        $this->as($viewer)->postJson("{$base}/admin/employees", ['name' => 'X', 'email' => 'x@a.test', 'roleId' => $dev->role_id])->assertForbidden();
        $this->as($viewer)->patchJson("{$base}/admin/employees/{$dev->id}", ['status' => 'inactive'])->assertForbidden();
        $this->as($viewer)->postJson("{$base}/roles", ['name' => 'X', 'scope' => 'self', 'permissions' => []])->assertForbidden();
        $this->as($viewer)->getJson("{$base}/admin/settings")->assertForbidden();
        $this->assertSame('active', $dev->fresh()->status);
    }

    public function test_manage_lets_a_superadmin_act_as_the_offices_admin_and_it_is_audited_in_that_office(): void
    {
        [$org, , $dev] = $this->officeWithPeople();
        $manager = $this->limited(['organizations.data.manage']);
        $base = "/api/v1/platform/organizations/{$org->id}/office";

        $this->as($manager)->patchJson("{$base}/admin/employees/{$dev->id}", ['status' => 'inactive'])->assertOk();
        $this->as($manager)->postJson("{$base}/roles", ['name' => 'Clerk', 'scope' => 'self', 'permissions' => []])->assertCreated();
        $this->as($manager)->putJson("{$base}/admin/settings", ['idleThresholdSeconds' => 600])->assertOk();

        $this->assertSame('inactive', $dev->fresh()->status);
        $this->assertSame(600, OrganizationSetting::withoutGlobalScopes()->where('organization_id', $org->id)->value('idle_threshold_seconds'));
        $entries = AuditLog::withoutGlobalScopes()->where('organization_id', $org->id)->where('actor_user_id', $manager->id)->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['employee.deactivated', 'role.created', 'settings.updated'], $entries);
    }

    public function test_without_data_view_a_superadmin_cannot_open_an_office_at_all(): void
    {
        [$org] = $this->officeWithPeople();

        $this->as($this->limited(['organizations.view', 'organizations.admins.manage']))
            ->getJson("/api/v1/platform/organizations/{$org->id}/office/employees")->assertForbidden();
    }

    public function test_what_a_superadmin_only_looks_at_is_not_logged(): void
    {
        [$org, , $dev] = $this->officeWithPeople();
        $owner = $this->owner;
        $base = "/api/v1/platform/organizations/{$org->id}/office";

        $this->as($owner)->getJson("{$base}/employees/{$dev->id}/timeline?day=2026-09-20")->assertOk();
        $this->as($owner)->getJson("{$base}/employees/{$dev->id}/screenshots?day=2026-09-20")->assertOk();
        $this->as($owner)->get("{$base}/reports/daily?from=2026-09-01&to=2026-09-02&format=csv")->assertOk();

        $this->assertSame(0, AuditLog::withoutGlobalScopes()->whereIn('action', ['timeline.viewed', 'screenshots.viewed', 'report.exported'])->count());
    }

    // ---- superadmins --------------------------------------------------------------------------

    public function test_the_owner_adds_a_superadmin_with_chosen_permissions(): void
    {
        Notification::fake();

        $response = $this->as($this->owner)->postJson('/api/v1/platform/superadmins', [
            'name' => 'Sam Super', 'email' => 'sam@platform.test', 'permissions' => ['organizations.view', 'organizations.data.view'],
        ])->assertCreated()->assertJsonPath('isOwner', false)->assertJsonPath('permissions', ['organizations.view', 'organizations.data.view']);

        $sam = User::withoutGlobalScopes()->findOrFail($response->json('id'));
        $this->assertTrue($sam->isSuperadmin());
        $this->assertNull($sam->organization_id);
        $this->assertNull($sam->role_id);
        Notification::assertSentTo($sam, WelcomeNotification::class);
        $this->assertSame('superadmin.created', AuditLog::withoutGlobalScopes()->latest('id')->value('action'));
    }

    public function test_nobody_gives_a_platform_permission_they_do_not_hold(): void
    {
        $staff = $this->limited(['platform.staff.manage', 'organizations.view']);

        $this->as($staff)->postJson('/api/v1/platform/superadmins', ['name' => 'X', 'email' => 'x@p.test', 'permissions' => ['organizations.view']])->assertCreated();
        $this->as($staff)->postJson('/api/v1/platform/superadmins', ['name' => 'Y', 'email' => 'y@p.test', 'permissions' => ['organizations.create']])
            ->assertStatus(403)->assertJsonPath('error.code', 'ROLE_ESCALATION');
        $this->assertDatabaseMissing('users', ['email' => 'y@p.test']);
    }

    public function test_the_owner_and_yourself_cannot_be_changed_and_permissions_can_be_edited(): void
    {
        $staff = $this->limited(['platform.staff.manage', 'organizations.view', 'organizations.create']);
        $sam = $this->limited(['organizations.view']);

        $this->as($staff)->patchJson("/api/v1/platform/superadmins/{$this->owner->id}", ['permissions' => []])->assertForbidden()->assertJsonPath('error.code', 'CANNOT_CHANGE_OWNER');
        $this->as($staff)->patchJson("/api/v1/platform/superadmins/{$staff->id}", ['permissions' => []])->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');

        $this->as($staff)->patchJson("/api/v1/platform/superadmins/{$sam->id}", ['permissions' => ['organizations.view', 'organizations.create']])->assertOk();
        $this->assertEqualsCanonicalizing(['organizations.view', 'organizations.create'], $sam->fresh()->superadmin_permissions);
        $entry = AuditLog::withoutGlobalScopes()->where('action', 'superadmin.permissions_changed')->firstOrFail();
        $this->assertSame(['organizations.create'], $entry->details['added']);

        // and more than the editor has is refused
        $this->as($staff)->patchJson("/api/v1/platform/superadmins/{$sam->id}", ['permissions' => ['platform.settings']])->assertForbidden();
    }

    public function test_deactivating_a_superadmin_signs_them_out(): void
    {
        $sam = $this->limited(['organizations.view']);
        $sam->createToken('dash');

        $this->as($this->owner)->patchJson("/api/v1/platform/superadmins/{$sam->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('status', 'inactive');

        $this->assertSame(0, $sam->tokens()->count());
    }

    public function test_the_platform_audit_log_lists_what_superadmins_did_anywhere(): void
    {
        [$org, , $dev] = $this->officeWithPeople();
        $this->as($this->owner)->patchJson("/api/v1/platform/organizations/{$org->id}", ['name' => 'Office Alpha'])->assertOk();
        $this->as($this->owner)->patchJson("/api/v1/platform/organizations/{$org->id}/office/admin/employees/{$dev->id}", ['status' => 'inactive'])->assertOk();
        AuditLog::record(User::find($dev->manager_id), 'employee.created', $dev); // an office's own entry, not a superadmin's

        $actions = collect($this->as($this->owner)->getJson('/api/v1/platform/audit')->assertOk()->json('entries'))->pluck('action')->all();

        $this->assertEqualsCanonicalizing(['organization.renamed', 'employee.deactivated'], $actions);
    }

    // ---- platform settings --------------------------------------------------------------------

    public function test_the_oldest_allowed_app_version_is_a_platform_setting_and_takes_effect(): void
    {
        $this->as($this->owner)->putJson('/api/v1/platform/settings', ['minAgentVersion' => 'banana'])->assertStatus(422);
        $this->as($this->owner)->putJson('/api/v1/platform/settings', ['minAgentVersion' => '1.2.3'])->assertOk()->assertJsonPath('minAgentVersion', '1.2.3');
        $this->assertSame('1.2.3', PlatformSetting::current()->min_agent_version);

        $dev = User::factory()->create();
        $this->as($dev)->withHeaders(['X-Agent-Version' => '1.0.0'])
            ->postJson('/api/v1/agent/sync', ['deviceId' => (string) Str::uuid(), 'clientTime' => now()->toIso8601String(), 'status' => ['state' => 'active'], 'sessions' => []])
            ->assertStatus(426);
    }

    // ---- terminal commands --------------------------------------------------------------------

    public function test_the_terminal_commands_make_the_owner_and_an_organization_with_its_first_admin(): void
    {
        // there is already an active owner (the one made in setUp): a second one is refused
        $this->artisan('tracker:make-superadmin', ['name' => 'Second', 'email' => 'second@platform.test'])->assertFailed();
        $this->owner->forceFill(['status' => 'inactive'])->save();

        $this->artisan('tracker:make-superadmin', ['name' => 'Boss', 'email' => 'boss@platform.test'])->assertSuccessful();
        $boss = User::withoutGlobalScopes()->where('email', 'boss@platform.test')->firstOrFail();
        $this->assertTrue($boss->is_owner);
        $this->assertNull($boss->organization_id);

        $this->artisan('tracker:make-organization', ['name' => 'Water District', 'adminName' => 'Wanda', 'adminEmail' => 'wanda@wd.test'])->assertSuccessful();
        $wanda = User::withoutGlobalScopes()->where('email', 'wanda@wd.test')->firstOrFail();
        $this->assertSame('Water District', $wanda->organization->name);
        $this->assertTrue($wanda->role->is_system);
    }
}
