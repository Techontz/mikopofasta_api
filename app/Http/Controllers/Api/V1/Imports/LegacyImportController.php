<?php

namespace App\Http\Controllers\Api\V1\Imports;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LegacyImport;
use App\Models\LegacyImportRow;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Services\LegacyImports\LegacyExporter;
use App\Services\LegacyImports\LegacyFileFormat;
use App\Services\LegacyImports\LegacyImportService;
use App\Services\LegacyImports\SpreadsheetWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Old-system data: Export File / Import File of the Loan File, Penalty List and Active Salary Advance list.
 *
 * Finance and Admin export, upload and send for approval (legacy_imports.manage); an Admin or the Super Admin who did not
 * upload the file approves or rejects it, maps unmatched customers and may roll an approved import back
 * (legacy_imports.approve). Every import keeps its own audit record: who uploaded, submitted, approved, rejected or rolled
 * it back and when, the file, branch, module, counts and totals.
 */
class LegacyImportController extends ApiController
{
    public function __construct(private readonly LegacyImportService $imports) {}

    /**
     * Import history, newest first (filters: module, status, branch).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('legacy_imports.manage', 'legacy_imports.approve');

        $imports = $this->scoped(LegacyImport::query())
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('branch_id') && $request->input('branch_id') !== 'all', fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->with(['branch', 'uploader', 'submitter', 'approver', 'rejecter', 'rollbacker'])
            ->latest('id')
            ->limit(500)
            ->get();

        return response()->json(['data' => $imports->map(fn (LegacyImport $import): array => $this->summary($import))->values()]);
    }

    /**
     * An empty Excel file in the module's columns, to fill in.
     */
    public function template(Request $request): StreamedResponse
    {
        $this->authorizeAny('legacy_imports.manage');
        $module = $request->validate(['module' => ['required', Rule::in(LegacyImport::MODULES)]])['module'];

        return $this->xlsx(LegacyFileFormat::label($module).' template.xlsx', [LegacyFileFormat::headers($module)]);
    }

    /**
     * Export File: one file of every branch the user can see (no branch to choose). Loan File also takes Active/Default
     * and the year.
     */
    public function export(Request $request, LegacyExporter $exporter): StreamedResponse
    {
        $this->authorizeAny('legacy_imports.manage');
        $data = $request->validate([
            'module' => ['required', Rule::in(LegacyImport::MODULES)],
            'loan_status' => [Rule::requiredIf($request->input('module') === LegacyImport::MODULE_LOAN), 'nullable', Rule::in(['Active', 'Default'])],
            'year' => [Rule::requiredIf($request->input('module') === LegacyImport::MODULE_LOAN), 'nullable', 'integer', 'between:2000,2100'],
        ], ['loan_status.required' => 'Choose the loan status (Active or Default).', 'year.required' => 'Choose the year.']);
        $status = $data['module'] === LegacyImport::MODULE_LOAN ? $data['loan_status'] : null;
        $year = $data['module'] === LegacyImport::MODULE_LOAN ? (int) $data['year'] : null;
        $branchIds = $this->visibleBranches()->modelKeys();

        return $this->xlsx($exporter->fileName($data['module'], $status, $year), $exporter->lines($data['module'], $branchIds, $status, $year));
    }

    /**
     * Import File: upload and validate. Nothing reaches a balance until the import is approved.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAny('legacy_imports.manage');
        $data = $request->validate([
            'module' => ['required', Rule::in(LegacyImport::MODULES)],
            'branch_id' => ['required', 'integer'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt,xlsx'],
        ], ['file.required' => 'Choose the file to import.', 'file.extensions' => 'Upload a CSV (.csv) or Excel (.xlsx) file.', 'branch_id.required' => 'Choose the branch the file belongs to.']);
        $this->assertBranchAccessible((int) $data['branch_id']);

        $import = $this->imports->upload($this->currentEmployee(), Branch::findOrFail($data['branch_id']), $data['module'], $request->file('file'), isset($data['year']) ? (int) $data['year'] : null);

        return $this->message('File uploaded and checked. Review the preview, then send it for approval.', 201, ['data' => $this->detail($import->refresh())]);
    }

    public function show(LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.manage', 'legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);

        return response()->json(['data' => $this->detail($legacyImport)]);
    }

    /**
     * The rows of an import with their status and reasons (filter: status).
     */
    public function rows(Request $request, LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.manage', 'legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);

