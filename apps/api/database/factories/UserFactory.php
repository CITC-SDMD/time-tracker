<?php

namespace Database\Factories;

use App\Models\User;
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
            // Individual-contributor by default — tests opt into a manager role
            // explicitly via the state helpers below, so a plain factory call never
            // accidentally creates someone with dashboard access.
            'role' => 'developer',
            'status' => 'active',
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

    public function oic(): static
    {
        return $this->state(fn () => ['role' => 'oic', 'manager_id' => null]);
    }

    public function projectManager(?User $reportsTo = null): static
    {
        return $this->state(fn () => ['role' => 'project_manager', 'manager_id' => $reportsTo?->id]);
    }

    public function teamLeader(?User $reportsTo = null): static
    {
        return $this->state(fn () => ['role' => 'team_leader', 'manager_id' => $reportsTo?->id]);
    }

    /** Any individual-contributor role — LEAD_DEVELOPER/DEVELOPER/CLIENT_SUPPORT/QA/SYSTEM_ANALYST. */
    public function individualContributor(?User $reportsTo = null, string $role = 'developer'): static
    {
        return $this->state(fn () => ['role' => $role, 'manager_id' => $reportsTo?->id]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['status' => 'inactive', 'deactivated_at' => now()]);
    }
}
