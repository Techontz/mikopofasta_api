<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Templates for tests — Settings → Notification Templates.
 *
 * The only Module 6 entity with a factory, and the only one that needs one.
 * Interest formulas are a fixed set of three the interest engine branches on,
 * and repayment schedules are seeded reference data whose codes products and
 * loans point at: a random one of either is a row nothing in the system knows
 * what to do with, so those two are built through their seeders or their
 * endpoints instead.
 *
 * The default body uses only placeholders the default event can supply, so a
 * plain `NotificationTemplate::factory()->create()` is a template that would
 * pass the same validation a person's would.
 *
 * @extends Factory<NotificationTemplate>
 */
final class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /**
     * @return array<model-property<NotificationTemplate>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Template '.fake()->unique()->numberBetween(1, 100000),
            'trigger_event' => NotificationTriggerEvent::PaymentReceived,
            'channel' => NotificationChannel::Sms,
            'subject' => null,
            'body' => 'Tumepokea malipo yako ya TZS {{amount_paid}} kwa mkopo namba {{loan_number}}.',
            'active' => true,
        ];
    }

    /**
     * Pins the event, and the body with it.
     *
     * The two travel together on purpose: an event carries its own set of
     * allowed placeholders, so moving a body written for `payment_received`
     * onto `payment_overdue` produces a template the save endpoint rejects —
     * which is correct behaviour and a confusing way for a fixture to fail.
     */
    public function forEvent(NotificationTriggerEvent $event): self
    {
        return $this->state(fn (): array => [
            'trigger_event' => $event,
            'body' => 'Habari {{customer_name}}, mkopo namba {{loan_number}}.',
        ]);
    }

    /** Email carries a subject; SMS does not. */
    public function email(): self
    {
        return $this->state(fn (): array => [
            'channel' => NotificationChannel::Email,
            'subject' => 'MikopoFasta',
        ]);
    }

    /**
     * A draft.
     *
     * Inactive rows sit outside the one-active-per-event-and-channel index, so
     * any number of these may exist alongside the live one.
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
