<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// (Email uniqueness is deliberately NOT a rule here — see AdminEmployeeController@store,
// which checks it directly so a collision reports 409, not this class's usual 422.)

// POST /api/v1/admin/employees (docs/DEVELOPMENT_PLAN.md §10, §10.1). Only `name`,
// `email` and `role` are ever read from the body — `managerId` is never accepted here;
// the controller always sets it to the caller's own id (§9.1 "one tier below,
// as your own direct report").
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level 'manager' middleware already gates this
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $allowedRoles = User::rolesOneTierBelow($this->user()?->role ?? '');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // Empty $allowedRoles (caller isn't a manager, or has no reports role below
            // them) means every value fails validation — the route middleware should
            // already have blocked non-managers, but this is the real enforcement of
            // "exactly one tier below the caller" from §9.1.
            'role' => ['required', 'string', Rule::in($allowedRoles)],
        ];
    }
}
