<?php

namespace Database\Seeders\DevSeed;

/**
 * The fictitious development data set (Kigoma / Kagera / Lindi microfinance operations, April – September 2026).
 * Natural keys: branch names, staff phones 07461000NN, customer phones 07579000NN, shareholder phones 07681000NN,
 * loan reasons ending "[DEVSEED-<code>]", references and idempotency keys starting "DEVSEED-".
 */
final class Catalog
{
    public const ZONE = 'KANDA YA ZIWA';

    public const MARKER = 'DEVSEED';

    /**
     * code => [name, phone, type, region]
     *
     * @var array<string, array{0: string, 1: string|null, 2: string, 3: string}>
     */
    public const BRANCHES = [
        'HQ' => ['Head office', null, 'main', 'Mwanza'],
        'KK' => ['Kakonko', null, 'main', 'Kigoma'],
        'MS' => ['Missenyi', null, 'main', 'Kagera'],
        'LN' => ['Lindi', null, 'main', 'Lindi'],
        'KB' => ['Kibondo', '0282810101', 'main', 'Kigoma'],
        'KS' => ['Kasulu', '0282810202', 'main', 'Kigoma'],
        'KM' => ['Kigoma Mjini', '0282810303', 'main', 'Kigoma'],
        'BH' => ['Buhigwe', '0282810404', 'sub', 'Kigoma'],
        'UV' => ['Uvinza', '0282810505', 'sub', 'Kigoma'],
    ];

    /** Branches created by this data set. */
    public const NEW_BRANCHES = ['KB', 'KS', 'KM', 'BH', 'UV'];

    /** District of each branch (customer addresses). */
    public const DISTRICTS = ['KK' => 'Kakonko', 'MS' => 'Missenyi', 'LN' => 'Lindi', 'KB' => 'Kibondo', 'KS' => 'Kasulu', 'KM' => 'Kigoma Urban', 'BH' => 'Buhigwe', 'UV' => 'Uvinza'];

