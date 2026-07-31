<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/**
 * What causes a notification to be sent.
 *
 * Mirrors the frontend's `NOTIFICATION_TRIGGER_EVENTS` exactly. Every one of
 * these is a moment the business documents describe a customer being told
 * about — LOAN PROCESS OVERVIEW's approval, mandate and disbursement outcomes,
 * and REPAYMENT OVERVIEW's "Tumepokea malipo yako ya XXX" on payment.
 *
 * Enumerated rather than free text on purpose. A template keyed to an event
 * nothing raises would look configured and never fire, and the failure would be
 * silent — the worst kind for a message someone is waiting on.
 */
enum NotificationTriggerEvent: string
{
    case LoanApplied = 'loan_applied';
    case LoanApproved = 'loan_approved';
    case LoanRejected = 'loan_rejected';
    case DisbursementSuccess = 'disbursement_success';
    case DisbursementFailed = 'disbursement_failed';
    case PaymentReceived = 'payment_received';
    case PaymentOverdue = 'payment_overdue';
    case LoanClosed = 'loan_closed';

    public function label(): string
    {
        return match ($this) {
            self::LoanApplied => 'Loan applied',
            self::LoanApproved => 'Loan approved',
            self::LoanRejected => 'Loan rejected',
            self::DisbursementSuccess => 'Disbursement successful',
            self::DisbursementFailed => 'Disbursement failed',
            self::PaymentReceived => 'Payment received',
            self::PaymentOverdue => 'Payment overdue',
            self::LoanClosed => 'Loan closed',
        };
    }

    /**
     * The placeholders a template for this event may use.
     *
     * Checked when a template is saved, so a message referring to a field the
     * event cannot supply is caught by the person writing it rather than
     * reaching a customer as a literal `{{amount}}`.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        $customer = ['customer_name', 'customer_number'];
        $loan = [...$customer, 'loan_number', 'principal_amount'];

        return match ($this) {
            self::LoanApplied, self::LoanApproved, self::LoanRejected, self::LoanClosed => $loan,
            self::DisbursementSuccess, self::DisbursementFailed => [...$loan, 'disbursed_amount'],
            self::PaymentReceived => [...$loan, 'amount_paid', 'outstanding_balance'],
            self::PaymentOverdue => [...$loan, 'amount_due', 'days_overdue'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }
}
