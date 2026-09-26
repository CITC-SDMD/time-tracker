<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes an account and sends its set-password link. Used for people an organization adds, for the admins a
 * superadmin adds to an organization, and for new superadmins, so all of them are made and invited the same way.
 */
class AccountService
{
    /** Whether an account with this email exists anywhere: emails are unique across every organization. */
    public function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        $query = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
        if ($exceptUserId !== null) {
            $query->whereKeyNot($exceptUserId);
        }

        return $query->exists();
    }

    /**
     * An organization person: they hold $role, in the organization that role belongs to, and report to $manager.
     *
     * @return array{0: User, 1: bool, 2: ?string} the account, whether the email went out, and the link to hand over when it did not
     */
    public function createPerson(User $actor, string $name, string $email, Role $role, ?User $manager): array
    {
        $account = $this->newAccount($actor, $name, $email);
        $account->organization_id = $role->organization_id;
        $account->role_id = $role->id;
        $account->manager_id = $manager?->id;
        $account->save();

        return [$account, ...$this->invite($account, $actor)];
    }

    /**
     * A platform superadmin with their own permissions.
     *
     * @param  list<string>  $permissions
     * @return array{0: User, 1: bool, 2: ?string}
     */
    public function createSuperadmin(User $actor, string $name, string $email, array $permissions): array
    {
        $account = $this->newAccount($actor, $name, $email);
        $account->is_superadmin = true;
        $account->superadmin_permissions = array_values($permissions);
        $account->save();

        return [$account, ...$this->invite($account, $actor)];
    }

    /**
     * Emails a fresh set-password link. A failing mail server never undoes the account: it is logged, and the
     * link comes back so the person who added the account can hand it over themselves.
     *
     * @return array{0: bool, 1: ?string}
     */
    public function invite(User $account, User $actor): array
    {
        $token = Password::broker('invites')->createToken($account);
        try {
            $account->notify(new WelcomeNotification($token, $actor->name));

            return [true, null];
        } catch (Throwable $e) {
            report($e);

            return [false, $account->passwordSetUrl($token)];
        }
    }

    private function newAccount(User $actor, string $name, string $email): User
    {
        // Nobody knows this password: the new person picks their own through the emailed set-password link (§9.2).
        // Explicit property assignment, not User::create([...]): role, manager and status are not mass-assignable.
        $account = new User;
        $account->name = $name;
        $account->email = trim($email);
        $account->password = Hash::make(Str::password(32));
        $account->status = 'active';
        $account->created_by = $actor->id;

        return $account;
    }
}