        $rows = $legacyImport->rows()
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with(['customer.branch', 'mapper', 'imported'])
            ->orderBy('row_number')
            ->get();

        return response()->json(['data' => $rows->map(fn (LegacyImportRow $row): array => [
            'id' => $row->id,
            'row_number' => $row->row_number,
            'status' => $row->status,
            'messages' => $row->messages ?? [],
            'raw' => $row->raw,
            'customer_name' => $row->customer_name,
            'branch_name' => $row->branch_name,
            'phone' => $row->phone,
            'customer' => $row->customer ? [
                'id' => $row->customer->id,
                'full_name' => $row->customer->full_name,
                'customer_number' => $row->customer->customer_number,
                'phone' => $row->customer->phone,
                'branch' => $row->customer->branch?->name,
            ] : null,
            'match_method' => $row->match_method,
            'mapped_by' => $row->mapper?->full_name,
            'loan_amount' => $row->loan_amount === null ? null : (float) $row->loan_amount,
            'interest' => $row->interest === null ? null : (float) $row->interest,
            'total_payable' => $row->total_payable === null ? null : (float) $row->total_payable,
            'collection' => $row->collection === null ? null : (float) $row->collection,
            'paid_amount' => $row->paid_amount === null ? null : (float) $row->paid_amount,
            'remain_amount' => $row->remain_amount === null ? null : (float) $row->remain_amount,
            'penalty_amount' => $row->penalty_amount === null ? null : (float) $row->penalty_amount,
            'fee' => $row->fee === null ? null : (float) $row->fee,
            'duration_type' => $row->duration_type,
            'sessions' => $row->sessions,
            'withdrawal_date' => $row->withdrawal_date?->toDateString(),
            'penalty_date' => $row->penalty_date?->toDateString(),
            'alert_date' => $row->alert_date?->toDateString(),
            'loan_status' => $row->loan_status,
            'monthly' => (object) ($row->monthly ?? []),
            'imported' => $this->importedRecord($row),
        ])->values()]);
    }

    public function submit(LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.manage');
        $this->assertBranchAccessible($legacyImport->branch_id);

        $import = $this->imports->submit($legacyImport, $this->currentEmployee());

        return $this->message('Import sent for approval.', 200, ['data' => $this->detail($import)]);
    }

    public function approve(LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);

        $import = $this->imports->approve($legacyImport, $this->currentEmployee());

        return $this->message('Import approved. '.($import->totals['importable_rows'] ?? 0).' records are now live.', 200, ['data' => $this->detail($import)]);
    }

    public function reject(Request $request, LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Please enter the reason for rejection']);

        $import = $this->imports->reject($legacyImport, $this->currentEmployee(), $data['reason']);

        return $this->message('Import rejected.', 200, ['data' => $this->detail($import)]);
    }

    public function rollback(Request $request, LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Please enter the reason for the rollback']);

        $import = $this->imports->rollback($legacyImport, $this->currentEmployee(), $data['reason']);

        return $this->message('Import rolled back. Its records were removed and its opening journal reversed.', 200, ['data' => $this->detail($import)]);
    }

    public function destroy(LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.manage', 'legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);
        abort_unless($legacyImport->uploaded_by === $this->currentEmployee()->id || $this->currentEmployee()->can('legacy_imports.approve'), 403, 'Only the uploader can delete this draft.');

        $this->imports->discard($legacyImport);

        return $this->message('Draft deleted.');
    }

    /**
     * Map a row by hand to an existing customer, or create a new customer from it (create = true).
     */
    public function mapRow(Request $request, LegacyImport $legacyImport, LegacyImportRow $row): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);
        abort_unless($row->legacy_import_id === $legacyImport->id, 404);
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'create' => ['sometimes', 'boolean'],
        ]);

        $customer = isset($data['customer_id']) ? Customer::query()->where('company_id', $legacyImport->company_id)->find($data['customer_id']) : null;
        abort_if(isset($data['customer_id']) && $customer === null, 422, 'Customer not found.');

        $this->imports->mapRow($row, $this->currentEmployee(), $customer, (bool) ($data['create'] ?? false));

        return $this->message('Row mapped.', 200, ['data' => $this->detail($legacyImport->refresh())]);
    }

    /**
     * Map All, step 1: every unmatched row with the customers it could belong to and the proposed choice.
     */
    public function mapSuggestions(LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);

        return response()->json(['data' => $this->imports->mapSuggestions($legacyImport)]);
    }

    /**
     * Map All, step 2: map several rows at once — each to an existing customer (customer_id) or to a new customer
     * (create = true). One transaction: if one row cannot be mapped, none is.
     */
    public function mapAll(Request $request, LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);
        $data = $request->validate([
            'mappings' => ['required', 'array', 'min:1', 'max:1000'],
            'mappings.*.row_id' => ['required', 'integer', 'distinct'],
            'mappings.*.customer_id' => ['nullable', 'integer', 'required_without:mappings.*.create'],
            'mappings.*.create' => ['sometimes', 'boolean'],
        ], ['mappings.required' => 'Choose a customer, or Create New Customer, for at least one row.']);

        $rows = LegacyImportRow::query()->where('legacy_import_id', $legacyImport->id)->whereKey(array_column($data['mappings'], 'row_id'))->get()->keyBy('id');
        $customers = Customer::query()->where('company_id', $legacyImport->company_id)->whereKey(array_filter(array_column($data['mappings'], 'customer_id')))->get()->keyBy('id');

        $mappings = [];
        foreach ($data['mappings'] as $index => $mapping) {
            $row = $rows->get((int) $mapping['row_id']);
            abort_if($row === null, 422, 'A row of this list does not belong to this import. Reload the page and try again.');
            $create = (bool) ($mapping['create'] ?? false);
            $customer = ! $create && isset($mapping['customer_id']) ? $customers->get((int) $mapping['customer_id']) : null;
            abort_if(! $create && $customer === null, 422, "Row {$row->row_number}: customer not found.");
            $mappings[] = ['row' => $row, 'customer' => $customer, 'create' => $create];
        }

        $result = $this->imports->mapRows($legacyImport, $this->currentEmployee(), $mappings);
        $message = "{$result['mapped']} ".($result['mapped'] === 1 ? 'row' : 'rows').' mapped'.($result['created'] > 0 ? ", {$result['created']} new ".($result['created'] === 1 ? 'customer' : 'customers').' created' : '').'.';

        return $this->message($message, 200, ['data' => $this->detail($legacyImport->refresh())]);
    }

    /**
     * Customers to map a row to: name, phone or customer number (company-wide, so a person registered in another branch
     * can be chosen).
     */
    public function customers(Request $request, LegacyImport $legacyImport): JsonResponse
    {
        $this->authorizeAny('legacy_imports.approve');
        $term = trim($request->string('q')->toString());
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $customers = Customer::query()
            ->where('company_id', $legacyImport->company_id)
            ->where(function ($query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->whereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$like])
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('alternative_phone', 'like', $like)
                    ->orWhere('customer_number', 'like', $like)
                    ->orWhere('customer_code', 'like', $like);
            })
            ->with('branch:id,name')
            ->orderByRaw('branch_id = ? DESC', [$legacyImport->branch_id])
            ->limit(25)
            ->get();

        return response()->json(['data' => $customers->map(fn (Customer $customer): array => [
            'value' => (string) $customer->id,
            'label' => trim("{$customer->full_name} / {$customer->customer_number} / ".($customer->phone ?? 'no phone').' / '.($customer->branch?->name ?? '')),
        ])->values()]);
    }

    /**
     * The exception file: the rows that were not plainly valid, in the module's columns plus row, status and reason.
     */
    public function exceptions(LegacyImport $legacyImport): StreamedResponse
    {
        $this->authorizeAny('legacy_imports.manage', 'legacy_imports.approve');
        $this->assertBranchAccessible($legacyImport->branch_id);

        return $this->xlsx(sprintf('%s exceptions import-%d.xlsx', $legacyImport->moduleLabel(), $legacyImport->id), $this->imports->exceptionLines($legacyImport));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(LegacyImport $import): array
    {
        return [
            'id' => $import->id,
            'module' => $import->module,
            'module_label' => $import->moduleLabel(),
            'branch_id' => $import->branch_id,
            'branch' => $import->branch?->name,
            'loan_status' => $import->loan_status,
            'year' => $import->year,
            'file_name' => $import->file_name,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'valid_rows' => $import->valid_rows,
            'warning_rows' => $import->warning_rows,
            'error_rows' => $import->error_rows,
            'duplicate_rows' => $import->duplicate_rows,
            'unmatched_rows' => $import->unmatched_rows,
            'totals' => $import->totals ?? [],
            'uploaded_by' => $import->uploader?->full_name,
            'uploaded_at' => $import->uploaded_at?->toDateTimeString(),
            'submitted_by' => $import->submitter?->full_name,
            'submitted_at' => $import->submitted_at?->toDateTimeString(),
            'approved_by' => $import->approver?->full_name,
            'approved_at' => $import->approved_at?->toDateTimeString(),
            'rejected_by' => $import->rejecter?->full_name,
            'rejected_at' => $import->rejected_at?->toDateTimeString(),
            'rejection_reason' => $import->rejection_reason,
            'rolled_back_by' => $import->rollbacker?->full_name,
            'rolled_back_at' => $import->rolled_back_at?->toDateTimeString(),
            'rollback_reason' => $import->rollback_reason,
            'journal_reference' => $import->journalEntry?->reference,
        ];
    }

    /**
     * The import with what the signed-in user may do with it now.
     *
     * @return array<string, mixed>
     */
    private function detail(LegacyImport $import): array
    {
        $import->loadMissing(['branch', 'uploader', 'submitter', 'approver', 'rejecter', 'rollbacker', 'journalEntry']);
        $viewer = $this->currentEmployee();
        $canApprove = $viewer->can('legacy_imports.approve');
        $own = $this->imports->ownImportReason($import, $viewer);
        $pending = $import->status === LegacyImport::STATUS_PENDING;

        return $this->summary($import) + [
            'headers' => LegacyFileFormat::headers($import->module),
            'can_submit' => $import->isEditable() && $viewer->can('legacy_imports.manage'),
            'can_approve' => $pending && $canApprove && $own === null,
            'approve_blocked_reason' => $pending && $canApprove ? $own : null,
            'can_map' => in_array($import->status, [LegacyImport::STATUS_DRAFT, LegacyImport::STATUS_PENDING, LegacyImport::STATUS_REJECTED], true) && $canApprove,
            'can_rollback' => $import->status === LegacyImport::STATUS_APPROVED && $canApprove,
            'can_delete' => $import->status === LegacyImport::STATUS_DRAFT && ($import->uploaded_by === $viewer->id || $canApprove),
        ];
    }

    /**
     * @return array{type: string, id: int, label: string, link: string|null}|null
     */
    private function importedRecord(LegacyImportRow $row): ?array
    {
        return match (true) {
            $row->imported instanceof Loan => ['type' => 'loan', 'id' => $row->imported->id, 'label' => 'Loan '.($row->imported->reference_number ?? $row->imported->loan_number), 'link' => '/loans/'.$row->imported->id],
            $row->imported instanceof Penalty => ['type' => 'penalty', 'id' => $row->imported->id, 'label' => 'Penalty #'.$row->imported->id.($row->imported->loan_id ? ' (on loan)' : ''), 'link' => '/penalties'],
            $row->imported instanceof SalaryAdvance => ['type' => 'salary_advance', 'id' => $row->imported->id, 'label' => 'Salary advance #'.$row->imported->id, 'link' => '/salary-advance/active'],
            default => null,
        };
    }

    /**
     * @param  list<list<string>>  $lines
     */
    private function xlsx(string $fileName, array $lines): StreamedResponse
    {
        $content = SpreadsheetWriter::xlsx($lines, pathinfo($fileName, PATHINFO_FILENAME));

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $fileName, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
