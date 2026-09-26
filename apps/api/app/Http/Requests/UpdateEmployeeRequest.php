<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PATCH /api/v1/admin/employees/{id} (docs/DEVELOPMENT_PLAN.md §10). Changes `name`, `status`, `roleId` or
// `managerId` (who the person reports to; null takes their manager away, which only someone reaching the whole
// organization may do). Which of them the caller may change, and for whom, is checked in AdminEmployeeController.
class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller checks the permissions, the reach and the self rules
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'roleId' => ['sometimes', 'integer'],
            'managerId' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
