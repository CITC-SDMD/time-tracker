<?php

namespace App\Http\Requests;

use App\Models\OfficeSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PUT /api/v1/admin/settings (docs/DEVELOPMENT_PLAN.md §8, §10). All fields optional —
// a PUT here is a partial update of the single settings row, not a full replace.
class UpdateOfficeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level 'oic' middleware already gates this
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'idleThresholdSeconds' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'windowTitleMode' => ['sometimes', 'string', Rule::in(['full', 'app_only'])],
            // a version like 1.2.3: anything else would lock every desktop app out of syncing
            'minAgentVersion' => ['sometimes', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+$/'],
            // it only ever goes up: lowering it would skip the tracking notice people already accepted
            'consentVersion' => ['sometimes', 'integer', 'min:'.OfficeSetting::current()->consent_version],
            // minutes between screenshots taken by the desktop apps: 0 = off
            'screenshotIntervalMinutes' => ['sometimes', 'integer', Rule::in([0, 5, 10, 15, 30])],
            'screenshotRandom' => ['sometimes', 'boolean'],
        ];
    }
}
