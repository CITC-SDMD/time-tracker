<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// The optional second sign-in step (docs/SECURITY_REVIEW.md): a 6-digit code from an authenticator app (TOTP, RFC 6238),
// or one of eight single-use recovery codes. The secret is encrypted in the database; recovery codes are kept only as
// hashes and shown once. A code that was accepted cannot be used again, and a wrong code counts against the person
// (see TwoFactorController::guarded and DashboardAuthController) so the six digits cannot be guessed.
class TwoFactorService
{
    private const RECOVERY_CODES = 8;

    public function __construct(private Google2FA $google2fa) {}

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return $this->enabled($user) ? count($user->two_factor_recovery_codes ?? []) : 0;
    }

    /**
     * Makes a new secret that is not on yet.
     *
     * @return array{secret: string, uri: string}
     */
    public function start(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();

        return ['secret' => $secret, 'uri' => $this->google2fa->getQRCodeUrl(config('app.name', 'Time Tracker'), $user->email, $secret)];
    }

    /**
     * Turns it on when the first code is right. Returns the recovery codes (shown once), or null for a wrong code.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null || ! $this->acceptCode($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->newRecoveryCodes($user);
    }

    /** The code at sign-in: an app code or a recovery code (each recovery code works once). */
    public function verify(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }

        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->acceptCode($user, $code);
        }

        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search($this->hashRecovery($code), $codes, true);
        if ($index === false) {
            return false;
        }
        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /**
     * Eight new recovery codes; the old ones stop working.
     *
     * @return list<string>
     */
    public function newRecoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $plain[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $user->forceFill(['two_factor_recovery_codes' => array_map($this->hashRecovery(...), $plain)])->save();

        return $plain;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null, 'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null, 'two_factor_last_step' => null,
        ])->save();
    }

    /** Right code and not used before: remembers its time step so the same code fails a second time. */
    private function acceptCode(User $user, string $code): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_step ?? 0, 1); // 0, not null: with null the library answers true, not the step
        if ($step === false) {
            return false;
        }
        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    private function hashRecovery(string $code): string
    {
        // ten random characters are far too many to guess, so a plain keyed hash is enough (a slow one would cost 8 x 0.3 s per set)
        return hash_hmac('sha256', strtolower(trim($code)), (string) config('app.key'));
    }
}
