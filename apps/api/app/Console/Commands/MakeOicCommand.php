<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// The one-time bootstrap step (docs/DEVELOPMENT_PLAN.md §9.2): creates the first
// account directly as OIC, since there's no dashboard yet to create it from. Every
// other account is created afterward through the normal manager-creates-a-direct-report
// flow, descending from this one OIC.
class MakeOicCommand extends Command
{
    protected $signature = 'tracker:make-oic {name} {email}';

    protected $description = 'Create the first OIC account (one-time setup step)';

    public function handle(): int
    {
        $name = $this->argument('name');
        $email = $this->argument('email');

        try {
            validator(['email' => $email], ['email' => 'required|email'])->validate();
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        if (User::where('role', 'OIC')->where('status', 'ACTIVE')->exists()) {
            $this->error('An active OIC already exists. Use the dashboard to manage accounts from here.');

            return self::FAILURE;
        }

        $temporaryPassword = Str::password(16);

        $oic = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($temporaryPassword),
            'role' => 'OIC',
            'manager_id' => null,
            'status' => 'ACTIVE',
        ]);

        $this->info("OIC account created: {$oic->email}");
        $this->warn("Temporary password: {$temporaryPassword}");
        $this->line('Log in with this once, then change the password — this is not emailed anywhere.');

        return self::SUCCESS;
    }
}
