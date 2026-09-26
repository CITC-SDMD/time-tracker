<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Composite primary key (user_id, day) — Eloquent doesn't natively support that, so
// avoid find()/save() here; SummaryService (Phase 4) reads/writes via explicit
// where('user_id', ...)->where('day', ...) queries and a locked upsert.
#[Fillable([
    'user_id', 'day', 'tracked_seconds', 'active_seconds', 'idle_seconds',
    'apps', 'app_names', 'first_activity_at', 'last_activity_at',
])]
class DailySummary extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'apps' => 'array',
            'app_names' => 'array',
            'first_activity_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
