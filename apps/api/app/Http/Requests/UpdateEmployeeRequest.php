<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PATCH /api/v1/admin/employees/{id} (docs/DEVELOPMENT_PLAN.md §10). `role` and
// `managerId` (re-parenting) are deliberately not accepted here — only the direct
// manager may change those, via a separate, narrower action (not yet built; see the
// plan's note in §10 row for this route). This request only ever changes `name` or
// `status`.
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
            'status' => ['sometimes', 'string', Rule::in(['ACTIVE', 'DEACTIVATED'])],
        ];
    }
}
