<?php

namespace App\Services;

use App\Enums\LoanMethod;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Models\Loan;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * eSIMU → Payments: read-only view of the existing payment records for eSIMU loans only (no new payment collection).
 * Collected amounts are what was allocated to eSIMU loans; eSIMU loans stay in every general loan report as before.
 */
class PhoneBondPayments
{
    public function __construct(private LoanService $loans) {}

    /**
     * @param  list<int>|null  $branchIds
     * @param  array{search?: string|null, status?: string|null, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function dashboard(int $companyId, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $loanIds = fn () => Loan::query()->select('id')->where('company_id', $companyId)->where('loan_method', LoanMethod::PhoneBond->value)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds));
        $allocations = fn () => DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.loan_id', $loanIds());
        $today = PhoneBond::today();
        $monthStart = $today->startOfMonth()->toDateString();

        $openLoans = Loan::whereIn('id', $loanIds())->whereIn('status', LoanStatus::values(...LoanStatus::repayable()))->get();
        $awaiting = DB::table('loan_schedules')->whereIn('loan_id', $openLoans->pluck('id'))
            ->whereDate('due_date', '<=', $today->toDateString())->whereColumn('paid_amount', '<', 'amount');

        $payments = $this->payments($companyId, $loanIds(), $from, $to, $filters);
        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = $payments->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        return [
            'cards' => [
                'collected_this_month' => round((float) $allocations()->where('payments.paid_on', '>=', $monthStart)->where('payments.paid_on', '<=', $today->toDateString())->sum('payment_allocations.amount'), 2),
                'collected_all_time' => round((float) $allocations()->sum('payment_allocations.amount'), 2),
                'confirmed_payments' => (int) $allocations()->whereBetween('payments.paid_on', [$from->toDateString(), $to->toDateString()])->distinct()->count('payments.id'),
                'awaiting_customer' => round((float) (clone $awaiting)->sum(DB::raw('amount - paid_amount')), 2),
                'awaiting_instalments' => (clone $awaiting)->count(),
                'expected_collection' => round((float) DB::table('loan_schedules')->whereIn('loan_id', $loanIds())->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2),
                'outstanding_loans' => $openLoans->count(),
                'outstanding_amount' => round($openLoans->sum(fn (Loan $loan): float => $this->loans->outstanding($loan)['total']), 2),
            ],
            'data' => collect($page->items())->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'customer' => $payment->customer?->full_name,
                'customer_id' => $payment->customer_id,
                'loan_number' => $payment->loan?->loan_number,
                'method' => trim($payment->channel.($payment->provider !== '' && $payment->provider !== null ? ' - '.$payment->provider : '')),
                'status' => $payment->status instanceof PaymentStatus ? $payment->status->value : $payment->status,
                'status_label' => $payment->status instanceof PaymentStatus ? $payment->status->label() : (string) $payment->status,
                'reference' => $payment->receipt_number ?? $payment->transaction_id ?? $payment->reference,
                'paid_on' => $payment->paid_on?->toDateString(),
                'amount' => round((float) ($payment->esimu_amount ?? $payment->amount), 2),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Payments made for eSIMU loans: allocated to one, or recorded against one and still awaiting confirmation.
     *
     * @param  array{search?: string|null, status?: string|null}  $filters
     */
    private function payments(int $companyId, Builder $loanIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $allocated = DB::table('payment_allocations')->selectRaw('payment_id, SUM(amount) as esimu_amount')->whereIn('loan_id', $loanIds)->groupBy('payment_id');

        return Payment::query()
            ->select('payments.*', 'esimu.esimu_amount')
            ->leftJoinSub($allocated, 'esimu', 'esimu.payment_id', '=', 'payments.id')
            ->where('payments.company_id', $companyId)
            ->where(fn ($query) => $query->whereNotNull('esimu.payment_id')->orWhereIn('payments.loan_id', $loanIds))
            ->whereBetween('payments.paid_on', [$from->toDateString(), $to->toDateString()])
            ->when(filled($filters['status'] ?? null) && ($filters['status'] ?? null) !== 'all', fn ($query) => $query->where('payments.status', $filters['status']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['search']).'%';
                $query->where(fn ($inner) => $inner->where('payments.receipt_number', 'like', $term)->orWhere('payments.transaction_id', 'like', $term)
                    ->orWhere('payments.reference', 'like', $term)->orWhere('payments.phone', 'like', $term)
                    ->orWhereHas('customer', fn ($customer) => $customer->whereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$term])->orWhere('phone', 'like', $term)));
            })
            ->with(['customer:id,first_name,middle_name,last_name', 'loan:id,loan_number'])
            ->orderByDesc('payments.paid_on')->orderByDesc('payments.id');
    }
}
