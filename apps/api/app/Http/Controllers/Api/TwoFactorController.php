<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

// Two-factor sign-in, for the dashboard (docs/SECURITY_REVIEW.md). Turning it on and off is the person's own choice and
// needs the password (and for turning it off, a code too). Someone who lost their phone is reset by a manager
// (`people.update` within their reach), by a superadmin, or on the server with `php artisan tracker:reset-two-factor`.
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor, private AccessService $access) {}

    /** GET /api/v1/me/two-factor */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $this->twoFactor->enabled($user),
            'recoveryCodesLeft' => $this->twoFactor->recoveryCodesLeft($user),
        ]);
    }

    /** POST /api/v1/me/two-factor  { currentPassword } gives { secret, uri }: a secret to put in the app, not on yet */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($refusal = $this->checkPassword($request, $user)) {
            return $refusal;
        }
        if ($this->twoFactor->enabled($user)) {
            return $this->refuse(409, 'TWO_FACTOR_ON', 'Two-factor sign-in is already on. Turn it off first to set it up again.');
        }

        return response()->json($this->twoFactor->start($user));
    }

    /** POST /api/v1/me/two-factor/confirm  { code } gives { recoveryCodes } */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();

        $codes = $this->guarded($user, fn () => $this->twoFactor->confirm($user, $data['code']));
        if ($codes === null) {
            return $this->refuse(422, 'WRONG_CODE', 'That code is not right. Check the code in your app and try again.');
        }
        AuditLog::record($user, 'two_factor.enabled', $user);

        return response()->json(['recoveryCodes' => $codes]);
    }

    /** POST /api/v1/me/two-factor/recovery-codes  { currentPassword, code } gives { recoveryCodes } (the old ones stop working) */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($refusal = $this->checkPasswordAndCode($request, $user)) {
            return $refusal;
        }

        return response()->json(['recoveryCodes' => $this->twoFactor->newRecoveryCodes($user)]);
    }

    /** DELETE /api/v1/me/two-factor  { currentPassword, code } */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($refusal = $this->checkPasswordAndCode($request, $user)) {
            return $refusal;
        }
        $this->twoFactor->disable($user);
        AuditLog::record($user, 'two_factor.disabled', $user);

        return response()->json(['enabled' => false]);
    }

    /** DELETE /api/v1/admin/employees/{id}/two-factor: for someone who lost their phone */
    public function reset(Request $request, int $id): JsonResponse
    {
        $caller = $request->user();
        $person = User::find($id);
        if ($person === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        if ($person->id === $caller->id) {
            return $this->refuse(400, 'CANNOT_MODIFY_SELF', 'Turn off your own two-factor sign-in from your profile.');
        }
        if (! $this->access->isVisible($caller, $person->id)) {
            return $this->refuse(403, 'FORBIDDEN', 'This person is not in your reach.');
        }

        self::resetFor($caller, $person, $this->twoFactor);

        return response()->json(['enabled' => false]);
    }

    /** Clears it for $person and signs them out of the dashboard; shared with the superadmin routes. */
    public static function resetFor(User $actor, User $person, TwoFactorService $twoFactor): void
    {
        $twoFactor->disable($person);
        $person->tokens()->where('name', 'not like', 'agent-%')->delete();
        AuditLog::record($actor, 'two_factor.reset', $person);
    }

    private function checkPassword(Request $request, User $user): ?JsonResponse
    {
        $request->validate(['currentPassword' => ['required', 'string']]);
        if (! Hash::check($request->string('currentPassword')->toString(), $user->password)) {
            return $this->refuse(422, 'WRONG_PASSWORD', 'Your password is not right.');
        }

        return null;
    }

    private function checkPasswordAndCode(Request $request, User $user): ?JsonResponse
    {
        $request->validate(['currentPassword' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        if ($refusal = $this->checkPassword($request, $user)) {
            return $refusal;
        }
        if (! $this->twoFactor->enabled($user)) {
            return $this->refuse(409, 'TWO_FACTOR_OFF', 'Two-factor sign-in is not on.');
        }
        if (! $this->guarded($user, fn () => $this->twoFactor->verify($user, $request->string('code')->toString()))) {
            return $this->refuse(422, 'WRONG_CODE', 'That code is not right.');
        }

        return null;
    }

    /** Runs a code check; five wrong codes in 15 minutes shut the checks for that person for the rest of the window. */
    private function guarded(User $user, \Closure $check): mixed
    {
        $key = self::attemptKey($user);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many wrong codes. Try again in a few minutes.');
        }
        $result = $check();
        if (! $result) {
            RateLimiter::hit($key, 900);
        }

        return $result;
    }

    /** the same counter guards the sign-in step (DashboardAuthController) and everything here */
    public static function attemptKey(User $user): string
    {
        return 'two-factor-user:'.$user->id;
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
