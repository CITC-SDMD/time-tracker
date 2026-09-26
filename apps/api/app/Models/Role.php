<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// a role an organization made for itself: a name, a reach (`scope`) and the permissions ticked from the
// platform's fixed list (App\Support\Permissions). The one with is_system is the organization's built-in
// admin: its permissions and scope are locked, only its name can change.
#[Fillable(['name', 'description', 'scope', 'permissions'])]
class Role extends Model
{
    use BelongsToOrganization, HasFactory;

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }
}
