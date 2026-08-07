<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your password was changed."
 *
 * The point is not to tell the user something they just did — they know. It is
 * to tell them when they did *not* do it. A password change they cannot
 * account for is the first and often only signal that an account has been
 * taken over, and it is only useful if it arrives out of band.
 *
 * Mail only, and only where an address is on file. Every account here signs in
 * by phone and an email is optional, so this is best-effort by design; the
 * audit entry is the record that always exists.
 */
final class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $ipAddress,
        private readonly string $changedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MikopoFasta password was changed')
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line('The password on your MikopoFasta account was changed on '.$this->changedAt.'.')
            ->line('It was changed from IP address '.$this->ipAddress.'.')
            ->line('All other sessions on this account have been signed out.')
            ->line('If this was you, no action is needed.')
            ->line('If it was not, contact your administrator immediately — somebody else may have access to your account.');
    }
}
