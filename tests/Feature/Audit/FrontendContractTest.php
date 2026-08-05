<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;

/**
 * The frontend is the API contract, so this asserts it directly: every field a
 * Zod schema in `mikopofasta_web/types/*.ts` declares must be present in the
 * matching API response, spelled the same way.
 *
 * A missing field is a runtime parse failure in the browser. An extra field is
 * not — Zod strips unknown keys by default — so extras are allowed and noted
 * rather than failed, since several resources deliberately carry a resolved
 * display name the frontend reads from its own store.
 */
beforeEach(function (): void {
    seedStaffBook();
    finalizedPayrollRun();

    // Templates are reference data nothing else seeds, and the schema below
    // cannot be checked against an empty table.
    test()->seed(Database\Seeders\NotificationTemplateSeeder::class);

    forgetAuthGuards();
});

/**
 * Required keys per schema, transcribed from the frontend's Zod definitions.
 *
 * @return array<string, list<string>>
 */
function contractSchemas(): array
{
    return [
        'CustomerSchema' => [
            'id', 'customerNumber', 'nidaNumber', 'firstName', 'middleName', 'lastName', 'dob',
            'gender', 'phone', 'photoPath', 'nidaVerifiedAt', 'otpVerifiedAt', 'faceVerifiedAt',
            'maritalStatus', 'regionId', 'districtId', 'wardId', 'streetId', 'residenceType',
            'customerCategoryId', 'dynamicFormData', 'branchId', 'kycStatus', 'status',
            'approvalStatus', 'approvedBy', 'approvedAt', 'rejectionReason', 'createdBy',
            'createdAt', 'deletedAt',
        ],
        'LoanSchema' => [
            'id', 'loanNumber', 'customerId', 'loanProductId', 'repaymentScheduleId', 'groupId',
            'branchId', 'officerId', 'principalAmount', 'interestRateSnapshot', 'penaltyRateSnapshot',
            'tenureDays', 'requiresMandateSnapshot', 'status', 'disbursementDate',
            'expectedCompletionDate', 'approvedBy', 'approvedAt', 'rejectedReason', 'closedAt',
            'frozenUntil', 'createdBy', 'deletedAt',
            // Flat on every loan, including the list: a consumer must be able
            // to ask "was this settled early" of any loan, not only of one it
            // fetched individually. The `earlySettlement` block is deliberately
            // NOT here — it is absent unless the caller loaded it.
            'earlySettledAt', 'interestWaived',
        ],
        'LoanScheduleSchema' => [
            'id', 'loanId', 'installmentNumber', 'dueDate', 'principalDue', 'interestDue',
            'penaltyDue', 'principalPaid', 'interestPaid', 'penaltyPaid', 'status',
        ],
        'PaymentSchema' => [
            'id', 'paymentReference', 'loanId', 'customerId', 'amount', 'channel', 'transactionId',
            'status', 'branchId', 'tellerId', 'receivedAt', 'confirmedAt', 'createdBy',
        ],
        'PaymentAllocationSchema' => [
            'id', 'paymentId', 'loanScheduleId', 'penaltyAllocated', 'interestAllocated',
            'principalAllocated', 'createdAt',
        ],
        'SuspenseItemSchema' => ['id', 'paymentId', 'reason', 'amount', 'status', 'resolvedBy', 'resolvedAt'],
        'ChartOfAccountSchema' => [
            'id', 'code', 'name', 'type', 'parentAccountId', 'isSystem', 'branchId', 'status', 'deletedAt',
        ],
        'JournalEntrySchema' => [
            'id', 'entryNumber', 'entryDate', 'description', 'sourceType', 'sourceId', 'isReversal',
            'reversedEntryId', 'createdBy', 'postedAt',
        ],
        'JournalEntryLineSchema' => [
            'id', 'journalEntryId', 'accountId', 'debitAmount', 'creditAmount', 'branchId',
            'customerId', 'loanId', 'staffProfileId',
        ],
        'BranchSchema' => [
            'id', 'name', 'regionId', 'zoneId', 'phone', 'type', 'parentBranchId', 'isHeadOffice',
            'status', 'createdBy', 'deletedAt',
        ],
        'UserSchema' => [
            'id', 'name', 'phone', 'email', 'role', 'branchId', 'zoneId', 'regionId', 'status',
            'lastLoginAt', 'createdBy', 'deletedAt',
        ],
        'StaffProfileSchema' => [
            'id', 'userId', 'employeeNumber', 'branchId', 'zoneId', 'baseSalary',
            'commissionEligible', 'paymentMethod', 'employmentStatus', 'hiredAt', 'deletedAt',
        ],
        'PayrollRunSchema' => ['id', 'period', 'status', 'generatedBy', 'finalizedAt'],
        'PayrollLineSchema' => [
            'id', 'payrollRunId', 'staffProfileId', 'baseSalary', 'commissionAmount',
            'allowancesTotal', 'deductionsTotal', 'netSalary', 'journalEntryId',
        ],
        'CommissionPoolSchema' => [
            'id', 'branchId', 'period', 'branchProfit', 'lossCarryForward', 'hqHoldAmount',
            'distributableProfit', 'poolPercentage', 'poolAmount',
        ],
        'ZoneCommissionDistributionSchema' => [
            'id', 'zoneId', 'period', 'totalPoolBase', 'overridePercentage', 'overrideAmount',
            'journalEntryId',
        ],
        'StaffAdvanceSchema' => [
            'id', 'staffProfileId', 'amount', 'status', 'requestedAt', 'approvedBy', 'approvedAt',
            'disbursedAt', 'journalEntryId',
        ],
        'StaffLoanSchema' => ['id', 'staffProfileId', 'amount', 'status', 'disbursedAt', 'journalEntryId'],
        'StaffPerformanceRecordSchema' => [
            'id', 'staffProfileId', 'period', 'targets', 'achieved', 'rating', 'recordedBy',
        ],
        'LoanProductSchema' => [
            'id', 'name', 'code', 'interestFormulaId', 'interestRate', 'minAmount', 'maxAmount',
            'minTenureDays', 'maxTenureDays', 'penaltyType', 'penaltyRate', 'penaltyGraceDays',
            'penaltyCapAmount', 'requiresMandate', 'status', 'createdBy', 'deletedAt',
        ],
        'CustomerCategorySchema' => [
            'id', 'name', 'code', 'riskTier', 'sector', 'requiredDocuments', 'dynamicFormSchema',
            'requiresExtraApproval', 'createdBy', 'deletedAt',
        ],
        'GuarantorSchema' => [
            'id', 'customerId', 'name', 'phone', 'nidaNumber', 'relationship', 'address',
            'occupation', 'createdAt',
        ],
        'NextOfKinSchema' => ['id', 'customerId', 'name', 'relationship', 'phone', 'address', 'createdAt'],
        'DisbursementBatchSchema' => [
            'id', 'loanId', 'batchReference', 'attemptNumber', 'channel', 'status', 'failureReason',
            'requestedBy', 'requestedAt', 'completedAt',
        ],
        'ReversalRequestSchema' => ['id', 'journalEntryId', 'requestedBy', 'reason', 'approvedBy', 'status'],
        'ZoneSchema' => ['id', 'name', 'zoneManagerId', 'deletedAt'],
        'RegionSchema' => ['id', 'name'],
        'InterestFormulaSchema' => ['id', 'name', 'code', 'description', 'deletedAt'],
        'RepaymentScheduleSchema' => ['id', 'name', 'code', 'frequencyDays', 'deletedAt'],
        'NotificationTemplateSchema' => [
            'id', 'name', 'triggerEvent', 'channel', 'subject', 'body', 'active', 'updatedBy',
            'updatedAt',
        ],
        'AuditLogSchema' => [
            'id', 'userId', 'action', 'auditableType', 'auditableId', 'beforeJson', 'afterJson',
            'ipAddress', 'userAgent', 'createdAt',
        ],
    ];
}

