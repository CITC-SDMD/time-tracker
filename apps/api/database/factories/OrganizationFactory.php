<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slug' => fake()->unique()->slug(3),
        ];
    }

    /**
     * A whole organization the way a superadmin makes it (settings and the built-in admin role included), named
     * $name. Tests that need two offices call this twice.
     */
    public static function made(string $name = 'Test Office'): Organization
    {
        $organization = app(OrganizationService::class)->create($name);
        // the old office called its admin role "OIC"; a name the tests keep using
        $organization->roles()->where('is_system', true)->update(['name' => 'OIC']);

        return $organization;
    }

    /** The one default organization of a test: made on first use, the same one afterwards. */
    public static function forTests(): Organization
    {
        return Organization::where('slug', 'test-office')->first() ?? self::made('Test Office');
    }
}
