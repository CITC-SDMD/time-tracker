<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// POST /api/v1/admin/employees, PATCH /api/v1/admin/employees/{id}
// (docs/DEVELOPMENT_PLAN.md §9.1, §10). Both routes carry the `manager` middleware;
// this controller adds the hierarchy-specific checks on top.
class AdminEmployeeController extends Controller
{
    public function __construct(private HierarchyService $hierarchy) {}

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $caller = $request->user();

        // §10.1-style validation is already done by StoreEmployeeRequest (role must be
        // exactly one tier below the caller's own — see User::rolesOneTierBelow).
        // Email uniqueness is checked here, not as a validation rule, so a collision
        // reports 409 (matches §12 Phase 2 task 8) rather than a generic 422.
        if (User::where('email', $request->string('email'))->exists()) {
            return response()->json([
                'error' => ['code' => 'EMAIL_TAKEN', 'message' => 'A user with this email already exists.'],
            ], 409);
        }

        // A real (never-emailed, never-logged) temporary password, same approach as
        // `tracker:make-oic`. The plan's longer-term design is an emailed
        // Password::sendResetLink() flow (§9.2) — that needs a working reset-password
        // page on the dashboard first, which hasn't been built yet, so this is the
        // interim mechanism: the manager sees it once, in this response, and passes
        // it to the new hire directly.
        $temporaryPassword = Str::password(16);

        // Explicit property assignment, not User::create([...]) — role/manager_id/
        // status/created_by are deliberately excluded from Fillable (see User.php), so
        // a mass-assignment create() would silently drop them (and did, until this was
        // caught by EmployeeManagementTest failing with a NOT NULL constraint error).
        $employee = new User;
        $employee->name = $request->string('name');
        $employee->email = $request->string('email');
        $employee->password = Hash::make($temporaryPassword);
        $employee->role = $request->string('role');
        $employee->manager_id = $caller->id;
        $employee->status = 'ACTIVE';
        $employee->created_by = $caller->id;
        $employee->save();

        AuditLog::record($caller, 'employee.created', $employee, ['role' => $employee->role]);

        return response()->json([
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $employee->role,
            'temporaryPassword' => $temporaryPassword,
        ], 201);
    }

    public function update(UpdateEmployeeRequest $request, int $id): JsonResponse
    {
        $caller = $request->user();
        $employee = User::findOrFail($id);
        $newStatus = $request->string('status')->toString();

        // Checked first, and before the self-check below, so it produces this more
        // specific error even for the realistic way it actually triggers: a lone OIC
        // deactivating themselves. (Two OICs deactivating each other can never drive
        // the active count to zero — the caller stays active — so that path is
        // defense-in-depth for future code, not something this test suite can hit.)
        if ($newStatus === 'DEACTIVATED' && $employee->role === 'OIC') {
            $otherActiveOics = User::where('role', 'OIC')
                ->where('status', 'ACTIVE')
                ->whereKeyNot($employee->id)
                ->exists();

            if (! $otherActiveOics) {
                return response()->json([
                    'error' => ['code' => 'LAST_OIC', 'message' => 'There must always be at least one active OIC.'],
                ], 400);
            }
        }

        if ($id === $caller->id) {
            return response()->json([
                'error' => ['code' => 'CANNOT_MODIFY_SELF', 'message' => 'You cannot change your own account here.'],
            ], 400);
        }

        // OIC has no manager_id, so no OIC is ever anyone's "descendant" — the normal
        // hierarchy check would make it impossible for any OIC to ever be deactivated
        // by anyone, including another OIC. Peer OICs are the one deliberate exception:
        // any active OIC may act on any other OIC. Everyone else still requires $id to
        // be a real descendant.
        $isPeerOic = $caller->role === 'OIC' && $employee->role === 'OIC';
        if (! $isPeerOic && ! in_array($id, $this->hierarchy->allDescendantIds($caller->id), true)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'This person is not in your hierarchy.'],
            ], 403);
        }

        if ($request->has('name')) {
            $employee->name = $request->string('name');
        }

        if ($newStatus === 'DEACTIVATED' && $employee->status !== 'DEACTIVATED') {
            $employee->status = 'DEACTIVATED';
            $employee->deactivated_at = now();
            // Agent tokens stay valid so the PC can still upload what it recorded before
            // the deactivation (§10.1 step 4.3, Test 4.15); the `active` middleware already
            // refuses them everywhere except /agent/sync. Any other token is revoked.
            $employee->tokens()->where('name', 'not like', 'agent-%')->delete();
            AuditLog::record($caller, 'employee.deactivated', $employee);
        } elseif ($newStatus === 'ACTIVE' && $employee->status !== 'ACTIVE') {
            $employee->status = 'ACTIVE';
            $employee->deactivated_at = null;
            AuditLog::record($caller, 'employee.reactivated', $employee);
        }

        $employee->save();

        return response()->json([
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $employee->role,
            'status' => $employee->status,
        ]);
    }
}
