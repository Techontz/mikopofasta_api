<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `NotificationTemplateSchema` in the frontend's
 * types/notification-template.ts.
 *
 * @mixin NotificationTemplate
 */
final class NotificationTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'triggerEvent' => $this->trigger_event->value,
            'triggerEventLabel' => $this->trigger_event->label(),
            'channel' => $this->channel->value,
            'channelLabel' => $this->channel->label(),
            'subject' => $this->subject,
            'body' => $this->body,
            'active' => $this->active,
            'updatedBy' => $this->updated_by === null ? null : (string) $this->updated_by,
            'updatedAt' => $this->updated_at?->toIso8601String(),

            /*
             * Beyond the frontend schema, and the reason the editor can be
             * useful rather than a blind text box: which placeholders this
             * event can fill, and which the body currently uses. The form shows
             * the first as a palette and the second so a reader can see at a
             * glance what the message will interpolate.
             */
            'availablePlaceholders' => $this->trigger_event->placeholders(),
            'placeholdersUsed' => $this->placeholdersUsed(),

            'updatedByName' => $this->whenLoaded(
                'editor',
                fn (): ?string => $this->updated_by === null ? null : $this->editor->name,
            ),
        ];
    }
}
