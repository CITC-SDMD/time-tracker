<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

// one row per organization: what its admins can set (docs/DEVELOPMENT_PLAN.md §9, §10).
#[Fillable(['timezone', 'idle_threshold_seconds', 'window_title_mode', 'consent_version', 'screenshot_interval_minutes', 'screenshot_random'])]
class OrganizationSetting extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'screenshot_interval_minutes' => 'integer',
            'screenshot_random' => 'boolean',
        ];
    }

    /** The settings of the organization this request works inside. */
    public static function current(): self
    {
        if (app(OrganizationContext::class)->id() === null) {
            throw new RuntimeException('No organization is set for this request.');
        }

        return self::firstOrFail();
    }
}
