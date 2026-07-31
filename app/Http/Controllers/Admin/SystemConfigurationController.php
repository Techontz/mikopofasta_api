<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Actions\ManageNotificationTemplateAction;
use App\Domain\Admin\Actions\ManageRepaymentScheduleAction;
use App\Domain\Admin\Actions\UpdateInterestFormulaAction;
use App\Domain\Admin\DTOs\NotificationTemplateData;
use App\Domain\Admin\DTOs\RepaymentScheduleData;
use App\Domain\Admin\Policies\SystemConfigurationPolicy;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAuditLogRequest;
use App\Http\Requests\Admin\NotificationTemplateRequest;
use App\Http\Requests\Admin\RepaymentScheduleRequest;
use App\Http\Requests\Admin\UpdateInterestFormulaRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\InterestFormulaResource;
use App\Http\Resources\NotificationTemplateResource;
use App\Http\Resources\RepaymentScheduleResource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InterestFormula;
use App\Models\Loan;
use App\Models\NotificationTemplate;
use App\Models\RepaymentSchedule;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Interest Formulas, Repayment Schedules, Notification Templates,
 * Audit Logs.
 *
 * One controller because the four screens share a policy and a shape: reference
 * data that is open to read and gated to change. The audit trail is the
 * exception and has its own permission — see SystemConfigurationPolicy.
 *
 * See docs/modules/administration.md.
 */
final class SystemConfigurationController extends Controller
{
    // -----------------------------------------------------------------------
    // Interest formulas — the fixed three
    //
    // Reading them is LoanConfigurationController's: the loan application form
    // is the main caller and they are its lookups. Only the write lives here.
    // -----------------------------------------------------------------------

    /**
     * PUT /api/v1/interest-formulas/{formula}
     *
     * Name and description only. There is no store and no destroy: the code is
     * a branch in the interest engine, so a fourth formula is a code change.
     */
    public function updateInterestFormula(
        UpdateInterestFormulaRequest $request,
        InterestFormula $formula,
        UpdateInterestFormulaAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $updated = $action->handle(
            $formula,
            (string) $request->validated('name'),
            $request->validated('description'),
            $this->actor($request),
        );

        // Counted so the row the screen swaps in matches the ones beside it.
        return ApiResponse::data(new InterestFormulaResource($updated->loadCount('products')));
    }

    // -----------------------------------------------------------------------
    // Repayment schedules — read alongside the formulas, written here
    // -----------------------------------------------------------------------

