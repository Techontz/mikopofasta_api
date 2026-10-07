<?php

namespace App\Services\Sms;

use App\Enums\LoanStatus;
use App\Models\Company;
use App\Models\LoanSchedule;
use App\Models\SmsLog;
use App\Services\LoanService;
use Carbon\CarbonImmutable;

/**
 * Daily automatic reminders (command sms:send-reminders): for every instalment still not fully paid,
 *  - repayment_reminder when it falls due in the template's `days` days (0 = on the due date);
 *  - overdue_reminder when it was due the template's `days` days ago.
 * Each instalment gets each reminder once (sms_logs.reference), so re-running the day sends nothing twice.
 */
class SmsReminders
{
    public function __construct(
        private readonly SmsTemplates $templates,
        private readonly SmsSender $sender,
        private readonly LoanService $loans,
    ) {}

    /**
     * @return array{reminders: int, overdue: int}
     */
    public function run(CarbonImmutable $today): array
    {
        $summary = ['reminders' => 0, 'overdue' => 0];

        foreach (Company::query()->pluck('name', 'id') as $companyId => $companyName) {
            $summary['reminders'] += $this->remind((int) $companyId, (string) $companyName, SmsTemplates::REPAYMENT_REMINDER, $today, [LoanStatus::Active, LoanStatus::Overdue], 1);
            $summary['overdue'] += $this->remind((int) $companyId, (string) $companyName, SmsTemplates::OVERDUE_REMINDER, $today, LoanStatus::repayable(), -1);
        }

        return $summary;
    }

    /**
     * @param  list<LoanStatus>  $statuses
     * @param  int  $direction  1 = instalments due in `days` days, -1 = instalments due `days` days ago
     */
    private function remind(int $companyId, string $companyName, string $key, CarbonImmutable $today, array $statuses, int $direction): int
    {
        $template = $this->templates->template($companyId, $key);
        if (! $template->is_active) {
            return 0;
        }

        $dueDate = $today->addDays($direction * (int) $template->days)->toDateString();
        $schedules = LoanSchedule::query()
            ->whereDate('due_date', $dueDate)
            ->whereColumn('paid_amount', '<', 'amount')
            ->whereHas('loan', fn ($query) => $query->where('company_id', $companyId)->whereIn('status', array_map(fn (LoanStatus $status): string => $status->value, $statuses)))
            ->with('loan.customer')
            ->orderBy('id')
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            $customer = $schedule->loan->customer;
            $reference = "{$key}:{$schedule->id}";
            if ($customer === null || SmsSender::normalisePhone($customer->phone) === null
                || SmsLog::where('company_id', $companyId)->where('reference', $reference)->exists()) {
                continue;
            }

            $message = SmsTemplates::fill($template->body, [
                'name' => $customer->first_name,
                'amount' => money((float) $schedule->amount - (float) $schedule->paid_amount),
                'due_date' => $schedule->due_date->format('d/m/Y'),
                'balance' => money($this->loans->outstanding($schedule->loan)['total']),
                'loan_number' => $schedule->loan->reference_number ?? $schedule->loan->loan_number,
                'company' => $companyName,
            ]);
            $category = $key === SmsTemplates::OVERDUE_REMINDER ? 'overdue' : 'reminder';
            $this->sender->send($companyId, (string) $customer->phone, $message, $category, ['customer_id' => $customer->id, 'reference' => $reference]);
            $sent++;
        }

        return $sent;
    }
}
