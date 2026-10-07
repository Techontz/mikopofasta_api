<?php

namespace App\Http\Controllers\Api\V1\Sms;

use App\Enums\LoanStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Integrations\Sms\ReportsSmsBalance;
use App\Integrations\Sms\SmsGateway;
use App\Models\Customer;
use App\Models\Group;
use App\Models\SmsContactGroup;
use App\Models\SmsContactGroupMember;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * SMS Centre → Send SMS (announcements typed or loaded from a draft, sent by hand) and SMS Log.
 *
 * Recipients are one of: customers (filtered by branch, customer status, customer group and loan status), a contact group,
 * or phone numbers typed in. Numbers are de-duplicated and anything that is not a phone number is skipped. Every SMS is
 * recorded as pending at once and delivered right after the response, so a large send never times out the page; the log
 * then shows each one as sent or failed.
 */
class SmsController extends ApiController
{
    public const MAX_RECIPIENTS = 5000;

    public function options(): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $companyId = (int) $this->currentEmployee()->company_id;

        return response()->json(['data' => [
            'customer_statuses' => collect(Customer::STATUSES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'customer_groups' => Group::where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])->map(fn (Group $group): array => ['value' => (string) $group->id, 'label' => $group->name]),
            'contact_groups' => SmsContactGroup::where('company_id', $companyId)->withCount('members')->orderBy('name')->get()
                ->map(fn (SmsContactGroup $group): array => ['value' => (string) $group->id, 'label' => "{$group->name} ({$group->members_count})"]),
            'drafts' => SmsTemplate::where('company_id', $companyId)->where('type', SmsTemplate::TYPE_ANNOUNCEMENT)->orderBy('name')->get(['id', 'name', 'body']),
            'variables' => SmsTemplates::ANNOUNCEMENT_VARIABLES,
        ]]);
    }

    /**
     * SMS credits left on the provider account; null when the driver cannot tell (log driver) or the provider did not answer.
     */
    public function balance(SmsGateway $gateway): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        if (! $gateway instanceof ReportsSmsBalance) {
            return response()->json(['data' => ['balance' => null, 'error' => 'Live SMS sending is not set up (SMS_DRIVER='.config('integrations.sms.driver').').']]);
        }

        try {
            return response()->json(['data' => ['balance' => $gateway->balance(), 'error' => null]]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['data' => ['balance' => null, 'error' => 'Could not read the SMS balance from the provider.']]);
        }
    }

    /**
     * How many people the send would reach and how the first message reads — shown before staff confirm.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $data = $this->validated($request, messageRequired: false);
        ['recipients' => $recipients, 'invalid' => $invalid] = $this->recipients($data);
        $first = $recipients->first();

        return response()->json(['data' => [
            'count' => $recipients->count(),
            'invalid' => $invalid,
            'sample' => $first && filled($data['message'] ?? null) ? $this->messageFor((string) $data['message'], $first) : null,
            'sample_phone' => $first['phone'] ?? null,
        ]]);
    }

    public function send(Request $request, SmsSender $sender): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $data = $this->validated($request, messageRequired: true);
        ['recipients' => $recipients, 'invalid' => $invalid] = $this->recipients($data);

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['audience' => 'No recipient with a valid phone number matches your selection.']);
        }
        if ($recipients->count() > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['audience' => 'Too many recipients ('.number_format($recipients->count()).'). Send to at most '.number_format(self::MAX_RECIPIENTS).' at a time: narrow the branch or status.']);
        }

        $employee = $this->currentEmployee();
        $batch = 'announcement:'.now()->format('ymdHis').Str::lower(Str::random(4));
        $logs = $recipients->map(fn (array $recipient): SmsLog => $sender->record(
            (int) $employee->company_id,
            $recipient['phone'],
            $this->messageFor((string) $data['message'], $recipient),
            'announcement',
            ['customer_id' => $recipient['customer_id'], 'employee_id' => $employee->id, 'reference' => $batch],
        ));

        $ids = $logs->pluck('id')->all();
        dispatch(function () use ($ids): void {
            $sender = app(SmsSender::class);
            SmsLog::whereKey($ids)->where('status', SmsSender::STATUS_PENDING)->orderBy('id')->each(fn (SmsLog $log) => $sender->deliver($log));
        })->afterResponse();

        $skipped = $invalid > 0 ? " ({$invalid} invalid numbers skipped)" : '';

        return $this->message("SMS is being sent to {$logs->count()} recipients{$skipped}", 201, ['count' => $logs->count(), 'invalid' => $invalid]);
    }

    /**
     * SMS Log: every SMS the system sent (automatic and announcements), newest first.
     */
    public function logs(Request $request): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $request->validate([
            'category' => ['nullable', Rule::in(['all', 'payment', 'reminder', 'overdue', 'announcement'])],
            'status' => ['nullable', Rule::in(['all', SmsSender::STATUS_PENDING, SmsSender::STATUS_SENT, SmsSender::STATUS_FAILED])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = $this->applyFilters(SmsLog::where('sms_logs.company_id', $this->currentEmployee()->company_id), $request->merge(['branch_id' => null]), 'sms_logs.created_at')
            ->when($request->filled('category') && $request->input('category') !== 'all', fn ($query) => $query->where('category', $request->string('category')->toString()))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with(['customer:id,first_name,middle_name,last_name', 'employee:id,first_name,last_name'])
            ->latest('id')
            ->limit(2000)
            ->get();

        return response()->json(['data' => $logs->map(fn (SmsLog $log): array => [
            'id' => $log->id,
            'date' => $log->created_at?->format('d/m/Y H:i'),
            'phone' => $log->phone,
            'customer' => $log->customer?->full_name,
            'message' => $log->message,
            'category' => $log->category ?? 'other',
            'status' => $log->status,
            'error' => $log->error,
            'sent_by' => $log->employee ? trim("{$log->employee->first_name} {$log->employee->last_name}") : 'System',
        ])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $messageRequired): array
    {
        return $request->validate([
            'audience' => ['required', Rule::in(['customers', 'contact_group', 'numbers'])],
            'message' => [$messageRequired ? 'required' : 'nullable', 'string', 'max:480'],
            'branch_id' => ['nullable'],
            'customer_status' => ['nullable', Rule::in(['all', ...array_keys(Customer::STATUSES)])],
            'group_id' => ['nullable', 'integer'],
            'loan_status' => ['nullable', Rule::in(['any', 'active', 'overdue', 'none'])],
            'contact_group_id' => ['required_if:audience,contact_group', 'nullable', 'integer'],
            'numbers' => ['required_if:audience,numbers', 'nullable', 'string', 'max:60000'],
        ], ['numbers.required_if' => 'Type at least one phone number.', 'contact_group_id.required_if' => 'Select a contact group.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{recipients: Collection<int, array{phone: string, name: string|null, customer_id: int|null}>, invalid: int}
     */
    private function recipients(array $data): array
    {
        $raw = match ($data['audience']) {
            'customers' => $this->customerRecipients($data),
            'contact_group' => $this->contactGroupRecipients((int) $data['contact_group_id']),
            default => collect(preg_split('/[\s,;]+/', (string) $data['numbers'], -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->map(fn (string $phone): array => ['phone' => $phone, 'name' => null, 'customer_id' => null]),
        };

        $valid = $raw->map(fn (array $recipient): array => ['phone' => SmsSender::normalisePhone($recipient['phone'])] + $recipient)
            ->filter(fn (array $recipient): bool => $recipient['phone'] !== null);

        return ['recipients' => $valid->unique('phone')->values(), 'invalid' => $raw->count() - $valid->count()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, array{phone: string, name: string|null, customer_id: int|null}>
     */
    private function customerRecipients(array $data): Collection
    {
        $branch = (string) ($data['branch_id'] ?? 'all');
        if ($branch !== 'all' && $branch !== '') {
            $this->assertBranchAccessible((int) $branch);
        }
        $status = $data['customer_status'] ?? 'all';
        $loanStatus = $data['loan_status'] ?? 'any';
        $statuses = fn (array $list): array => array_map(fn (LoanStatus $value): string => $value->value, $list);

        return $this->scoped(Customer::query()->where('company_id', $this->currentEmployee()->company_id))
            ->when($branch !== 'all' && $branch !== '', fn ($query) => $query->where('branch_id', (int) $branch))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(filled($data['group_id'] ?? null), fn ($query) => $query->where('group_id', (int) $data['group_id']))
            ->when($loanStatus === 'active', fn ($query) => $query->whereHas('loans', fn ($loans) => $loans->whereIn('status', $statuses(LoanStatus::repayable()))))
            ->when($loanStatus === 'overdue', fn ($query) => $query->whereHas('loans', fn ($loans) => $loans->whereIn('status', $statuses([LoanStatus::Overdue, LoanStatus::Default]))))
            ->when($loanStatus === 'none', fn ($query) => $query->whereDoesntHave('loans', fn ($loans) => $loans->whereIn('status', $statuses(LoanStatus::repayable()))))
            ->whereNotNull('phone')
            ->orderBy('id')
            ->limit(self::MAX_RECIPIENTS + 1)
            ->get(['id', 'first_name', 'phone'])
            ->map(fn (Customer $customer): array => ['phone' => (string) $customer->phone, 'name' => $customer->first_name, 'customer_id' => $customer->id]);
    }

    /**
     * @return Collection<int, array{phone: string, name: string|null, customer_id: int|null}>
     */
    private function contactGroupRecipients(int $groupId): Collection
    {
        $group = SmsContactGroup::where('company_id', $this->currentEmployee()->company_id)->find($groupId);
        if ($group === null) {
            throw ValidationException::withMessages(['contact_group_id' => 'Select a valid contact group.']);
        }

        return $group->members()->get()->map(fn (SmsContactGroupMember $member): array => ['phone' => $member->phone, 'name' => $member->name, 'customer_id' => null]);
    }

    /**
     * @param  array{phone: string, name: string|null, customer_id: int|null}  $recipient
     */
    private function messageFor(string $message, array $recipient): string
    {
        return SmsTemplates::fill($message, ['name' => $recipient['name'] ?? 'Mteja', 'company' => $this->currentCompany()->name]);
    }
}
