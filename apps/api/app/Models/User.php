<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToOrganization;
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

// A person belongs to one organization (a superadmin to none) and holds one of the roles that organization made.
// `role_id`, `manager_id`, `status` etc. are deliberately NOT in Fillable: controllers set them explicitly after
// the permission checks (AdminEmployeeController), never straight from request input. See
// docs/DEVELOPMENT_PLAN.md §9 "never trust the client".
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasApiTokens, HasFactory, Notifiable;

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

    /** Platform staff: no organization and no organization role, only their own list of platform permissions. */
    public function isSuperadmin(): bool
    {
        return (bool) $this->is_superadmin;
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, User> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Direct reports only, not the full descendant tree. See HierarchyService for that. */
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
            'invite_failed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
            'detection_enabled' => 'boolean',
            'consent_accepted_at' => 'datetime',
            'is_superadmin' => 'boolean',
            'is_owner' => 'boolean',
            'superadmin_permissions' => 'array',
        ];
    }
}
