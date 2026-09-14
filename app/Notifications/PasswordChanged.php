<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * "Your password was just changed."
 *
 * This is the detection half of the control. The password change itself is
 * already authenticated, so this mail is not asking permission — it is telling
 * the account owner that it happened, to an address an attacker who only has a
 * session token does not control. If it arrives and they did not do it, the
 * reset flow is their remedy and the mail says so.
 *
 * Queued on `high` like the other mail a human is waiting on. Nothing about the
 * password itself is in the body, by design.
 */
class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public const string ON_QUEUE = 'high';

    public function __construct(private readonly Carbon $changedAt)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            // The same dedicated mailer the reset mail uses. It is pinned rather
            // than inherited because the default MAIL_MAILER is `log` in several
            // environments, which would silently swallow a security alert.
            ->mailer((string) config('mail.password_reset_mailer'))
            ->subject('Your ' . config('app.name') . ' password was changed')
            ->line('The password for your admin account was changed on '
                . $this->changedAt->toDayDateTimeString() . ' (UTC).')
            ->line('Every other signed-in session was ended as part of the change.')
            ->line('If this was you, nothing further is needed.')
            ->action('I did not do this — reset my password', rtrim((string) config('app.frontend_url'), '/') . '/forgot-password')
            ->line('Resetting the password immediately ends any session an attacker holds.');
    }
}
