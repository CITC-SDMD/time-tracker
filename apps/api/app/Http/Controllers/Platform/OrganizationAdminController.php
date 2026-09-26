<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// /api/v1/platform/organizations/{organization}/admins (docs/DEVELOPMENT_PLAN.md §9.4): a superadmin with
// `organizations.admins.manage` adds the people who hold the built-in admin role of an organization, and can
// deactivate, reactivate and invite them again. These admins then make everything else themselves. The middleware has
// already put the request inside the organization, so the queries below only see its people.
class OrganizationAdminController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(): JsonResponse
    {
        $adminRole = $this->adminRole();

        return response()->json(
            User::where('role_id', $adminRole->id)->orderBy('name')->get()->map(fn (User $admin) => $this->payload($admin))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        if ($this->accounts->emailTaken($data['email'])) {
            return response()->json(['error' => ['code' => 'EMAIL_TAKEN', 'message' => 'A user with this email already exists.']], 409);
        }

        [$admin, $emailSent, $link] = $this->accounts->createPerson($request->user(), $data['name'], $data['email'], $this->adminRole(), null);
        AuditLog::record($request->user(), 'employee.created', $admin, ['role' => $this->adminRole()->name]);

        return response()->json([...$this->payload($admin), 'emailSent' => $emailSent, ...($emailSent ? [] : ['setPasswordUrl' => $link])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', Rule::in(['active', 'inactive'])]]);
        $admin = $this->find($id);
        if ($admin === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
        }

        if ($data['status'] === 'inactive' && $admin->status === 'active') {
            $othersActive = User::where('role_id', $admin->role_id)->where('status', 'active')->whereKeyNot($admin->id)->exists();
            if (! $othersActive && ! $admin->organization?->isSuspended()) {
                return response()->json(['error' => ['code' => 'LAST_ADMIN', 'message' => 'The organization must always keep at least one active admin. Add another admin first.']], 400);
            }
            $admin->status = 'inactive';
            $admin->deactivated_at = now();
            $admin->tokens()->where('name', 'not like', 'agent-%')->delete();
            AuditLog::record($request->user(), 'employee.deactivated', $admin);
        } elseif ($data['status'] === 'active' && $admin->status !== 'active') {
            $admin->status = 'active';
            $admin->deactivated_at = null;
            AuditLog::record($request->user(), 'employee.reactivated', $admin);
        }
        $admin->save();

        return response()->json($this->payload($admin));
    }

    public function resendInvite(Request $request, int $id): JsonResponse
    {
        $admin = $this->find($id);
        if ($admin === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
        }
        if ($admin->status !== 'active') {
            return response()->json(['error' => ['code' => 'ACCOUNT_INACTIVE', 'message' => "{$admin->name} is deactivated. Reactivate the account first."]], 409);
        }

        [$emailSent, $link] = $this->accounts->invite($admin, $request->user());
        AuditLog::record($request->user(), 'employee.invite_resent', $admin, ['emailSent' => $emailSent]);

        return response()->json(['emailSent' => $emailSent, ...($emailSent ? [] : ['setPasswordUrl' => $link])]);
    }

    private function adminRole(): Role
    {
        return Role::where('is_system', true)->firstOrFail();
    }

    private function find(int $id): ?User
    {
        return User::where('role_id', $this->adminRole()->id)->whereKey($id)->first();
    }

    /** @return array<string, mixed> */
    private function payload(User $admin): array
    {
        return [
            'id' => (string) $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => $admin->status,
            'createdAt' => $admin->created_at?->toIso8601String(),
        ];
    }
}
