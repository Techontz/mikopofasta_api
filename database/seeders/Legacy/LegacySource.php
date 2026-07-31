<?php

declare(strict_types=1);

namespace Database\Seeders\Legacy;

/**
 * Values transcribed from the legacy mikopofasta.co.tz screens.
 *
 * This file is a TRANSCRIPTION, not a fixture. Every value below was read off a
 * screenshot of the running legacy system, and every block names the page it
 * came from. Nothing here is generated, rounded, translated or filled in.
 *
 * The rule for this file:
 *
 *   If it was not visible on a legacy screen, it does not belong here.
 *
 * A lookup whose dropdown was never opened is absent rather than guessed —
 * see UNOBSERVED at the bottom, which is the standing list of what still has
 * to be captured before the corresponding table can be seeded. An empty array
 * there is a known gap; an invented default would be a silent lie, and the
 * whole point of this file is that a reader can tell the two apart.
 *
 * Legacy spellings are preserved exactly as data ("Headquater", "Aproved",
 * "Penarty"). That is deliberate and is NOT in tension with the spelling sweep
 * done across the UI: labels we render are ours to correct, but a lookup value
 * that has to match the old system's records is evidence, and correcting
 * evidence makes it stop matching.
 */
final class LegacySource
{
    /**
     * Branches, exactly as spelled on the legacy screens.
     *
     * Source: Loan Disbursed (/admin/disburse_loan) — "NEW KALENGE" on all ten
     * visible rows; Loan Pending Approve (/admin/loan_pending) — "Missenyi"
     * (row 1) and "Kakonko" (rows 4-9).
     *
     * Note the casing is inconsistent in the legacy data itself: NEW KALENGE is
     * upper case where the other two are title case. Reproduced as found.
     *
     * NOT the full branch list. These are the three that appear in loan data;
     * the Branch dropdown was never captured open. See UNOBSERVED['branches'].
     *
     * @return list<string>
     */
    public static function branches(): array
    {
        return ['NEW KALENGE', 'Missenyi', 'Kakonko'];
    }

    /**
     * Restoration Type / Duration Type / Loan Duration.
     *
     * Source: Loan Withdrawal (/admin/loan_withdrawal) filter tabs, which read
     * "All | Monthly | Weekly | Daily | Back" — the tab strip enumerates the
     * whole set, which is why this list is complete and the branch list is not.
     * Corroborated by the "Restoration Type" column on Loan Disbursed (Weekly)
     * and "Loan Duration" on Loan Pending Approve (Monthly).
     *
     * @return list<string>
     */
    public static function restorationTypes(): array
    {
        return ['Daily', 'Weekly', 'Monthly'];
    }

    /**
     * The seven headquarters accounts and their balances.
     *
     * Source: Headquater Account Balance (/admin/hq_account_balance).
     *
     * This list is provably complete: the seven amounts sum to 8,667,270, which
     * is exactly the TOTAL the page prints. A missing account would break that.
     *
     * These are NOT the chart of accounts. The legacy HQ module is a small
     * internal transfer ledger between seven named pots, with its own transfer
     * screens ("From Headquater Transaction - CEO ACC"), and it does not map
     * onto our §5 chart one-for-one — DISBURSEMENT ACCOUNT and SAVING ACCOUNT
     * have no §5 counterpart at all. Modelled as its own table for that reason.
     *
     * @return list<array{name: string, balance: string}>
     */
    public static function hqAccounts(): array
    {
        return [
            ['name' => 'SALARY ADVANCE ACCOUNT', 'balance' => '198190.00'],
            ['name' => 'DISBURSEMENT ACCOUNT', 'balance' => '7184000.00'],
            ['name' => 'PENALTY ACCOUNT', 'balance' => '26390.00'],
            ['name' => 'INTEREST ACCOUNT', 'balance' => '759790.00'],
            ['name' => 'RESERVE ACCOUNT', 'balance' => '221900.00'],
            ['name' => 'LOAN FEE ACCOUNT', 'balance' => '97000.00'],
            ['name' => 'SAVING ACCOUNT', 'balance' => '180000.00'],
        ];
    }

    /** The printed total, kept so a seeder can assert the transcription foots. */
    public static function hqAccountsTotal(): string
    {
        return '8667270.00';
    }

