<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PATCH /api/v1/admin/employees/{id} (docs/DEVELOPMENT_PLAN.md §10). Changes `name`, `status` or
// `managerId` (moving the person to another manager). `role` is never accepted: a role change is a
// different account. Whether the caller may touch this person, and whether the new manager sits
// exactly one tier above them, is checked in AdminEmployeeController.
class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller checks the hierarchy/self rules — see AdminEmployeeController
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'managerId' => ['sometimes', 'integer'],
        ];
    }
}
