<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A tracking session (docs/DEVELOPMENT_PLAN.md §8) — not related to Laravel's own
// HTTP session concept. `id` is a UUID v7 generated on the employee's PC; it is
// never auto-generated here (see §10.2 idempotency).
#[Fillable([
    'id', 'user_id', 'device_id', 'type', 'app_name', 'app_key', 'process_name',
    'window_title', 'idle_app_name', 'started_at', 'ended_at', 'duration_seconds',
    'day', 'clock_changed', 'received_at',
])]
class Session extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'day' => 'date',
            'clock_changed' => 'boolean',
            'received_at' => 'datetime',
        ];
    }
}
