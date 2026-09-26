<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// the single office that already exists becomes the first organization: its settings, its roles (made from
// the old fixed roles) and all its rows move over. On a fresh database there is nothing to move and only the
// platform settings row is written. The permission names are written out here on purpose, so this migration
// keeps working when the catalog in the code changes later.
return new class extends Migration
{
    private const ALL = [
        'people.view', 'people.create', 'people.update', 'people.assign_role', 'timeline.view',
        'screenshots.view', 'reports.view', 'reports.export', 'settings.manage', 'audit.view', 'roles.manage',
    ];

    private const MANAGER = [
        'people.view', 'people.create', 'people.update', 'people.assign_role', 'timeline.view',
        'screenshots.view', 'reports.view', 'reports.export',
    ];

    private const SUPERADMIN = [
        'organizations.view', 'organizations.create', 'organizations.update', 'organizations.admins.manage',
        'organizations.data.view', 'organizations.data.manage', 'platform.settings', 'platform.staff.manage',
        'platform.audit.view',
    ];

    /** old role => [name, scope, permissions, is_system] */
    private const OLD_ROLES = [
        'oic' => ['OIC', 'organization', self::ALL, true],
        'project_manager' => ['Project Manager', 'team', self::MANAGER, false],
        'team_leader' => ['Team Leader', 'team', self::MANAGER, false],
        'lead_developer' => ['Lead Developer', 'self', [], false],
        'developer' => ['Developer', 'self', [], false],
        'client_support' => ['Client Support', 'self', [], false],
        'qa' => ['QA', 'self', [], false],
        'system_analyst' => ['System Analyst', 'self', [], false],
    ];

    public function up(): void
    {
        $office = DB::table('office_settings')->first();
        DB::table('platform_settings')->insert(['id' => 1, 'min_agent_version' => $office->min_agent_version ?? '0.1.0']);

        if (DB::table('users')->exists()) {
            $this->moveExistingOffice($office);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::drop('office_settings');
    }

    private function moveExistingOffice(?object $office): void
    {
        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Main Office', 'slug' => 'main-office', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('organization_settings')->insert([
            'organization_id' => $organizationId,
            'timezone' => $office->timezone ?? 'UTC',
            'idle_threshold_seconds' => $office->idle_threshold_seconds ?? 300,
            'window_title_mode' => $office->window_title_mode ?? 'full',
            'consent_version' => $office->consent_version ?? 1,
            'screenshot_interval_minutes' => $office->screenshot_interval_minutes ?? 0,
            'screenshot_random' => $office->screenshot_random ?? false,
        ]);

        // the platform owner: the old superadmin (the first one when there were several)
        $owner = DB::table('users')->where('role', 'superadmin')->orderBy('id')->value('id');
        DB::table('users')->where('role', 'superadmin')->update([
            'is_superadmin' => true,
            'superadmin_permissions' => json_encode(self::SUPERADMIN),
        ]);
        if ($owner !== null) {
            DB::table('users')->where('id', $owner)->update(['is_owner' => true]);
        }

        foreach (self::OLD_ROLES as $old => [$name, $scope, $permissions, $system]) {
            if (! DB::table('users')->where('role', $old)->exists()) {
                continue;
            }
            $roleId = DB::table('roles')->insertGetId([
                'organization_id' => $organizationId, 'name' => $name, 'scope' => $scope,
                'permissions' => json_encode($permissions), 'is_system' => $system,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('users')->where('role', $old)->update(['role_id' => $roleId, 'organization_id' => $organizationId]);
        }

        foreach (['audit_logs', 'employee_statuses', 'sessions', 'daily_summaries', 'devices', 'screenshots'] as $table) {
            DB::table($table)->update(['organization_id' => $organizationId]);
        }
        // platform actions (organization_id null) did not exist before this migration
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be undone: the single office is now an organization. Restore a backup instead.');
    }
};