    /**
     * Groups.
     *
     * Source: Group List (/admin/group) — "Showing 1 to 1 of 1 entries".
     *
     * One group, named WAZURI. This is the complete legacy group table, and it
     * supersedes the earlier written brief that asked for thirty seeded groups.
     *
     * The legacy Group List has three columns only — S/NO., Group Name, Action
     * — so the legacy group record carries no branch, leader, member count or
     * balance of its own. Ours does; those columns stay, unpopulated, rather
     * than being back-filled with invented values.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return ['WAZURI'];
    }

    /**
     * Loan status values, as rendered in the Loan Status column.
     *
     * Source: Loan Pending Approve — the PENDING badge on all ten rows.
     *
     * Only PENDING has actually been seen. The Loan menu implies four more
     * states (Disbursed, Withdrawal, Rejected, and whatever a settled loan
     * becomes), but their exact spelling is unknown: the Loan Rejected screen
     * was captured empty, so its badge text has never been read.
     *
     * @return list<string>
     */
    public static function loanStatuses(): array
    {
        return ['PENDING'];
    }

    /**
     * Customer status values.
     *
     * Source: Loan Pending Approve — the NEW badge on all ten rows. The All
     * Customer screen has a Status column that would enumerate the rest, but it
     * was captured with no rows in it.
     *
     * @return list<string>
     */
    public static function customerStatuses(): array
    {
        return ['NEW'];
    }

    /**
     * Disbursed loans — page 1 of 4, ten of thirty-four rows.
     *
     * Source: Loan Disbursed (/admin/disburse_loan).
     *
     * Rows 1 and 10 genuinely have a blank Customer Name in the legacy system;
     * that is reproduced rather than filled in.
     *
     * `principalPlusInterest` is exactly `disbursed x (1 + interestRate)` on
     * every one of the ten rows, so the legacy interest basis is confirmed as
     * simple interest on the original principal.
     *
     * `restoration` is transcribed verbatim and is NOT derivable from the other
     * columns on this page — it is consistently larger than
     * principalPlusInterest / repayments, by 1,250 on the four small weekly
     * loans but by 20,000 on the two large ones. Whatever the add-on is
     * (savings contribution, fee, insurance) it comes from a screen not yet
     * captured, so nothing here computes it.
     *
     * @return list<array<string, mixed>>
     */
    public static function disbursedLoans(): array
    {
        return [
            ['row' => 1, 'customerName' => '', 'branch' => 'NEW KALENGE', 'loanAc' => '21065796958137', 'disbursed' => '600000.00', 'interestRate' => '20', 'principalPlusInterest' => '720000.00', 'restorationType' => 'Weekly', 'repayments' => 5, 'restoration' => '164000.00', 'date' => '2026-06-22'],
            ['row' => 2, 'customerName' => 'tumaini c katakuzi', 'branch' => 'NEW KALENGE', 'loanAc' => '13258174470965', 'disbursed' => '100000.00', 'interestRate' => '30', 'principalPlusInterest' => '130000.00', 'restorationType' => 'Weekly', 'repayments' => 2, 'restoration' => '67500.00', 'date' => '2026-05-16'],
            ['row' => 3, 'customerName' => 'CHRIZESTOM B KATAKUZI', 'branch' => 'NEW KALENGE', 'loanAc' => '93662917750144', 'disbursed' => '50000.00', 'interestRate' => '30', 'principalPlusInterest' => '65000.00', 'restorationType' => 'Weekly', 'repayments' => 1, 'restoration' => '70000.00', 'date' => '2026-05-16'],
            ['row' => 4, 'customerName' => 'ELISHA M ADAMU', 'branch' => 'NEW KALENGE', 'loanAc' => '65278293348050', 'disbursed' => '20000.00', 'interestRate' => '30', 'principalPlusInterest' => '26000.00', 'restorationType' => 'Weekly', 'repayments' => 4, 'restoration' => '7750.00', 'date' => '2026-04-08'],
            ['row' => 5, 'customerName' => 'ASHA Z JUMA', 'branch' => 'NEW KALENGE', 'loanAc' => '50764086733495', 'disbursed' => '20000.00', 'interestRate' => '30', 'principalPlusInterest' => '26000.00', 'restorationType' => 'Weekly', 'repayments' => 4, 'restoration' => '7750.00', 'date' => '2026-04-08'],
            ['row' => 6, 'customerName' => 'HASSAN J SAIDI', 'branch' => 'NEW KALENGE', 'loanAc' => '17436245039817', 'disbursed' => '20000.00', 'interestRate' => '30', 'principalPlusInterest' => '26000.00', 'restorationType' => 'Weekly', 'repayments' => 4, 'restoration' => '7750.00', 'date' => '2026-04-08'],
            ['row' => 7, 'customerName' => 'ZUENA E HASAN', 'branch' => 'NEW KALENGE', 'loanAc' => '62185794780031', 'disbursed' => '40000.00', 'interestRate' => '30', 'principalPlusInterest' => '52000.00', 'restorationType' => 'Weekly', 'repayments' => 4, 'restoration' => '14250.00', 'date' => '2026-04-07'],
            ['row' => 8, 'customerName' => 'ALY J JACKSPON', 'branch' => 'NEW KALENGE', 'loanAc' => '24627150937498', 'disbursed' => '20000.00', 'interestRate' => '30', 'principalPlusInterest' => '26000.00', 'restorationType' => 'Weekly', 'repayments' => 4, 'restoration' => '7750.00', 'date' => '2026-04-07'],
            ['row' => 9, 'customerName' => 'CATHERINI D NTABA', 'branch' => 'NEW KALENGE', 'loanAc' => '37763241605529', 'disbursed' => '30000.00', 'interestRate' => '30', 'principalPlusInterest' => '39000.00', 'restorationType' => 'Weekly', 'repayments' => 4, 'restoration' => '11000.00', 'date' => '2026-04-07'],
            ['row' => 10, 'customerName' => '', 'branch' => 'NEW KALENGE', 'loanAc' => '15794266725818', 'disbursed' => '700000.00', 'interestRate' => '20', 'principalPlusInterest' => '840000.00', 'restorationType' => 'Weekly', 'repayments' => 5, 'restoration' => '188000.00', 'date' => '2026-04-07'],
        ];
    }

