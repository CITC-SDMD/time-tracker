<?php

namespace App\Support;

// the fixed lists of permissions (docs/DEVELOPMENT_PLAN.md §9.1). The platform defines them; an organization only
// chooses which of its own roles hold which. Everyone always has their own data (own day, screenshots,
// profile), so none of these is needed for that.
final class Permissions
{
    public const SCOPES = ['self', 'team', 'organization'];

    /** How far a role reaches, wider is higher (used to stop anyone giving more reach than they have). */
    public const SCOPE_RANK = ['self' => 0, 'team' => 1, 'organization' => 2];

    /** @var array<string, array{group: string, label: string, description: string}> */
    public const ORGANIZATION = [
        'people.view' => ['group' => 'People', 'label' => 'See people', 'description' => 'See the people list and who is tracking right now.'],
        'people.create' => ['group' => 'People', 'label' => 'Add people', 'description' => 'Add a person and send them their set-password link.'],
        'people.update' => ['group' => 'People', 'label' => 'Manage people', 'description' => 'Edit a name, deactivate or reactivate, send a new link, change who someone reports to, delete an unused account.'],
        'people.assign_role' => ['group' => 'People', 'label' => 'Change roles', 'description' => 'Give a person another role.'],
        'timeline.view' => ['group' => 'Tracked time', 'label' => 'See days and timelines', 'description' => 'Open a person\'s day: totals, applications and window titles.'],
        'screenshots.view' => ['group' => 'Tracked time', 'label' => 'See screenshots', 'description' => 'Open a person\'s screenshots.'],
        'reports.view' => ['group' => 'Reports', 'label' => 'See reports', 'description' => 'Daily, application and team reports.'],
        'reports.export' => ['group' => 'Reports', 'label' => 'Download reports', 'description' => 'Download reports as CSV files.'],
        'settings.manage' => ['group' => 'Organization', 'label' => 'Change settings', 'description' => 'Timezone, idle limit, window titles and screenshots for the whole organization.'],
        'audit.view' => ['group' => 'Organization', 'label' => 'See the audit log', 'description' => 'Who did what in the organization.'],
        'roles.manage' => ['group' => 'Organization', 'label' => 'Manage roles', 'description' => 'Create, change and delete roles.'],
        'tasks.view' => ['group' => 'Tasks', 'label' => 'See tasks', 'description' => 'See the task list and the hours tracked against each one.'],
        'tasks.manage' => ['group' => 'Tasks', 'label' => 'Manage tasks', 'description' => 'Create, change and archive tasks, and choose who they are assigned to.'],
    ];

    /** Permissions that concern the whole organization, so they need a role with the organization scope. */
    public const ORGANIZATION_WIDE = ['settings.manage', 'audit.view', 'roles.manage', 'tasks.manage'];

    /** What a superadmin who may only look inside an organization gets there. */
    public const READ_ONLY = ['people.view', 'timeline.view', 'screenshots.view', 'reports.view', 'reports.export', 'audit.view', 'tasks.view'];

    /** @var array<string, array{group: string, label: string, description: string}> */
    public const SUPERADMIN = [
        'organizations.view' => ['group' => 'Organizations', 'label' => 'See organizations', 'description' => 'List organizations and open their profile and usage.'],
        'organizations.create' => ['group' => 'Organizations', 'label' => 'Create organizations', 'description' => 'Add a new organization.'],
        'organizations.update' => ['group' => 'Organizations', 'label' => 'Change organizations', 'description' => 'Rename, suspend and reactivate an organization.'],
        'organizations.admins.manage' => ['group' => 'Organizations', 'label' => 'Manage organization admins', 'description' => 'Add, invite again, deactivate and reactivate the admins of an organization.'],
        'organizations.data.view' => ['group' => 'Inside an organization', 'label' => 'Look inside an organization', 'description' => 'Open an organization read-only: people, timelines, screenshots, reports and audit log.'],
        'organizations.data.manage' => ['group' => 'Inside an organization', 'label' => 'Change things inside an organization', 'description' => 'Do what its admin can do: people, roles and settings. Includes looking.'],
        'organizations.detection.manage' => ['group' => 'Inside an organization', 'label' => 'Detection: turn it on or off for a person', 'description' => 'Switch the virtual machine detection on or off for one person of an organization.'],
        'platform.settings' => ['group' => 'Platform', 'label' => 'Platform settings', 'description' => 'The oldest desktop app version that may sync.'],
        'platform.staff.manage' => ['group' => 'Platform', 'label' => 'Manage superadmins', 'description' => 'Add superadmins, choose their permissions, deactivate them.'],
        'platform.audit.view' => ['group' => 'Platform', 'label' => 'See the platform audit log', 'description' => 'Organizations created or suspended, and superadmin changes.'],
    ];

    /** @return list<string> */
    public static function organizationKeys(): array
    {
        return array_keys(self::ORGANIZATION);
    }

    /** @return list<string> */
    public static function superadminKeys(): array
    {
        return array_keys(self::SUPERADMIN);
    }

    /**
     * Why $permissions cannot go with $scope, or null when the pair is fine. A role that only reaches the person
     * themselves cannot hold a permission about other people, and the organization-wide permissions need the
     * organization scope.
     *
     * @param  list<string>  $permissions
     */
    public static function problemWith(string $scope, array $permissions): ?string
    {
        if ($permissions !== [] && $scope === 'self') {
            return 'These permissions are about other people, so the role has to reach a team or the whole organization.';
        }
        if ($scope !== 'organization' && array_intersect($permissions, self::ORGANIZATION_WIDE) !== []) {
            return 'Settings, the audit log and roles are for the whole organization, so the role has to reach the whole organization.';
        }

        return null;
    }
}
