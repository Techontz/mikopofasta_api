<?php

namespace App\Services\Crm;

use App\Models\AuditLog;
use App\Models\CrmInteraction;
use App\Models\CrmTicket;
use App\Models\Customer;
use App\Models\CustomerUpdateRequest;
use App\Models\Employee;
use App\Services\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CRM department's dashboard ("Customer Care Overview"), in the viewer's branches (CRM sees every branch):
 *
 *  - Total Customers: registered customers (approved, or registered where no approval was required), against the total at the
 *    end of last month;
 *  - Pending KYC: registrations waiting for KYC approval now; the change compares registrations submitted this month and last;
 *  - KYC Approved / KYC Rejected: decisions taken this month against last month (rejections are read from the audit trail,
 *    since a returned registration keeps no rejection date);
 *  - Open Complaints / Pending Requests: CRM cases of each type still being handled; the change compares cases opened this
 *    month and last;
 *  - Follow-ups Due Today: follow-ups due today or overdue and not done; the change compares follow-ups set for today and
 *    for yesterday;
 *  - Customer Updates: changes to customers' details applied this month (approved branch requests and CRM / Admin changes);
 *  - the sidebar counts: what is waiting for CRM (KYC, requests, follow-ups, complaints, branch changes to approve).
 */
class CustomerCareDashboard
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Employee $viewer): array
    {
        $today = CarbonImmutable::today();
        $month = [$today->startOfMonth(), $today->endOfMonth()->endOfDay()];
        $lastMonth = [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->endOfDay()];

        $customers = fn (): Builder => $this->access->scope(Customer::query(), $viewer);
        $cases = fn (): Builder => $this->access->scope(CrmTicket::query(), $viewer);
        $followUps = fn (): Builder => $this->access->scope(CrmInteraction::query(), $viewer)->whereNotNull('follow_up_date');
        $updates = fn (): Builder => $this->access->scope(CustomerUpdateRequest::query(), $viewer);
        $rejections = fn (array $range): int => AuditLog::where('company_id', $viewer->company_id)
            ->where('action', 'Customer.rejected')
            ->where('auditable_type', (new Customer)->getMorphClass())
            ->whereIn('auditable_id', $customers()->select('id'))
            ->whereBetween('created_at', $range)
            ->count();

        $registered = fn (): Builder => $customers()->whereIn('approval_status', ['approved', 'not_required']);
        $totalCustomers = $registered()->count();
        $pendingKyc = $customers()->where('approval_status', 'pending')->count();
        $approved = fn (array $range): int => $customers()->whereBetween('approved_at', $range)->count();
        $opened = fn (string $type, array $range): int => $cases()->where('type', $type)->whereBetween('created_at', $range)->count();
        $openCases = fn (string $type): int => $cases()->where('type', $type)->whereIn('status', CrmTicket::OPEN_STATUSES)->count();
        $followUpsDue = $followUps()->whereNull('follow_up_done_at')->whereDate('follow_up_date', '<=', $today)->count();
        $applied = fn (array $range): int => $updates()->where('status', CustomerUpdateRequest::STATUS_APPROVED)->whereBetween('decided_at', $range)->count();
        $openComplaints = $openCases('complaint');
        $pendingRequests = $openCases('request');

        return [
            'cards' => [
                'total_customers' => ['value' => $totalCustomers, 'change' => $this->change($totalCustomers, $registered()->where('created_at', '<', $month[0])->count())],
                'pending_kyc' => ['value' => $pendingKyc, 'change' => $this->change(
                    $customers()->where('approval_status', 'pending')->whereBetween('created_at', $month)->count(),
                    $customers()->whereNotIn('approval_status', ['not_required'])->whereBetween('created_at', $lastMonth)->count(),
                )],
                'kyc_approved' => ['value' => $approved($month), 'change' => $this->change($approved($month), $approved($lastMonth))],
                'kyc_rejected' => ['value' => $rejections($month), 'change' => $this->change($rejections($month), $rejections($lastMonth))],
                'open_complaints' => ['value' => $openComplaints, 'change' => $this->change($opened('complaint', $month), $opened('complaint', $lastMonth))],
                'pending_requests' => ['value' => $pendingRequests, 'change' => $this->change($opened('request', $month), $opened('request', $lastMonth))],
                'follow_ups_due' => ['value' => $followUpsDue, 'change' => $this->change(
                    $followUps()->whereDate('follow_up_date', $today)->count(),
                    $followUps()->whereDate('follow_up_date', $today->subDay())->count(),
                )],
                'customer_updates' => ['value' => $applied($month), 'change' => $this->change($applied($month), $applied($lastMonth))],
            ],
            'cases_by_category' => $this->casesByCategory($cases()->whereBetween('created_at', $month)),
            'case_status' => collect(CrmTicket::STATUSES)
                ->map(fn (string $label, string $status): array => ['status' => $status, 'label' => ucwords(strtolower($label)), 'count' => $cases()->where('status', $status)->count()])
                ->values()->all(),
            'pending_kyc' => $customers()->where('approval_status', 'pending')->oldest('id')->limit(5)->get()
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'customer_code' => $customer->customer_code,
                    'name' => $customer->full_name,
                    'phone' => $customer->phone,
                    'submitted_on' => $customer->created_at?->toDateString(),
                ])->all(),
            'recent_updates' => $updates()->where('status', CustomerUpdateRequest::STATUS_APPROVED)->with(['customer', 'decider'])->latest('decided_at')->latest('id')->limit(5)->get()
                ->flatMap(fn (CustomerUpdateRequest $request): array => array_map(fn (array $change): array => [
                    'request_id' => $request->id,
                    'customer_id' => $request->customer_id,
                    'customer_code' => $request->customer?->customer_code,
                    'name' => $request->customer?->full_name,
                    'field' => $change['field'],
                    'old' => $change['old'],
                    'new' => $change['new'],
                    'updated_on' => $request->decided_at?->toDateString(),
                    'by' => $request->decider?->full_name,
                ], $request->changes))
                ->take(5)->values()->all(),
            'badges' => $this->badges($viewer),
            'kyc_pipeline' => $this->kycPipeline($viewer, $today),
        ];
    }

    /**
     * Pending Approvals / Applications of the CRM dashboard (enhancement §17): new registrations awaiting KYC approval and
     * changes to approved profiles awaiting re-approval, together. Pending stays until decided whatever its age; Approved
     * and Rejected Today use the decision day; Customer Applications Today the submission day. A change CRM or Admin made
     * themselves (applied at once, requester = decider) is not an application.
     *
     * @return list<array{key: string, label: string, count: int, registrations: int, profile_changes: int, link: string}>
     */
    private function kycPipeline(Employee $viewer, CarbonImmutable $today): array
    {
        $day = [$today->startOfDay(), $today->endOfDay()];
        $customers = fn (): Builder => $this->access->scope(Customer::query(), $viewer);
        $reviewed = fn (): Builder => $this->access->scope(CustomerUpdateRequest::query(), $viewer)
            ->where(fn (Builder $query) => $query->whereNull('decided_by')->orWhereColumn('decided_by', '!=', 'requested_by'));
        $rejectedRegistrations = AuditLog::where('company_id', $viewer->company_id)
            ->where('action', 'Customer.rejected')
            ->where('auditable_type', (new Customer)->getMorphClass())
            ->whereIn('auditable_id', $customers()->select('id'))
            ->whereBetween('created_at', $day)
            ->count();

        $item = fn (string $key, string $label, int $registrations, int $changes, string $link): array => [
            'key' => $key, 'label' => $label, 'count' => $registrations + $changes, 'registrations' => $registrations, 'profile_changes' => $changes, 'link' => $link,
        ];

        return [
            $item('pending', 'Pending Applications',
                $customers()->where('approval_status', 'pending')->count(),
                $reviewed()->where('status', CustomerUpdateRequest::STATUS_PENDING)->count(),
                '/customers/approvals'),
            $item('approved_today', 'Approved Today',
                $customers()->whereBetween('approved_at', $day)->count(),
                $reviewed()->where('status', CustomerUpdateRequest::STATUS_APPROVED)->whereBetween('decided_at', $day)->count(),
                '/customers?approval_status=approved'),
            $item('rejected_today', 'Rejected Today',
                $rejectedRegistrations,
                $reviewed()->where('status', CustomerUpdateRequest::STATUS_REJECTED)->whereBetween('decided_at', $day)->count(),
                '/customers?approval_status=rejected'),
            $item('applications_today', 'Customer Applications Today',
                $customers()->whereNotIn('approval_status', ['not_required'])->whereBetween('created_at', $day)->count(),
                $reviewed()->whereBetween('created_at', $day)->count(),
                '/customers/approvals'),
        ];
    }

    /**
     * What waits for CRM, for the sidebar counts: registrations to approve, open complaints and requests, follow-ups due today
     * or overdue, and branch changes to customers' details to approve.
     *
     * @return array{kyc: int, complaints: int, requests: int, follow_ups: int, updates: int}
     */
    public function badges(Employee $viewer): array
    {
        $cases = fn (string $type): int => $this->access->scope(CrmTicket::query(), $viewer)->where('type', $type)->whereIn('status', CrmTicket::OPEN_STATUSES)->count();

        return [
            'kyc' => $this->access->scope(Customer::query(), $viewer)->where('approval_status', 'pending')->count(),
            'complaints' => $cases('complaint'),
            'requests' => $cases('request'),
            'follow_ups' => $this->access->scope(CrmInteraction::query(), $viewer)->whereNotNull('follow_up_date')->whereNull('follow_up_done_at')->whereDate('follow_up_date', '<=', CarbonImmutable::today())->count(),
            'updates' => $this->access->scope(CustomerUpdateRequest::query(), $viewer)->where('status', CustomerUpdateRequest::STATUS_PENDING)->count(),
        ];
    }

    /**
     * @return array{total: int, rows: list<array{category: string, label: string, count: int, percent: float}>}
     */
    private function casesByCategory(Builder $cases): array
    {
        $counts = $cases->toBase()->selectRaw('category, COUNT(*) AS total')->groupBy('category')->pluck('total', 'category')->map(fn ($count): int => (int) $count);
        $total = $counts->sum();

        return [
            'total' => $total,
            'rows' => $counts->sortDesc()->map(fn (int $count, string $category): array => [
                'category' => $category,
                'label' => CrmTicket::categoryLabel($category),
                'count' => $count,
                'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
            ])->values()->all(),
        ];
    }

    /**
     * Percentage change from the previous figure; null when there was nothing to compare with.
     */
    private function change(float|int $current, float|int $previous): ?float
    {
        return $previous == 0 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }
}
