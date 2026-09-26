<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountService;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// The one-time bootstrap step (docs/DEVELOPMENT_PLAN.md §9.4): creates the platform owner, the main superadmin with
// every platform permission. The owner then adds organizations and more superadmins from the dashboard.
class MakeSuperadminCommand extends Command
{
    protected $signature = 'tracker:make-superadmin {name} {email}';

    protected $description = 'Create the platform owner (the main superadmin), a one-time setup step';

    public function handle(AccountService $accounts): int
    {
        $name = $this->argument('name');
        $email = $this->argument('email');

        try {
            validator(['email' => $email], ['email' => 'required|email'])->validate();
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($accounts->emailTaken($email)) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        if (User::withoutGlobalScopes()->where('is_owner', true)->where('status', 'active')->exists()) {
            $this->error('An active platform owner already exists. Add superadmins from the dashboard.');

            return self::FAILURE;
        }

        $temporaryPassword = Str::password(16);

        // Explicit property assignment, not User::create([...]): these fields are not mass-assignable (see User.php).
        $owner = new User;
        $owner->name = $name;
        $owner->email = $email;
        $owner->password = Hash::make($temporaryPassword);
        $owner->is_superadmin = true;
        $owner->is_owner = true;
        $owner->superadmin_permissions = Permissions::superadminKeys();
        $owner->status = 'active';
        $owner->save();

        $this->info("Platform owner created: {$owner->email}");
        $this->warn("Temporary password: {$temporaryPassword}");
        $this->line('Log in with this once, then change the password. It is not emailed anywhere.');

        return self::SUCCESS;
    }
}
