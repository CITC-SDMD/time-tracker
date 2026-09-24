<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'state', 'current_app', 'idle_app_name', 'device_id', 'agent_version',
    'since', 'last_seen_at', 'clock_skew_seconds', 'tracking_device_since',
])]
class EmployeeStatus extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'last_seen_at' => 'datetime',
            'tracking_device_since' => 'datetime',
        ];
    }
}