    /**
     * What the Loan Disbursed footer prints across all thirty-four rows.
     *
     * Kept because it bounds the twenty-four rows still to be captured: they
     * account for 12,940,888 of disbursement and 20,286,147 of principal plus
     * interest between them. Note the trailing 888 — at least one uncaptured
     * loan is not a round figure.
     *
     * @return array{disbursed: string, principalPlusInterest: string, rowCount: int}
     */
    public static function disbursedLoanTotals(): array
    {
        return ['disbursed' => '14540888.00', 'principalPlusInterest' => '21006147.00', 'rowCount' => 34];
    }

    /**
     * Loans awaiting approval — page 1 of 3, ten of twenty-four rows.
     *
     * Source: Loan Pending Approve (/admin/loan_pending). This screen is the
     * only capture that carries customer phone numbers, so it is also the
     * source for those ten customers' contact details.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingLoans(): array
    {
        return [
            ['row' => 1, 'loanAc' => '82743814036592', 'customerName' => 'maswi m gachuma', 'phone' => '255769138896', 'branch' => 'Missenyi', 'amount' => '1000000.00', 'duration' => 'Monthly', 'repayments' => 6, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 2, 'loanAc' => '56087605493931', 'customerName' => 'EDIGAR J PAUL', 'phone' => '255712456324', 'branch' => 'NEW KALENGE', 'amount' => '50000.00', 'duration' => 'Monthly', 'repayments' => 2, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 3, 'loanAc' => '70834043792958', 'customerName' => 'FARYJALLAH M JOHN', 'phone' => '255758144234', 'branch' => 'NEW KALENGE', 'amount' => '20000.00', 'duration' => 'Monthly', 'repayments' => 3, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 4, 'loanAc' => '26520771139534', 'customerName' => 'JASITIN J LUVANGA', 'phone' => '255645473537', 'branch' => 'Kakonko', 'amount' => '540000.00', 'duration' => 'Monthly', 'repayments' => 4, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 5, 'loanAc' => '64711930294580', 'customerName' => 'EZRA J MBWILO', 'phone' => '255702635621', 'branch' => 'Kakonko', 'amount' => '600000.00', 'duration' => 'Monthly', 'repayments' => 5, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 6, 'loanAc' => '39853821290456', 'customerName' => 'REBECCA P MPPOGOLE', 'phone' => '255713121681', 'branch' => 'Kakonko', 'amount' => '2000000.00', 'duration' => 'Monthly', 'repayments' => 8, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 7, 'loanAc' => '67053217596041', 'customerName' => 'REMY I SWALEHE', 'phone' => '255654656981', 'branch' => 'Kakonko', 'amount' => '1230000.00', 'duration' => 'Monthly', 'repayments' => 7, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 8, 'loanAc' => '31993425808510', 'customerName' => 'AISHA M MTWEE', 'phone' => '255648497977', 'branch' => 'Kakonko', 'amount' => '1500000.00', 'duration' => 'Monthly', 'repayments' => 8, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 9, 'loanAc' => '31948051760729', 'customerName' => 'ELIA F NGALEMBULA', 'phone' => '255754356993', 'branch' => 'Kakonko', 'amount' => '500000.00', 'duration' => 'Monthly', 'repayments' => 4, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
            ['row' => 10, 'loanAc' => '82640150985323', 'customerName' => 'JACLINE P NDILASELA', 'phone' => '255796007151', 'branch' => 'NEW KALENGE', 'amount' => '2000000.00', 'duration' => 'Monthly', 'repayments' => 3, 'loanStatus' => 'PENDING', 'customerStatus' => 'NEW'],
        ];
    }

    /** Loan Pending Approve prints "Showing 1 to 10 of 24 entries". */
    public static function pendingLoanRowCount(): int
    {
        return 24;
    }

