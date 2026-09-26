<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccessService;
use App\Services\AccountService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// /api/v1/platform/superadmins (docs/DEVELOPMENT_PLAN.md §9.4): the owner and superadmins with `platform.staff.manage`
// add more superadmins, each with their own list of platform permissions. Nobody gives a permission they do not hold
// themselves, nobody changes their own permissions, and the owner can never be changed.
class SuperadminController extends Controller
{
    public function __construct(
        private AccessService $access,
        private AccountService $accounts,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            User::withoutGlobalScopes()->where('is_superadmin', true)->orderByDesc('is_owner')->orderBy('name')->get()
                ->map(fn (User $user) => $this->payload($user))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::superadminKeys())],
        ]);
        $caller = $request->user();

        if ($this->accounts->emailTaken($data['email'])) {
            return response()->json(['error' => ['code' => 'EMAIL_TAKEN', 'message' => 'A user with this email already exists.']], 409);
        }
        if ($refusal = $this->checkGrant($caller, $data['permissions'])) {
            return $refusal;
        }

        [$superadmin, $emailSent, $link] = $this->accounts->createSuperadmin($caller, $data['name'], $data['email'], $data['permissions']);
        AuditLog::recordPlatform($caller, 'superadmin.created', $superadmin, ['permissions' => $superadmin->superadmin_permissions]);

        return response()->json([...$this->payload($superadmin), 'emailSent' => $emailSent, ...($emailSent ? [] : ['setPasswordUrl' => $link])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::superadminKeys())],
        ]);
        $caller = $request->user();
        $target = User::withoutGlobalScopes()->where('is_superadmin', true)->find($id);

        if ($target === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
        }
        if ($target->is_owner) {
            return response()->json(['error' => ['code' => 'CANNOT_CHANGE_OWNER', 'message' => 'The owner account cannot be changed here.']], 403);
        }
        if ($target->id === $caller->id) {
            return response()->json(['error' => ['code' => 'CANNOT_MODIFY_SELF', 'message' => 'You cannot change your own account here.']], 400);
        }

        // someone with more permissions than the caller cannot be edited by them either
        if ($refusal = $this->checkGrant($caller, [...($target->superadmin_permissions ?? []), ...($data['permissions'] ?? [])])) {
            return $refusal;
        }

        $changes = [];
        if (isset($data['name']) && trim($data['name']) !== $target->name) {
            $target->name = trim($data['name']);
            $changes['nameTo'] = $target->name;
        }
        if (isset($data['email']) && mb_strtolower(trim($data['email'])) !== mb_strtolower($target->email)) {
            if ($this->accounts->emailTaken($data['email'])) {
                return response()->json(['error' => ['code' => 'EMAIL_TAKEN', 'message' => 'A user with this email already exists.']], 409);
            }
            $target->email = trim($data['email']);
            $changes['emailTo'] = $target->email;
            // the old address must not keep working, so any open session or link ends here
            $target->tokens()->delete();
        }
        if ($changes !== []) {
            AuditLog::recordPlatform($caller, 'superadmin.updated', $target, $changes);
        }
        if (isset($data['permissions'])) {
            $before = $target->superadmin_permissions ?? [];
            $target->superadmin_permissions = array_values($data['permissions']);
            AuditLog::recordPlatform($caller, 'superadmin.permissions_changed', $target, [
                'added' => array_values(array_diff($target->superadmin_permissions, $before)),
                'removed' => array_values(array_diff($before, $target->superadmin_permissions)),
            ]);
        }
        if (isset($data['status']) && $data['status'] !== $target->status) {
            $target->status = $data['status'];
            $target->deactivated_at = $data['status'] === 'inactive' ? now() : null;
            if ($data['status'] === 'inactive') {
                $target->tokens()->delete();
            }
            AuditLog::recordPlatform($caller, $data['status'] === 'inactive' ? 'employee.deactivated' : 'employee.reactivated', $target);
        }
        $target->save();

        return response()->json($this->payload($target));
    }

    /** @param list<string> $permissions */
    private function checkGrant(User $caller, array $permissions): ?JsonResponse
    {
        foreach ($permissions as $permission) {
            if (! $this->access->superadminCan($caller, $permission)) {
                return response()->json(['error' => ['code' => 'ROLE_ESCALATION', 'message' => 'You cannot give a permission you do not have yourself.']], 403);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'isOwner' => (bool) $user->is_owner,
            'permissions' => $user->is_owner ? Permissions::superadminKeys() : ($user->superadmin_permissions ?? []),
            'createdAt' => $user->created_at?->toIso8601String(),
        ];
    }
}
