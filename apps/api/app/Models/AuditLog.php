<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Readable only by the OIC (docs/DEVELOPMENT_PLAN.md §9.1) — not filtered by hierarchy. The names are
// copied into each entry so the log still reads correctly after an account is deleted.
#[Fillable(['actor_user_id', 'actor_name', 'action', 'target_user_id', 'target_name', 'details', 'created_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** @param array<string, mixed>|null $details */
    public static function record(User $actor, string $action, ?User $target = null, ?array $details = null): self
    {
        return self::create([
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
