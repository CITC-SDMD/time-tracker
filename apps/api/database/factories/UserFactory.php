<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // A member with no permissions in the default test organization by default: tests opt into a role with the
            // state helpers below, so a plain factory call never accidentally creates someone with wider access.
            'organization_id' => fn () => OrganizationFactory::forTests()->id,
            'role_id' => fn () => RoleFactory::forTests('developer')->id,
            'status' => 'active',
            'detection_enabled' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** The organization admin of the default test organization (every permission, the whole organization). */
    public function oic(): static
    {
        return $this->state(fn () => ['role_id' => fn () => RoleFactory::forTests('oic')->id, 'manager_id' => null]);
    }

    public function projectManager(?User $reportsTo = null): static
    {
        return $this->reportingTo($reportsTo, 'project_manager');
    }

    public function teamLeader(?User $reportsTo = null): static
    {
        return $this->reportingTo($reportsTo, 'team_leader');
    }

    /** Any member role: lead_developer/developer/client_support/qa/system_analyst. */
    public function individualContributor(?User $reportsTo = null, string $role = 'developer'): static
    {
        return $this->reportingTo($reportsTo, $role);
    }

    /** Holds $role (in $role's organization) and, when given, reports to $reportsTo. */
    public function withRole(Role $role, ?User $reportsTo = null): static
    {
        return $this->state(fn () => ['organization_id' => $role->organization_id, 'role_id' => $role->id, 'manager_id' => $reportsTo?->id]);
    }

    /** A person of $organization holding its built-in admin role. */
    public function adminOf(Organization $organization): static
    {
        return $this->withRole(Role::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_system', true)->firstOrFail());
    }

    /** A platform superadmin with these platform permissions (all of them by default), the owner when $owner. */
    public function superadmin(?array $permissions = null, bool $owner = false): static
    {
        return $this->state(fn () => [
            'organization_id' => null,
            'role_id' => null,
            'is_superadmin' => true,
            'is_owner' => $owner,
            'superadmin_permissions' => $permissions ?? Permissions::superadminKeys(),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['status' => 'inactive', 'deactivated_at' => now()]);
    }

    /** the person reports to $reportsTo (and so is in their organization), holding the old role $key */
    private function reportingTo(?User $reportsTo, string $key): static
    {
        return $this->state(fn () => [
            'organization_id' => $reportsTo?->organization_id ?? fn () => OrganizationFactory::forTests()->id,
            'role_id' => fn () => RoleFactory::forTests($key, $reportsTo?->organization)->id,
            'manager_id' => $reportsTo?->id,
        ]);
    }
}
