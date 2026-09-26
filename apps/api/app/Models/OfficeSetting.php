<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Single row, id = 1. Read/write restricted to the OIC (docs/DEVELOPMENT_PLAN.md §9.1).
#[Fillable(['timezone', 'idle_threshold_seconds', 'window_title_mode', 'min_agent_version', 'consent_version', 'screenshot_interval_minutes', 'screenshot_random'])]
class OfficeSetting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'screenshot_interval_minutes' => 'integer',
            'screenshot_random' => 'boolean',
        ];
    }

    /** The only row. */
    public static function current(): self
    {
        return self::findOrFail(1);
    }
}
