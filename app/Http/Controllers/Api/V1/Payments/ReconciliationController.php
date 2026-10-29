<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Payments\ReasonRequest;
use App\Http\Requests\Api\Payments\VerifyDepositRequest;
use App\Http\Resources\Api\V1\Payments\TellerDepositResource;
use App\Models\ApprovalPolicy;
use App\Models\TellerDeposit;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payments → Bank Reconciliation: teller deposit slips vs teller cash vs bank statement.
 * Verify (match statement) → Confirm (ledger + loan allocation + SMS), or Reject with reason.
 */
class ReconciliationController extends ApiController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('payments.verify');

        $query = $this->scoped(TellerDeposit::query())
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('zone_id') && $request->input('zone_id') !== 'all', fn ($query) => $query->whereHas('branch', fn ($branch) => $branch->where('zone_id', $request->integer('zone_id'))))
            ->with(['branch', 'employee', 'bankAccount', 'verifier', 'confirmer', 'payments.customer', 'payments.loan'])
            ->latest('deposit_date')
            ->latest('id');
        $this->applyFilters($query, $request, 'deposit_date');
        $rows = $query->get();

        // Enhancement §8.2: counts and totals of every status within the selected branch, zone and period.
        $all = $this->scoped(TellerDeposit::query())
            ->when($request->filled('zone_id') && $request->input('zone_id') !== 'all', fn ($query) => $query->whereHas('branch', fn ($branch) => $branch->where('zone_id', $request->integer('zone_id'))));
        $this->applyFilters($all, $request, 'deposit_date');
        $summary = $all->selectRaw('status, COUNT(*) as records, COALESCE(SUM(amount), 0) as amount')->groupBy('status')->get()
            ->mapWithKeys(fn ($row): array => [$row->status => ['count' => (int) $row->records, 'amount' => round((float) $row->amount, 2)]]);

        return TellerDepositResource::collection($rows)->additional(['summary' => $summary->all() ?: (object) []]);
    }

    /**
     * Finance checks the slip against the bank statement. A matching slip is posted at once — the money reaches the loans and
     * the bank — because the teller made the deposit and Finance is the checker: there is no separate Confirm click to forget.
     * A mismatch stays unposted for investigation. Rule 6: the teller who recorded the deposit cannot verify it.
     */
    public function verify(VerifyDepositRequest $request, TellerDeposit $tellerDeposit, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);
        $duties->assertCanApprove($tellerDeposit->employee_id, $this->currentEmployee(), 'teller deposit', workflow: ApprovalPolicy::TELLER_DEPOSITS);
        self::allowTimeToPostEveryReceipt();

        $deposit = DB::transaction(function () use ($request, $tellerDeposit): TellerDeposit {
            $deposit = $this->payments->verifyDeposit($tellerDeposit, (float) $request->input('statement_amount'), $request->string('statement_reference')->toString(), $this->currentEmployee());

            return $deposit->status === TellerDeposit::STATUS_VERIFIED ? $this->payments->confirmDeposit($deposit, $this->currentEmployee()) : $deposit;
        });

        return $deposit->status === TellerDeposit::STATUS_CONFIRMED
            ? $this->message('Deposit verified and posted: the loans are reduced and the money is in the bank')
            : $this->message('Amount mismatch: deposit kept pending for investigation', 200, ['mismatch' => true]);
    }

    /**
     * Posts a slip verified before verification started posting by itself (older deposits left at "verified").
     * Rule 6: the teller who recorded the deposit cannot confirm (post) it.
     */
    public function confirm(TellerDeposit $tellerDeposit, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);
        $duties->assertCanApprove($tellerDeposit->employee_id, $this->currentEmployee(), 'teller deposit', workflow: ApprovalPolicy::TELLER_DEPOSITS);
        self::allowTimeToPostEveryReceipt();

        $this->payments->confirmDeposit($tellerDeposit, $this->currentEmployee());

        return $this->message('Payment confirmed successfully');
    }

    /**
     * Rule 6: the teller who recorded the deposit cannot reject it.
     */
    public function reject(ReasonRequest $request, TellerDeposit $tellerDeposit, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);
        $duties->assertCanApprove($tellerDeposit->employee_id, $this->currentEmployee(), 'teller deposit', workflow: ApprovalPolicy::TELLER_DEPOSITS);

        $this->payments->rejectDeposit($tellerDeposit, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Deposit rejected successfully');
    }

    /**
     * Posting a slip is a full repayment per receipt — lock the loan, allocate it across the instalments reached, journal it,
     * settle penalties — so a slip carrying a hundred receipts is a hundred times the work of a short one and runs past the
     * default web time limit. A request cut off halfway rolls the whole slip back and answers 500, which is what a long slip
     * used to do, so the limit is lifted for the two actions that post one.
     */
    private static function allowTimeToPostEveryReceipt(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
    }

    /**
     * GET payments/reconciliation/{tellerDeposit}/receipt — the bank receipt the teller attached (enhancement §8.4), streamed
     * from private storage to Finance or the branch teller. A deposit without one says so; nothing is generated.
     */
    public function receipt(TellerDeposit $tellerDeposit): StreamedResponse|JsonResponse
    {
        $this->authorizeAny('payments.verify', 'payments.cash');
        abort_unless((int) $tellerDeposit->company_id === (int) $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);

        if (! $tellerDeposit->hasReceipt()) {
            return $this->message('No receipt was uploaded for this deposit.', 404);
        }

        return Storage::disk(TellerDeposit::DISK)->response($tellerDeposit->receipt_file, $tellerDeposit->receipt_file_name, ['Cache-Control' => 'private, max-age=300'], 'inline');
    }
}
