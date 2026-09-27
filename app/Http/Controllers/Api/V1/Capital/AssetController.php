<?php

namespace App\Http\Controllers\Api\V1\Capital;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Capital\AssetContributionRequest;
use App\Http\Requests\Api\Capital\AssetUpdateRequest;
use App\Models\ApprovalPolicy;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetEvent;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Assets\AssetQrCode;
use App\Services\Assets\AssetRegistry;
use App\Services\Assets\AssetTypes;
use App\Services\CapitalContributions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Capital → Assets: asset capital contributions (Add Capitals with Pay Method ASSET) and the Asset Registry — list, view,
 * descriptive edit, branch transfer, status change, memo revaluation, contribution reversal, QR code / label / scan and
 * documents. capital.view reads; capital.manage changes.
 *
 * C6 maker/checker: a new asset contribution is recorded PENDING (no journal, not counted) and posted when a different
 * authorised user approves it (POST assets/{asset}/approve) or rejected (POST assets/{asset}/reject).
 */
class AssetController extends ApiController
{
    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly AssetTypes $types,
        private readonly AssetQrCode $qr,
    ) {}

    /**
     * Asset form configuration (types, type-specific fields, documents, option lists).
     */
    public function config(): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');

        return response()->json(['data' => $this->types->publicConfig()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');
        $request->validate([
            'asset_type' => ['nullable', Rule::in($this->types->keys())],
            'status' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'integer'],
            'share_holder_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) $request->input('search'));
        $assets = Asset::where('company_id', $this->currentEmployee()->company_id)
            ->with(['shareHolder', 'branch', 'capital.shareTransactions', 'capital.recorder', 'capital.approver', 'capital.rejecter'])
            ->when($request->filled('asset_type'), fn ($query) => $query->where('asset_type', $request->string('asset_type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('share_holder_id'), fn ($query) => $query->where('share_holder_id', $request->integer('share_holder_id')))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('asset_code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")
                ->orWhere('specifications', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $assets->map(fn (Asset $asset): array => $this->present($asset))->values()]);
    }

    public function store(AssetContributionRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee();
        $holder = ShareHolder::where('company_id', $employee->company_id)->findOrFail($request->integer('share_id'));

        $result = $this->registry->contribute($holder, $request->validated(), $employee, $request->input('idempotency_key'));
        $asset = $result['asset']->fresh(['shareHolder', 'branch', 'capital.shareTransactions', 'journalEntry']);

        return $this->message(
            $result['created'] ? "Asset capital recorded successfully — {$asset->asset_code} awaiting approval by another authorised user" : 'Asset capital was already recorded',
            $result['created'] ? 201 : 200,
            ['data' => $this->present($asset)],
        );
    }

    /**
     * Approve a pending asset contribution: posts Dr the fixed-asset account / Cr CAPITAL ACCOUNT. The employee who recorded it
     * cannot approve it (rule 6).
     */
    public function approve(Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);

        $this->registry->approve($asset, $this->currentEmployee());

        return $this->message('Asset Contribution Approved successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    /**
     * Reject a pending asset contribution (nothing was posted).
     */
    public function reject(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->registry->reject($asset, $data['reason'], $this->currentEmployee());

        return $this->message('Asset Contribution Rejected successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    public function show(Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');
        $this->ensureCompany($asset);

        return response()->json(['data' => $this->detail($asset)]);
    }

    /**
     * QR scan landing: the asset summary for a signed-in user with capital access. Unknown tokens and other companies'
     * assets are not found.
     */
    public function scan(string $token): JsonResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');

        $asset = Asset::where('company_id', $this->currentEmployee()->company_id)->where('qr_token', $token)
            ->with(['shareHolder', 'branch', 'capital.shareTransactions'])
            ->first();
        abort_if($asset === null, 404, 'Asset not found.');

        return response()->json(['data' => $this->present($asset)]);
    }

    public function update(AssetUpdateRequest $request, Asset $asset): JsonResponse
    {
        $this->ensureCompany($asset);
        $this->registry->update($asset, $request->validated(), $this->currentEmployee());

        return $this->message('Asset Updated successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    public function transfer(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        $data = $request->validate([
            'to_branch_id' => ['required', 'integer'],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [], ['to_branch_id' => 'destination branch', 'transfer_date' => 'transfer date']);

        $this->registry->transfer($asset, (int) $data['to_branch_id'], CarbonImmutable::parse($data['transfer_date']), $data['reason'], $this->currentEmployee());

        return $this->message('Asset Transferred successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    public function status(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('assets.statuses')))],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->registry->changeStatus($asset, $data['status'], $data['reason'], $this->currentEmployee());

        return $this->message('Asset Status Updated successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    public function revalue(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        $data = $request->validate([
            'new_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'valuation_date' => ['required', 'date', 'before_or_equal:today'],
            'valuation_method' => ['required', Rule::in(array_keys(config('assets.valuation_methods')))],
            'valued_by' => ['nullable', 'string', 'max:191'],
            'valuation_reference' => ['nullable', 'string', 'max:191'],
            'reason' => ['required', 'string', 'max:1000'],
            'contribution_value' => ['prohibited'],
        ], ['contribution_value.prohibited' => 'The contribution value is a permanent snapshot and cannot be revalued.'], ['new_value' => 'new current value']);

        $this->registry->revalue($asset, $data, $this->currentEmployee());

        return $this->message('Asset Revalued successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    public function reverse(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->registry->reverse($asset, $data['reason'], $this->currentEmployee());

        return $this->message('Asset Contribution Reversed successfully', 200, ['data' => $this->detail($asset->fresh())]);
    }

    /**
     * QR code SVG (inline, or as a download with ?download=1).
     */
    public function qr(Request $request, Asset $asset): Response
    {
        $this->authorizeAny('capital.view', 'capital.manage');
        $this->ensureCompany($asset);

        $headers = ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.($asset->asset_code ?? 'asset').'-qr.svg"';
        }

        return response($this->qr->svg($asset, $request->integer('size', 240) > 0 ? min(1000, $request->integer('size', 240)) : 240), 200, $headers);
    }

    public function storeDocument(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        $request->validate([
            'document_type' => ['required', Rule::in($this->types->documentTypes($asset->asset_type))],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('assets.document_mimes')), 'max:'.config('assets.document_max_kb')],
        ], [], ['document_type' => 'document type']);

        $document = $this->registry->addDocument($asset, $request->file('file'), $request->string('document_type')->toString(), $this->currentEmployee());

        return $this->message('Document Uploaded successfully', 201, ['data' => $this->presentDocument($asset, $document->load('uploader'))]);
    }

    public function document(Request $request, Asset $asset, AssetDocument $document): StreamedResponse
    {
        $this->authorizeAny('capital.view', 'capital.manage');
        $this->ensureCompany($asset);
        abort_unless((int) $document->asset_id === $asset->id && Storage::disk(Asset::DISK)->exists($document->path), 404);

        return Storage::disk(Asset::DISK)->response(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }

    public function destroyDocument(Request $request, Asset $asset, AssetDocument $document): JsonResponse
    {
        $this->authorizeAny('capital.manage');
        $this->ensureCompany($asset);
        abort_unless((int) $document->asset_id === $asset->id, 404);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->registry->deleteDocument($document, $this->currentEmployee(), $request->input('reason'));

        return $this->message('Document Deleted successfully');
    }

    private function ensureCompany(Asset $asset): void
    {
        abort_unless((int) $asset->company_id === (int) $this->currentEmployee()->company_id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Asset $asset): array
    {
        $capital = $asset->capital;

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'asset_type' => $asset->asset_type,
            'asset_type_label' => $this->types->label($asset->asset_type),
            'description' => $asset->description,
            'share_holder_id' => $asset->share_holder_id,
            'share_holder' => $asset->shareHolder?->full_name,
            'quantity' => $asset->quantity,
            'unit_value' => (float) $asset->unit_value,
            'contribution_value' => (float) $asset->contribution_value,
            'current_value' => (float) $asset->current_value,
            'branch_id' => $asset->branch_id,
            'branch' => $asset->branch?->name,
            'location' => $asset->location,
            'condition' => $asset->condition,
            'condition_label' => $asset->condition ? (config('assets.conditions')[$asset->condition] ?? $asset->condition) : null,
            'status' => $asset->status,
            'status_label' => match ($asset->status) {
                AssetRegistry::PENDING => 'Pending Approval',
                default => config('assets.statuses')[$asset->status] ?? ucfirst(str_replace('_', ' ', $asset->status)),
            },
            'contributed_on' => $asset->contributed_on?->toDateString(),
            'specifications' => $asset->specifications ?? [],
            'identifiers' => $this->types->identifiers($asset->asset_type, $asset->specifications),
            'ledger_account' => $asset->ledger_account,
            'ledger_account_label' => $asset->ledgerAccount()?->label(),
            'capital_id' => $asset->capital_id,
            'share_transaction_reference' => $capital?->shareTransactions->firstWhere('status', ShareTransaction::COMPLETED)?->reference,
            'qr_endpoint' => "capital/assets/{$asset->id}/qr",
            'scan_url' => $this->qr->url($asset),
            'scan_path' => "/capital/assets/scan/{$asset->qr_token}",
            ...$this->approvalFields($asset),
        ];
    }

    /**
     * Maker/checker fields of the asset's contribution (C6).
     *
     * @return array<string, mixed>
     */
    private function approvalFields(Asset $asset): array
    {
        $capital = $asset->capital;
        $pending = $asset->status === AssetRegistry::PENDING && $capital !== null && $capital->isPending();
        $viewer = $this->currentEmployee();

        return [
            'contribution_status' => $capital?->status,
            'requested_by' => $capital?->recorder?->full_name,
            'approved_by' => $capital?->approver?->full_name,
            'approved_at' => $capital?->approved_at?->toDateTimeString(),
            'rejected_by' => $capital?->rejecter?->full_name,
            'rejected_at' => $capital?->rejected_at?->toDateTimeString(),
            'rejection_reason' => $capital?->rejection_reason,
            ...app(SegregationOfDuties::class)->flags($capital === null ? null : app(CapitalContributions::class)->initiatorIds($capital), $viewer, $pending, Gate::allows('capital.manage'), workflow: ApprovalPolicy::ASSET_CONTRIBUTIONS),
            'can_reject' => $pending && Gate::allows('capital.manage'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Asset $asset): array
    {
        $asset->loadMissing(['shareHolder', 'branch', 'capital.shareTransactions', 'capital.recorder', 'capital.approver', 'capital.rejecter', 'journalEntry', 'recorder', 'events.employee', 'documents.uploader']);
        $capital = $asset->capital;

        return $this->present($asset) + [
            'valuation' => [
                'method' => $asset->valuation_method,
                'method_label' => config('assets.valuation_methods')[$asset->valuation_method] ?? $asset->valuation_method,
                'date' => $asset->valuation_date?->toDateString(),
                'valued_by' => $asset->valued_by,
                'reference' => $asset->valuation_reference,
                'notes' => $asset->valuation_notes,
                'contribution_value' => (float) $asset->contribution_value,
            ],
            'notes' => $asset->notes,
            'journal_entry_id' => $asset->journal_entry_id,
            'journal_reference' => $asset->journalEntry?->reference,
            'recorded_by' => $asset->recorder?->full_name,
            'created_at' => $asset->created_at?->format('Y-m-d H:i:s'),
            'contribution_reversed' => $capital?->isReversed() ?? false,
            'reversal_reason' => $capital?->reversal_reason,
            'events' => $asset->events->sortByDesc(fn (AssetEvent $event): string => $event->occurred_at?->format('Y-m-d H:i:s').sprintf('%010d', $event->id))->map(fn (AssetEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'occurred_at' => $event->occurred_at?->format('Y-m-d H:i:s'),
                'employee' => $event->employee?->full_name,
                'previous_value' => $event->previous_value,
                'new_value' => $event->new_value,
                'amount_before' => $event->amount_before === null ? null : (float) $event->amount_before,
                'amount_after' => $event->amount_after === null ? null : (float) $event->amount_after,
                'reason' => $event->reason,
            ])->values(),
            'documents' => $asset->documents->sortByDesc('id')->map(fn (AssetDocument $document): array => $this->presentDocument($asset, $document))->values(),
            'document_types' => [...$this->types->get($asset->asset_type)['documents'], ['key' => 'other', 'label' => 'Other', 'required' => false]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDocument(Asset $asset, AssetDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime' => $document->mime,
            'size' => $document->size,
            'uploaded_by' => $document->uploader?->full_name,
            'uploaded_at' => $document->created_at?->format('Y-m-d H:i:s'),
            'endpoint' => "capital/assets/{$asset->id}/documents/{$document->id}",
        ];
    }
}
