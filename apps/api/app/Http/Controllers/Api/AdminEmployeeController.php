<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessService;
use App\Services\AccountService;
use App\Services\HierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

// POST /api/v1/admin/employees, PATCH/DELETE /api/v1/admin/employees/{id}, POST .../resend-invite
// (docs/DEVELOPMENT_PLAN.md §9.1, §10). The routes carry `permission:` middleware; this controller adds
// the checks on WHOM: inside the organization (an id from another one does not exist: 404), inside the caller's
// reach (403), never yourself, and never handing out more than the caller has (ROLE_ESCALATION).
class AdminEmployeeController extends Controller
{
    public function __construct(
        private AccessService $access,
        private HierarchyService $hierarchy,
        private AccountService $accounts,
    ) {}

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $caller = $request->user();

        // Email uniqueness is checked here, not as a validation rule, so a collision reports 409
        // (matches §12 Phase 2 task 8) rather than a generic 422. Emails are unique across every organization.
        if ($this->accounts->emailTaken($request->string('email')->toString())) {
            return $this->refuse(409, 'EMAIL_TAKEN', 'A user with this email already exists.');
        }

        $role = Role::find($request->integer('roleId'));
        if ($role === null) {
            return $this->refuse(422, 'ROLE_NOT_FOUND', 'Choose one of the roles of this organization.');
        }
        if (! $this->access->canGrantRole($caller, $role)) {
            return $this->refuse(403, 'ROLE_ESCALATION', 'You cannot give a role that can do more than your own.');
        }

        // Unless said otherwise the new person reports to the caller (a superadmin has no place in the tree).
        $manager = $caller->isSuperadmin() ? null : $caller;
        if ($request->has('managerId')) {
            if ($request->input('managerId') === null) {
                // leaving a new person without a manager is for someone who reaches the whole organization
                if ($this->access->scope($caller) !== 'organization') {
                    return $this->refuse(422, 'MANAGER_REQUIRED', 'Choose who this person reports to.');
                }
                $manager = null;
            } else {
                $manager = $this->pickManager($caller, $request->input('managerId'));
                if ($manager instanceof JsonResponse) {
                    return $manager;
                }
            }
        }

        [$employee, $emailSent, $link] = $this->accounts->createPerson(
            $caller, $request->string('name')->toString(), $request->string('email')->toString(), $role, $manager,
        );

        AuditLog::record($caller, 'employee.created', $employee, ['role' => $role->name]);