    /**
     * key => [first, middle, last, gender, dob, branch code|null, role key, position, salary, hired at, final status]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string|null, 6: string, 7: string, 8: int, 9: string, 10: string}>
     */
    public const STAFF = [
        'HQ_HR' => ['Loveness', 'Peter', 'Mushi', 'female', '1986-11-05', 'HQ', 'hr', 'hq', 1100000, '2025-01-13 09:00', 'active'],
        'HQ_ADM' => ['Beatrice', 'Godwin', 'Temba', 'female', '1985-05-18', 'HQ', 'admin', 'hq', 1300000, '2025-01-20 09:00', 'active'],
        'HQ_FIN1' => ['Anna', 'Michael', 'Shirima', 'female', '1988-03-14', 'HQ', 'finance', 'hq', 1200000, '2025-02-03 09:00', 'active'],
        'HQ_CR1' => ['Ibrahim', 'Kassim', 'Mfinanga', 'male', '1989-01-30', 'HQ', 'credit_officer', 'hq', 1000000, '2025-03-17 09:00', 'active'],
        'HQ_FIN2' => ['Kelvin', 'Joseph', 'Massawe', 'male', '1991-07-22', 'HQ', 'finance', 'hq', 950000, '2025-06-02 09:00', 'active'],
        'HQ_CR2' => ['Moses', 'Elia', 'Lyimo', 'male', '1993-09-09', 'HQ', 'credit_officer', 'hq', 850000, '2025-10-06 09:00', 'active'],
        'KK_LO' => ['Yusuph', 'Abdallah', 'Kalinga', 'male', '1992-06-06', 'KK', 'loan_officer', 'employee', 500000, '2026-02-02 09:00', 'active'],
        'MS_LO' => ['Godfrey', 'Deogratias', 'Rutashobya', 'male', '1991-08-14', 'MS', 'loan_officer', 'employee', 500000, '2026-02-02 10:00', 'active'],
        'LN_LO' => ['Mariam', 'Saidi', 'Mnyampanda', 'female', '1993-04-27', 'LN', 'loan_officer', 'employee', 500000, '2026-02-02 11:00', 'active'],
        'ZM_ZIWA' => ['Stephen', 'Alphonce', 'Magesa', 'male', '1982-08-25', 'KM', 'zone_manager', 'zone', 1400000, '2026-03-03 09:00', 'active'],
        'KB_BM' => ['Emmanuel', 'Josephat', 'Ntahondi', 'male', '1984-04-12', 'KB', 'branch_manager', 'employee', 800000, '2026-03-09 09:00', 'active'],
        'KB_LO' => ['Rehema', 'Ally', 'Bukuru', 'female', '1994-02-20', 'KB', 'loan_officer', 'employee', 520000, '2026-03-09 10:00', 'active'],
        'KB_LO2' => ['Daudi', 'Petro', 'Kavejuru', 'male', '1996-12-01', 'KB', 'loan_officer', 'employee', 480000, '2026-03-10 09:00', 'active'],
        'KB_TL' => ['Neema', 'Yohana', 'Buyogera', 'female', '1997-06-15', 'KB', 'teller', 'employee', 420000, '2026-03-10 10:00', 'active'],
        'KS_BM' => ['Salum', 'Hamisi', 'Rashidi', 'male', '1983-10-10', 'KS', 'branch_manager', 'employee', 750000, '2026-03-11 09:00', 'active'],
        'KS_LO' => ['Agnes', 'Paulo', 'Kanyinyi', 'female', '1995-03-03', 'KS', 'loan_officer', 'employee', 500000, '2026-03-11 10:00', 'active'],
        'KS_TL' => ['Joyce', 'Elias', 'Ndayisaba', 'female', '1998-08-08', 'KS', 'teller', 'employee', 400000, '2026-03-11 11:00', 'active'],
        'KM_BM' => ['Hassani', 'Omari', 'Kiza', 'male', '1980-12-24', 'KM', 'branch_manager', 'employee', 850000, '2026-03-12 09:00', 'active'],
        'KM_LO' => ['Mwanaisha', 'Juma', 'Mussa', 'female', '1993-05-05', 'KM', 'loan_officer', 'employee', 520000, '2026-03-12 10:00', 'active'],
        'KM_LO2' => ['Baraka', 'Samweli', 'Lukas', 'male', '1997-01-17', 'KM', 'loan_officer', 'employee', 480000, '2026-03-12 11:00', 'active'],
        'KM_TL' => ['Upendo', 'Festo', 'Ngeze', 'female', '1996-09-29', 'KM', 'teller', 'employee', 420000, '2026-03-12 12:00', 'active'],
        'BH_BM' => ['Faustine', 'Leonard', 'Bitegeko', 'male', '1987-07-07', 'BH', 'branch_manager', 'employee', 720000, '2026-03-16 09:00', 'active'],
        'BH_LO' => ['Zawadi', 'Ramadhani', 'Seif', 'female', '1995-11-11', 'BH', 'loan_officer', 'employee', 480000, '2026-03-16 10:00', 'active'],
        'BH_TL' => ['Paschal', 'Mathias', 'Nzigo', 'male', '1998-04-04', 'BH', 'teller', 'employee', 400000, '2026-03-16 11:00', 'active'],
        'UV_BM' => ['Grace', 'Anthony', 'Mapunda', 'female', '1986-02-02', 'UV', 'branch_manager', 'employee', 720000, '2026-03-17 09:00', 'active'],
        'UV_LO' => ['Idrisa', 'Selemani', 'Kasimu', 'male', '1994-10-19', 'UV', 'loan_officer', 'employee', 480000, '2026-03-17 10:00', 'active'],
        'UV_TL' => ['Happiness', 'Charles', 'Mbwambo', 'female', '1999-12-12', 'UV', 'teller', 'employee', 380000, '2026-03-17 11:00', 'active'],
        'KS_LO_REJ' => ['Juma', 'Rajabu', 'Chande', 'male', '1995-05-30', 'KS', 'loan_officer', 'employee', 480000, '2026-03-20 09:00', 'rejected'],
        'UV_TL_REJ' => ['Sophia', 'Ezekiel', 'Nyirenda', 'female', '1998-01-09', 'UV', 'teller', 'employee', 380000, '2026-03-20 10:00', 'rejected'],
        'BH_LO_BLK' => ['Peter', 'Nassoro', 'Mwita', 'male', '1996-07-21', 'BH', 'loan_officer', 'employee', 460000, '2026-03-20 11:00', 'blocked'],
    ];

