<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/**
 * How a notification reaches its recipient.
 *
 * Mirrors the frontend's `NOTIFICATION_CHANNELS`. Two, because those are the
 * two the business documents describe — REPAYMENT OVERVIEW §1 Step 5 calls
 * `POST /notifications/sms`, and email is the other channel the templates
 * screen offers.
 */
enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'Email',
        };
    }

    /**
     * Whether a message on this channel carries a subject line.
     *
     * SMS does not, which is why `subject` is nullable and why a template
     * supplying one for SMS is rejected rather than silently ignored.
     */
    public function hasSubject(): bool
    {
        return $this === self::Email;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
