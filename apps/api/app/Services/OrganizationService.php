<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes an organization the way a superadmin does (dashboard or terminal): its settings and its one built-in
 * role, the admin. Everything else in it, people and other roles, is made by its own admins.
 */
class OrganizationService
{
    public const ADMIN_ROLE_NAME = 'Admin';

    public function create(string $name, string $timezone = 'Asia/Manila'): Organization
    {
        return DB::transaction(function () use ($name, $timezone) {
            $organization = new Organization;
            $organization->name = trim($name);
            $organization->slug = $this->uniqueSlug($name);
            $organization->status = 'active';
            $organization->save();

            $settings = new OrganizationSetting;
            $settings->organization_id = $organization->id;
            $settings->timezone = $timezone;
            $settings->idle_threshold_seconds = 300;
            $settings->window_title_mode = 'full';
            $settings->consent_version = 1;
            $settings->screenshot_interval_minutes = 0;
            $settings->screenshot_random = false;
            $settings->save();

            $this->createAdminRole($organization);

            return $organization;
        });
    }

    public function createAdminRole(Organization $organization): Role
    {
        $role = new Role;
        $role->organization_id = $organization->id;
        $role->name = self::ADMIN_ROLE_NAME;
        $role->description = 'Runs the organization: everything, for everyone.';
        $role->scope = 'organization';
        $role->permissions = Permissions::organizationKeys();
        $role->is_system = true;
        $role->save();

        return $role;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        for ($i = 2; Organization::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