    /**
     * Loan categories: key => definition. Fees (type, value, insurance) are set through Settings → Loan Fees.
     *
     * @var array<string, array<string, mixed>>
     */
    public const LOAN_CATEGORIES = [
        'WAJ_W' => ['name' => 'DEV BIASHARA WIKI', 'type' => 'WAJASIRIAMALI', 'from' => 100000, 'to' => 2500000, 'rate' => 25, 'formula' => 'SIMPLE', 'duration' => 'weekly', 'rep' => [2, 8], 'fee_deduct' => 'YES', 'penalty' => 'YES', 'approve' => 'branch', 'topup' => 50, 'take_home' => 70, 'mandate' => 'NO', 'freeze' => 7, 'fee' => ['PERCENTAGE', 2, 5000]],
        'WAJ_M' => ['name' => 'DEV BIASHARA KUBWA', 'type' => 'WAJASIRIAMALI', 'from' => 1000000, 'to' => 6000000, 'rate' => 6, 'formula' => 'FLATRATE', 'duration' => 'monthly', 'rep' => [2, 6], 'fee_deduct' => 'YES', 'penalty' => 'YES', 'approve' => 'hq', 'topup' => 60, 'take_home' => 70, 'mandate' => 'NO', 'freeze' => 0, 'fee' => ['PERCENTAGE', 2.5, 20000]],
        'MTU' => ['name' => 'DEV MTUMISHI MSHAHARA', 'type' => 'WATUMISHI_WA_UMMA', 'from' => 500000, 'to' => 5000000, 'rate' => 30, 'formula' => 'SIMPLE', 'duration' => 'monthly', 'rep' => [2, 6], 'fee_deduct' => 'YES', 'penalty' => 'YES', 'approve' => 'hq', 'topup' => 50, 'take_home' => 60, 'mandate' => 'YES', 'freeze' => 7, 'fee' => ['PERCENTAGE', 1.5, 0]],
        'BIN' => ['name' => 'DEV BINAFSI MDOGO', 'type' => 'SEKTA_BINAFSI', 'from' => 200000, 'to' => 1500000, 'rate' => 20, 'formula' => 'SIMPLE', 'duration' => 'monthly', 'rep' => [1, 3], 'fee_deduct' => 'NO', 'penalty' => 'YES', 'approve' => 'branch', 'topup' => 50, 'take_home' => 70, 'mandate' => 'NO', 'freeze' => 0, 'fee' => ['MONEY', 15000, 0]],
        'B30' => ['name' => 'DEV BINAFSI SIKU 30', 'type' => 'SEKTA_BINAFSI', 'from' => 100000, 'to' => 800000, 'rate' => 15, 'formula' => 'SIMPLE', 'duration' => 'monthly', 'rep' => [1, 1], 'fee_deduct' => 'YES', 'penalty' => 'NO', 'approve' => 'branch', 'topup' => 0, 'take_home' => 80, 'mandate' => 'NO', 'freeze' => 30, 'fee' => ['PERCENTAGE', 2, 0]],
        'STU' => ['name' => 'DEV MWANAFUNZI ADA', 'type' => 'MWANAFUNZI_CHUO', 'from' => 100000, 'to' => 800000, 'rate' => 15, 'formula' => 'SIMPLE', 'duration' => 'monthly', 'rep' => [1, 4], 'fee_deduct' => 'NO', 'penalty' => 'NO', 'approve' => 'branch', 'topup' => 0, 'take_home' => 100, 'mandate' => 'NO', 'freeze' => 0, 'fee' => ['MONEY', 0, 0]],
        'PEN' => ['name' => 'DEV MSTAAFU PENSHENI', 'type' => 'MSTAAFU_UMMA', 'from' => 300000, 'to' => 3000000, 'rate' => 5, 'formula' => 'FLATRATE', 'duration' => 'monthly', 'rep' => [2, 10], 'fee_deduct' => 'YES', 'penalty' => 'YES', 'approve' => 'zone manager', 'topup' => 50, 'take_home' => 60, 'mandate' => 'NO', 'freeze' => 7, 'fee' => ['PERCENTAGE', 2, 10000]],
    ];

