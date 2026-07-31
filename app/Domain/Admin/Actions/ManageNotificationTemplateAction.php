<?php

declare(strict_types=1);

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\DTOs\NotificationTemplateData;
use App\Domain\Admin\Exceptions\SystemConfigurationException;
use App\Enums\AuditAction;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Notification templates — Settings → Notification Templates.
 *
 * Two rules are enforced here rather than in validation, because both need to
 * look at something the payload does not contain:
 *
 *   - **Placeholders must be ones the event can supply.** Which they are
 *     depends on `trigger_event`, so it cannot be a static rule.
 *   - **One active template per event and channel.** The database enforces it
 *     too, but a unique-index violation surfaces as an opaque 500; catching it
 *     here means the person editing the message is told which template is
 *     already live.
 */
final class ManageNotificationTemplateAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(NotificationTemplateData $data, User $actor): NotificationTemplate
    {
        $this->guard($data, null);

        return DB::transaction(function () use ($data, $actor): NotificationTemplate {
            $template = NotificationTemplate::query()->create($this->attributes($data, $actor));

            $this->audit->log(
                AuditAction::NotificationTemplateCreated,
                $template,
                after: $this->snapshot($template),
                actor: $actor,
            );

            return $template;
        });
    }

    public function update(
        NotificationTemplate $template,
        NotificationTemplateData $data,
        User $actor,
    ): NotificationTemplate {
        $this->guard($data, $template);

        return DB::transaction(function () use ($template, $data, $actor): NotificationTemplate {
            $before = $this->snapshot($template);

            $template->update($this->attributes($data, $actor));

            $this->audit->log(
                AuditAction::NotificationTemplateUpdated,
                $template,
                before: $before,
                after: $this->snapshot($template),
                actor: $actor,
            );

            return $template->fresh();
        });
    }

    /**
     * Retires a template.
     *
     * Soft-deleted: what was being sent to customers last quarter is part of
     * the record of what the company told them, and a support question about a
     * message someone received is unanswerable once the template is gone.
     */
    public function delete(NotificationTemplate $template, User $actor): void
    {
        DB::transaction(function () use ($template, $actor): void {
            $this->audit->log(
                AuditAction::NotificationTemplateDeleted,
                $template,
                before: $this->snapshot($template),
                actor: $actor,
            );

            $template->delete();
        });
    }

    private function guard(NotificationTemplateData $data, ?NotificationTemplate $except): void
    {
        // SMS has no subject line; supplying one is a mistake worth naming
        // rather than a value to quietly drop.
        if (! $data->channel->hasSubject() && $data->subject !== null) {
            throw SystemConfigurationException::subjectOnSms();
        }

        $this->guardPlaceholders($data);

        if (! $data->active) {
            return;
        }

        $clash = NotificationTemplate::query()
            ->where('trigger_event', $data->triggerEvent)
            ->where('channel', $data->channel)
            ->where('active', true)
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($clash) {
            throw SystemConfigurationException::templateAlreadyActive(
                $data->triggerEvent->label(),
                $data->channel->label(),
            );
        }
    }

    /**
     * Every `{{placeholder}}` in the body must be one this event can fill.
     *
     * An unknown one is not a small problem: it reaches the customer as the
     * literal text `{{amount}}`, and the only person who can prevent that is
     * the one writing the message.
     */
    private function guardPlaceholders(NotificationTemplateData $data): void
    {
        preg_match_all(NotificationTemplate::PLACEHOLDER_PATTERN, $data->body, $matches);

        // One capturing group in the pattern, so group 1 always exists.
        $used = array_values(array_unique(array_map('strtolower', $matches[1])));
        $allowed = $data->triggerEvent->placeholders();
        $unknown = array_values(array_diff($used, $allowed));

        if ($unknown !== []) {
            throw SystemConfigurationException::unknownPlaceholders($unknown, $allowed);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(NotificationTemplateData $data, User $actor): array
    {
        return [
            'name' => $data->name,
            'trigger_event' => $data->triggerEvent,
            'channel' => $data->channel,
            'subject' => $data->subject,
            'body' => $data->body,
            'active' => $data->active,
            'updated_by' => $actor->getKey(),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(NotificationTemplate $template): array
    {
        return $template->only(['name', 'trigger_event', 'channel', 'subject', 'body', 'active']);
    }
}
