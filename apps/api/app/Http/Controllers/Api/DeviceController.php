<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

// The PCs a person is signed in on (docs/SECURITY_REVIEW.md). Each PC holds one desktop token, named `agent-<device id>`
// by AuthController@login. A person can sign their own PCs out (a lost laptop), and so can someone who manages them
// (`people.update` within their reach). Signing out deletes the token: the app's next call gets 401 and asks to sign
// in again; the data waiting on the PC stays there and is sent after the next sign-in.
class DeviceController extends Controller
{
    public function __construct(private AccessService $access) {}

    /** GET /api/v1/me/devices */
    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->listFor($request->user()));
    }

    /** DELETE /api/v1/me/devices/{deviceId} */
    public function signOutMine(Request $request, string $deviceId): JsonResponse
    {
        return $this->signOut($request->user(), $request->user(), $deviceId);
    }

    /** GET /api/v1/admin/employees/{id}/devices */
    public function ofPerson(Request $request, int $id): JsonResponse
    {
        $person = User::find($id);
        if ($person === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        if ($refusal = $this->checkReach($request->user(), $person)) {
            return $refusal;
        }

        return response()->json($this->listFor($person));
    }

    /** DELETE /api/v1/admin/employees/{id}/devices/{deviceId} */
    public function signOutPerson(Request $request, int $id, string $deviceId): JsonResponse
    {
        $person = User::find($id);
        if ($person === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        if ($refusal = $this->checkReach($request->user(), $person)) {
            return $refusal;
        }

        return $this->signOut($request->user(), $person, $deviceId);
    }

    private function checkReach(User $caller, User $person): ?JsonResponse
    {
        if ($person->id !== $caller->id && ! $this->access->isVisible($caller, $person->id)) {
            return $this->refuse(403, 'FORBIDDEN', 'This person is not in your reach.');
        }

        return null;
    }

    /** @return list<array{deviceId: string, computerName: ?string, agentVersion: ?string, lastUsedAt: ?string, signedInAt: string, expiresAt: ?string}> */
    private function listFor(User $person): array
    {
        $tokens = $person->tokens()->where('name', 'like', 'agent-%')->orderByDesc('last_used_at')->get();
        $devices = Device::where('user_id', $person->id)->get()->keyBy('id');

        return $tokens->map(function (PersonalAccessToken $token) use ($devices) {
            $deviceId = substr($token->name, strlen('agent-'));
            $device = $devices->get($deviceId);

            return [
                'deviceId' => $deviceId,
                'computerName' => $device?->computer_name,
                'agentVersion' => $device?->agent_version,
                'lastUsedAt' => $token->last_used_at?->utc()->toIso8601ZuluString(),
                'signedInAt' => $token->created_at->utc()->toIso8601ZuluString(),
                'expiresAt' => $token->expires_at?->utc()->toIso8601ZuluString(),
            ];
        })->values()->all();
    }

    private function signOut(User $caller, User $person, string $deviceId): JsonResponse
    {
        $deleted = $person->tokens()->where('name', 'agent-'.mb_substr($deviceId, 0, 64))->delete();
        if ($deleted === 0) {
            return $this->refuse(404, 'NOT_FOUND', 'That PC is not signed in.');
        }

        $computer = Device::where('user_id', $person->id)->whereKey($deviceId)->value('computer_name');
        AuditLog::record($caller, 'device.signed_out', $person, ['deviceId' => $deviceId, 'computerName' => $computer]);

        return response()->json(['signedOut' => true]);
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
