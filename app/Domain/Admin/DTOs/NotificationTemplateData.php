<?php

declare(strict_types=1);

namespace App\Domain\Admin\DTOs;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;

/** Mirrors the frontend's `SaveNotificationTemplateInputSchema`. */
final readonly class NotificationTemplateData
{
    public function __construct(
        public string $name,
        public NotificationTriggerEvent $triggerEvent,
        public NotificationChannel $channel,
        public ?string $subject,
        public string $body,
        public bool $active,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $subject = trim((string) ($validated['subject'] ?? ''));

        return new self(
            name: trim((string) $validated['name']),
            triggerEvent: NotificationTriggerEvent::from((string) $validated['triggerEvent']),
            channel: NotificationChannel::from((string) $validated['channel']),
            subject: $subject === '' ? null : $subject,
            body: trim((string) $validated['body']),
            active: (bool) ($validated['active'] ?? true),
        );
    }
}
