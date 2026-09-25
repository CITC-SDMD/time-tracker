<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

// POST /api/v1/agent/sync (docs/DEVELOPMENT_PLAN.md §10.1). This validates only the
// envelope; problems with an individual session are not request failures — the
// controller reports them per session in `rejected` so one bad row never blocks a batch.
// validated() returns only the fields declared here, so `uid`, `userId`, `role` and
// friends in the body are ignored (§9.3 item 6, Test 4.13).
class AgentSyncRequest extends FormRequest
{
    public const MAX_SESSIONS = 100;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['deviceId' => $this->header('X-Device-Id')]);
    }

    public function rules(): array
    {
        return [
            'deviceId' => ['required', 'uuid'],
            'clientTime' => ['required', 'date'],
            'computerName' => ['nullable', 'string', 'max:128'],
            'dbReset' => ['sometimes', 'boolean'],
            'status' => ['required', 'array'],
            'status.state' => ['required', 'in:active,idle,paused,away,not_tracking'],
            'status.currentApp' => ['nullable', 'string', 'max:128'],
            'status.idleAppName' => ['nullable', 'string', 'max:128'],
            'status.since' => ['nullable', 'date'],
            'status.trackingStartedAt' => ['nullable', 'date'],
            'sessions' => ['present', 'array', 'max:'.self::MAX_SESSIONS],
            'sessions.*' => ['array'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $sessions = $this->input('sessions');
        if (is_array($sessions) && count($sessions) > self::MAX_SESSIONS) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'TOO_MANY_SESSIONS',
                    'message' => 'At most '.self::MAX_SESSIONS.' sessions per request.',
                ],
            ], 400));
        }

        parent::failedValidation($validator);
    }
}
