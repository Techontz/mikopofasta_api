<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * The messages the system sends — Settings → Notification Templates.
 *
 * ## Where the wording comes from
 *
 * Swahili, because the customers are Tanzanian and the one message the business
 * documents write out verbatim is Swahili: REPAYMENT OVERVIEW §1 Step 5 gives
 * *"Tumepokea malipo yako ya XXX"* — "we have received your payment of XXX" —
 * as what a customer gets when a repayment lands. That message is seeded
 * exactly, with `{{amount_paid}}` standing where the document writes XXX.
 *
 * The others follow its register: the same second person, the same brevity, and
 * the same habit of naming the figure. LOAN PROCESS OVERVIEW describes each of
 * those moments — application received, approved, rejected, disbursed, failed —
 * as one the customer is told about, without quoting the words, so the wording
 * here is written to match the one message that *is* quoted rather than
 * invented in a different voice.
 *
 * **These are meant to be edited.** That is the whole point of the screen: the
 * seeded row is a working default so the system is never silent on an event,
 * not a decision about what the business wants to say. Editing one is a
 * configuration change and needs no developer.
 *
 * ## Why SMS only
 *
 * Every seeded template is SMS. It is the channel the documents actually
 * describe — `POST /notifications/sms` is the call REPAYMENT OVERVIEW makes —
 * and the customer base reached by phone rather than email. Email templates are
 * left for whoever wants them: seeding empty ones would put eight rows on the
 * screen that nobody wrote and nothing sends.
 */
final class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            NotificationTemplate::query()->firstOrCreate(
                [
                    'trigger_event' => $template['trigger_event'],
                    'channel' => NotificationChannel::Sms,
                ],
                [
                    'name' => $template['name'],
                    // SMS carries no subject line — see NotificationChannel.
                    'subject' => null,
                    'body' => $template['body'],
                    'active' => true,
                ],
            );
        }
    }

    /**
     * @return list<array{trigger_event: NotificationTriggerEvent, name: string, body: string}>
     */
    private function templates(): array
    {
        return [
            [
                'trigger_event' => NotificationTriggerEvent::LoanApplied,
                'name' => 'Maombi yamepokelewa',
                'body' => 'Habari {{customer_name}}, tumepokea maombi yako ya mkopo namba {{loan_number}} '
                    .'ya kiasi cha TZS {{principal_amount}}. Tutakujulisha uamuzi hivi karibuni.',
            ],
            [
                'trigger_event' => NotificationTriggerEvent::LoanApproved,
                'name' => 'Mkopo umeidhinishwa',
                'body' => 'Hongera {{customer_name}}, mkopo wako namba {{loan_number}} wa TZS '
                    .'{{principal_amount}} umeidhinishwa.',
            ],
            [
                'trigger_event' => NotificationTriggerEvent::LoanRejected,
                'name' => 'Mkopo haujaidhinishwa',
                'body' => 'Habari {{customer_name}}, samahani maombi yako ya mkopo namba {{loan_number}} '
                    .'hayajaidhinishwa. Tafadhali wasiliana na tawi letu kwa maelezo zaidi.',
            ],
            [
                'trigger_event' => NotificationTriggerEvent::DisbursementSuccess,
                'name' => 'Mkopo umetolewa',
                'body' => 'Habari {{customer_name}}, TZS {{disbursed_amount}} ya mkopo namba '
                    .'{{loan_number}} imetumwa kwenye namba yako. Asante kwa kutuamini.',
            ],
            [
                'trigger_event' => NotificationTriggerEvent::DisbursementFailed,
                'name' => 'Malipo hayakufanikiwa',
                'body' => 'Habari {{customer_name}}, malipo ya mkopo namba {{loan_number}} hayakufanikiwa. '
                    .'Tafadhali wasiliana na tawi letu.',
            ],
            [
                /*
                 * The one message quoted in the documents. REPAYMENT OVERVIEW
                 * §1 Step 5: "Tumepokea malipo yako ya XXX". `{{amount_paid}}`
                 * is XXX; the balance is added because a customer who has just
                 * paid asks what is left next, and the event can supply it.
                 */
                'trigger_event' => NotificationTriggerEvent::PaymentReceived,
                'name' => 'Malipo yamepokelewa',
                'body' => 'Tumepokea malipo yako ya TZS {{amount_paid}} kwa mkopo namba {{loan_number}}. '
                    .'Salio lako ni TZS {{outstanding_balance}}. Asante.',
            ],
            [
                'trigger_event' => NotificationTriggerEvent::PaymentOverdue,
                'name' => 'Kumbusho la malipo',
                'body' => 'Habari {{customer_name}}, mkopo wako namba {{loan_number}} una malipo ya TZS '
                    .'{{amount_due}} yaliyochelewa siku {{days_overdue}}. Tafadhali lipa haraka '
                    .'kuepuka faini.',
            ],
            [
                'trigger_event' => NotificationTriggerEvent::LoanClosed,
                'name' => 'Mkopo umekamilika',
                'body' => 'Hongera {{customer_name}}, umemaliza kulipa mkopo namba {{loan_number}}. '
                    .'Karibu tena.',
            ],
        ];
    }
}