/**
 * Which endpoint yields a record of each schema, and how to reach the record
 * inside the response envelope.
 *
 * @return array<string, array{uri: string, path: string}>
 */
function contractEndpoints(): array
{
    return [
        'CustomerSchema' => ['uri' => '/api/v1/customers', 'path' => 'data.0'],
        'LoanSchema' => ['uri' => '/api/v1/loans', 'path' => 'data.0'],
        'PaymentSchema' => ['uri' => '/api/v1/payments', 'path' => 'data.0'],
        'ChartOfAccountSchema' => ['uri' => '/api/v1/ledger/accounts', 'path' => 'data.0'],
        'JournalEntrySchema' => ['uri' => '/api/v1/ledger/entries', 'path' => 'data.0'],
        'BranchSchema' => ['uri' => '/api/v1/branches', 'path' => 'data.0'],
        'UserSchema' => ['uri' => '/api/v1/users', 'path' => 'data.0'],
        'StaffProfileSchema' => ['uri' => '/api/v1/staff', 'path' => 'data.0'],
        'PayrollRunSchema' => ['uri' => '/api/v1/payroll', 'path' => 'data.0'],
        'CommissionPoolSchema' => ['uri' => '/api/v1/commission', 'path' => 'data.0'],
        'StaffAdvanceSchema' => ['uri' => '/api/v1/staff/advances', 'path' => 'data.0'],
        'StaffLoanSchema' => ['uri' => '/api/v1/staff/loans', 'path' => 'data.0'],
        'StaffPerformanceRecordSchema' => ['uri' => '/api/v1/staff/performance', 'path' => 'data.0'],
        'LoanProductSchema' => ['uri' => '/api/v1/loan-products', 'path' => 'data.0'],
        'CustomerCategorySchema' => ['uri' => '/api/v1/customer-categories', 'path' => 'data.0'],
        'ZoneSchema' => ['uri' => '/api/v1/zones', 'path' => 'data.0'],
        'RegionSchema' => ['uri' => '/api/v1/regions', 'path' => 'data.0'],
        'InterestFormulaSchema' => ['uri' => '/api/v1/interest-formulas', 'path' => 'data.0'],
        'RepaymentScheduleSchema' => ['uri' => '/api/v1/repayment-schedules', 'path' => 'data.0'],
        'NotificationTemplateSchema' => ['uri' => '/api/v1/notification-templates', 'path' => 'data.0'],
        'AuditLogSchema' => ['uri' => '/api/v1/audit-logs', 'path' => 'data.0'],
    ];
}