    /** POST /api/v1/repayment-schedules */
    public function storeRepaymentSchedule(
        RepaymentScheduleRequest $request,
        ManageRepaymentScheduleAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $schedule = $action->create(
            RepaymentScheduleData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(
            new RepaymentScheduleResource($schedule->loadCount(['loans', 'products'])),
            status: Response::HTTP_CREATED,
        );
    }

    /** PUT /api/v1/repayment-schedules/{schedule} */
    public function updateRepaymentSchedule(
        RepaymentScheduleRequest $request,
        RepaymentSchedule $schedule,
        ManageRepaymentScheduleAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $updated = $action->update(
            $schedule,
            RepaymentScheduleData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new RepaymentScheduleResource($updated->loadCount(['loans', 'products'])));
    }

    /** DELETE /api/v1/repayment-schedules/{schedule} */
    public function destroyRepaymentSchedule(
        Request $request,
        RepaymentSchedule $schedule,
        ManageRepaymentScheduleAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $action->delete($schedule, $this->actor($request));

        return ApiResponse::data(['message' => "{$schedule->name} removed."]);
    }

    // -----------------------------------------------------------------------
    // Notification templates
    // -----------------------------------------------------------------------

    /** GET /api/v1/notification-templates?trigger_event=&channel=&active= */
    public function notificationTemplates(Request $request): JsonResponse
    {
        $templates = NotificationTemplate::query()
            ->with('editor')
            ->when(
                $request->filled('trigger_event'),
                fn (Builder $q) => $q->where('trigger_event', $request->string('trigger_event')),
            )
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')))
            ->when($request->has('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderBy('trigger_event')
            ->orderBy('channel')
            ->get();

        return ApiResponse::data(
            NotificationTemplateResource::collection($templates),
            meta: [
                /*
                 * The vocabulary the editor needs to offer, rather than the
                 * frontend keeping its own copy. Events and their placeholders
                 * are decided here — a copy on the other side would be a second
                 * list to keep in step, and the failure mode is a template
                 * saved against a placeholder the server then rejects.
                 */
                'triggerEvents' => array_map(
                    static fn (NotificationTriggerEvent $e): array => [
                        'value' => $e->value,
                        'label' => $e->label(),
                        'placeholders' => $e->placeholders(),
                    ],
                    NotificationTriggerEvent::cases(),
                ),
                'channels' => array_map(
                    static fn (NotificationChannel $c): array => [
                        'value' => $c->value,
                        'label' => $c->label(),
                        'hasSubject' => $c->hasSubject(),
                    ],
                    NotificationChannel::cases(),
                ),
            ],
        );
    }

    /** POST /api/v1/notification-templates */
    public function storeNotificationTemplate(
        NotificationTemplateRequest $request,
        ManageNotificationTemplateAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $template = $action->create(
            NotificationTemplateData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new NotificationTemplateResource($template), status: Response::HTTP_CREATED);
    }

    /** PUT /api/v1/notification-templates/{template} */
    public function updateNotificationTemplate(
        NotificationTemplateRequest $request,
        NotificationTemplate $template,
        ManageNotificationTemplateAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $updated = $action->update(
            $template,
            NotificationTemplateData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new NotificationTemplateResource($updated));
    }

    /** DELETE /api/v1/notification-templates/{template} */
    public function destroyNotificationTemplate(
        Request $request,
        NotificationTemplate $template,
        ManageNotificationTemplateAction $action,
    ): JsonResponse {
        $this->authorizeManage($request);

        $action->delete($template, $this->actor($request));

        return ApiResponse::data(['message' => "{$template->name} removed."]);
    }

    // -----------------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------------

    /**
     * GET /api/v1/audit-logs
     *
     * Read-only, and there is no other verb: §2 makes the audit trail
     * append-only, and an endpoint that could edit or delete a row would defeat
     * the only thing it is for.
     */
    public function auditLogs(IndexAuditLogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $this->authorizeAuditRead($request, $filters);

        $logs = AuditLog::query()
            ->with('user')
            ->when(isset($filters['action']), fn (Builder $q) => $q->where('action', $filters['action']))
            ->when(isset($filters['user_id']), fn (Builder $q) => $q->where('user_id', $filters['user_id']))
            ->when(
                isset($filters['auditable_id']),
                fn (Builder $q) => $q->where('auditable_id', $filters['auditable_id']),
            )
            ->when(
                isset($filters['auditable_type']),
                /*
                 * Matches either spelling. The column stores the fully-qualified
                 * class; the screen shows the short name, and someone filtering
                 * from what they can see should not have to know the namespace.
                 */
                fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                    $type = (string) $filters['auditable_type'];

                    $inner->where('auditable_type', $type)
                        ->orWhere('auditable_type', 'like', '%\\\\'.$type);
                }),
            )
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when(isset($filters['search']), fn (Builder $q) => $this->searchLogs($q, (string) $filters['search']))
            // Newest first: an audit trail is read from the most recent event
            // backwards, which is how anyone investigating actually works.
            ->latest('created_at')
            ->latest('id');

        return ApiResponse::paginated(
            $logs->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            AuditLogResource::class,
            [
                // What the filter dropdown offers: the actions actually present,
                // not the enum. A vocabulary that is extensible by design cannot
                // be enumerated from the enum without going stale.
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
            ],
        );
    }

    /**
     * @param Builder<AuditLog> $query
     * @return Builder<AuditLog>
     */
    private function searchLogs(Builder $query, string $term): Builder
    {
        $like = '%'.trim($term).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('action', 'like', $like)
                ->orWhere('auditable_type', 'like', $like)
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like));
        });
    }

    /**
     * Who may read the trail, and how much of it.
     *
     * The whole trail needs `audit.view` or `admin.org_settings` — it records
     * every module's activity, so it reveals more than any single screen it
     * summarises.
     *
     * A trail **pinned to one record** is a different question. The audit panel
     * on a customer's profile and a loan's detail page shows that record's own
     * history — who approved it, when it was disbursed — which is what the rest
     * of the page already says. Requiring the global grant to see it would hide
     * a loan's history from the officer working the loan, which is neither what
     * §2 is protecting nor what the screens are for.
     *
     * So a pinned read is authorised against the **record's own policy**: if you
     * may view the loan, you may read its history. The types are enumerated
     * rather than resolved from the string, because letting a caller name any
     * class would turn this into a way to probe for models with permissive
     * policies.
     *
     * @param array<string, mixed> $filters
     */
    private function authorizeAuditRead(Request $request, array $filters): void
    {
        $actor = $this->actor($request);

        if (app(SystemConfigurationPolicy::class)->viewAudit($actor)) {
            return;
        }

        $record = $this->pinnedRecord($filters);

        abort_if($record === null, Response::HTTP_FORBIDDEN);

        Gate::forUser($actor)->authorize('view', $record);

        /*
         * And the branch, which the policy deliberately does not answer.
         *
         * LoanPolicy::view says so in its own docblock: §13 scope is
         * BranchScopeGuard's, because a scope failure must surface as
         * BRANCH_SCOPE_VIOLATION and be audited, which a yes/no policy cannot
         * do. Without this, `loans.view` alone would let an officer read the
         * history of a loan at a branch they cannot see the loan itself at.
         */
        app(BranchScopeGuard::class)->authorizeBranchId(
            $actor,
            $record->getAttribute('branch_id'),
            $record::class,
        );
    }

    /**
     * The single record a filtered trail is pinned to, if it is pinned to one.
     *
     * Null unless both `auditable_type` and `auditable_id` are given and the
     * type is one of the two the record-level panels ask for. Anything else
     * falls back to needing the global grant.
     *
     * @param array<string, mixed> $filters
     */
    private function pinnedRecord(array $filters): ?Model
    {
        if (! isset($filters['auditable_type'], $filters['auditable_id'])) {
            return null;
        }

        /** @var array<string, class-string<Model>> $auditable */
        $auditable = [
            'Customer' => Customer::class,
            Customer::class => Customer::class,
            'Loan' => Loan::class,
            Loan::class => Loan::class,
        ];

        $model = $auditable[(string) $filters['auditable_type']] ?? null;

        return $model === null ? null : $model::query()->find($filters['auditable_id']);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            app(SystemConfigurationPolicy::class)->manage($this->actor($request)),
            Response::HTTP_FORBIDDEN,
        );
    }
}
