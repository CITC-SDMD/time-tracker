<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Readable only by the OIC (docs/DEVELOPMENT_PLAN.md §9.1) — not filtered by hierarchy.
#[Fillable(['actor_user_id', 'action', 'target_user_id', 'details', 'created_at'])]
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
            'action' => $action,
            'target_user_id' => $target?->id,
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
