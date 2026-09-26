<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    private const MANAGER = ['people.view', 'people.create', 'people.update', 'people.assign_role', 'timeline.view', 'screenshots.view', 'reports.view', 'reports.export'];

    /** the roles the old single office had, by key: [name, scope, permissions] (tests build people with them) */
    public const OLD = [
        'project_manager' => ['Project Manager', 'team', self::MANAGER],
        'team_leader' => ['Team Leader', 'team', self::MANAGER],
        'lead_developer' => ['Lead Developer', 'self', []],
        'developer' => ['Developer', 'self', []],
        'client_support' => ['Client Support', 'self', []],
        'qa' => ['QA', 'self', []],
        'system_analyst' => ['System Analyst', 'self', []],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => OrganizationFactory::forTests()->id,
            'name' => fake()->unique()->jobTitle(),
            'scope' => 'self',
            'permissions' => [],
            'is_system' => false,
        ];
    }

    /** A role with these permissions and this reach, in the default test organization unless $organization is given. */
    public static function make2(string $name, string $scope, array $permissions, ?Organization $organization = null): Role
    {
        $organization ??= OrganizationFactory::forTests();

        return Role::unguarded(fn () => Role::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => $name],
            ['scope' => $scope, 'permissions' => $permissions, 'is_system' => false],
        ));
    }

    /**
     * The role a test person holds. 'oic' is the organization admin (every permission, the whole organization); the
     * others are the old fixed roles as roles this organization "made".
     */
    public static function forTests(string $key, ?Organization $organization = null): Role
    {
        $organization ??= OrganizationFactory::forTests();
        if ($key === 'oic') {
            return Role::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_system', true)->firstOrFail();
        }
        [$name, $scope, $permissions] = self::OLD[$key];

        return self::make2($name, $scope, $permissions, $organization);
    }

    /** every organization permission, the whole organization: what the admin role holds */
    public static function allPermissions(): array
    {
        return Permissions::organizationKeys();
    }
}
