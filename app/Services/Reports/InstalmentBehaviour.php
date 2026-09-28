<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Days Past Due per instalment (Documents: 🧠 OVERVIEW ALL REPORT → "REPAYMENT BEHAVIOR REPORT": Days Delayed = Payment Date − Due Date).
 *
 * The schedule only stores the amount paid, so the payment date of each instalment is rebuilt by replaying the loan's
 * repayments in date order against its instalments in due-date order, exactly like LoanService::deposit() fills them:
 * only the principal + interest + insurance part of a repayment reaches the schedule (the penalty part is taken first
 * under the Principal → Penalty → Interest order but settles penalties, not instalments).
 * Inferred: an instalment is paid on the date of the repayment that completes it; an unpaid instalment that fell due
 * before today is delayed by (today − due date) and still open.
 */
class InstalmentBehaviour
{
    /**
     * DPD buckets in report order.
     *
     * @var list<string>
     */
    public const BUCKETS = ['0', '1–7', '8–30', '31–60', '61–90', '90+'];

    /**
     * Documents label per bucket ("0 days → On time, 1–7 → Slight delay, 8–30 → Risk, 30+ → Default").
     *
     * @var array<string, string>
     */
    public const BUCKET_LABELS = [
        '0' => 'On time', '1–7' => 'Slight delay', '8–30' => 'Risk', '31–60' => 'Default', '61–90' => 'Default', '90+' => 'Default',
    ];

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 0 => '0',
            $days <= 7 => '1–7',
            $days <= 30 => '8–30',
            $days <= 60 => '31–60',
            $days <= 90 => '61–90',
            default => '90+',
        };
    }

    /**
     * Instalments of the given loans with their rebuilt payment date and delay.
     *
     * @param  list<int>  $loanIds
     * @return Collection<int, array{id: int, loan_id: int, due_date: string, amount: float, paid_amount: float, paid_date: ?string, delay_days: ?int, is_due: bool, is_paid: bool, bucket: ?string}>
     */
    public function instalments(array $loanIds, CarbonImmutable $today): Collection
    {
        if ($loanIds === []) {
            return collect();
        }

        $schedules = collect();
        $deposits = collect();
        foreach (array_chunk($loanIds, 1000) as $chunk) {
            $schedules = $schedules->concat(DB::table('loan_schedules')->whereIn('loan_id', $chunk)->orderBy('due_date')->orderBy('id')->get(['id', 'loan_id', 'due_date', 'amount']));
            $deposits = $deposits->concat(DB::table('loan_transactions')->whereIn('loan_id', $chunk)->where('type', 'deposit')->whereNull('reversed_at')
                ->orderBy('transaction_date')->orderBy('id')->get(['loan_id', 'transaction_date', 'amount', 'penalty']));
        }
        $depositsByLoan = $deposits->groupBy('loan_id');

        return $schedules->groupBy('loan_id')->flatMap(function (Collection $loanSchedules, int $loanId) use ($depositsByLoan, $today): array {
            $queue = ($depositsByLoan->get($loanId) ?? collect())
                ->map(fn (object $deposit): array => ['date' => substr((string) $deposit->transaction_date, 0, 10), 'left' => round((float) $deposit->amount - (float) $deposit->penalty, 2)])
                ->values()
                ->all();
            $cursor = 0;
            $rows = [];

            foreach ($loanSchedules as $schedule) {
                $amount = round((float) $schedule->amount, 2);
                $paid = 0.0;
                $paidDate = null;

                while ($paid < $amount - 0.005 && $cursor < count($queue)) {
                    $portion = min($queue[$cursor]['left'], $amount - $paid);
                    $paid = round($paid + $portion, 2);
                    $queue[$cursor]['left'] = round($queue[$cursor]['left'] - $portion, 2);
                    if ($paid >= $amount - 0.005) {
                        $paidDate = $queue[$cursor]['date'];
                    }
                    if ($queue[$cursor]['left'] <= 0.005) {
                        $cursor++;
                    }
                }

                $due = substr((string) $schedule->due_date, 0, 10);
                $dueDate = CarbonImmutable::parse($due);
                $isPaid = $paidDate !== null;
                $isDue = $isPaid || $dueDate->lt($today);
                $delay = match (true) {
                    $isPaid => (int) $dueDate->diffInDays(CarbonImmutable::parse($paidDate), false),
                    $isDue => (int) $dueDate->diffInDays($today, false),
                    default => null,
                };

                $rows[] = [
                    'id' => (int) $schedule->id,
                    'loan_id' => $loanId,
                    'due_date' => $due,
                    'amount' => $amount,
                    'paid_amount' => $paid,
                    'paid_date' => $paidDate,
                    'delay_days' => $delay,
                    'is_due' => $isDue,
                    'is_paid' => $isPaid,
                    'bucket' => $delay === null ? null : self::bucket($delay),
                ];
            }

            return $rows;
        })->values();
    }
}
