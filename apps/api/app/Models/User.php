<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// The hierarchy: OIC -> PROJECT_MANAGER -> TEAM_LEADER -> individual contributors.
// docs/DEVELOPMENT_PLAN.md §9.1. `role`, `manager_id`, `status` etc. are deliberately
// NOT in Fillable — controllers set them explicitly after validating the "one tier
// below the caller" rule (AdminEmployeeController@store), never via mass assignment
// straight from request input. See §9.3 "never trust the client".
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Roles that get any dashboard access at all, scoped to their own hierarchy. */
    public const array MANAGER_ROLES = ['superadmin', 'oic', 'project_manager', 'team_leader'];

    /** Roles with no reports — desktop app only, see only their own data. */
    public const array INDIVIDUAL_CONTRIBUTOR_ROLES = [
        'lead_developer', 'developer', 'client_support', 'qa', 'system_analyst',
    ];

    /**
     * The set of roles a direct report of $role is allowed to have — i.e. exactly
     * one tier below. Empty for individual-contributor roles (they create no one).
     *
     * @return list<string>
     */
    public static function rolesOneTierBelow(string $role): array
    {
        return match ($role) {
            'oic' => ['project_manager'],
            'project_manager' => ['team_leader'],
            'team_leader' => self::INDIVIDUAL_CONTRIBUTOR_ROLES,
            default => [],
        };
    }

    /** Roles a manager may give to someone (never 'oic' or 'superadmin'). */
    public const array ASSIGNABLE_ROLES = [
        'project_manager', 'team_leader', 'lead_developer', 'developer', 'client_support', 'qa', 'system_analyst',
    ];

    /**
     * The roles a person holding $role may report to: the ones whose direct reports can have $role.
     * Empty for an OIC (no manager).
     *
     * @return list<string>
     */
    public static function rolesThatManage(string $role): array
    {
        return array_values(array_filter(
            self::MANAGER_ROLES,
            fn (string $manager) => in_array($role, self::rolesOneTierBelow($manager), true),
        ));
    }

    /**
     * The dashboard page where this person picks a new password (welcome and reset emails). The
     * token and email travel as ONE opaque URL-safe value: no "&", "@" or "%" for a mail gateway
     * or link scanner to mangle, which would otherwise cut the email off the link.
     */
    public function passwordSetUrl(string $token): string
    {
        $payload = json_encode(['token' => $token, 'email' => $this->getEmailForPasswordReset()]);

        return config('app.dashboard_url').'/reset-password?link='.rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public function isManagerRole(): bool
    {
        return in_array($this->role, self::MANAGER_ROLES, true);
    }

    /** @return BelongsTo<User, User> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Direct reports only — not the full descendant tree. See HierarchyService for that. */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function employeeStatus(): HasOne
    {
        return $this->hasOne(EmployeeStatus::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
            'consent_accepted_at' => 'datetime',
        ];
    }
}
