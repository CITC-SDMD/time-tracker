<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Single row, id = 1. Read/write restricted to the OIC (docs/DEVELOPMENT_PLAN.md §9.1).
#[Fillable(['timezone', 'idle_threshold_seconds', 'window_title_mode', 'min_agent_version', 'consent_version'])]
class OfficeSetting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /** The only row. */
    public static function current(): self
    {
        return self::findOrFail(1);
    }
}
