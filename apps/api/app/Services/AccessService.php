<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\Permissions;

/**
 * The one place that decides what a person may do and whom they may see (docs/DEVELOPMENT_PLAN.md §9.1).
 *
 * - An organization person has the permissions and the reach (scope) of their role.
 * - A superadmin has platform permissions. Inside an organization they open (the context is set to it) they
 *   act as a virtual organization-wide role: all organization permissions with `organizations.data.manage`,
 *   the read-only set with `organizations.data.view`, nothing without either.
 * - Everyone can always see themselves.
 */
class AccessService
{
    public function __construct(
        private OrganizationContext $context,
        private HierarchyService $hierarchy,
    ) {}

    /** @return list<string> */
    public function permissions(User $user): array
    {
        if ($user->isSuperadmin()) {
            if ($this->context->id() === null) {
                return [];
            }
            if ($this->superadminCan($user, 'organizations.data.manage')) {
                return Permissions::organizationKeys();
            }

            return $this->superadminCan($user, 'organizations.data.view') ? Permissions::READ_ONLY : [];
        }

        return $user->role?->permissions ?? [];
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }

    /**
     * Whether $user may see the virtual machine detection flags (docs/DEVELOPMENT_PLAN.md §16): superadmins who may
     * look inside the office they have open, and the people who hold the organization's built-in admin role.
     */
    public function canSeeDetection(User $user): bool
    {
        if ($user->isSuperadmin()) {
            return $this->context->id() !== null && $this->superadminCan($user, 'organizations.data.view');
        }

        return (bool) $user->role?->is_system;
    }

    /** self, team or organization */
    public function scope(User $user): string
    {
        if ($user->isSuperadmin()) {
            return $this->context->id() === null ? 'self' : 'organization';
        }

        return $user->role?->scope ?? 'self';
    }

    /** Whether a superadmin holds a platform permission (the owner holds all of them). */
    public function superadminCan(User $user, string $permission): bool
    {
        if (! $user->isSuperadmin()) {
            return false;
        }
        if ($user->is_owner) {
            return true;
        }
        $held = $user->superadmin_permissions ?? [];

        return in_array($permission, $held, true)
            || ($permission === 'organizations.data.view' && in_array('organizations.data.manage', $held, true));
    }

    /**
     * Everyone $user may see: themselves, plus everyone below them with a team scope, or everyone in the
     * organization with the organization scope.
     *
     * @return list<int>
     */
    public function visibleUserIds(User $user): array
    {
        return match ($this->scope($user)) {
            'organization' => $this->context->id() === null ? [$user->id] : User::query()->pluck('id')->all(),
            'team' => [$user->id, ...$this->hierarchy->allDescendantIds($user->id)],
            default => [$user->id],
        };
    }

    public function isVisible(User $viewer, int $targetUserId): bool
    {
        return in_array($targetUserId, $this->visibleUserIds($viewer), true);
    }

    /**
     * Whether $user may see $target's days, screenshots and so on: their own, or someone in their reach and only
     * if they hold $permission.
     */
    public function canSee(User $user, int $targetUserId, string $permission): bool
    {
        // a superadmin outside any office has no days or pictures of their own to look at
        if ($user->isSuperadmin() && $this->context->id() === null) {
            return false;
        }
        if ($targetUserId === $user->id) {
            return true;
        }

        return $this->can($user, $permission) && $this->isVisible($user, $targetUserId);
    }

    /**
     * Nobody may give more than they have: a role can be given, made or edited by someone only when every permission
     * of it is one they hold themselves and its reach is not wider than theirs.
     *
     * @param  list<string>  $permissions
     */
    public function canGrant(User $user, array $permissions, string $scope): bool
    {
        return array_diff($permissions, $this->permissions($user)) === []
            && Permissions::SCOPE_RANK[$scope] <= Permissions::SCOPE_RANK[$this->scope($user)];
    }

    public function canGrantRole(User $user, Role $role): bool
    {
        return $this->canGrant($user, $role->permissions ?? [], $role->scope);
    }
}
