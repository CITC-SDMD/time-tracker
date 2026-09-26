<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

// The way back in for someone locked out by two-factor sign-in when nobody in the dashboard can reset them: the owner
// who lost their phone and recovery codes. Needs access to the server, which is the point.
class ResetTwoFactor extends Command
{
    protected $signature = 'tracker:reset-two-factor {email : the account\'s email address}';

    protected $description = 'Turn off two-factor sign-in for an account (lost phone and recovery codes)';

    public function handle(TwoFactorService $twoFactor): int
    {
        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $this->argument('email')))])->first();
        if ($user === null) {
            $this->error('There is no account with that email address.');

            return self::FAILURE;
        }

        $twoFactor->disable($user);
        $user->tokens()->where('name', 'not like', 'agent-%')->delete();
        $this->info("Two-factor sign-in is off for {$user->email}. They can sign in with the password and turn it on again from their profile.");

        return self::SUCCESS;
    }
}
