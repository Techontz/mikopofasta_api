<?php

namespace App\Services\LegacyImports;

use App\Console\Commands\CreateHistoricalCustomers;
use App\Enums\Account;
use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\LegacyImport;
use App\Models\LegacyImportRow;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Models\WriteOffRequest;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Customers\CustomerRegistrar;
use App\Services\Customers\KycStatusCalculator;
use App\Services\Ledger;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Brings the old system's outstanding balances in as opening balances: Loan File → outstanding principal, Penalty List →
 * outstanding penalty, Active Salary Advance → outstanding salary advance — three separate debts, never mixed.
 *
 * Upload → validate → preview → submit → approve (someone other than the uploader) → live. Nothing changes a balance before
 * approval. On approval each importable row becomes a loan, penalty or salary advance flagged `is_legacy_opening`:
 *
 *  - a loan keeps the printed Loan Amount (old principal + old interest) as amount_approved and total_payable, and what
 *    the old system had collected as opening_paid_principal = Loan Amount − Remain Amount, so its outstanding principal is
 *    exactly the printed Remain Amount. The old system cannot split principal , so no interest is invented
 *    (interest_amount = 0); the January–September columns stay history on the import row and reduce nothing;
 *  - a penalty is its own debt, on no loan's principal. It is attached to the customer's old-system loan only when there
 *    is exactly one it can belong to, so a loan repayment collects it in the usual Principal → Penalty order;
 *  - a salary advance keeps Loan Amount / Principal + Interest, with opening_paid = Principal + Interest − Remain Amount.
 *
 * One balanced opening journal per import brings the receivables into the books — Dr LOAN RECEIVABLE (the Remain Amounts)
 * or Dr SALARY ADVANCE RECEIVABLE (the principal still owed) / Cr OLD SYSTEM OPENING BALANCE — so repayments credit a
 * receivable that exists and no cash appears to have been received today. Old-system penalties are cash basis like every
 * unaccrued penalty and need no journal.
 */
final class LegacyImportService
{
    /** The most data rows one file may carry. */
    public const MAX_ROWS = 5000;

    /** The loan category every old-system loan is filed under: no interest, no penalties, no top-up, offered in no branch. */
    public const LEGACY_CATEGORY = 'OLD SYSTEM LOAN (IMPORTED)';