    /** Customer type of each loan category key (and of customers without a loan). */
    public const TYPE_OF = ['WAJ_W' => 'WAJASIRIAMALI', 'WAJ_M' => 'WAJASIRIAMALI', 'MTU' => 'WATUMISHI_WA_UMMA', 'BIN' => 'SEKTA_BINAFSI', 'B30' => 'SEKTA_BINAFSI', 'STU' => 'MWANAFUNZI_CHUO', 'PEN' => 'MSTAAFU_UMMA'];

    /**
     * Expense types to add: [scope, name].
     *
     * @var list<array{0: string, 1: string}>
     */
    public const EXPENSE_TYPES = [
        ['branch', 'Kodi ya Ofisi'], ['branch', 'Usafiri'], ['branch', 'Mawasiliano'], ['branch', 'Matengenezo'], ['branch', 'Vifaa vya Ofisi'], ['branch', 'Usafi'],
        ['hq', 'Ukaguzi wa Hesabu'], ['hq', 'Mafunzo ya Wafanyakazi'], ['hq', 'Leseni na Vibali'],
        ['bank', 'Ada za Benki'],
    ];

    /**
     * Loans. Keys: code, branch, cat, amount, sessions, apply (date the application is made), stop (last workflow
     * step: apply|manager|credit|prepare|reject_manager|reject_credit|return|full), source (cash|CRDB|NMB), plan
     * (repayments relative to the disbursement date W: "due:a-b" instalments on their due date, "late:k:+d" instalment k
     * d days late, "amt:+d:amount" a partial payment, "full:+d" the whole outstanding balance), channel (cash|mpesa),
     * topup (code of the running loan it refinances), writeoff (date), name (customer name override).
     *
     * @return list<array<string, mixed>>
     */
    public static function loans(): array
    {
        $loans = [];
        $offsets = ['KB' => 0, 'KS' => 1, 'KM' => 2, 'BH' => 3, 'UV' => 4];

        foreach ($offsets as $branch => $offset) {
            $day = fn (string $date): string => date('Y-m-d', strtotime($date." +{$offset} days"));
            $salaryProduct = in_array($branch, ['KS', 'UV'], true) ? ['PEN', 3000000] : ['MTU', 4000000];

            $loans[] = ['code' => "{$branch}-T1", 'branch' => $branch, 'cat' => 'WAJ_W', 'amount' => 1000000, 'sessions' => 4, 'apply' => $day('2026-04-06'), 'plan' => $branch === 'KS' ? ['due:1-1', 'amt:+14:150000', 'full:+21'] : ['due:1-4']];
            $loans[] = ['code' => "{$branch}-T2", 'branch' => $branch, 'cat' => $salaryProduct[0], 'amount' => $salaryProduct[1], 'sessions' => 2, 'apply' => $day('2026-04-08'), 'plan' => ['due:1-2']];
            $loans[] = ['code' => "{$branch}-T3", 'branch' => $branch, 'cat' => 'WAJ_M', 'amount' => 3500000, 'sessions' => 2, 'apply' => $day('2026-04-13'), 'plan' => ['due:1-2']];
            $loans[] = ['code' => "{$branch}-T4", 'branch' => $branch, 'cat' => 'BIN', 'amount' => 700000, 'sessions' => 3, 'apply' => $day('2026-05-04'), 'plan' => $branch === 'KB' ? ['due:1-1', 'full:+40'] : ['due:1-1', 'late:2:+8', 'due:3-3']];
            $loans[] = ['code' => "{$branch}-T5", 'branch' => $branch, 'cat' => 'WAJ_W', 'amount' => 2000000, 'sessions' => 5, 'apply' => $day('2026-05-18'), 'plan' => ['due:1-5'], 'channel' => 'mpesa'];
        }

        return [
            ...$loans,
            // Kibondo
            ['code' => 'KB-T6', 'branch' => 'KB', 'cat' => 'WAJ_W', 'amount' => 800000, 'sessions' => 8, 'apply' => '2026-07-20', 'plan' => ['due:1-4']],
            ['code' => 'KB-T7', 'branch' => 'KB', 'cat' => 'STU', 'amount' => 400000, 'sessions' => 2, 'apply' => '2026-08-10', 'plan' => ['due:1-1']],
            ['code' => 'KB-T8', 'branch' => 'KB', 'cat' => 'PEN', 'amount' => 1500000, 'sessions' => 6, 'apply' => '2026-09-11', 'stop' => 'apply'],
            // Kasulu
            ['code' => 'KS-T6', 'branch' => 'KS', 'cat' => 'BIN', 'amount' => 900000, 'sessions' => 3, 'apply' => '2026-05-12', 'plan' => ['due:1-1']],
            ['code' => 'KS-T7', 'branch' => 'KS', 'cat' => 'MTU', 'amount' => 2500000, 'sessions' => 3, 'apply' => '2026-09-10', 'stop' => 'manager'],
            ['code' => 'KS-T8', 'branch' => 'KS', 'cat' => 'WAJ_W', 'amount' => 1200000, 'sessions' => 6, 'apply' => '2026-09-12', 'stop' => 'prepare', 'source' => 'CRDB'],
            // Kigoma Mjini
            ['code' => 'KM-T6', 'branch' => 'KM', 'cat' => 'WAJ_M', 'amount' => 5000000, 'sessions' => 3, 'apply' => '2026-07-06', 'plan' => ['due:1-2'], 'source' => 'CRDB'],
            ['code' => 'KM-T7', 'branch' => 'KM', 'cat' => 'B30', 'amount' => 600000, 'sessions' => 1, 'apply' => '2026-08-31', 'plan' => ['full:+6'], 'name' => ['DEV', 'FREEZE', 'ACTIVE', 'male']],
            ['code' => 'KM-T8', 'branch' => 'KM', 'cat' => 'WAJ_W', 'amount' => 1600000, 'sessions' => 8, 'apply' => '2026-06-14', 'plan' => ['due:1-5']],
            ['code' => 'KM-T8B', 'branch' => 'KM', 'cat' => 'WAJ_W', 'amount' => 2400000, 'sessions' => 6, 'apply' => '2026-07-21', 'plan' => ['due:1-6'], 'topup' => 'KM-T8'],
            // Buhigwe
            ['code' => 'BH-T6', 'branch' => 'BH', 'cat' => 'WAJ_W', 'amount' => 600000, 'sessions' => 6, 'apply' => '2026-05-25', 'plan' => ['due:1-2'], 'writeoff' => '2026-08-25'],
            ['code' => 'BH-T7', 'branch' => 'BH', 'cat' => 'WAJ_W', 'amount' => 1000000, 'sessions' => 6, 'apply' => '2026-08-09', 'plan' => ['due:1-2']],
            ['code' => 'BH-T8', 'branch' => 'BH', 'cat' => 'PEN', 'amount' => 2000000, 'sessions' => 5, 'apply' => '2026-09-11', 'stop' => 'credit'],
            // Uvinza
            ['code' => 'UV-T6', 'branch' => 'UV', 'cat' => 'B30', 'amount' => 500000, 'sessions' => 1, 'apply' => '2026-06-30', 'plan' => ['full:+4'], 'name' => ['DEV', 'FREEZE', 'EXPIRED', 'female']],
            ['code' => 'UV-T7', 'branch' => 'UV', 'cat' => 'PEN', 'amount' => 1200000, 'sessions' => 6, 'apply' => '2026-08-02', 'plan' => ['due:1-1'], 'source' => 'CRDB'],
            ['code' => 'UV-T8', 'branch' => 'UV', 'cat' => 'STU', 'amount' => 300000, 'sessions' => 2, 'apply' => '2026-09-03', 'stop' => 'reject_credit'],
            // Kakonko
            ['code' => 'KK-1', 'branch' => 'KK', 'cat' => 'WAJ_W', 'amount' => 1000000, 'sessions' => 5, 'apply' => '2026-04-20', 'plan' => ['due:1-5'], 'channel' => 'mpesa'],
            ['code' => 'KK-2', 'branch' => 'KK', 'cat' => 'MTU', 'amount' => 3000000, 'sessions' => 2, 'apply' => '2026-04-21', 'plan' => ['due:1-2']],
            ['code' => 'KK-3', 'branch' => 'KK', 'cat' => 'BIN', 'amount' => 500000, 'sessions' => 1, 'apply' => '2026-08-16', 'plan' => []],
            ['code' => 'KK-4', 'branch' => 'KK', 'cat' => 'WAJ_M', 'amount' => 2000000, 'sessions' => 3, 'apply' => '2026-09-11', 'stop' => 'manager'],
            // Missenyi
            ['code' => 'MS-1', 'branch' => 'MS', 'cat' => 'WAJ_M', 'amount' => 2000000, 'sessions' => 2, 'apply' => '2026-04-15', 'plan' => ['due:1-2']],
            ['code' => 'MS-2', 'branch' => 'MS', 'cat' => 'WAJ_W', 'amount' => 700000, 'sessions' => 4, 'apply' => '2026-06-01', 'plan' => ['due:1-4']],
            ['code' => 'MS-3', 'branch' => 'MS', 'cat' => 'STU', 'amount' => 350000, 'sessions' => 2, 'apply' => '2026-08-05', 'stop' => 'reject_manager'],
            ['code' => 'MS-4', 'branch' => 'MS', 'cat' => 'PEN', 'amount' => 1000000, 'sessions' => 4, 'apply' => '2026-07-13', 'plan' => ['late:1:+7', 'due:2-2']],
            // Lindi
            ['code' => 'LN-1', 'branch' => 'LN', 'cat' => 'WAJ_W', 'amount' => 900000, 'sessions' => 3, 'apply' => '2026-04-12', 'plan' => ['due:1-3']],
            ['code' => 'LN-2', 'branch' => 'LN', 'cat' => 'BIN', 'amount' => 800000, 'sessions' => 2, 'apply' => '2026-05-17', 'plan' => ['due:1-1', 'full:+77']],
            ['code' => 'LN-3', 'branch' => 'LN', 'cat' => 'WAJ_M', 'amount' => 2500000, 'sessions' => 2, 'apply' => '2026-04-19', 'plan' => ['due:1-2']],
            ['code' => 'LN-4', 'branch' => 'LN', 'cat' => 'STU', 'amount' => 400000, 'sessions' => 2, 'apply' => '2026-09-08', 'stop' => 'return'],
        ];
    }

