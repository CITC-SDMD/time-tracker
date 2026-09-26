<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Sent to the OLD address when someone changes the email of their own account, so a hijacked session
// that changes it is noticed. It says where the login moved to and who to tell; it carries no link.
class EmailChangedNotification extends Notification
{
    public function __construct(private string $name, private string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject("Your {$app} email address was changed")
            ->greeting("Hello {$this->name},")
            ->line("The email address of your {$app} account was changed to {$this->newEmail}.")
            ->line('From now on, sign in with the new address. This address no longer works for signing in.')
            ->line('If you did not do this, tell the person in charge of your office straight away.');
    }
}