    public const CUSTOMER_SOURCE = 'legacy_import';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly LoanService $loans,
        private readonly CustomerRegistrar $registrar,
    ) {}

    /**
     * Read and validate an uploaded file into a draft import. Changes no balance.
     *
     * @throws ValidationException when the file cannot be read or is not the module's format
     */
    public function upload(Employee $employee, Branch $branch, string $module, UploadedFile $file, ?int $year = null): LegacyImport
    {
        try {
            $lines = SpreadsheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $headerIndex = LegacyFileFormat::locateHeader($module, $lines);
        if ($headerIndex === null) {
            $first = collect($lines)->first(fn (array $line): bool => implode('', $line) !== '') ?? [];
            throw ValidationException::withMessages(['file' => LegacyFileFormat::mismatchReason($module, $first)]);
        }

        // Rows are kept under the names of the layout the file follows; LegacyFileFormat::cell() reads an earlier layout's
        // columns under the current names.
        $headers = LegacyFileFormat::layout($module, $lines[$headerIndex]) ?? LegacyFileFormat::headers($module);
        $data = [];
        foreach (array_slice($lines, $headerIndex + 1, null, true) as $index => $line) {
            $cells = array_slice(array_pad($line, count($headers), ''), 0, count($headers));
            if (implode('', $cells) === '' || $this->isTotalLine($cells)) {
                continue;
            }
            $data[$index + 1] = array_combine($headers, $cells);
        }

        if ($data === []) {
            throw ValidationException::withMessages(['file' => 'The file has the right columns but no rows to import.']);
        }
        if (count($data) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.count($data).' rows; at most '.self::MAX_ROWS.' can be imported at once. Split it by status or year.']);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs("legacy-imports/{$branch->company_id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'csv'), 'local');

        return DB::transaction(function () use ($employee, $branch, $module, $file, $year, $data, $hash, $path): LegacyImport {
            $import = LegacyImport::create([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'module' => $module,
                'year' => $module === LegacyImport::MODULE_LOAN ? ($year ?? (int) now()->year) : null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path ?: null,
                'file_hash' => $hash,
                'status' => LegacyImport::STATUS_DRAFT,
                'uploaded_by' => $employee->id,
                'uploaded_at' => now(),
            ]);

            foreach ($data as $rowNumber => $raw) {
                $import->rows()->create(['row_number' => $rowNumber, 'raw' => $raw, 'fingerprint' => '']);
            }

            return $this->evaluate($import);
        });
    }

    /**
     * (Re)read every row from what was uploaded and decide its status: errors, customer match, duplicates (within the
     * file, against every import waiting or approved, and against the records already in this system), warnings. Rows
     * mapped to a customer by hand keep that customer. Then the counts and totals of the import are refreshed.
     */
    public function evaluate(LegacyImport $import): LegacyImport
    {
        if (! in_array($import->status, [LegacyImport::STATUS_DRAFT, LegacyImport::STATUS_PENDING, LegacyImport::STATUS_REJECTED], true)) {
            return $import;
        }

        $branches = Branch::query()->where('company_id', $import->company_id)->get(['id', 'name'])
            ->keyBy(fn (Branch $branch): string => LegacyRowReader::nameKey($branch->name));
        $importBranch = Branch::find($import->branch_id);
        $matcher = new LegacyCustomerMatcher($import->company_id);
        $rows = $import->rows()->orderBy('row_number')->get();
        $headers = LegacyFileFormat::headers($import->module);

        $read = [];
        foreach ($rows as $row) {
            $read[$row->id] = LegacyRowReader::read($import->module, array_map(fn (string $header): string => LegacyFileFormat::cell($row->raw ?? [], $header), $headers));
            $row->fingerprint = LegacyRowReader::fingerprint($import->module, $import->branch_id, $read[$row->id]['attributes']);
        }

        $elsewhere = LegacyImportRow::query()
            ->whereIn('fingerprint', $rows->pluck('fingerprint')->unique()->values())
            ->where('legacy_import_id', '!=', $import->id)
            ->whereIn('status', [...LegacyImportRow::IMPORTABLE, LegacyImportRow::STATUS_IMPORTED])
            ->whereHas('import', fn ($query) => $query->where('company_id', $import->company_id)->whereIn('status', LegacyImport::HOLDING_STATUSES))
            ->with('import:id,status,file_name')
            ->get()
            ->groupBy('fingerprint');

        $seen = [];
        foreach ($rows as $row) {
            $result = $read[$row->id];
            $manual = $row->match_method === LegacyImportRow::MATCH_MANUAL ? $row->customer_id : null;

            $row->fill(array_fill_keys(['customer_name', 'branch_name', 'phone', 'loan_amount', 'interest', 'total_payable', 'collection', 'paid_amount', 'remain_amount',
                'penalty_amount', 'fee', 'duration_type', 'sessions', 'withdrawal_date', 'penalty_date', 'alert_date', 'loan_status', 'monthly'], null));
            $row->fill($result['attributes']);
            $row->status = LegacyImportRow::STATUS_VALID;
            $row->messages = [];

            foreach ($result['errors'] as $message) {
                $row->note(LegacyImportRow::STATUS_ERROR, $message);
            }
            foreach ($result['warnings'] as $message) {
                $row->note(LegacyImportRow::STATUS_WARNING, $message);
            }

            if ($result['branch_name'] !== '') {
                $branch = $branches->get(LegacyRowReader::nameKey($result['branch_name']));
                if ($branch === null) {
                    $row->note(LegacyImportRow::STATUS_ERROR, "Branch not found: \"{$result['branch_name']}\".");
                } elseif ($branch->id !== $import->branch_id) {
                    $row->note(LegacyImportRow::STATUS_ERROR, "This row is for branch {$branch->name}, but the file is being imported for {$importBranch?->name}. Upload each branch's file under its own branch.");
                }
            }

            $row->customer_id = null;
            $row->match_method = null;
            if ($manual !== null && ($customer = $matcher->find($manual)) !== null) {
                $row->customer_id = $customer->id;
                $row->match_method = LegacyImportRow::MATCH_MANUAL;
            } elseif ($row->customer_name !== null) {
                $match = $matcher->match($row->customer_name, $row->phone, $import->branch_id);
                $row->customer_id = $match['customer']?->id;
                $row->match_method = $match['method'];
                if ($match['message'] !== null) {
                    $row->note($match['status'], $match['message']);
                }
            }

            if (isset($seen[$row->fingerprint])) {
                $row->note(LegacyImportRow::STATUS_DUPLICATE, "Duplicate: the same record as row {$seen[$row->fingerprint]} of this file.");
            } elseif (($other = $elsewhere->get($row->fingerprint)?->first()) !== null) {
                $row->note(LegacyImportRow::STATUS_DUPLICATE, $other->import->status === LegacyImport::STATUS_APPROVED
                    ? "Already imported: import #{$other->import->id} ({$other->import->file_name}), row {$other->row_number}. Its balance is not added again."
                    : "Already waiting for approval in import #{$other->import->id} ({$other->import->file_name}), row {$other->row_number}.");
            } elseif ($row->status !== LegacyImportRow::STATUS_ERROR) {
                $seen[$row->fingerprint] = $row->row_number;
            }

            if ($row->customer_id !== null) {
                $this->checkAgainstLiveRecords($import->module, $row);
            }

            $row->save();
        }

        return $this->refreshSummary($import);
    }

    /**
     * Send a draft (or a rejected import whose rows were mapped since) for approval.
     */
    public function submit(LegacyImport $import, Employee $employee): LegacyImport
    {
        return DB::transaction(function () use ($import, $employee): LegacyImport {
            $locked = LegacyImport::lockForUpdate()->findOrFail($import->id);
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft or rejected import can be sent for approval.']);
            }

            $this->evaluate($locked);
            if ($locked->importableRows()->doesntExist()) {
                throw ValidationException::withMessages(['status' => 'No row of this file can be imported. Correct the file and upload it again.']);
            }

            $locked->update([
                'status' => LegacyImport::STATUS_PENDING,
                'submitted_by' => $employee->id,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Approve a pending import: its importable rows become live loans, penalties or salary advances, with one opening
     * journal. The rows are checked once more first, so a record approved in the meantime is not added twice.
     *
     * @throws AccessDeniedHttpException when the approver uploaded or submitted the import
     */
    public function approve(LegacyImport $import, Employee $approver): LegacyImport
    {
        return DB::transaction(function () use ($import, $approver): LegacyImport {
            $locked = LegacyImport::lockForUpdate()->findOrFail($import->id);
            if ($locked->status !== LegacyImport::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only an import waiting for approval can be approved.']);
            }
            $this->assertNotOwn($locked, $approver, 'approve');

            // Serialise approvals of the company, so two imports holding the same record cannot both pass the check.
            DB::table('companies')->where('id', $locked->company_id)->lockForUpdate()->first(['id']);
            $this->evaluate($locked);

            $rows = $locked->importableRows()->orderBy('row_number')->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'No row of this file can be imported any more (they are duplicates or have errors). Reject it instead.']);
            }

            $receivable = 0.0;
            $category = $locked->module === LegacyImport::MODULE_LOAN ? $this->legacyCategory($locked->company_id) : null;
            foreach ($rows as $row) {
                $record = match ($locked->module) {
                    LegacyImport::MODULE_LOAN => $this->createLoan($locked, $row, $category, $approver),
                    LegacyImport::MODULE_PENALTY => $this->createPenalty($locked, $row),
                    LegacyImport::MODULE_SALARY_ADVANCE => $this->createSalaryAdvance($locked, $row),
                };
                $receivable += match ($locked->module) {
                    LegacyImport::MODULE_LOAN => (float) $row->remain_amount,
                    LegacyImport::MODULE_SALARY_ADVANCE => max(0.0, (float) $record->amount - (float) $record->opening_paid),
                    default => 0.0,
                };

                $row->imported()->associate($record);
                $row->status = LegacyImportRow::STATUS_IMPORTED;
                $row->save();
            }

            $entry = null;
            if (round($receivable, 2) > 0) {
                $account = $locked->module === LegacyImport::MODULE_LOAN ? Account::LoanReceivable : Account::SalaryAdvanceReceivable;
                $entry = $this->ledger->journal($locked->company_id, sprintf('OPENING BALANCE — OLD SYSTEM %s #%d', strtoupper($locked->moduleLabel()), $locked->id), [
                    ['account' => $account, 'branch' => $locked->branch_id, 'debit' => round($receivable, 2)],
                    ['account' => Account::LegacyOpeningBalance, 'branch' => $locked->branch_id, 'credit' => round($receivable, 2)],
                ], $locked, CarbonImmutable::today(), $locked->branch_id, $approver, TransactionType::OpeningBalance);
            }

            $locked->update([
                'status' => LegacyImport::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'journal_entry_id' => $entry?->id,
            ]);

            $this->linkPenalties($locked->company_id, $rows->pluck('customer_id')->unique()->all());

            return $this->refreshSummary($locked->refresh());
        });
    }

    /**
     * Reject a pending import with the reason; the uploader corrects the file and uploads it again.
     */
    public function reject(LegacyImport $import, Employee $approver, string $reason): LegacyImport
    {
        return DB::transaction(function () use ($import, $approver, $reason): LegacyImport {
            $locked = LegacyImport::lockForUpdate()->findOrFail($import->id);
            if ($locked->status !== LegacyImport::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only an import waiting for approval can be rejected.']);
            }
            $this->assertNotOwn($locked, $approver, 'reject');

            $locked->update([
                'status' => LegacyImport::STATUS_REJECTED,
                'rejected_by' => $approver->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Resolve a row by hand: attach it to an existing customer, or (explicitly) create a new customer from it. The row is
     * then checked again like every other row.
     */
    public function mapRow(LegacyImportRow $row, Employee $employee, ?Customer $customer, bool $create = false): LegacyImportRow
    {
        return DB::transaction(function () use ($row, $employee, $customer, $create): LegacyImportRow {
            $import = LegacyImport::lockForUpdate()->findOrFail($row->legacy_import_id);
            if (! in_array($import->status, [LegacyImport::STATUS_DRAFT, LegacyImport::STATUS_PENDING, LegacyImport::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['customer_id' => 'Rows of an approved or rolled back import can no longer be mapped.']);
            }
            if ($customer !== null && (int) $customer->company_id !== $import->company_id) {
                throw ValidationException::withMessages(['customer_id' => 'The customer belongs to another company.']);
            }
            if ($customer === null && ! $create) {
                throw ValidationException::withMessages(['customer_id' => 'Choose a customer, or create a new customer from this row.']);
            }
            if ($create && blank($row->customer_name)) {
                throw ValidationException::withMessages(['customer_id' => 'This row has no customer name to create a customer from.']);
            }

            $customer ??= $this->createCustomer($import, $row);
            $row->forceFill(['customer_id' => $customer->id, 'match_method' => LegacyImportRow::MATCH_MANUAL, 'mapped_by' => $employee->id])->save();
            $this->evaluate($import);

            return $row->refresh();
        });
    }

    /**
     * Undo an approved import as a whole (safe recovery): only while none of its records has been touched since — no
     * repayment, penalty payment or waiver, write-off, top-up or status change. Its records are deleted, its opening
     * journal reversed, and its rows can be imported again from a corrected file.
     */
    public function rollback(LegacyImport $import, Employee $employee, string $reason): LegacyImport
    {
        return DB::transaction(function () use ($import, $employee, $reason): LegacyImport {
            $locked = LegacyImport::lockForUpdate()->findOrFail($import->id);
            if ($locked->status !== LegacyImport::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved import can be rolled back.']);
            }

            $rows = $locked->rows()->where('status', LegacyImportRow::STATUS_IMPORTED)->with('imported')->get();
            $blockers = $rows->map(fn (LegacyImportRow $row): ?string => $this->rollbackBlocker($row))->filter()->values();
            if ($blockers->isNotEmpty()) {
                throw ValidationException::withMessages(['status' => [
                    'This import cannot be rolled back: '.$blockers->count().' of its records have been used since it was approved.',
                    ...$blockers->take(10)->all(),
                ]]);
            }

            if ($locked->journalEntry !== null && ! $locked->journalEntry->reversal()->exists()) {
                $this->ledger->reverse($locked->journalEntry, 'Legacy import #'.$locked->id.' rolled back: '.$reason);
            }

            $customerIds = $rows->pluck('customer_id')->unique()->filter()->all();
            foreach ($rows as $row) {
                $record = $row->imported;
                if ($record instanceof Loan) {
                    // Old-system penalties of other imports attached to this loan go back to standing on their own.
                    Penalty::query()->where('loan_id', $record->id)->where('is_legacy_opening', true)->update(['loan_id' => null]);
                }
                $record?->delete();
                $row->forceFill(['status' => LegacyImportRow::STATUS_ROLLED_BACK, 'imported_type' => null, 'imported_id' => null])->save();
            }

            $locked->update([
                'status' => LegacyImport::STATUS_ROLLED_BACK,
                'rolled_back_by' => $employee->id,
                'rolled_back_at' => now(),
                'rollback_reason' => $reason,
            ]);

            $this->linkPenalties($locked->company_id, $customerIds);

            return $this->refreshSummary($locked->refresh());
        });
    }

    /**
     * Throw away a draft that was never sent for approval (the uploader or an approver).
     */
    public function discard(LegacyImport $import): void
    {
        if ($import->status !== LegacyImport::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft can be deleted; a submitted import stays in the history.']);
        }

        $import->delete();
    }

    /**
     * The exception file: every row that is not plainly valid, in the module's own columns (so it can be corrected and
     * uploaded again), followed by the row number, status and reasons.
     *
     * @return list<list<string>>
     */
    public function exceptionLines(LegacyImport $import): array
    {
        $headers = LegacyFileFormat::headers($import->module);
        $lines = [[...$headers, ...LegacyFileFormat::EXCEPTION_COLUMNS]];

        $rows = $import->rows()
            ->whereNotIn('status', [LegacyImportRow::STATUS_VALID, LegacyImportRow::STATUS_IMPORTED])
            ->orderBy('row_number')
            ->get();
        foreach ($rows as $row) {
            $lines[] = [
                ...array_map(fn (string $header): string => LegacyFileFormat::cell($row->raw ?? [], $header), $headers),
                (string) $row->row_number,
                strtoupper($row->status),
                implode(' | ', $row->messages ?? []),
            ];
        }

        return $lines;
    }

    /**
     * Who may not decide on their own import: the person who uploaded it or sent it for approval, as for every other approval
     * (rule 6, {@see SegregationOfDuties}) — except the Super Admin, who may approve anything, their own import included (user
     * decision 2026-09-16), or someone the company explicitly allows to self-approve.
     */
    public function ownImportReason(LegacyImport $import, Employee $employee): ?string
    {
        return app(SegregationOfDuties::class)->blockedReason(
            array_values(array_filter([$import->uploaded_by, $import->submitted_by])),
            $employee,
            'You uploaded or submitted this import; another authorised user must approve or reject it.',
        );
    }

    private function assertNotOwn(LegacyImport $import, Employee $employee, string $action): void
    {
        if (($reason = $this->ownImportReason($import, $employee)) !== null) {
            throw new AccessDeniedHttpException($reason);
        }
    }

    /**
     * Refresh the counts and totals the preview and the audit record show.
     */
    private function refreshSummary(LegacyImport $import): LegacyImport
    {
        $rows = $import->rows()->get();
        $importable = $rows->filter(fn (LegacyImportRow $row): bool => in_array($row->status, [...LegacyImportRow::IMPORTABLE, LegacyImportRow::STATUS_IMPORTED], true) && $row->customer_id !== null);
        $sum = fn (Collection $items, string $column): float => round((float) $items->sum(fn (LegacyImportRow $row): float => (float) $row->{$column}), 2);
        $isLoan = $import->module === LegacyImport::MODULE_LOAN;
        $active = $importable->where('loan_status', 'Active');
        $default = $importable->where('loan_status', 'Default');

        $import->update([
            'total_rows' => $rows->count(),
            'valid_rows' => $rows->whereIn('status', [LegacyImportRow::STATUS_VALID, LegacyImportRow::STATUS_IMPORTED])->count(),
            'warning_rows' => $rows->where('status', LegacyImportRow::STATUS_WARNING)->count(),
            'error_rows' => $rows->where('status', LegacyImportRow::STATUS_ERROR)->count(),
            'duplicate_rows' => $rows->where('status', LegacyImportRow::STATUS_DUPLICATE)->count(),
            'unmatched_rows' => $rows->where('status', LegacyImportRow::STATUS_UNMATCHED)->count(),
            'totals' => [
                'importable_rows' => $importable->count(),
                'loan_outstanding' => $isLoan ? $sum($importable, 'remain_amount') : 0.0,
                'penalty_outstanding' => $import->module === LegacyImport::MODULE_PENALTY ? $sum($importable, 'penalty_amount') : 0.0,
                'salary_advance_outstanding' => $import->module === LegacyImport::MODULE_SALARY_ADVANCE ? $sum($importable, 'remain_amount') : 0.0,
                'loan_amount' => $sum($importable, 'loan_amount'),
                'paid_amount' => $sum($importable, 'paid_amount'),
                'active_loans' => $isLoan ? $active->count() : 0,
                'active_outstanding' => $isLoan ? $sum($active, 'remain_amount') : 0.0,
                'default_loans' => $isLoan ? $default->count() : 0,
                'default_outstanding' => $isLoan ? $sum($default, 'remain_amount') : 0.0,
                'total_issued' => $isLoan ? $sum($importable, 'loan_amount') : 0.0,
            ],
        ]);

        return $import;
    }

    /**
     * A file's TOTAL line (printed under the rows) is not a record.
     *
     * @param  list<string>  $cells
     */
    private function isTotalLine(array $cells): bool
    {
        foreach (array_slice($cells, 0, 3) as $cell) {
            if (preg_match('/^\s*(grand\s+)?total\s*:?\s*$/i', $cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same record already standing in this system — entered live, or imported earlier under a name written differently
     * (an exported file prints the customer's registered name) — is a duplicate; a customer who already runs a loan of this
     * system is worth a second look before an old loan is added beside it.
     */
    private function checkAgainstLiveRecords(string $module, LegacyImportRow $row): void
    {
        if ($module === LegacyImport::MODULE_LOAN && $row->withdrawal_date !== null && $row->loan_amount !== null) {
            $same = Loan::query()
                ->where('customer_id', $row->customer_id)
                ->whereDate('withdrawn_at', $row->withdrawal_date)
                ->where(fn ($query) => $query->where('total_payable', $row->loan_amount)->orWhere('amount_approved', $row->loan_amount))
                ->value('loan_number');
            if ($same !== null) {
                $row->note(LegacyImportRow::STATUS_DUPLICATE, "Already in this system as loan {$same} (same customer, withdrawal date and amount).");

                return;
            }

            $running = Loan::query()->where('customer_id', $row->customer_id)->where('is_legacy_opening', false)->status(...LoanStatus::repayable())->value('loan_number');
            if ($running !== null) {
                $row->note(LegacyImportRow::STATUS_WARNING, "The customer already has running loan {$running} in this system. Make sure it is not the same loan.");
            }
        }

        if ($module === LegacyImport::MODULE_PENALTY && $row->penalty_date !== null && $row->penalty_amount !== null) {
            $same = Penalty::query()
                ->where('customer_id', $row->customer_id)
                ->whereDate('penalty_date', $row->penalty_date)
                ->where(fn ($query) => $query->where('amount', $row->penalty_amount)->orWhereRaw('amount - paid_amount = ?', [$row->penalty_amount]))
                ->exists();
            if ($same) {
                $row->note(LegacyImportRow::STATUS_DUPLICATE, 'Already in this system: the customer has a penalty of the same amount on the same date.');
            }
        }

        if ($module === LegacyImport::MODULE_SALARY_ADVANCE && $row->loan_amount !== null) {
            $same = SalaryAdvance::query()
                ->where('customer_id', $row->customer_id)
                ->whereIn('status', ['active', 'done'])
                ->whereNull('reversed_at')
                ->where('amount', $row->loan_amount)
                ->where('total_payable', $row->total_payable)
                ->exists();
            if ($same) {
                $row->note(LegacyImportRow::STATUS_DUPLICATE, 'Already in this system: the customer has a salary advance with the same Loan Amount and Principal + Interest.');
            }
        }
    }

    private function createLoan(LegacyImport $import, LegacyImportRow $row, LoanCategory $category, Employee $approver): Loan
    {
        $amount = round((float) $row->loan_amount, 2);
        $remain = round((float) $row->remain_amount, 2);
        $status = $row->loan_status === 'Default' ? LoanStatus::Default : LoanStatus::Active;

        $loan = Loan::create([
            'company_id' => $import->company_id,
            'branch_id' => $import->branch_id,
            'customer_id' => $row->customer_id,
            'loan_category_id' => $category->id,
            'loan_number' => $this->loans->newLoanNumber(),
            'amount_applied' => $amount,
            'amount_approved' => $amount,
            'duration' => Duration::from((string) $row->duration_type),
            'sessions' => (int) $row->sessions,
            'instalment' => (float) ($row->collection ?? 0),
            'restoration' => (float) ($row->collection ?? 0),
            'formula' => $category->formula,
            'fee_deduct' => false,
            'reason' => sprintf('Old system opening balance — Loan File import #%d, row %d', $import->id, $row->row_number),
            'interest_rate' => 0,
            'interest_amount' => 0,
            'total_payable' => $amount,
            'loan_fee' => 0,
            'insurance' => 0,
            'status' => $status,
            'is_legacy_opening' => true,
            'opening_paid_principal' => round($amount - $remain, 2),
            'legacy_import_row_id' => $row->id,
            'approved_at' => now(),
            'withdrawn_at' => $row->withdrawal_date,
            'days_past_due' => 0,
        ]);
        $loan->update(['reference_number' => 'OS'.str_pad((string) $loan->id, 8, '0', STR_PAD_LEFT)]);

        $customer = Customer::find($row->customer_id);
        if ($status === LoanStatus::Default) {
            $customer?->update(['status' => 'out']);
        } elseif ($customer !== null && $customer->status !== 'out') {
            $customer->update(['status' => 'open']);
        }

        return $loan;
    }

    private function createPenalty(LegacyImport $import, LegacyImportRow $row): Penalty
    {
        return Penalty::create([
            'company_id' => $import->company_id,
            'branch_id' => $import->branch_id,
            'customer_id' => $row->customer_id,
            'loan_id' => null,
            'amount' => round((float) $row->penalty_amount, 2),
            'paid_amount' => 0,
            'is_waived' => false,
            'is_legacy_opening' => true,
            'legacy_import_row_id' => $row->id,
            'penalty_date' => $row->penalty_date,
        ]);
    }

    private function createSalaryAdvance(LegacyImport $import, LegacyImportRow $row): SalaryAdvance
    {
        $amount = round((float) $row->loan_amount, 2);
        $total = round((float) $row->total_payable, 2);
        $date = $row->alert_date !== null ? CarbonImmutable::parse($row->alert_date) : CarbonImmutable::now();

        $advance = new SalaryAdvance;
        $advance->forceFill([
            'company_id' => $import->company_id,
            'branch_id' => $import->branch_id,
            'customer_id' => $row->customer_id,
            'salary_advance_category_id' => null,
            'amount' => $amount,
            'interest_rate' => $amount > 0 ? round(($total - $amount) / $amount * 100, 2) : 0,
            'total_payable' => $total,
            'fee' => round((float) ($row->fee ?? 0), 2),
            'status' => 'active',
            'approved_at' => $date,
            'is_legacy_opening' => true,
            'opening_paid' => round($total - (float) $row->remain_amount, 2),
            'legacy_import_row_id' => $row->id,
            'created_at' => $date,
            'updated_at' => now(),
        ]);
        $advance->save();

        return $advance;
    }

    /**
     * Attach every old-system penalty that stands on its own to the customer's old-system loan when there is exactly one
     * it can belong to — the only running old-system loan, or among several the one whose Loan Amount the Penalty List
     * printed — so a loan repayment collects it (Principal → Penalty). It stays its own penalty; the loan's principal is
     * untouched. Anything unclear stays on its own and is paid from the Penalty List.
     *
     * @param  list<int>  $customerIds
     */
    private function linkPenalties(int $companyId, array $customerIds): void
    {
        if ($customerIds === []) {
            return;
        }

        $loans = Loan::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds)
            ->where('is_legacy_opening', true)
            ->status(...LoanStatus::repayable())
            ->get(['id', 'customer_id', 'amount_approved'])
            ->groupBy('customer_id');

        Penalty::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds)
            ->where('is_legacy_opening', true)
            ->whereNull('loan_id')
            ->with('legacyImportRow:id,loan_amount')
            ->get()
            ->each(function (Penalty $penalty) use ($loans): void {
                $candidates = $loans->get($penalty->customer_id, collect());
                if ($candidates->count() > 1) {
                    $printed = (float) ($penalty->legacyImportRow?->loan_amount ?? 0);
                    $candidates = $candidates->filter(fn (Loan $loan): bool => $printed > 0 && abs((float) $loan->amount_approved - $printed) < 0.005);
                }
                if ($candidates->count() === 1) {
                    $penalty->update(['loan_id' => $candidates->first()->id]);
                }
            });
    }

    /**
     * Why an imported record can no longer be taken back, or null.
     */
    private function rollbackBlocker(LegacyImportRow $row): ?string
    {
        $record = $row->imported;
        $who = "Row {$row->row_number} ({$row->customer_name})";

        return match (true) {
            $record === null => null,
            $record instanceof Loan => match (true) {
                $record->transactions()->exists() => "{$who}: loan {$record->loan_number} has repayments.",
                ! in_array($record->status, [LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default], true) => "{$who}: loan {$record->loan_number} is now {$record->status->label()}.",
                WriteOffRequest::query()->where('loan_id', $record->id)->exists() || $record->writeOff()->exists() => "{$who}: loan {$record->loan_number} has a write-off or write-off request.",
                Loan::query()->where('topup_of_loan_id', $record->id)->exists() => "{$who}: loan {$record->loan_number} was topped up.",
                Penalty::query()->where('loan_id', $record->id)->where(fn ($query) => $query->where('is_legacy_opening', false)->orWhere('paid_amount', '>', 0)->orWhere('is_waived', true))->exists() => "{$who}: loan {$record->loan_number} has penalties charged, paid or waived since.",
                default => null,
            },
            $record instanceof Penalty => (float) $record->paid_amount > 0 || $record->is_waived || $record->payments()->exists() ? "{$who}: the penalty has been paid or waived." : null,
            $record instanceof SalaryAdvance => $record->payments()->exists() || $record->status !== 'active' || $record->reversed_at !== null ? "{$who}: the salary advance has repayments or is no longer active." : null,
            default => null,
        };
    }

    /**
     * The loan category old-system loans are filed under. It charges no interest, fee or penalty, allows no top-up and is
     * assigned to no branch, so it is never offered for a new application.
     */
    private function legacyCategory(int $companyId): LoanCategory
    {
        $existing = LoanCategory::query()->where('company_id', $companyId)->where('name', self::LEGACY_CATEGORY)->first();
        if ($existing !== null) {
            return $existing;
        }

        $customerCategory = CustomerCategory::query()->where('company_id', $companyId)->orderBy('id')->value('id');
        if ($customerCategory === null) {
            throw ValidationException::withMessages(['status' => 'Create at least one customer category (Settings) before importing old-system loans.']);
        }

        return LoanCategory::create([
            'company_id' => $companyId,
            'customer_category_id' => $customerCategory,
            'name' => self::LEGACY_CATEGORY,
            'amount_from' => 0,
            'amount_to' => 9999999999,
            'interest_rate' => 0,
            'formula' => 'SIMPLE',
            'duration' => Duration::Monthly->value,
            'repayment_from' => 1,
            'repayment_to' => 1,
            'fee_deduct' => false,
            'has_penalty' => false,
            'topup_percent' => 0,
            'fee_type' => 'money',
            'fee_value' => 0,
            'insurance' => 0,
        ]);
    }

    /**
     * A customer created on purpose from an unmatched row, filed in the import's branch. Only what the file prints is kept:
     * no gender, date of birth or ID is invented, so KYC stays incomplete until registration is finished. A phone already
     * held by another customer is kept as the alternative number only.
     */
    private function createCustomer(LegacyImport $import, LegacyImportRow $row): Customer
    {
        [$first, $middle, $last] = CreateHistoricalCustomers::splitName((string) $row->customer_name);
        $phone = LegacyRowReader::phone($row->phone);
        $taken = $phone !== null && Customer::withTrashed()->whereIn('phone', [$phone, '0'.substr($phone, 3), '+'.$phone])->exists();

        $customer = new Customer;
        $customer->forceFill([
            'company_id' => $import->company_id,
            'branch_id' => $import->branch_id,
            'customer_number' => $this->registrar->nextCustomerNumber(),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'phone' => $taken ? null : $phone,
            'alternative_phone' => $taken ? $phone : null,
            'status' => 'pending',
            'account_status' => 'active',
            'approval_status' => 'pending',
            'kyc_status' => KycStatusCalculator::INCOMPLETE,
            'registration_source' => self::CUSTOMER_SOURCE,
            'status_remarks' => sprintf('Created from the old-system %s (import #%d, row %d). Gender, date of birth and ID are not in the file: complete registration before any new loan.%s',
                $import->moduleLabel(), $import->id, $row->row_number, $taken ? " The printed phone {$phone} belongs to another customer." : ''),
        ]);
        $customer->save();

        return $customer;
    }
}
