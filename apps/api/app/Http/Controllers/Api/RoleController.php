<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AccessService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

// GET/POST /api/v1/roles, PATCH/DELETE /api/v1/roles/{id} (docs/DEVELOPMENT_PLAN.md §9.1, §10). Each organization
// makes its own roles from the platform's fixed list of permissions. Nobody gives more than they have: the
// permissions of a role someone makes or edits must all be ones they hold, and its reach may not be wider than theirs.
class RoleController extends Controller
{
    public function __construct(private AccessService $access) {}

    /** The organization's roles. Whoever may add people or change roles needs the list too (to choose from it). */
    public function index(Request $request): JsonResponse
    {
        $caller = $request->user();

        return response()->json(
            Role::withCount('users')->orderBy('name')->get()
                ->map(fn (Role $role) => $this->payload($role, $this->access->canGrantRole($caller, $role)))
                ->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $caller = $request->user();
        $data['permissions'] = array_values($data['permissions']);

        if ($problem = Permissions::problemWith($data['scope'], $data['permissions'])) {
            return $this->refuse(422, 'INVALID_ROLE', $problem);
        }
        if (! $this->access->canGrant($caller, $data['permissions'], $data['scope'])) {
            return $this->refuse(403, 'ROLE_ESCALATION', 'You cannot make a role that can do more than your own.');
        }
        if ($this->nameTaken($data['name'])) {
            return $this->refuse(409, 'ROLE_NAME_TAKEN', 'This organization already has a role with that name.');
        }

        $role = new Role;
        $role->name = trim($data['name']);
        $role->description = $data['description'] ?? null;
        $role->scope = $data['scope'];
        $role->permissions = $data['permissions'];
        $role->is_system = false;
        $role->save();

        AuditLog::record($caller, 'role.created', null, ['role' => $role->name, 'scope' => $role->scope, 'permissions' => $role->permissions]);

        return response()->json($this->payload($role->loadCount('users'), true), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::withCount('users')->find($id);
        if ($role === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        $caller = $request->user();
        $data = $this->validated($request, partial: true);

        if ($caller->role_id === $role->id) {
            return $this->refuse(403, 'CANNOT_EDIT_OWN_ROLE', 'You cannot edit the role you hold yourself.');
        }
        if (! $this->access->canGrantRole($caller, $role)) {
            return $this->refuse(403, 'ROLE_ESCALATION', 'This role can do more than yours, so you cannot change it.');
        }

        $scope = $data['scope'] ?? $role->scope;
        $permissions = array_values($data['permissions'] ?? $role->permissions);
        if ($role->is_system && ($scope !== $role->scope || array_diff($permissions, $role->permissions) !== [] || array_diff($role->permissions, $permissions) !== [])) {
            return $this->refuse(409, 'ROLE_LOCKED', 'The admin role of an organization keeps all permissions. Only its name and description can change.');
        }
        if ($problem = Permissions::problemWith($scope, $permissions)) {
            return $this->refuse(422, 'INVALID_ROLE', $problem);
        }
        if (! $this->access->canGrant($caller, $permissions, $scope)) {
            return $this->refuse(403, 'ROLE_ESCALATION', 'You cannot give a role more than your own permissions.');
        }
        if (isset($data['name']) && $this->nameTaken($data['name'], $role->id)) {
            return $this->refuse(409, 'ROLE_NAME_TAKEN', 'This organization already has a role with that name.');
        }

        $before = ['name' => $role->name, 'scope' => $role->scope, 'permissions' => $role->permissions];
        if (isset($data['name'])) {
            $role->name = trim($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $role->description = $data['description'];
        }
        $role->scope = $scope;
        $role->permissions = $permissions;
        $role->save();

        AuditLog::record($caller, 'role.updated', null, [
            'role' => $role->name,
            ...($before['name'] !== $role->name ? ['renamedFrom' => $before['name']] : []),
            ...($before['scope'] !== $role->scope ? ['scopeFrom' => $before['scope'], 'scopeTo' => $role->scope] : []),
            'permissionsAdded' => array_values(array_diff($role->permissions, $before['permissions'])),
            'permissionsRemoved' => array_values(array_diff($before['permissions'], $role->permissions)),
        ]);

        return response()->json($this->payload($role, true));
    }

    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $role = Role::withCount('users')->find($id);
        if ($role === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        $caller = $request->user();

        if ($role->is_system) {
            return $this->refuse(409, 'ROLE_LOCKED', 'The admin role of an organization cannot be deleted.');
        }
        if ($caller->role_id === $role->id) {
            return $this->refuse(403, 'CANNOT_EDIT_OWN_ROLE', 'You cannot delete the role you hold yourself.');
        }
        if (! $this->access->canGrantRole($caller, $role)) {
            return $this->refuse(403, 'ROLE_ESCALATION', 'This role can do more than yours, so you cannot delete it.');
        }
        if ($role->users_count > 0) {
            return $this->refuse(409, 'ROLE_IN_USE', "{$role->users_count} people hold this role. Give them another role first.");
        }

        AuditLog::record($caller, 'role.deleted', null, ['role' => $role->name]);
        $role->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scope' => [$sometimes, 'string', Rule::in(Permissions::SCOPES)],
            'permissions' => [$partial ? 'sometimes' : 'present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::organizationKeys())],
        ]);
    }

    private function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $query = Role::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    /** @return array<string, mixed> */
    private function payload(Role $role, bool $assignable): array
    {
        return [
            'id' => (string) $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'scope' => $role->scope,
            'permissions' => $role->permissions,
            'isSystem' => $role->is_system,
            'memberCount' => (int) ($role->users_count ?? 0),
            // whether the caller may give this role to somebody (nobody gives more than they have)
            'assignable' => $assignable,
        ];
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
