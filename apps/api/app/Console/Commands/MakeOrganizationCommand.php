<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use App\Services\OrganizationService;
use App\Support\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// The terminal way to do what a superadmin does on the dashboard (docs/DEVELOPMENT_PLAN.md §9.4): make an organization and
// its first admin. The admin gets a temporary password to print here (no mail is sent) and makes everything else
// themselves.
class MakeOrganizationCommand extends Command
{
    protected $signature = 'tracker:make-organization {name} {adminName} {adminEmail} {--timezone=Asia/Manila}';

    protected $description = 'Create an organization and its first admin';

    public function handle(OrganizationService $organizations, AccountService $accounts, OrganizationContext $context): int
    {
        $email = (string) $this->argument('adminEmail');

        if (validator(['email' => $email, 'timezone' => $this->option('timezone')], ['email' => 'required|email', 'timezone' => 'timezone'])->fails()) {
            $this->error('The admin email or the timezone is not valid.');

            return self::FAILURE;
        }
        if ($accounts->emailTaken($email)) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        $organization = $organizations->create((string) $this->argument('name'), (string) $this->option('timezone'));
        $temporaryPassword = Str::password(16);

        $context->within($organization->id, function () use ($organization, $email, $temporaryPassword) {
            $admin = new User;
            $admin->name = (string) $this->argument('adminName');
            $admin->email = $email;
            $admin->password = Hash::make($temporaryPassword);
            $admin->organization_id = $organization->id;
            $admin->role_id = Role::where('is_system', true)->firstOrFail()->id;
            $admin->status = 'active';
            $admin->save();
        });

        $this->info("Organization created: {$organization->name}");
        $this->info("Admin account: {$email}");
        $this->warn("Temporary password: {$temporaryPassword}");
        $this->line('Log in with this once, then change the password. It is not emailed anywhere.');

        return self::SUCCESS;
    }
}