    /**
     * Customers without a loan: [branch, type, face verified, approval, registered at].
     *
     * @var list<array{0: string, 1: string, 2: bool, 3: string, 4: string}>
     */
    public const EXTRA_CUSTOMERS = [
        ['KS', 'SEKTA_BINAFSI', true, 'rejected', '2026-08-18 10:00'],
        ['BH', 'WAJASIRIAMALI', true, 'rejected', '2026-08-19 10:00'],
        ['LN', 'MWANAFUNZI_CHUO', true, 'rejected', '2026-08-20 10:00'],
        ['KB', 'MSTAAFU_UMMA', false, 'pending', '2026-09-01 10:00'],
        ['KM', 'WATUMISHI_WA_UMMA', false, 'pending', '2026-09-02 10:00'],
        ['UV', 'WAJASIRIAMALI', false, 'pending', '2026-09-03 10:00'],
        ['KK', 'SEKTA_BINAFSI', false, 'pending', '2026-09-04 10:00'],
        ['MS', 'WAJASIRIAMALI', false, 'pending', '2026-09-07 10:00'],
        ['KM', 'MWANAFUNZI_CHUO', true, 'pending', '2026-09-09 10:00'],
        ['KB', 'WAJASIRIAMALI', true, 'pending', '2026-09-10 10:00'],
    ];

