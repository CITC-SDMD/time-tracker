<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Sent when a manager adds someone (docs/DEVELOPMENT_PLAN.md §9.2). It carries a link to pick
// a password — never a password — and works for everyone, including individual contributors
// who only ever use the desktop app.
class WelcomeNotification extends Notification
{
    public function __construct(private string $token, private string $addedBy) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $app = config('app.name');
        $days = (int) (config('auth.passwords.invites.expire') / (60 * 24));

        return (new MailMessage)
            ->subject("Welcome to {$app}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->addedBy} added you to {$app}. Choose your password to get started.")
            ->action('Set your password', $notifiable->passwordSetUrl($this->token))
            ->line("This link works for {$days} days. After that, ask {$this->addedBy} to send a new one.")
            ->line('Once your password is set, sign in to the desktop app with this email address.')
            ->line('If you were not expecting this email, you can ignore it.');
    }
}