    /**
     * Every customer named on a captured legacy screen, with whatever that
     * screen carried about them.
     *
     * Eighteen people, drawn from the two loan lists. The All Customer screen
     * — which would give customer number, date of birth, age and gender — was
     * captured with no rows, so those fields are unknown for all eighteen and
     * are not invented here.
     *
     * Names are reproduced exactly, including legacy capitalisation (two are
     * lower case where the rest are upper) and legacy misspellings of names
     * ("JACKSPON", "CHRIZESTOM", "MPPOGOLE"). A person's name as the system
     * records it is not a spelling error to be swept.
     *
     * @return list<array{name: string, branch: string, phone: string|null}>
     */
    public static function customers(): array
    {
        return [
            ['name' => 'tumaini c katakuzi', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'CHRIZESTOM B KATAKUZI', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'ELISHA M ADAMU', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'ASHA Z JUMA', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'HASSAN J SAIDI', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'ZUENA E HASAN', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'ALY J JACKSPON', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'CATHERINI D NTABA', 'branch' => 'NEW KALENGE', 'phone' => null],
            ['name' => 'maswi m gachuma', 'branch' => 'Missenyi', 'phone' => '255769138896'],
            ['name' => 'EDIGAR J PAUL', 'branch' => 'NEW KALENGE', 'phone' => '255712456324'],
            ['name' => 'FARYJALLAH M JOHN', 'branch' => 'NEW KALENGE', 'phone' => '255758144234'],
            ['name' => 'JASITIN J LUVANGA', 'branch' => 'Kakonko', 'phone' => '255645473537'],
            ['name' => 'EZRA J MBWILO', 'branch' => 'Kakonko', 'phone' => '255702635621'],
            ['name' => 'REBECCA P MPPOGOLE', 'branch' => 'Kakonko', 'phone' => '255713121681'],
            ['name' => 'REMY I SWALEHE', 'branch' => 'Kakonko', 'phone' => '255654656981'],
            ['name' => 'AISHA M MTWEE', 'branch' => 'Kakonko', 'phone' => '255648497977'],
            ['name' => 'ELIA F NGALEMBULA', 'branch' => 'Kakonko', 'phone' => '255754356993'],
            ['name' => 'JACLINE P NDILASELA', 'branch' => 'NEW KALENGE', 'phone' => '255796007151'],
        ];
    }