    /** @var list<string> */
    public const FEMALE_NAMES = ['Amina', 'Halima', 'Mwajuma', 'Scholastica', 'Tumaini', 'Pendo', 'Rukia', 'Veronica', 'Asha', 'Esther', 'Salma', 'Janeth', 'Winfrida', 'Mwanahawa', 'Faraja', 'Anastazia', 'Sikudhani', 'Rosemary', 'Zuhura', 'Imelda', 'Getrude', 'Leah', 'Tatu', 'Mariamu', 'Neema', 'Stella', 'Hawa', 'Agripina', 'Mwamvita', 'Christina'];

    /** @var list<string> */
    public const MALE_NAMES = ['Juma', 'Athumani', 'Elias', 'Nuhu', 'Bakari', 'Kassimu', 'Anselm', 'Mussa', 'Kulwa', 'Doto', 'Jonas', 'Abdallah', 'Hamza', 'Gervas', 'Masudi', 'Elisha', 'Ramadhani', 'Onesmo', 'Shabani', 'Deusdedit', 'Hussein', 'Felician', 'Mohamedi', 'Yakobo', 'Zakaria', 'Makame', 'Benedicto', 'Selemani', 'Titus', 'Issa'];

    /** @var list<string> */
    public const LAST_NAMES = ['Ruhumbika', 'Kabura', 'Bilinganya', 'Kaziyareli', 'Ntibonera', 'Mpangala', 'Nsekela', 'Kalumuna', 'Rwegasira', 'Bisanda', 'Nkwabi', 'Kajoro', 'Ngendakumana', 'Sindayigaya', 'Mbonimpa', 'Kanyamala', 'Rwakatare', 'Kitambi', 'Nyamwihura', 'Ntungwa', 'Kashindye', 'Mushumbusi', 'Bigirwa', 'Nzeyimana', 'Mkwawa', 'Luhende', 'Kaboneke', 'Ndalichako', 'Mahenge', 'Kasoga', 'Mnyawami', 'Chilumba', 'Bwatota', 'Kinyaga'];