it('emits every field the frontend Zod schemas require', function (): void {
    actingAsSeededUser('0754000001');

    $schemas = contractSchemas();
    $missing = [];

    foreach (contractEndpoints() as $schema => $endpoint) {
        $response = test()->getJson($endpoint['uri']);

        expect($response->getStatusCode())->toBe(200, "{$endpoint['uri']} did not return 200");

        $record = $response->json($endpoint['path']);

        if ($record === null) {
            $missing[] = "{$schema}: no record at {$endpoint['uri']} to check";

            continue;
        }

        foreach ($schemas[$schema] as $key) {
            if (! array_key_exists($key, $record)) {
                $missing[] = "{$schema}.{$key} absent from {$endpoint['uri']}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('emits nested schedule, allocation and payroll-line records in full', function (): void {
    actingAsSeededUser('0754000001');

    $schemas = contractSchemas();
    $missing = [];

    $loanId = test()->getJson('/api/v1/loans')->json('data.0.id');
    $schedule = test()->getJson("/api/v1/loans/{$loanId}/schedule")->assertOk()->json('data.0');

    foreach ($schemas['LoanScheduleSchema'] as $key) {
        array_key_exists($key, $schedule ?? []) || $missing[] = "LoanScheduleSchema.{$key}";
    }

    $paymentId = test()->getJson('/api/v1/payments')->json('data.0.id');
    $allocation = test()->getJson("/api/v1/payments/{$paymentId}")->assertOk()->json('data.allocations.0');

    foreach ($schemas['PaymentAllocationSchema'] as $key) {
        array_key_exists($key, $allocation ?? []) || $missing[] = "PaymentAllocationSchema.{$key}";
    }

    $runId = test()->getJson('/api/v1/payroll')->json('data.0.id');
    $line = test()->getJson("/api/v1/payroll/{$runId}")->assertOk()->json('data.lines.0');

    foreach ($schemas['PayrollLineSchema'] as $key) {
        array_key_exists($key, $line ?? []) || $missing[] = "PayrollLineSchema.{$key}";
    }

    $entryId = test()->getJson('/api/v1/ledger/entries')->json('data.0.id');
    $entryLine = test()->getJson("/api/v1/ledger/entries/{$entryId}")->assertOk()->json('data.lines.0');

    foreach ($schemas['JournalEntryLineSchema'] as $key) {
        array_key_exists($key, $entryLine ?? []) || $missing[] = "JournalEntryLineSchema.{$key}";
    }

    expect($missing)->toBe([]);
});

it('returns every id as a string, because the frontend types them z.string()', function (): void {
    actingAsSeededUser('0754000001');

    $offenders = [];

    foreach (contractEndpoints() as $schema => $endpoint) {
        $record = test()->getJson($endpoint['uri'])->json($endpoint['path']);

        foreach ($record ?? [] as $key => $value) {
            // Every id-shaped key must be a string or null — never a JSON
            // number, which the frontend's z.string() would reject.
            if ((str_ends_with($key, 'Id') || $key === 'id') && $value !== null && ! is_string($value)) {
                $offenders[] = sprintf('%s.%s is %s', $schema, $key, get_debug_type($value));
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('returns every money field as a decimal string, never a float', function (): void {
    actingAsSeededUser('0754000001');

    $moneyKeys = [
        'amount', 'principalAmount', 'baseSalary', 'netSalary', 'commissionAmount',
        'allowancesTotal', 'deductionsTotal', 'debitAmount', 'creditAmount', 'poolAmount',
        'branchProfit', 'hqHoldAmount', 'distributableProfit', 'lossCarryForward',
        'overrideAmount', 'totalPoolBase', 'minAmount', 'maxAmount',
        // Waived interest is money like any other. A float here would round a
        // figure the institution is writing off, on the one record that exists
        // to say exactly how much it wrote off.
        'interestWaived',
    ];

    $offenders = [];

    foreach (contractEndpoints() as $schema => $endpoint) {
        $record = test()->getJson($endpoint['uri'])->json($endpoint['path']);

        foreach ($record ?? [] as $key => $value) {
            if (! in_array($key, $moneyKeys, true) || $value === null) {
                continue;
            }

            // A float has already lost precision by the time it is JSON.
            if (! is_string($value) || preg_match('/^-?\d+\.\d{2}$/', $value) !== 1) {
                $offenders[] = sprintf('%s.%s = %s (%s)', $schema, $key, var_export($value, true), get_debug_type($value));
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('wraps every success in the §1 envelope and every error in its own', function (): void {
    actingAsSeededUser('0754000001');

    // Success: { data, meta? }
    $ok = test()->getJson('/api/v1/customers')->assertOk();
    expect($ok->json())->toHaveKeys(['data', 'meta'])
        ->and($ok->json('meta.pagination'))->toHaveKeys(['page', 'perPage', 'total', 'lastPage']);

    // Validation: 422 with { message, error_code, errors }
    $invalid = test()->postJson('/api/v1/payroll/generate', ['period' => 'nope'])->assertStatus(422);
    expect($invalid->json())->toHaveKeys(['message', 'error_code', 'errors']);

    // Not found: 404 with { message, error_code } and no errors map
    $missing = test()->getJson('/api/v1/customers/999999')->assertStatus(404);
    expect($missing->json())->toHaveKeys(['message', 'error_code'])
        ->and($missing->json('error_code'))->toBe('RESOURCE_NOT_FOUND')
        ->and($missing->json())->not->toHaveKey('errors');

    // Forbidden: 403 with FORBIDDEN
    officerAt('Kakonko', RoleName::LoanOfficer);
    $denied = test()->getJson('/api/v1/users')->assertStatus(403);
    expect($denied->json('error_code'))->toBe('FORBIDDEN');

    // Unauthenticated: 401
    forgetAuthGuards();
    $anon = test()->getJson('/api/v1/customers')->assertStatus(401);
    expect($anon->json('error_code'))->toBe('UNAUTHENTICATED');
});

it('returns the documented status code for each kind of outcome', function (): void {
    actingAsSeededUser('0754000001');

    // 200 read
    test()->getJson('/api/v1/branches')->assertStatus(200);

    // 201 create
    actingAsHr();
    test()->postJson('/api/v1/staff/performance', [
        'staffProfileId' => staffFor('0754000005')->getKey(),
        'period' => currentPeriod(),
        'targets' => ['loans_disbursed' => 10],
        'achieved' => ['loans_disbursed' => 9],
        'rating' => 'B',
    ])->assertStatus(201);

    // 409 workflow conflict — a second run for the same period
    test()->postJson('/api/v1/payroll/generate', ['period' => currentPeriod()])->assertStatus(409);

    // 405 wrong verb
    test()->postJson('/api/v1/branches/1', [])->assertStatus(405);

    // 422 validation
    test()->postJson('/api/v1/payroll/generate', ['period' => ''])->assertStatus(422);
});
