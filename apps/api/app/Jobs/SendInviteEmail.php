<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

// Sends one set-password email in the background (used by the people import, so a request never waits on the mail
// server). A send that works clears the "not delivered" mark; when every try has failed the person is marked and the
// admin is told in the audit log, and the people list offers "Resend link".
class SendInviteEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public int $userId, public string $token, public string $addedBy, public ?int $actorId = null)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);
        if ($user === null || $user->status !== 'active') {
            return;
        }

        $user->notify(new WelcomeNotification($this->token, $this->addedBy));

        if ($user->invite_failed_at !== null) {
            $user->forceFill(['invite_failed_at' => null])->save();
        }
    }

    public function failed(Throwable $e): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);
        if ($user === null) {
            return;
        }

        $user->forceFill(['invite_failed_at' => now()])->save();
        $actor = $this->actorId === null ? null : User::withoutGlobalScopes()->find($this->actorId);
        if ($actor !== null) {
            $entry = AuditLog::record($actor, 'employee.invite_failed', $user);
            // no request is running here, so the entry belongs to the person's organization, not to the actor's
            if ($entry->organization_id !== $user->organization_id) {
                $entry->organization_id = $user->organization_id;
                $entry->save();
            }
        }
    }
}
