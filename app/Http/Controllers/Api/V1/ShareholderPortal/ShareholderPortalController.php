<?php

namespace App\Http\Controllers\Api\V1\ShareholderPortal;

use App\Enums\Account;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Bank\BankTransferResource;
use App\Models\AuditLog;
use App\Models\BankTransfer;
use App\Models\Capital;
use App\Models\ShareHolder;
use App\Services\CapitalContributions;
use App\Services\CompanyFunds;
use App\Services\Ledger;
use App\Services\Reports\Financial\CashAccounts;
use App\Services\Shareholders\ShareholderAccounts;
use App\Services\Shareholders\ShareholderPortal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shareholder Portal API (portal/shareholder/*). The shareholder is resolved ONLY from the signed-in account's link
 * (`$request->user()->shareHolder`) — no endpoint accepts a shareholder id — and every endpoint also requires its
 * shareholder.* permission. Own data only, except the directory (public holdings whitelist) and the share structure.
 *
 * Capital submitted here goes through the existing two-step flow ({@see CapitalContributions::requestContribution()}):
 * PENDING, no journal, until a different authorised staff user approves it in Capital → Add Capitals.
 */
class ShareholderPortalController extends ApiController
{
    public const RECEIPT_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const RECEIPT_MAX_KB = 5120;

    public function __construct(
        private readonly ShareholderPortal $portal,
        private readonly CapitalContributions $contributions,
        private readonly ShareholderAccounts $accounts,
    ) {}

    public function dashboard(): JsonResponse
    {
        $holder = $this->holder('shareholder.portal');

        return response()->json(['data' => $this->portal->dashboard($holder)]);
    }

    public function profile(): JsonResponse
    {
        $holder = $this->holder('shareholder.profile');

        return response()->json(['data' => $this->presentProfile($holder)]);
    }

    /**
     * Only safe fields: e-mail, phone (kept unique and applied to the login phone in the same transaction) and photo.
     * Names, date of birth, identity and financial fields stay read-only.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $holder = $this->holder('shareholder.profile');
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'max:30', 'regex:'.ShareholderAccounts::PHONE_PATTERN],
            'passport_photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=100,min_height=100'],
        ], ['mobile.regex' => 'The phone number must be 9 to 15 digits.'], ['mobile' => 'phone number']);

        $before = ['email' => $holder->email, 'mobile' => $holder->mobile, 'photo' => $holder->passport_photo !== null];

        DB::transaction(function () use ($holder, $validated, $request, $before): void {
            $locked = ShareHolder::whereKey($holder->id)->lockForUpdate()->firstOrFail();
            $mobile = trim($validated['mobile']);
            $this->accounts->syncLogin($locked, $mobile, $validated['email']);
            $locked->update(['email' => $validated['email'], 'mobile' => $mobile]);

            $photo = $request->file('passport_photo');
            $previous = $locked->passport_photo;
            if ($photo !== null) {
                $locked->update(['passport_photo' => $photo->store("share-holders/{$locked->company_id}", ShareHolder::DISK)]);
                if ($previous) {
                    Storage::disk(ShareHolder::DISK)->delete($previous);
                }
            }

            $after = ['email' => $locked->email, 'mobile' => $locked->mobile, 'photo' => $locked->passport_photo !== null, 'photo_replaced' => $photo !== null];
            AuditLog::create([
                'company_id' => $locked->company_id,
                'employee_id' => $this->currentEmployee()->id,
                'action' => 'ShareHolder.profile_updated',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->id,
                'before' => $before,
                'after' => $after,
                'context' => ['via' => 'shareholder_portal'],
                'ip_address' => $request->ip(),
            ]);
        });

        return $this->message('Profile updated successfully', 200, ['data' => $this->presentProfile($holder->fresh())]);
    }

    public function photo(): StreamedResponse
    {
        $holder = $this->holder('shareholder.profile');
        abort_unless($holder->passport_photo && Storage::disk(ShareHolder::DISK)->exists($holder->passport_photo), 404);

        return Storage::disk(ShareHolder::DISK)->response($holder->passport_photo, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function capital(): JsonResponse
    {
        $holder = $this->holder('shareholder.capital.view');

        return response()->json(['data' => $this->portal->contributions($holder)]);
    }

    /**
     * Submit a contribution: PENDING approval, no journal and no balance change until another authorised staff user
     * approves it. Any shareholder id in the body is ignored.
     */
    public function submitCapital(Request $request): JsonResponse
    {
        $holder = $this->holder('shareholder.capital.submit');
        $companyId = (int) $holder->company_id;

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', 'in:CASH,BANK'],
            'bank_account_id' => ['nullable', 'required_if:payment_method,BANK', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
            'transaction_reference' => ['required', 'string', 'max:50'],
            'receipt_file' => ['nullable', 'file', 'mimes:'.implode(',', self::RECEIPT_MIMES), 'max:'.self::RECEIPT_MAX_KB],
        ], [], ['payment_method' => 'payment method', 'bank_account_id' => 'bank account', 'transaction_reference' => 'transaction reference', 'receipt_file' => 'receipt']);

        $employee = $this->currentEmployee();
        $result = $this->contributions->requestContribution(
            $holder,
            (float) $validated['amount'],
            $validated['payment_method'],
            $validated['payment_method'] === 'BANK' ? (int) $validated['bank_account_id'] : null,
            $employee,
            trim($validated['transaction_reference']),
            null,
            CarbonImmutable::now(),
            null,
            function (Capital $capital) use ($request, $employee): void {
                if ($request->file('receipt_file') !== null) {
                    $capital->attachReceipt($request->file('receipt_file'));
                }

                AuditLog::create([
                    'company_id' => $capital->company_id,
                    'employee_id' => $employee->id,
                    'action' => 'Capital.submitted_by_shareholder',
                    'auditable_type' => $capital->getMorphClass(),
                    'auditable_id' => $capital->id,
                    'before' => null,
                    'after' => ['status' => Capital::STATUS_PENDING, 'amount' => (float) $capital->amount, 'pay_method' => $capital->pay_method, 'bank_account_id' => $capital->bank_account_id, 'reference' => $capital->receipt_number],
                    'context' => ['share_holder_id' => $capital->share_holder_id, 'via' => 'shareholder_portal'],
                    'ip_address' => $request->ip(),
                ]);
            },
            Capital::SOURCE_SHAREHOLDER_PORTAL,
        );

        return $this->message('Capital contribution submitted — pending approval by the finance team', 201, [
            'data' => $this->portal->presentContribution($result['capital']->refresh()->load(['bankAccount', 'journalEntry'])),
        ]);
    }

    public function cancelCapital(Capital $capital): JsonResponse
    {
        $holder = $this->holder('shareholder.capital.submit');
        $this->ensureOwn($capital, $holder);
        abort_unless($capital->isFromShareholderPortal(), 403, 'Only contributions you submitted from the portal can be cancelled.');

        $cancelled = $this->contributions->cancel($capital, $this->currentEmployee());

        return $this->message('Capital contribution cancelled', 200, ['data' => $this->portal->presentContribution($cancelled->load(['bankAccount', 'journalEntry']))]);
    }

    public function receipt(Capital $capital): StreamedResponse
    {
        $holder = $this->holder('shareholder.capital.view');
        $this->ensureOwn($capital, $holder);
        abort_unless($capital->receipt_file && Storage::disk(Capital::DISK)->exists($capital->receipt_file), 404);

        return Storage::disk(Capital::DISK)->response($capital->receipt_file, $capital->receipt_file_name, ['Cache-Control' => 'private, max-age=300'], 'inline');
    }

    public function shares(): JsonResponse
    {
        $holder = $this->holder('shareholder.capital.view');

        return response()->json(['data' => $this->portal->shares($holder)]);
    }

    public function dividends(): JsonResponse
    {
        $holder = $this->holder('shareholder.dividends.view');

        return response()->json(['data' => $this->portal->dividends($holder)]);
    }

    public function statement(Request $request): JsonResponse
    {
        $holder = $this->holder('shareholder.statements');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->portal->statement($holder, $from, $to)]);
    }

    /**
     * The statement for printing / saving as PDF; each download is audit-logged.
     */
    public function downloadStatement(Request $request): JsonResponse
    {
        $holder = $this->holder('shareholder.statements');
        [$from, $to] = $this->range($request);

        AuditLog::create([
            'company_id' => $holder->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => 'ShareHolder.statement_downloaded',
            'auditable_type' => $holder->getMorphClass(),
            'auditable_id' => $holder->id,
            'context' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $this->portal->statement($holder, $from, $to)]);
    }

    public function shareholderDirectory(): JsonResponse
    {
        $holder = $this->holder('shareholder.directory');

        return response()->json(['data' => $this->portal->directory((int) $holder->company_id, $holder->id)]);
    }

    public function structure(): JsonResponse
    {
        $holder = $this->holder('shareholder.directory');

        return response()->json(['data' => $this->portal->structure((int) $holder->company_id)]);
    }

    public function bankAccounts(): JsonResponse
    {
        $holder = $this->holder('shareholder.capital.submit');

        return response()->json(['data' => $this->portal->bankAccounts((int) $holder->company_id)]);
    }

    /**
     * Both reserve legs of the shareholder's company (pending first): Finance's HQ reserve → Investment RESERVE A/C, and the
     * owners' Investment RESERVE A/C → OPERATION PRINCIPAL. Shown with the HQ reserve still to send, the Investment RESERVE
     * A/C balance and the OPERATION PRINCIPAL. Shareholders are one of the three approvers of both.
     */
    public function reserveTransfers(Ledger $ledger, CashAccounts $cash): JsonResponse
    {
        $holder = $this->holder('shareholder.portal');
        $companyId = (int) $holder->company_id;

        $transfers = BankTransfer::where('company_id', $companyId)
            ->whereIn('type', [CompanyFunds::RESERVE_TO_INVESTMENT, CompanyFunds::RESERVE_TO_PRINCIPAL])
            ->with(['employee', 'approver', 'rejectedBy', 'journalEntry', 'reversedBy', 'reversalJournalEntry'])
            ->orderByRaw('status = ? DESC', [CompanyFunds::PENDING])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => BankTransferResource::collection($transfers),
            'hq_reserve_balance' => $cash->hqReserve($companyId),
            'investment_reserve_balance' => $ledger->balance($companyId, Account::InvestmentReserve) + 0.0,
            'operation_principal_balance' => $ledger->balance($companyId, Account::Principal) + 0.0,
        ]);
    }

    public function approveReserveTransfer(BankTransfer $bankTransfer, CompanyFunds $funds): JsonResponse
    {
        $this->ensureReserveTransfer($bankTransfer, $this->holder('shareholder.portal'));

        $funds->approve($bankTransfer, $this->currentEmployee());

        return $this->message('Transaction Approved successfully');
    }

    public function rejectReserveTransfer(Request $request, BankTransfer $bankTransfer, CompanyFunds $funds): JsonResponse
    {
        $this->ensureReserveTransfer($bankTransfer, $this->holder('shareholder.portal'));
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $funds->reject($bankTransfer, $validated['reason'], $this->currentEmployee());

        return $this->message('Transaction Rejected successfully');
    }

    private function ensureReserveTransfer(BankTransfer $bankTransfer, ShareHolder $holder): void
    {
        abort_unless(
            in_array($bankTransfer->type, [CompanyFunds::RESERVE_TO_INVESTMENT, CompanyFunds::RESERVE_TO_PRINCIPAL], true)
                && (int) $bankTransfer->company_id === (int) $holder->company_id,
            404,
        );
    }

    /**
     * The signed-in account's own shareholder record (403 without a link), after the permission check.
     */
    private function holder(string $permission): ShareHolder
    {
        $this->authorizeAny($permission);

        $holder = $this->currentEmployee()->shareHolder;
        abort_if($holder === null, 403, 'Your account is not linked to a shareholder.');
        abort_unless((int) $holder->company_id === (int) $this->currentEmployee()->company_id, 403, 'Your account is not linked to a shareholder.');

        return $holder;
    }

    private function ensureOwn(Capital $capital, ShareHolder $holder): void
    {
        abort_unless((int) $capital->share_holder_id === (int) $holder->id && (int) $capital->company_id === (int) $holder->company_id, 404);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : $to->startOfYear();

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProfile(ShareHolder $holder): array
    {
        $account = $this->currentEmployee();

        return [
            'holder_number' => $holder->holder_number,
            'first_name' => $holder->first_name,
            'middle_name' => $holder->middle_name,
            'last_name' => $holder->last_name,
            'name' => $holder->full_name,
            'mobile' => $holder->mobile,
            'email' => $holder->email,
            'gender' => $holder->gender,
            'date_of_birth' => $holder->date_of_birth?->toDateString(),
            'photo_endpoint' => $holder->passport_photo ? 'portal/shareholder/profile/photo?v='.$holder->updated_at?->timestamp : null,
            'login' => $account->phone,
            'account_type' => $account->account_type,
            'registered_at' => $holder->created_at?->toDateString(),
        ];
    }
}