    /**
     * Shareholders: key => [first, middle, last, gender, dob].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const SHAREHOLDERS = [
        'SH1' => ['Abdul', 'Rahman', 'Kitwana', 'male', '1970-02-11'],
        'SH2' => ['Winfrida', 'Paul', 'Kagoma', 'female', '1974-06-23'],
        'SH3' => ['Musa', 'Hussein', 'Lugendo', 'male', '1968-10-30'],
        'SH4' => ['Consolata', 'Mary', 'Minja', 'female', '1979-01-15'],
        'SH5' => ['Ramadhani', 'Twaha', 'Kombo', 'male', '1981-09-05'],
    ];

    /**
     * Capital contributions: [key, shareholder, amount, CASH|BANK, bank name, contributed at, shares linked on 14 Sep].
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string, 4: string|null, 5: string, 6: int|null}>
     */
    public const CONTRIBUTIONS = [
        ['CAP-001', 'SH1', 20000000, 'BANK', 'NMB', '2026-04-01 10:00', 200],
        ['CAP-002', 'SH2', 15000000, 'CASH', null, '2026-04-01 11:00', 150],
        ['CAP-003', 'SH3', 10000000, 'BANK', 'CRDB', '2026-04-01 12:00', 100],
        ['CAP-004', 'SH4', 10000000, 'CASH', null, '2026-04-01 14:00', 100],
        ['CAP-005', 'SH5', 5000000, 'BANK', 'NMB', '2026-04-01 15:00', 50],
        ['CAP-006', 'SH3', 4000000, 'BANK', 'NMB', '2026-04-01 16:00', null],
        ['CAP-007', 'SH4', 15000000, 'CASH', null, '2026-06-30 10:00', null],
        ['CAP-008', 'SH1', 5000000, 'CASH', null, '2026-07-10 10:00', null],
    ];
}
