<?php

namespace App\Services\Hrm;

use App\Models\Employee;
use App\Services\LegacyImports\SpreadsheetWriter;
use App\Services\MobileDisbursementFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The bank's bulk payment upload file (Documents: bank_disbursement_file.xlsx) for money paid to staff — salaries, commission,
 * staff salary advances and staff loans: one row per employee with their name, salary account bank and number, phone, the
 * amount to pay and what it is for. Finance downloads it, uploads it to the bank, then records the payment in the system.
 * The staff counterpart of {@see MobileDisbursementFile} (customer loans by mobile money).
 */
final class BankDisbursementFile
{
    /**
     * The file's columns, in the bank's order.
     *
     * @var list<string>
     */
    public const HEADERS = ['first_name', 'last_name', 'bank', 'account_number', 'phone_number', 'amount', 'payment_details'];

    /**
     * Banks the upload accepts (the template's "bank" drop-down), as the bank expects them written.
     *
     * @var list<string>
     */
    public const BANKS = [
        'CRDB', 'NMB', 'TPB', 'NBC', 'COVENANT', 'CBA', 'CANARA', 'ABSA', 'ACCESS BANK', 'UBA', 'STANBIC', 'SCB', 'PBZ', 'MKOMBOZI',
        'MAENDELEO', 'LETSHEGO', 'KCB', 'IM BANK', 'FNB', 'EQUITY BANK', 'DTB', 'DCB', 'MWALIMU BANK', 'AKIBA BANK', 'EXIM',
    ];

    /** @var list<list<string>> */
    private array $rows = [self::HEADERS];

    /**
     * Adds one payment. Bank, account and phone default to the employee's current salary information; payroll passes the
     * values stored on its line. Nothing to pay adds nothing.
     */
    public function add(Employee $employee, float $amount, string $details, ?string $bank = null, ?string $account = null, ?string $phone = null): self
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return $this;
        }

        $info = $employee->salaryInfo;
        $this->rows[] = [
            trim((string) $employee->first_name),
            trim((string) $employee->last_name),
            strtoupper(trim((string) ($bank ?? $info?->bank_name))),
            trim((string) ($account ?? $info?->account_number)),
            trim((string) ($phone ?? $employee->phone)),
            self::amount($amount),
            mb_substr($details, 0, 100),
        ];

        return $this;
    }

    public function download(string $name): StreamedResponse
    {
        $content = SpreadsheetWriter::xlsx($this->rows, 'Disbursement');

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $name.'-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * 150000.00 → "150000", 1250.5 → "1250.50": a plain number the bank reads, summable in Excel.
     */
    private static function amount(float $amount): string
    {
        return floor($amount) === $amount ? (string) (int) $amount : number_format($amount, 2, '.', '');
    }
}
