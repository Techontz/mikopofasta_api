<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Visa\VisaCustomerResource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\LegacyImports\SpreadsheetReader;
use App\Services\LegacyImports\SpreadsheetWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * VISA — customer bank account & card password list (live admin/bank_password).
 */
class VisaController extends ApiController
{
    /** Export / import columns; the import finds a customer by Customer ID, or by phone when the ID is blank. */
    private const HEADERS = ['Customer ID', 'Branch', 'Customer Name', 'Phone Number', 'Account Name', 'VISA'];

    /**
     * Employed customers (work status "ent") and customers with a bank account (filter: branch).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('visa.manage');

        return VisaCustomerResource::collection($this->listQuery($request)->get());
    }

    /**
     * GET /visa/customers/export: the list (same branch filter) as an Excel file in the columns the import reads.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAny('visa.manage');

        $rows = [self::HEADERS];
        foreach ($this->listQuery($request)->get() as $customer) {
            $rows[] = [(string) $customer->id, (string) $customer->branch?->name, (string) $customer->full_name, (string) $customer->phone, (string) $customer->bank_account_name, (string) $customer->bank_password];
        }
        $content = SpreadsheetWriter::xlsx($rows, 'VISA');

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'visa-bank-accounts-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * POST /visa/customers/import: sets Account Name and VISA from a CSV / Excel file in the export's columns.
     * A blank cell keeps the current value; rows for customers outside the user's branches are rejected.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorizeAny('visa.manage');
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        try {
            $lines = SpreadsheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $header = array_map(fn ($cell): string => strtolower(trim((string) $cell)), $lines[0] ?? []);
        $column = fn (string $name): ?int => ($index = array_search(strtolower($name), $header, true)) === false ? null : $index;
        [$idColumn, $phoneColumn, $nameColumn, $visaColumn] = [$column('Customer ID'), $column('Phone Number'), $column('Account Name'), $column('VISA')];
        if (($idColumn === null && $phoneColumn === null) || ($nameColumn === null && $visaColumn === null)) {
            throw ValidationException::withMessages(['file' => 'The first row must have the column headings of the export: "Customer ID" (or "Phone Number") and "Account Name" and/or "VISA".']);
        }

        $cell = fn (array $line, ?int $index): string => $index === null ? '' : trim((string) ($line[$index] ?? ''));
        $employee = $this->currentEmployee();
        $updated = 0;
        $unchanged = 0;
        $failed = [];

        DB::transaction(function () use ($lines, $cell, $idColumn, $phoneColumn, $nameColumn, $visaColumn, $employee, $request, &$updated, &$unchanged, &$failed): void {
            foreach (array_slice($lines, 1) as $offset => $line) {
                $rowNumber = $offset + 2;
                [$id, $phone, $name, $visa] = [$cell($line, $idColumn), $cell($line, $phoneColumn), $cell($line, $nameColumn), $cell($line, $visaColumn)];
                if ($id === '' && $phone === '' && $name === '' && $visa === '') {
                    continue;
                }
                if (mb_strlen($name) > 255 || mb_strlen($visa) > 255) {
                    $failed[] = ['row' => $rowNumber, 'reason' => 'Account Name or VISA is longer than 255 characters.'];

                    continue;
                }

                $matches = $this->scoped(Customer::query())
                    ->when($id !== '', fn (Builder $query) => $query->whereKey(ctype_digit($id) ? (int) $id : 0), fn (Builder $query) => $query->where('phone', $phone))
                    ->limit(2)
                    ->get();
                if ($matches->count() !== 1) {
                    $failed[] = ['row' => $rowNumber, 'reason' => $matches->isEmpty()
                        ? ($id !== '' ? "Customer ID {$id} was not found in your branches." : ($phone !== '' ? "No customer with phone {$phone} in your branches." : 'Customer ID and Phone Number are both blank.'))
                        : "More than one customer has phone {$phone}; fill in the Customer ID."];

                    continue;
                }

                $customer = $matches->first();
                $changes = array_filter(['bank_account_name' => $name, 'bank_password' => $visa], fn (string $value): bool => $value !== '');
                $changes = array_filter($changes, fn (string $value, string $key): bool => $customer->{$key} !== $value, ARRAY_FILTER_USE_BOTH);
                if ($changes === []) {
                    $unchanged++;

                    continue;
                }

                $before = ['bank_account_name' => $customer->bank_account_name, 'bank_password_set' => filled($customer->bank_password)];
                $customer->update($changes);
                AuditLog::create([
                    'company_id' => $customer->company_id,
                    'employee_id' => $employee->id,
                    'action' => 'Customer.visa_updated',
                    'auditable_type' => $customer->getMorphClass(),
                    'auditable_id' => $customer->id,
                    'before' => $before,
                    'after' => ['bank_account_name' => $customer->bank_account_name, 'bank_password_set' => filled($customer->bank_password), 'source' => 'import'],
                    'ip_address' => $request->ip(),
                ]);
                $updated++;
            }
        });

        return $this->message("Import finished: {$updated} updated, {$unchanged} unchanged, ".count($failed).' failed', 200, [
            'data' => ['updated' => $updated, 'unchanged' => $unchanged, 'failed' => $failed],
        ]);
    }

    /**
     * Employed customers (work status "ent") and customers with a bank account or VISA, in the user's branches.
     *
     * @return Builder<Customer>
     */
    private function listQuery(Request $request): Builder
    {
        return $this->applyFilters($this->scoped(Customer::query()), $request)
            ->where(fn (Builder $query) => $query->where('work_status', 'ent')->orWhereNotNull('bank_account_name')->orWhereNotNull('bank_password'))
            ->with('branch')
            ->orderBy('first_name');
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAny('visa.manage');
        $this->assertBranchAccessible($customer->branch_id);

        $data = $request->validate([
            'ac_name' => ['nullable', 'string', 'max:255'],
            'ac_password' => ['nullable', 'string', 'max:255'],
        ]);

        $before = ['bank_account_name' => $customer->bank_account_name, 'bank_password_set' => filled($customer->bank_password)];

        $customer->update([
            'bank_account_name' => $data['ac_name'] ?? null,
            'bank_password' => $data['ac_password'] ?? null,
        ]);

        AuditLog::create([
            'company_id' => $customer->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => 'Customer.visa_updated',
            'auditable_type' => $customer->getMorphClass(),
            'auditable_id' => $customer->id,
            'before' => $before,
            'after' => ['bank_account_name' => $customer->bank_account_name, 'bank_password_set' => filled($customer->bank_password)],
            'ip_address' => $request->ip(),
        ]);

        return $this->message('Account Updated successfully', 200, ['data' => new VisaCustomerResource($customer->load('branch'))]);
    }
}
