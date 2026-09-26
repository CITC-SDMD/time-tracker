<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PATCH /api/v1/admin/employees/{id} (docs/DEVELOPMENT_PLAN.md §10). Changes `name`, `status`, `role`
// or `managerId` (moving the person to another manager). Only people above someone may change their
// role (never their own), and never to `oic` or `superadmin`. Whether the caller may touch this
// person, and whether the new manager sits exactly one tier above the (new) role, is checked in
// AdminEmployeeController.
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
            'role' => ['sometimes', 'string', Rule::in(User::ASSIGNABLE_ROLES)],
            'managerId' => ['sometimes', 'integer'],
        ];
    }
}
