<?php

namespace App\Services\LegacyImports;

use App\Enums\Duration;
use App\Models\LegacyImport;
use App\Services\Customers\HistoricalNameMatcher;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads one data row of an old-system file into the values the import works with, and says exactly what is wrong with it.
 *
 * The figures are taken as printed. Nothing is recalculated: the Remain Amount is the opening balance even when Loan Amount
 * − Paid Amount comes to something else (that is reported as a warning, never "corrected"), and the January–September
 * columns are history only — they never reduce the balance a second time.
 *
 * Errors keep a row out of the import; warnings let it in but ask for a second look.
 */
final class LegacyRowReader
{
    /**
     * @param  list<string>  $cells  the row's cells in the module's column order
     * @return array{attributes: array<string, mixed>, branch_name: string, errors: list<string>, warnings: list<string>}
     */
    public static function read(string $module, array $cells): array
    {
        $headers = LegacyFileFormat::headers($module);
        $value = fn (string $header): string => trim((string) ($cells[array_search($header, $headers, true)] ?? ''));
        $errors = [];
        $warnings = [];

        $name = self::cleanName($value('Customer Name'));
        if ($name === '') {
            $errors[] = 'Customer Name is missing.';
        }
        $branchName = $value('Branch Name');
        if ($branchName === '') {
            $errors[] = 'Branch Name is missing.';
        }

        $attributes = ['customer_name' => $name === '' ? null : $name, 'branch_name' => $branchName === '' ? null : $branchName];

        match ($module) {
            LegacyImport::MODULE_LOAN => self::loan($value, $attributes, $errors, $warnings),
            LegacyImport::MODULE_PENALTY => self::penalty($value, $attributes, $errors, $warnings),
            LegacyImport::MODULE_SALARY_ADVANCE => self::salaryAdvance($value, $attributes, $errors, $warnings),
        };

        return ['attributes' => $attributes, 'branch_name' => $branchName, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  callable(string): string  $value
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function loan(callable $value, array &$attributes, array &$errors, array &$warnings): void
    {
        $phoneText = $value('Phone Number');
        $phone = self::phone($phoneText);
        if ($phoneText !== '' && $phone === null) {
            $warnings[] = "Phone Number \"{$phoneText}\" is not a Tanzanian mobile number; the customer is matched by name and branch instead.";
        }
        $attributes['phone'] = $phone ?? ($phoneText === '' ? null : $phoneText);

        $loanAmount = self::amount($value('Loan Amount'), 'Loan Amount', $errors, required: true);
        if ($loanAmount !== null && $loanAmount <= 0) {
            $errors[] = 'Invalid Loan Amount: it must be more than 0.';
        }
        $collection = self::amount($value('Collection'), 'Collection', $errors);
        $paid = self::amount($value('Paid Amount'), 'Paid Amount', $errors) ?? 0.0;
        $remain = self::amount($value('Remain Amount'), 'Remain Amount', $errors, required: true, label: 'Invalid Remain Amount');

        if ($remain !== null) {
            if ($remain <= 0) {
                $errors[] = 'Invalid Remain Amount: nothing is owed on this loan, so there is no opening balance to import.';
            } elseif ($loanAmount !== null && $loanAmount > 0 && $remain > $loanAmount + 0.004) {
                // The old system added its fee to some balances (2024 loans): the Remain Amount is still what is owed.
                $warnings[] = 'The Remain Amount of '.self::show($remain).' is more than the Loan Amount of '.self::show($loanAmount).' (the old system added a fee to the balance). The whole Remain Amount is kept as outstanding principal.';
            }
        }
        if ($loanAmount !== null && $remain !== null && $remain > 0 && $remain <= $loanAmount + 0.004 && abs($loanAmount - $paid - $remain) >= 0.5) {
            $warnings[] = sprintf('Loan Amount − Paid Amount = %s, but the Remain Amount is %s. The Remain Amount is kept as the opening balance.', self::show($loanAmount - $paid), self::show($remain));
        }

        [$duration, $sessions] = self::duration($value('Duration Type / Number'));
        if ($duration === null) {
            $errors[] = 'Invalid Duration Type / Number "'.$value('Duration Type / Number').'": write the type and number, e.g. "Monthly / 6" (Daily, Weekly or Monthly).';
        }

        $withdrawal = self::date($value('Withdrawal Date'));
        if ($withdrawal === null) {
            $errors[] = 'Invalid Withdrawal Date "'.$value('Withdrawal Date').'".';
        } elseif ($withdrawal->isAfter(CarbonImmutable::today())) {
            $errors[] = 'Invalid Withdrawal Date: '.$withdrawal->toDateString().' is in the future.';
        }

        $statusText = $value('Loan Status');
        $status = match (strtolower($statusText)) {
            'active' => 'Active',
            'default' => 'Default',
            default => null,
        };
        if ($status === null) {
            $errors[] = 'Invalid Loan Status "'.$statusText.'": it must be Active or Default.';
        }

        $monthly = [];
        foreach (LegacyFileFormat::MONTHS as $index => $month) {
            $amount = self::amount($value($month), $month, $errors);
            if ($amount !== null && $amount < 0) {
                $errors[] = "Invalid {$month} payment: it cannot be negative.";
            } elseif ($amount !== null && $amount > 0) {
                $monthly[$index + 1] = $amount;
            }
        }
        if ($monthly !== [] && array_sum($monthly) > $paid + 0.5) {
            $warnings[] = sprintf('The January–September payments add up to %s, more than the Paid Amount of %s. They are kept as history only.', self::show(array_sum($monthly)), self::show($paid));
        }

        $attributes += [
            'loan_amount' => $loanAmount,
            'collection' => $collection,
            'paid_amount' => $paid,
            'remain_amount' => $remain,
            'duration_type' => $duration?->value,
            'sessions' => $sessions,
            'withdrawal_date' => $withdrawal?->toDateString(),
            'loan_status' => $status,
            'monthly' => $monthly === [] ? null : $monthly,
        ];
    }

    /**
     * @param  callable(string): string  $value
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function penalty(callable $value, array &$attributes, array &$errors, array &$warnings): void
    {
        $loanAmount = self::amount($value('Loan Amount'), 'Loan Amount', $errors);
        $penalty = self::amount($value('Penalty Amount'), 'Penalty Amount', $errors, required: true);
        if ($penalty !== null && $penalty < 0) {
            $errors[] = 'Invalid Penalty Amount: '.self::show($penalty).' is negative. The old system used negative penalties to correct earlier ones; net it into the customer\'s penalty before importing.';
        } elseif ($penalty !== null && $penalty == 0.0) {
            $errors[] = 'Invalid Penalty Amount: it is 0, so there is nothing to import.';
        }

        $date = self::date($value('Date'));
        if ($date === null) {
            $errors[] = 'Invalid Date "'.$value('Date').'".';
        } elseif ($date->isAfter(CarbonImmutable::today())) {
            $errors[] = 'Invalid Date: '.$date->toDateString().' is in the future.';
        }

        $attributes += [
            'loan_amount' => $loanAmount,
            'penalty_amount' => $penalty,
            'penalty_date' => $date?->toDateString(),
        ];
    }

    /**
     * @param  callable(string): string  $value
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function salaryAdvance(callable $value, array &$attributes, array &$errors, array &$warnings): void
    {
        $loanAmount = self::amount($value('Loan Amount'), 'Loan Amount', $errors, required: true);
        if ($loanAmount !== null && $loanAmount <= 0) {
            $errors[] = 'Invalid Loan Amount: it must be more than 0.';
        }

        // "Interest" is an amount (30,000). A printout that shows the rate instead ("10%") is read as that share of the
        // Loan Amount.
        $interestText = $value('Interest');
        $interest = null;
        if (preg_match('/^\s*([\d.,]+)\s*%\s*$/', $interestText, $match) && $loanAmount !== null) {
            $interest = round($loanAmount * (float) str_replace(',', '', $match[1]) / 100, 2);
        } else {
            $interest = self::amount($interestText, 'Interest', $errors);
        }
        if ($interest !== null && $interest < 0) {
            $errors[] = 'Invalid Interest: it cannot be negative.';
        }

        $total = self::amount($value('Principal + Interest'), 'Principal + Interest', $errors);
        if ($total === null && $loanAmount !== null) {
            $total = round($loanAmount + ($interest ?? 0), 2);
        }
        if ($total !== null && $loanAmount !== null && $interest !== null && abs($loanAmount + $interest - $total) >= 0.5) {
            $warnings[] = sprintf('Loan Amount + Interest = %s, but Principal + Interest is %s. Principal + Interest is kept.', self::show($loanAmount + $interest), self::show($total));
        }
        if ($total !== null && $loanAmount !== null && $total < $loanAmount - 0.004) {
            $errors[] = 'Invalid Principal + Interest: it is less than the Loan Amount.';
        }

        $paid = self::amount($value('Paid Amount'), 'Paid Amount', $errors) ?? 0.0;
        $remain = self::amount($value('Remain Amount'), 'Remain Amount', $errors, required: true, label: 'Invalid Remain Amount');
        if ($remain !== null) {
            if ($remain <= 0) {
                $errors[] = 'Invalid Remain Amount: nothing is owed on this salary advance, so there is no opening balance to import.';
            } elseif ($total !== null && $remain > $total + 0.004) {
                $errors[] = 'Invalid Remain Amount: '.self::show($remain).' is more than Principal + Interest ('.self::show($total).').';
            }
        }
        if ($total !== null && $remain !== null && $remain > 0 && abs($total - $paid - $remain) >= 0.5) {
            $warnings[] = sprintf('Principal + Interest − Paid Amount = %s, but the Remain Amount is %s. The Remain Amount is kept as the opening balance.', self::show($total - $paid), self::show($remain));
        }

        $statusText = $value('Status');
        if (strtolower($statusText) !== 'active') {
            $errors[] = 'Invalid Status "'.$statusText.'": only Active salary advances are imported.';
        }

        $fee = self::amount($value('Charges'), 'Charges', $errors) ?? 0.0;
        if ($fee < 0) {
            $errors[] = 'Invalid Charges: they cannot be negative.';
        }

        // The old system's combined "Date Alert" cell carries the alert after the date ("2026-08-01 old").
        $dateText = $value('Date');
        $date = self::date((string) preg_replace('/\s+(old|new)\s*$/i', '', $dateText));
        if ($date === null) {
            $warnings[] = $dateText === ''
                ? 'Date is empty; the approval date of the import is used as the advance date.'
                : "Date \"{$dateText}\" is not a date; the approval date of the import is used as the advance date.";
        } elseif ($date->isAfter(CarbonImmutable::today())) {
            $errors[] = 'Invalid Date: '.$date->toDateString().' is in the future.';
        }

        $attributes += [
            'loan_amount' => $loanAmount,
            'interest' => $interest,
            'total_payable' => $total,
            'paid_amount' => $paid,
            'remain_amount' => $remain,
            'fee' => $fee,
            'alert_date' => $date?->toDateString(),
        ];
    }

    /**
     * A money cell: "1,200,000", "1200000.00", "TSh 1,200,000" or "(5,000)". Empty is null; anything else is an error.
     *
     * @param  list<string>  $errors
     */
    public static function amount(string $text, string $column, array &$errors, bool $required = false, ?string $label = null): ?float
    {
        $clean = str_replace([',', ' ', "\u{00A0}", 'TSH', 'TSh', 'Tsh', 'tsh', '/='], '', trim($text));
        if ($clean === '' || $clean === '-') {
            if ($required) {
                $errors[] = ($label ?? "Invalid {$column}").": {$column} is missing.";
            }

            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $clean, $match)) {
            [$negative, $clean] = [true, $match[1]];
        }
        if (! is_numeric($clean)) {
            $errors[] = ($label ?? "Invalid {$column}").": \"{$text}\" is not an amount.";

            return null;
        }

        return round(($negative ? -1 : 1) * (float) $clean, 2);
    }

    /**
     * A date cell: 2026-08-20, 20/08/2026, 20-08-2026, 20.08.2026, 20 Aug 2026, or an Excel serial number (46255). Day
     * before month, as written in Tanzania. A time after the date is ignored.
     */
    public static function date(string $text): ?CarbonImmutable
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{5}(\.\d+)?$/', $text)) {
            $serial = (int) floor((float) $text);

            return $serial > 20000 && $serial < 80000 ? CarbonImmutable::create(1899, 12, 30)->addDays($serial) : null;
        }

