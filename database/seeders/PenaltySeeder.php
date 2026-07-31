<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Actions\RecordCashPaymentAction;
use App\Domain\Repayments\Actions\RunOverdueProcessAction;
use App\Domain\Repayments\Enums\TriggeredBy;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Overdue installments, the penalties they attract, and one collected penalty.
 *
 * Everything here goes through the real engines — `RunOverdueProcessAction`
 * computes the penalties and `RecordCashPaymentAction` collects one — so the
 * seeded figures are ones PenaltyCalculator and the allocator actually
 * produced. Writing `penalty_due` directly would have been quicker and would
 * have seeded numbers no code path can reproduce.
 *
 * What it makes true on a fresh database:
 *
 *   Penalty → Penalty List   has rows, and they carry real DPD-based amounts
 *   Penalty → Paid Penalty   has one row, tied to a real payment
 *   2200 Penalty Income      is non-zero, and equals the paid register's total
 *
 * The back-dating is the only artificial part, and it is unavoidable: a
 * database seeded today has no loan that fell overdue in the past, and a
 * penalty screen with nothing on it demonstrates nothing.
 */
final class PenaltySeeder extends Seeder
{
    /**
     * How far past due to push each chosen loan's first installment.
     *
     * Three different ages rather than one, so the list shows a spread — a
     * screen where every row carries the same figure hides whether the
     * calculation depends on anything at all.
     */
    private const DAYS_OVERDUE = [45, 30, 12];

    public function run(): void
    {
        $loans = Loan::query()
            ->with('schedules')
            ->whereIn('status', [LoanStatus::Active->value, LoanStatus::Arrears->value])
            ->orderBy('id')
            ->take(count(self::DAYS_OVERDUE))
            ->get();

        if ($loans->isEmpty()) {
            $this->command?->warn('PenaltySeeder: no active loans to age, skipping.');

            return;
        }

        foreach ($loans as $index => $loan) {
            $first = $loan->schedules->sortBy('installment_number')->first();

            if ($first === null) {
                continue;
            }

            $first->update([
                'due_date' => Date::now()->subDays(self::DAYS_OVERDUE[$index])->toDateString(),
            ]);
        }

        // The real job, so the amounts are the ones the engine computes.
        app(RunOverdueProcessAction::class)->handle(TriggeredBy::Manual, $this->actor());

        $this->collectOnePenalty();
    }

    /**
     * Pays one penalty off, so the Paid Penalty register is not empty.
     *
     * Allocation order is Penalty → Interest → Principal (§7), so a payment of
     * exactly the penalty lands entirely on it — which is what makes this a
     * penalty collection rather than a repayment that happens to touch one.
     */
    private function collectOnePenalty(): void
    {
        $loan = Loan::query()
            ->with('schedules')
            ->whereHas('schedules', fn ($q) => $q->where('penalty_due', '>', 0))
            ->orderBy('id')
            ->first();

        if ($loan === null) {
            return;
        }

        $schedule = $loan->schedules->firstWhere(fn ($s): bool => $s->outstandingPenalty()->isPositive());

        if ($schedule === null) {
            return;
        }

        $amount = $schedule->outstandingPenalty();

        if (! $amount->isPositive()) {
            return;
        }

        // The same action the teller endpoint calls, so the payment, its
        // allocation and its ledger entry are all produced the ordinary way.
        app(RecordCashPaymentAction::class)->handle($loan, $amount, $this->actor());
    }

    private function actor(): User
    {
        $user = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', [RoleName::Finance->value, RoleName::SuperAdmin->value]))
            ->oldest('id')
            ->first() ?? User::query()->oldest('id')->first();

        if ($user === null) {
            throw new RuntimeException('PenaltySeeder needs at least one user. Run UserSeeder first.');
        }

        return $user;
    }
}
