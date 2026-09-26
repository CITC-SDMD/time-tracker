<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// one government office using the platform. It is created by a superadmin (never by itself), with its
// settings and its built-in admin role; everything else in it is made by its own admins.
#[Fillable(['name', 'slug'])]
class Organization extends Model
{
    use HasFactory;

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasOne<OrganizationSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(OrganizationSetting::class);
    }
}