        $text = (string) preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $text);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd M Y', 'd F Y', 'j M Y', 'M d, Y', 'd-M-Y', 'd/m/y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date !== null && $date->format($format) === $text) {
                return $date;
            }
            // Accept a day or month written without its leading zero (1/8/2026).
            if ($date !== null && str_contains($format, 'd') && $date->format(str_replace(['d', 'm'], ['j', 'n'], $format)) === $text) {
                return $date;
            }
        }

        return null;
    }

    /**
     * A Tanzanian mobile number in the 255XXXXXXXXX form, or null when the text is not one (0712…, 712…, +255712… and
     * 255712… are all accepted).
     */
    public static function phone(?string $text): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $text);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '255') => $digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '255'.substr($digits, 1),
            strlen($digits) === 9 && ! str_starts_with($digits, '0') => '255'.$digits,
            default => null,
        };
    }

    /**
     * "Monthly / 6", "Monthly 6", "6 Monthly", "Weekly/12", "DAILY - 30" → [Duration::Monthly, 6].
     *
     * @return array{0: Duration|null, 1: int|null}
     */
    public static function duration(string $text): array
    {
        $lower = strtolower($text);
        $duration = match (true) {
            str_contains($lower, 'month') => Duration::Monthly,
            str_contains($lower, 'week') => Duration::Weekly,
            str_contains($lower, 'dai') || str_contains($lower, 'day') => Duration::Daily,
            default => null,
        };
        $number = preg_match('/(\d+)/', $text, $match) ? (int) $match[1] : null;

        return $duration === null || $number === null || $number < 1 ? [null, null] : [$duration, $number];
    }

    /**
     * A customer name as printed, with repeated spaces collapsed; case and spelling are kept exactly.
     */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * The name as compared when matching and fingerprinting: capitals, no dots, single spaces ("Shaban d.  Masonjo" →
     * "SHABAN D MASONJO").
     */
    public static function nameKey(?string $name): string
    {
        return implode(' ', HistoricalNameMatcher::parts((string) $name));
    }

    /**
     * The key of a row that says "this is the same old-system record": the same customer, branch and the figures that
     * identify the loan, penalty or advance — not its balance, which changes between two exports of the same record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fingerprint(string $module, int $branchId, array $attributes): string
    {
        $amount = fn (string $key): string => number_format((float) ($attributes[$key] ?? 0), 2, '.', '');
        $parts = match ($module) {
            LegacyImport::MODULE_LOAN => [$amount('loan_amount'), (string) ($attributes['withdrawal_date'] ?? '')],
            LegacyImport::MODULE_PENALTY => [$amount('penalty_amount'), (string) ($attributes['penalty_date'] ?? ''), $amount('loan_amount')],
            LegacyImport::MODULE_SALARY_ADVANCE => [$amount('loan_amount'), $amount('total_payable'), (string) ($attributes['alert_date'] ?? '')],
            default => [],
        };

        return hash('sha256', implode('|', [$module, $branchId, self::nameKey($attributes['customer_name'] ?? ''), ...$parts]));
    }

    private static function show(float $amount): string
    {
        return number_format($amount, abs($amount - round($amount)) < 0.005 ? 0 : 2);
    }
}
