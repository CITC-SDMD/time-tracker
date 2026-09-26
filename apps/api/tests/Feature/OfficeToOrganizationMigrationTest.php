<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.4: the single office that existed before organizations becomes the first organization,
// with its settings, roles (made from the old fixed ones) and every row. The database is built up to just before the
// migration, filled with the old shape of data, and then the migration runs.
class OfficeToOrganizationMigrationTest extends TestCase
{
    private const BEFORE = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_09_24_020412_create_personal_access_tokens_table.php',
        'database/migrations/2026_09_24_021958_add_hierarchy_fields_to_users_table.php',
        'database/migrations/2026_09_24_021959_create_employee_statuses_table.php',
        'database/migrations/2026_09_24_022000_create_sessions_table.php',
        'database/migrations/2026_09_24_022001_create_daily_summaries_table.php',
        'database/migrations/2026_09_24_022002_create_devices_table.php',
        'database/migrations/2026_09_24_022003_create_office_settings_table.php',
        'database/migrations/2026_09_24_022004_create_audit_logs_table.php',
        'database/migrations/2026_09_25_090000_create_password_invite_tokens_table.php',
        'database/migrations/2026_09_25_100000_keep_audit_names_when_a_user_is_deleted.php',
        'database/migrations/2026_09_26_002438_create_media_table.php',
        'database/migrations/2026_09_26_010000_create_screenshots_table.php',
        'database/migrations/2026_09_26_010100_add_screenshot_settings_to_office_settings.php',
        'database/migrations/2026_09_27_000001_create_organizations_roles_and_settings_tables.php',
        'database/migrations/2026_09_27_000002_add_organization_to_users_and_tenant_tables.php',
    ];

    private const THE_MIGRATION = 'database/migrations/2026_09_27_000003_move_existing_office_into_an_organization.php';

    private function legacyUser(string $email, string $role, ?int $manager = null): int
    {
        return DB::table('users')->insertGetId([
            'name' => ucfirst($role), 'email' => $email, 'password' => 'x', 'role' => $role, 'manager_id' => $manager,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_existing_office_becomes_the_first_organization(): void
    {
        $this->artisan('migrate:fresh', ['--path' => self::BEFORE])->assertSuccessful();

        DB::table('office_settings')->insert([
            'id' => 1, 'timezone' => 'Asia/Manila', 'idle_threshold_seconds' => 420, 'window_title_mode' => 'app_only',
            'min_agent_version' => '1.4.0', 'consent_version' => 3, 'screenshot_interval_minutes' => 15, 'screenshot_random' => true,
        ]);
        $super = $this->legacyUser('admin@x.test', 'superadmin');
        $oic = $this->legacyUser('oic@x.test', 'oic');
        $pm = $this->legacyUser('pm@x.test', 'project_manager', $oic);
        $tl = $this->legacyUser('tl@x.test', 'team_leader', $pm);
        $dev = $this->legacyUser('dev@x.test', 'developer', $tl);
        $qa = $this->legacyUser('qa@x.test', 'qa', $tl);
        DB::table('daily_summaries')->insert(['user_id' => $dev, 'day' => '2026-09-20', 'tracked_seconds' => 60, 'apps' => '{}', 'app_names' => '{}']);
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $dev, 'device_id' => (string) Str::uuid(), 'type' => 'application', 'app_name' => 'X',
            'started_at' => '2026-09-20 01:00:00', 'ended_at' => '2026-09-20 01:01:00', 'duration_seconds' => 60, 'day' => '2026-09-20', 'received_at' => now(),
        ]);
        DB::table('employee_statuses')->insert(['user_id' => $dev, 'state' => 'active', 'since' => now(), 'last_seen_at' => now()]);
        DB::table('audit_logs')->insert(['actor_user_id' => $oic, 'action' => 'employee.created', 'target_user_id' => $pm, 'created_at' => now()]);

        $this->artisan('migrate', ['--path' => [self::THE_MIGRATION]])->assertSuccessful();

        // one organization with the settings of the office
        $org = DB::table('organizations')->first();
        $this->assertSame(1, DB::table('organizations')->count());
        $settings = DB::table('organization_settings')->where('organization_id', $org->id)->first();
        $this->assertSame(['Asia/Manila', 420, 'app_only', 3, 15, 1], [
            $settings->timezone, $settings->idle_threshold_seconds, $settings->window_title_mode, $settings->consent_version,
            $settings->screenshot_interval_minutes, (int) $settings->screenshot_random,
        ]);
        // the oldest allowed app version moved to the platform
        $this->assertSame('1.4.0', DB::table('platform_settings')->value('min_agent_version'));
        $this->assertFalse(Schema::hasTable('office_settings'));
        $this->assertFalse(Schema::hasColumn('users', 'role'));

        // roles made from the old ones: only the ones somebody held
        $roles = DB::table('roles')->where('organization_id', $org->id)->get()->keyBy('name');
        $this->assertEqualsCanonicalizing(['Admin', 'Project Manager', 'Team Leader', 'Developer', 'QA'], $roles->keys()->all());
        $this->assertTrue((bool) $roles['Admin']->is_system);
        $this->assertSame('organization', $roles['Admin']->scope);
        $this->assertSame('team', $roles['Team Leader']->scope);
        $this->assertContains('people.assign_role', json_decode($roles['Team Leader']->permissions));
        $this->assertNotContains('settings.manage', json_decode($roles['Team Leader']->permissions));
        $this->assertSame('self', $roles['Developer']->scope);
        $this->assertSame([], json_decode($roles['Developer']->permissions));

        // everybody is placed, the reporting line is untouched
        foreach (['oic' => 'Admin', 'pm' => 'Project Manager', 'tl' => 'Team Leader', 'dev' => 'Developer', 'qa' => 'QA'] as $key => $roleName) {
            $user = DB::table('users')->where('email', "{$key}@x.test")->first();
            $this->assertSame($org->id, $user->organization_id);
            $this->assertSame($roles[$roleName]->id, $user->role_id);
        }
        $this->assertSame($tl, DB::table('users')->where('id', $dev)->value('manager_id'));
        $this->assertSame($tl, DB::table('users')->where('id', $qa)->value('manager_id'));

        // the old superadmin is the platform owner, in no organization
        $owner = DB::table('users')->where('id', $super)->first();
        $this->assertTrue((bool) $owner->is_superadmin);
        $this->assertTrue((bool) $owner->is_owner);
        $this->assertNull($owner->organization_id);
        $this->assertNull($owner->role_id);
        $this->assertContains('platform.staff.manage', json_decode($owner->superadmin_permissions));

        // every row of the office carries the organization
        foreach (['sessions', 'daily_summaries', 'employee_statuses', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->whereNull('organization_id')->count(), "{$table} rows without an organization");
            $this->assertSame(1, DB::table($table)->where('organization_id', $org->id)->count());
        }
    }

    public function test_a_fresh_database_only_gets_the_platform_settings_row(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertSame(0, DB::table('organizations')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame('0.1.0', DB::table('platform_settings')->value('min_agent_version'));
    }
}
