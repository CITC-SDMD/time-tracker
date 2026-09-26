<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Readable by whoever holds `audit.view` in the organization, and not filtered by reach; platform actions (no
// organization) are read with `platform.audit.view`. The names are copied into each entry so the log still reads
// correctly after an account is deleted.
#[Fillable(['organization_id', 'actor_user_id', 'actor_name', 'action', 'target_user_id', 'target_name', 'details', 'created_at'])]
class AuditLog extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * A platform action (an organization created or suspended, a superadmin added): it belongs to the platform, so it
     * carries no organization even though the request may be working inside one.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function recordPlatform(User $actor, string $action, ?User $target = null, ?array $details = null): self
    {
        $entry = self::record($actor, $action, $target, $details);
        $entry->organization_id = null;
        $entry->save();

        return $entry;
    }

    /** @param array<string, mixed>|null $details */
    public static function record(User $actor, string $action, ?User $target = null, ?array $details = null): self
    {
        return self::create([
            // the organization the action happened in; none for platform actions such as creating an organization
            'organization_id' => app(OrganizationContext::class)->id() ?? $actor->organization_id,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'target_user_id' => $target?->id,
            'target_name' => $target?->name,
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