        return response()->json([
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $role->name,
            'roleId' => (string) $role->id,
            'emailSent' => $emailSent,
            ...($emailSent ? [] : ['setPasswordUrl' => $link]),
        ], 201);
    }

    public function update(UpdateEmployeeRequest $request, int $id): JsonResponse
    {
        $caller = $request->user();
        $employee = User::find($id);
        if ($employee === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        $newStatus = $request->string('status')->toString();

        // Checked first, and before the self-check below, so a lone admin deactivating themselves is told
        // there must always be an admin rather than just "not yourself".
        if ($newStatus === 'inactive' && $employee->status === 'active' && $this->isLastAdmin($employee)) {
            return $this->refuse(400, 'LAST_ADMIN', 'The organization must always keep at least one active admin.');
        }

        if ($refusal = $this->checkTarget($caller, $employee)) {
            return $refusal;
        }

        $touchesPerson = $request->hasAny(['name', 'status', 'managerId']);
        if ($touchesPerson && ! $this->access->can($caller, 'people.update')) {
            return $this->refuse(403, 'PERMISSION_DENIED', 'Your role does not allow this.');
        }
        if ($request->has('roleId') && ! $this->access->can($caller, 'people.assign_role')) {
            return $this->refuse(403, 'PERMISSION_DENIED', 'Your role does not allow this.');
        }

        // The role and the move are checked before anything is changed, so a refused request leaves the account untouched.
        $newRole = null;
        if ($request->has('roleId') && $request->integer('roleId') !== $employee->role_id) {
            $newRole = $this->checkRoleChange($caller, $employee, $request->integer('roleId'));
            if ($newRole instanceof JsonResponse) {
                return $newRole;
            }
        }

        $newManager = null;
        $removeManager = false;
        if ($request->has('managerId') && $request->input('managerId') !== $employee->manager_id) {
            if ($request->input('managerId') === null) {
                if ($this->access->scope($caller) !== 'organization') {
                    return $this->refuse(422, 'MANAGER_REQUIRED', 'Only someone who reaches the whole organization can leave a person without a manager.');
                }
                $removeManager = $employee->manager_id !== null;
            } else {
                $newManager = $this->pickManager($caller, $request->input('managerId'), $employee);
                if ($newManager instanceof JsonResponse) {
                    return $newManager;
                }
                $newManager = $newManager->id === $employee->manager_id ? null : $newManager;
            }
        }

        if ($request->has('name')) {
            $employee->name = $request->string('name');
        }

        $previousManager = $employee->manager;
        $previousRole = $employee->role;
        if ($newManager) {
            $employee->manager_id = $newManager->id;
        } elseif ($removeManager) {
            $employee->manager_id = null;
        }
        $movedTo = $newManager ?? ($removeManager ? false : null);

        if ($newRole !== null) {
            AuditLog::record($caller, 'employee.role_changed', $employee, [
                'from' => $previousRole?->name,
                'to' => $newRole->name,
                ...($movedTo === null ? [] : ['managerFrom' => $previousManager?->name, 'managerTo' => $movedTo ? $movedTo->name : null]),
            ]);
            $employee->role_id = $newRole->id;
        } elseif ($movedTo !== null) {
            AuditLog::record($caller, 'employee.moved', $employee, [
                'from' => $previousManager?->name,
                'to' => $movedTo ? $movedTo->name : null,
            ]);
        }

        if ($newStatus === 'inactive' && $employee->status !== 'inactive') {
            $employee->status = 'inactive';
            $employee->deactivated_at = now();
            // Agent tokens stay valid so the PC can still upload what it recorded before
            // the deactivation (§10.1 step 4.3, Test 4.15); the `active` middleware already
            // refuses them everywhere except /agent/sync. Any other token is revoked.
            $employee->tokens()->where('name', 'not like', 'agent-%')->delete();
            AuditLog::record($caller, 'employee.deactivated', $employee);
        } elseif ($newStatus === 'active' && $employee->status !== 'active') {
            $employee->status = 'active';
            $employee->deactivated_at = null;
            AuditLog::record($caller, 'employee.reactivated', $employee);
        }

        $employee->save();
        $employee->load('role', 'manager');

        return response()->json([
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $employee->role?->name,
            'roleId' => (string) $employee->role_id,
            'status' => $employee->status,
            'managerId' => $employee->manager_id === null ? null : (string) $employee->manager_id,
            'managerName' => $employee->manager?->name,
        ]);
    }

    /**
     * Emails a fresh set-password link to someone in the caller's reach (the welcome link lasts 3 days).
     * When the mail cannot be sent the link comes back instead, as when the account was created.
     */
    public function resendInvite(Request $request, int $id): JsonResponse
    {
        $caller = $request->user();
        $employee = User::find($id);
        if ($employee === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        if ($refusal = $this->checkTarget($caller, $employee)) {
            return $refusal;
        }
        if ($employee->status !== 'active') {
            return $this->refuse(409, 'ACCOUNT_INACTIVE', "{$employee->name} is deactivated. Reactivate the account first.");
        }

        [$emailSent, $link] = $this->accounts->invite($employee, $caller);

        AuditLog::record($caller, 'employee.invite_resent', $employee, ['emailSent' => $emailSent]);

        return response()->json([
            'emailSent' => $emailSent,
            ...($emailSent ? [] : ['setPasswordUrl' => $link]),
        ]);
    }

    /**
     * Removes an account that was added by mistake or never used. Anyone who has tracked time or has
     * people reporting to them is refused: the organization keeps all tracked data, so they are deactivated
     * instead, which keeps their history.
     */
    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $caller = $request->user();
        $employee = User::find($id);
        if ($employee === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        if ($refusal = $this->checkTarget($caller, $employee)) {
            return $refusal;
        }
        if ($this->isLastAdmin($employee)) {
            return $this->refuse(400, 'LAST_ADMIN', 'The organization must always keep at least one active admin.');
        }
        if (User::where('manager_id', $id)->exists()) {
            return $this->refuse(409, 'HAS_REPORTS', "{$employee->name} has people reporting to them. Delete or move those accounts first.");
        }
        if (DB::table('sessions')->where('user_id', $id)->exists() || DB::table('daily_summaries')->where('user_id', $id)->exists()) {
            return $this->refuse(409, 'HAS_DATA', "{$employee->name} has tracked time, which the organization keeps. Deactivate the account instead.");
        }

        // Written first: the entry keeps the person's name after the row is gone.
        AuditLog::record($caller, 'employee.deleted', $employee, ['email' => $employee->email, 'role' => $employee->role?->name]);

        $employee->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $employee->email)->delete();
        DB::table('password_invite_tokens')->where('email', $employee->email)->delete();
        $employee->delete();

        return response()->noContent();
    }

    /** Not yourself, and inside the caller's reach. Returns the refusal, or null when the caller may act on $employee. */
    private function checkTarget(User $caller, User $employee): ?JsonResponse
    {
        if ($employee->id === $caller->id) {
            return $this->refuse(400, 'CANNOT_MODIFY_SELF', 'You cannot change your own account here.');
        }
        if (! $this->access->isVisible($caller, $employee->id)) {
            return $this->refuse(403, 'FORBIDDEN', 'This person is not in your reach.');
        }

        return null;
    }

    /**
     * Whether $employee may take the role $roleId from $caller. Returns the new role, or the refusal to send back.
     * Nobody gives more than they have, and nobody changes the role of someone who holds more than they do.
     */
    private function checkRoleChange(User $caller, User $employee, int $roleId): Role|JsonResponse
    {
        $newRole = Role::find($roleId);
        if ($newRole === null) {
            return $this->refuse(422, 'ROLE_NOT_FOUND', 'Choose one of the roles of this organization.');
        }
        if ($employee->role !== null && ! $this->access->canGrantRole($caller, $employee->role)) {
            return $this->refuse(403, 'ROLE_ESCALATION', "{$employee->name} holds a role that can do more than yours, so you cannot change it.");
        }
        if (! $this->access->canGrantRole($caller, $newRole)) {
            return $this->refuse(403, 'ROLE_ESCALATION', 'You cannot give a role that can do more than your own.');
        }
        if ($employee->status === 'active' && $this->isLastAdmin($employee) && ! $newRole->is_system) {
            return $this->refuse(400, 'LAST_ADMIN', 'The organization must always keep at least one active admin.');
        }

        return $newRole;
    }

    /**
     * The manager the caller picked for $employee (or for a new person when $employee is null): somebody in the
     * caller's reach, active, and not somebody who would end up above themselves. Returns the manager or the refusal.
     */
    private function pickManager(User $caller, mixed $managerId, ?User $employee = null): User|JsonResponse
    {
        $manager = User::find((int) $managerId);
        if ($manager === null || ! $this->access->isVisible($caller, $manager->id)) {
            return $this->refuse(403, 'FORBIDDEN', 'That manager is not in your reach.');
        }
        if ($manager->status !== 'active') {
            return $this->refuse(422, 'MANAGER_INACTIVE', "{$manager->name} is deactivated and cannot manage anyone.");
        }
        if ($employee !== null && $this->hierarchy->wouldCreateLoop($employee->id, $manager->id)) {
            return $this->refuse(422, 'WOULD_CREATE_LOOP', "{$manager->name} reports to {$employee->name} (directly or further down), so they cannot be their manager.");
        }

        return $manager;
    }

    /** Whether $employee is the organization's only active holder of the built-in admin role. */
    private function isLastAdmin(User $employee): bool
    {
        if (! $employee->role?->is_system) {
            return false;
        }

        return ! User::where('role_id', $employee->role_id)
            ->where('status', 'active')
            ->whereKeyNot($employee->id)
            ->exists();
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
