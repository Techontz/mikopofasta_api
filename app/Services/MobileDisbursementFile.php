<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\LegacyImports\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile money disbursement file (Documents: disbursement_file.xlsx): one row per customer to pay, in the columns the
 * bulk-payment upload reads. Used for customer loans ready to pay out and customer salary advances approved.
 */
final class MobileDisbursementFile
{
    public const HEADERS = ['first_name', 'last_name', 'phone_number', 'amount', 'payment_details'];

    /** @var list<list<string>> */
    private array $rows = [self::HEADERS];

    /**
     * The customer's registered mobile wallet when they are paid by mobile money, otherwise their phone.
     */
    public function add(Customer $customer, float $amount, string $details): self
    {
        $phone = $customer->payment_method === 'mno' && filled($customer->wallet_number) ? $customer->wallet_number : $customer->phone;
        $this->rows[] = [(string) $customer->first_name, (string) $customer->last_name, (string) $phone, (string) round($amount, 2), $details];

        return $this;
    }

    public function download(string $name): StreamedResponse
    {
        $content = SpreadsheetWriter::xlsx($this->rows, 'Disbursement');

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $name.'-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
