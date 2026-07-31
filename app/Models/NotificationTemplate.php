<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The message sent when something happens to a loan — Settings → Notification
 * Templates.
 *
 * One active template per (event, channel), enforced by a partial unique index;
 * see the migration for why NULL-distinctness is the right tool there.
 *
 * @property int $id
 * @property string $name
 * @property NotificationTriggerEvent $trigger_event
 * @property NotificationChannel $channel
 * @property string|null $subject
 * @property string $body
 * @property bool $active
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 */
class NotificationTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationTemplateFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * How a placeholder is written in a template body: `{{customer_name}}`.
     *
     * Double braces rather than a bare `{name}`, because a message may
     * legitimately contain a brace and the doubled form is the convention every
     * templating language in this space uses.
     */
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /** @var list<string> */
    protected $fillable = [
        'name', 'trigger_event', 'channel', 'subject', 'body', 'active', 'updated_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The placeholders this body actually references.
     *
     * @return list<string>
     */
    public function placeholdersUsed(): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $this->body, $matches);

        // The pattern has exactly one capturing group, so group 1 is always
        // present — empty when the body has no placeholders at all.
        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * The template that should fire for an event on a channel, if any.
     *
     * The one place the sender asks. Returns null rather than falling back to
     * another channel's message: an SMS is not an acceptable substitute for an
     * email nobody configured, and a silent substitution would be worse than
     * sending nothing.
     *
     * @param Builder<NotificationTemplate>|null $query
     */
    public static function forEvent(
        NotificationTriggerEvent $event,
        NotificationChannel $channel,
        ?Builder $query = null,
    ): ?self {
        return ($query ?? self::query())
            ->where('trigger_event', $event)
            ->where('channel', $channel)
            ->where('active', true)
            ->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_event' => NotificationTriggerEvent::class,
            'channel' => NotificationChannel::class,
            'active' => 'boolean',
        ];
    }
}
