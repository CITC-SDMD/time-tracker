<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// (Email uniqueness is deliberately NOT a rule here: AdminEmployeeController@store checks it directly, across
// every organization, so a collision reports 409, not this class's usual 422.)

// POST /api/v1/admin/employees (docs/DEVELOPMENT_PLAN.md §10). `roleId` is one of the organization's roles that the
// caller may give (checked in the controller, which also decides who the person reports to); `managerId` is optional
// and defaults to the caller.
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `permission:people.create` middleware already gates this
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'roleId' => ['required', 'integer'],
            'managerId' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