    /**
     * The shape of the legacy customer registration form.
     *
     * Source: Register Customer (/admin/basic_info), step 1 of 3. The three
     * steps are labelled "Basic information", "Aditinal Detail" and
     * "Passport size & Bank Detail".
     *
     * Recorded because one detail here contradicts the written brief: District,
     * Ward and Street are FREE-TEXT INPUTS on the legacy form, not dropdowns.
     * Only Region is a select. There is therefore no legacy district, ward or
     * street lookup table to copy — our four-level geography hierarchy is
     * something we added, and seeding it from "the legacy values" is not
     * possible because the legacy system never had any.
     *
     * The phone field's placeholder is "Eg.0753(XXXX)34", which is the only
     * captured statement of the legacy phone format.
     *
     * @return array<string, string>
     */
    public static function registrationFields(): array
    {
        return [
            'First Name' => 'text',
            'Middle name' => 'text',
            'Last name' => 'text',
            'Branch' => 'select',
            'Employee' => 'select',
            'Gender' => 'select',
            'Date of Birth' => 'date',
            'Year' => 'readonly',      // derived from Date of Birth; greyed out
            'Phone Number' => 'text',  // placeholder "Eg.0753(XXXX)34"
            'Loan Type' => 'select',
            'Types of customer' => 'select',
            'Region' => 'select',
            'District' => 'text',
            'Ward' => 'text',
            'Street' => 'text',
        ];
    }

    /**
     * Lookups the legacy system demonstrably has, whose contents no captured
     * screen reveals.
     *
     * These tables are NOT empty. Each is seeded with inferred values from
     * InferredLookups, or with the working demo data the rest of the seeders
     * produce, because an empty dropdown makes the form it sits on
     * unsubmittable — a registration screen with no branches cannot be used at
     * all, and that is a worse outcome than a value that later turns out to be
     * wrong.
     *
     * What this list is, then, is the standing record of which seeded values
     * are placeholders rather than facts, and what capture would replace each
     * with the real thing. An entry here means "usable, but do not quote it
     * back to the business as their data".
     *
     * @return array<string, string>
     */
    public static function unobserved(): array
    {
        return [
            'branches' => 'Only three appear in loan data. Needs the Branch select on /admin/basic_info opened, or the branch list screen.',
            'employees' => 'The Employee select on /admin/basic_info, opened. No employee name appears anywhere in the captures.',
            'gender' => 'The Gender select opened, to confirm the legacy label text and casing. Seeded as Male/Female from our own enum, which is almost certainly right.',
            'loanTypes' => 'The Loan Type select on /admin/basic_info, opened.',
            'customerTypes' => 'The "Types of customer" select on /admin/basic_info, opened.',
            'regions' => 'The Region select opened. Tanzania has 31 regions; which subset the legacy lists is unknown.',
            'guarantors' => 'No captured screen shows a guarantor field. Step 2 ("Aditinal Detail") was never captured.',
            'banks' => 'Step 3 ("Passport size & Bank Detail") was never captured.',
            'paymentMethods' => 'The Method column on /admin/loan_withdrawal — that table was captured empty.',
            'charger' => 'The Charger column on the two Headquater Transaction screens — both captured empty.',
            'staffNames' => 'The Staff Name column on the two Headquater Transaction screens — both captured empty.',
            'hqTransactionStatuses' => 'The status column on the Headquater Transaction screens — both captured empty.',
            'customerStatuses' => 'Only NEW seen. The All Customer Status column would enumerate the rest; that screen was captured with no rows.',
            'loanStatuses' => 'Only PENDING seen. The Loan Rejected screen would show the rejected badge; it was captured empty.',
            'disbursedLoans' => 'Pages 2-4 of /admin/disburse_loan — 24 of 34 rows uncaptured.',
            'pendingLoans' => 'Pages 2-3 of /admin/loan_pending — 14 of 24 rows uncaptured.',
            'customerDetail' => 'Customer number, national ID, date of birth and gender for all eighteen known customers — currently seeded with marked synthetic values by LegacyCustomerSeeder. /admin/all_customer was captured with no rows.',
            'branchRegions' => 'Which region each branch sits in. Inferred from Tanzanian geography in InferredLookups::branchRegions(); needs the branch list screen.',
            'branchPhones' => 'Branch contact numbers. Seeded synthetic in InferredLookups::branchPhones(); needs the branch list screen.',
        ];
    }
}
