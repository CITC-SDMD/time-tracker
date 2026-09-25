<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\HierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

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

        // Nobody knows this password: the new person picks their own through the emailed
        // set-password link (§9.2).
        $unusablePassword = Str::password(32);

        // Explicit property assignment, not User::create([...]) — role/manager_id/
        // status/created_by are deliberately excluded from Fillable (see User.php), so
        // a mass-assignment create() would silently drop them (and did, until this was
        // caught by EmployeeManagementTest failing with a NOT NULL constraint error).
        $employee = new User;
        $employee->name = $request->string('name');
        $employee->email = $request->string('email');
        $employee->password = Hash::make($unusablePassword);
        $employee->role = $request->string('role');
        $employee->manager_id = $caller->id;
        $employee->status = 'ACTIVE';
        $employee->created_by = $caller->id;
        $employee->save();

        AuditLog::record($caller, 'employee.created', $employee, ['role' => $employee->role]);

        // A failing mail server must not undo the account: it is logged, and the manager is
        // handed the link to pass on themselves.
        $token = Password::broker('invites')->createToken($employee);
        $emailSent = true;
        try {
            $employee->notify(new WelcomeNotification($token, $caller->name));
        } catch (Throwable $e) {
            report($e);
            $emailSent = false;
        }

        return response()->json([
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $employee->role,
            'emailSent' => $emailSent,
            ...($emailSent ? [] : ['setPasswordUrl' => $employee->passwordSetUrl($token)]),
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

    /**
     * Removes an account that was added by mistake or never used. Anyone who has tracked time or has
     * people reporting to them is refused: the office keeps all tracked data, so they are deactivated
     * instead, which keeps their history.
     */
    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $caller = $request->user();
        $employee = User::findOrFail($id);

        if ($id === $caller->id) {
            return $this->refuse(400, 'CANNOT_MODIFY_SELF', 'You cannot change your own account here.');
        }
        if ($employee->role === 'OIC') {
            return $this->refuse(400, 'CANNOT_DELETE_OIC', 'An OIC account cannot be deleted. Deactivate it instead.');
        }
        if (! in_array($id, $this->hierarchy->allDescendantIds($caller->id), true)) {
            return $this->refuse(403, 'FORBIDDEN', 'This person is not in your hierarchy.');
        }
        if (User::where('manager_id', $id)->exists()) {
            return $this->refuse(409, 'HAS_REPORTS', "{$employee->name} has people reporting to them. Delete or move those accounts first.");
        }
        if (DB::table('sessions')->where('user_id', $id)->exists() || DB::table('daily_summaries')->where('user_id', $id)->exists()) {
            return $this->refuse(409, 'HAS_DATA', "{$employee->name} has tracked time, which the office keeps. Deactivate the account instead.");
        }

        // Written first: the entry keeps the person's name after the row is gone.
        AuditLog::record($caller, 'employee.deleted', $employee, ['email' => $employee->email, 'role' => $employee->role]);

        $employee->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $employee->email)->delete();
        DB::table('password_invite_tokens')->where('email', $employee->email)->delete();
        $employee->delete();

        return response()->noContent();
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
