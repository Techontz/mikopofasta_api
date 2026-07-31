<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's `SaveNotificationTemplateInputSchema`.
 *
 * Which placeholders the body may use, and whether an active template already
 * exists for this event and channel, are checked in
 * ManageNotificationTemplateAction — both depend on the chosen event, which a
 * static rule cannot see.
 */
final class NotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'triggerEvent' => ['required', Rule::enum(NotificationTriggerEvent::class)],
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'subject' => ['nullable', 'string', 'max:200'],
            /*
             * 1000 rather than unbounded. An SMS is billed per 160 characters
             * and a body long enough to overflow the column would fail at the
             * database instead of at the person writing it.
             */
            'body' => ['required', 'string', 'min:2', 'max:1000'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.min' => 'Enter a template name.',
            'body.min' => 'Enter the message.',
            'body.max' => 'Keep the message under 1000 characters.',
        ];
    }
}
