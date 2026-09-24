<?php

namespace App\Http\Requests;

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
            'windowTitleMode' => ['sometimes', 'string', Rule::in(['FULL', 'APP_ONLY'])],
            'minAgentVersion' => ['sometimes', 'string', 'max:32'],
            'consentVersion' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
