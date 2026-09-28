<?php

/*
|--------------------------------------------------------------------------
| Permissions and default roles
|--------------------------------------------------------------------------
|
| Documents (ACCOUNT OVERVIEW "ROLE CONTROL", LOAN PROCESS, STAFF COMMISSION and
| the handwritten notes) define who may do what. Roles are stored per company and
| can be edited; these are the defaults seeded for a new company.
|
| Data scope: HQ roles see every branch, zone managers see the branches of their
| zone, branch roles see only their own branch.
|
*/

return [
    'permissions' => [
        'dashboard.view' => 'View dashboard',
        'settings.manage' => 'Manage branches, products, formulas, fees and settings',
        'users.manage' => 'Manage staff accounts and roles',
        'capital.view' => 'View capital and shareholder accounts',
        'capital.manage' => 'Register shareholders, add capital and distribute dividends',
        'shares.view' => 'View the share register, ownership, share valuations, share history and share reports',
        'shares.issue' => 'Issue new shares to shareholders and investors',
        'shares.transfer' => 'Transfer existing shares between shareholders',
        'shares.value' => 'Record share value changes (valuations)',
        'shares.manage' => 'Set up the share structure and initial allocation, cancel, adjust and reverse share transactions',
        'float.manage' => 'Transfer float between company, branches and accounts',
        'bank.manage' => 'Manage bank accounts and bank transfers',
        'funds.transfer' => 'Send petty cash to a branch and send HQ reserve to the Investment RESERVE A/C',
        'expenses.request' => 'Request expenses',
        'expenses.approve_branch' => 'Approve small branch expenses',
        'expenses.approve_hq' => 'Approve large and HQ expenses',
        'hq.manage' => 'Manage HQ accounts and transactions',
        'customers.view' => 'View customers',
        'customers.manage' => 'Create and edit customers, upload documents, face verification, freeze, save and resume drafts',
        'customers.edit' => 'Edit the details of a customer already registered (not the branch or officer, documents, face verification or freeze)',
        'customers.approve' => 'Approve or reject customer registrations',
        'customers.assign_officer' => 'Register a customer for another officer',
        'groups.view' => 'View customer groups and their members',
        'groups.manage' => 'Create, edit and delete customer groups',
        'branches.view_all' => 'Cross-branch visibility; register into any branch',
        'loans.view' => 'View loans',
        'loans.apply' => 'Apply for loans on behalf of customers',
        'loans.approve_manager' => 'Branch manager loan approval',
        'loans.credit_review' => 'Credit officer review (telco verification)',
        'loans.prepare_disbursement' => 'Prepare disbursement batches',
        'loans.disburse' => 'Disburse and retry disbursements',
        'loans.write_off' => 'Write off defaulted loans',
        'loans.reverse_repayment' => 'Request the reversal of a posted loan repayment (another user approves; the money returns to suspense)',
        'loans.reverse_disbursement' => 'Request the reversal of a loan disbursement that has no repayments or penalties (another user approves)',
        'penalties.reverse_payment' => 'Request the reversal of a direct penalty payment',
        'reversals.approve' => 'Approve or reject reversal requests (repayment, disbursement, penalty payment) raised by another user',
        'loans.recover' => 'Record money recovered on a written-off loan (split principal, penalty, interest and insurance; Finance entries post at once, branch entries wait for Finance)',
        'payments.cash' => 'Record cash repayments (teller)',
        'payments.verify' => 'Verify cash deposits and reconcile bank statements',
        'payments.suspense' => 'Allocate unmatched payments from suspense',
        'accounting.view' => 'View chart of accounts, journals and balances',
        'accounting.reverse' => 'Post reversal entries',
        'accounting.close_period' => 'Run month-end profit and dividend processes',
        'salary_advance.manage' => 'Manage customer salary advances',
        'penalties.manage' => 'Manage penalties',
        'legacy_imports.manage' => 'Export the Loan File, Penalty List and Active Salary Advance list, upload old-system files and send them for approval',
        'legacy_imports.approve' => 'Approve or reject old-system imports uploaded by another user, map unmatched customers and roll back an import',
        'income.view' => 'View deducted loan fee income',
        'savings.manage' => 'Manage insurance savings',
        'visa.manage' => 'Manage customer bank card details',
        'reports.view' => 'View operational reports',
        'reports.financial' => 'View financial, commission and strategic reports',
        'hrm.manage' => 'Manage staff records, leave, allowances and deductions',
        'hrm.staff_privileges' => 'Grant or revoke individual staff privileges (per-employee permissions)',
        'hrm.staff_reset_password' => 'Reset a staff member\'s password to the configured default password',
        'payroll.approve' => 'Generate and review payroll and commission (HR)',
        'payroll.pay' => 'Final payroll approval and payment (Finance)',
        'crm.use' => 'Use customer relationship tools',
        'messages.use' => 'Use internal messaging',
        'goals.manage' => 'Set goals and targets',
        'goals.view' => 'View goals and progress',
        'audit.view' => 'View audit trail',
        'approvals.view' => 'View the Pending Approvals list',
        'approvals.self_approve' => 'Approve own financial transactions',
        'shareholder.portal' => 'Shareholder Portal: view my shareholder dashboard',
        'shareholder.profile' => 'Shareholder Portal: view and update my profile (safe fields only)',
        'shareholder.capital.view' => 'Shareholder Portal: view my capital, shares and contribution history',
        'shareholder.capital.submit' => 'Shareholder Portal: submit and cancel my pending capital contributions',
        'shareholder.dividends.view' => 'Shareholder Portal: view my dividends and dividend payments',
        'shareholder.statements' => 'Shareholder Portal: view and download my statements',
        'shareholder.directory' => 'Shareholder Portal: view all shareholders\' public holdings and the company share structure',
    ],

    /*
    | Permissions that are never implied: the Super Admin does not receive them automatically and no role holds them by
    | default. They are effective only when a company grants them explicitly (role permission or employee override).
    | Rule 6 (segregation of duties): the initiator of a financial transaction must not approve it unless the company
    | explicitly allows self-approval. `funds.transfer` is Finance's own leg of the HQ money chain (petty cash to a
    | branch, HQ reserve to the Investment RESERVE A/C): the owners approve those requests, they never raise them, so
    | the Super Admin does not hold it implicitly.
    */
    'explicit_only' => ['approvals.self_approve', 'funds.transfer'],

    /*
    | Company money: bank accounts and bank transfers belong to the company owners. These permissions are effective only
    | for a Super Admin / Admin staff login or a login linked to a shareholder of the company — never for any other role,
    | whatever its role permissions or per-employee overrides say.
    */
    'company_money' => [
        'permissions' => ['bank.manage'],
        'roles' => ['super_admin', 'admin'],
    ],

    /*
    | Shareholder Portal permissions. They are effective ONLY for an employee account linked to a shareholder record
    | (share_holders.employee_id): never implied by the Super Admin role, never granted by a staff role or an employee
    | override. A pure shareholder account (employees.account_type = shareholder) holds nothing else (AccessControl).
    */
    'shareholder_portal' => [
        'shareholder.portal', 'shareholder.profile', 'shareholder.capital.view', 'shareholder.capital.submit',
        'shareholder.dividends.view', 'shareholder.statements', 'shareholder.directory',
    ],

    'roles' => [
        'super_admin' => ['name' => 'Super Admin', 'scope' => 'company', 'permissions' => ['*']],
        'admin' => ['name' => 'Admin', 'scope' => 'company', 'permissions' => [
            'dashboard.view', 'settings.manage', 'users.manage', 'hrm.staff_privileges', 'hrm.staff_reset_password', 'float.manage', 'bank.manage', 'expenses.request',
            'expenses.approve_hq', 'hq.manage', 'customers.view', 'customers.manage', 'customers.edit', 'customers.approve',
            'customers.assign_officer', 'groups.view', 'groups.manage', 'branches.view_all', 'loans.view', 'loans.write_off', 'loans.reverse_repayment', 'loans.reverse_disbursement',
            'reversals.approve', 'loans.recover', 'accounting.view', 'salary_advance.manage', 'penalties.manage', 'savings.manage', 'visa.manage',
            'reports.view', 'reports.financial', 'income.view', 'crm.use', 'messages.use', 'goals.manage', 'goals.view', 'audit.view', 'approvals.view',
            'legacy_imports.manage', 'legacy_imports.approve',
        ]],
        'finance' => ['name' => 'Finance', 'scope' => 'company', 'permissions' => [
            'dashboard.view', 'float.manage', 'funds.transfer', 'expenses.approve_branch', 'hq.manage', 'customers.view', 'customers.edit', 'groups.view', 'branches.view_all',
            'loans.view', 'loans.prepare_disbursement', 'loans.disburse', 'loans.reverse_repayment', 'loans.reverse_disbursement', 'loans.recover', 'payments.verify', 'payments.suspense',
            'accounting.view', 'accounting.reverse', 'accounting.close_period', 'salary_advance.manage', 'penalties.manage', 'penalties.reverse_payment', 'reversals.approve',
            'savings.manage', 'payroll.pay', 'reports.view', 'reports.financial', 'income.view', 'messages.use', 'goals.view', 'approvals.view',
            'legacy_imports.manage',
        ]],
        'hr' => ['name' => 'HR', 'scope' => 'company', 'permissions' => [
            'dashboard.view', 'users.manage', 'hrm.manage', 'hrm.staff_privileges', 'payroll.approve', 'branches.view_all', 'reports.view', 'messages.use', 'goals.view',
        ]],
        'zone_manager' => ['name' => 'Zone Manager', 'scope' => 'zone', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.edit', 'groups.view', 'loans.view', 'reports.view', 'crm.use', 'messages.use', 'goals.view',
        ]],
        'branch_manager' => ['name' => 'Branch Manager', 'scope' => 'branch', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.manage', 'customers.edit', 'customers.approve', 'customers.assign_officer', 'groups.view', 'groups.manage',
            'loans.view', 'loans.approve_manager', 'expenses.request', 'penalties.manage', 'reports.view', 'crm.use',
            'messages.use', 'goals.view',
        ]],
        'credit_officer' => ['name' => 'Credit Officer', 'scope' => 'company', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.edit', 'groups.view', 'branches.view_all', 'loans.view', 'loans.credit_review', 'reports.view', 'messages.use', 'goals.view',
        ]],
        'loan_officer' => ['name' => 'Loan Officer', 'scope' => 'branch', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.manage', 'customers.edit', 'groups.view', 'groups.manage',
            'loans.view', 'loans.apply', 'crm.use', 'messages.use', 'goals.view',
        ]],
        'teller' => ['name' => 'Teller', 'scope' => 'branch', 'permissions' => [
            'dashboard.view', 'loans.view', 'payments.cash', 'loans.recover', 'savings.manage',
            'messages.use', 'goals.view',
        ]],
        'shareholder' => ['name' => 'Shareholder', 'scope' => 'branch', 'permissions' => [
            'shareholder.portal', 'shareholder.profile', 'shareholder.capital.view', 'shareholder.capital.submit',
            'shareholder.dividends.view', 'shareholder.statements', 'shareholder.directory',
        ]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff privilege page (HRM → All Active Staff → Privilege)
    |--------------------------------------------------------------------------
    |
    | Live admin/privillage/{id} lists 16 module privileges (15 here: CLIENTLESS went with the Agent module, removed 2026-09-17) (displayed alphabetically) that are added to / removed from
    | one user. Each item here maps to the real permission keys it controls: adding an item grants every key, removing
    | it revokes every key (stored as per-employee overrides, see AccessControl). The first group is the live list in the
    | live display order with the live item keys; the following groups hold this app's additional permissions that
    | have no live privilege item, so every key in 'permissions' belongs to exactly one item (enforced by tests).
    |
    */
    'privileges' => [
        [
            'key' => 'main',
            'label' => 'Privilege List',
            'items' => [
                ['key' => 'apply', 'label' => 'APPLY LOAN', 'permissions' => ['loans.apply']],
                ['key' => 'aprove', 'label' => 'APPROVE', 'permissions' => ['loans.approve_manager', 'loans.credit_review', 'customers.approve']],
                ['key' => 'bank', 'label' => 'BANK', 'permissions' => ['bank.manage', 'funds.transfer']],
                ['key' => 'bankpassword', 'label' => 'BANK PASSWORD', 'permissions' => ['visa.manage']],
                ['key' => 'customer', 'label' => 'CUSTOMER', 'permissions' => ['customers.view', 'customers.manage', 'customers.edit']],
                ['key' => 'debit', 'label' => 'DEBIT PENDING', 'permissions' => ['salary_advance.manage']],
                ['key' => 'expenses', 'label' => 'EXPENSES', 'permissions' => ['expenses.request', 'expenses.approve_branch']],
                ['key' => 'float', 'label' => 'FLOAT', 'permissions' => ['float.manage']],
                ['key' => 'group', 'label' => 'GROUP', 'permissions' => ['groups.view', 'groups.manage']],
                ['key' => 'income', 'label' => 'INCOME', 'permissions' => ['income.view']],
                ['key' => 'loan', 'label' => 'LOAN', 'permissions' => ['loans.view']],
                ['key' => 'penarty', 'label' => 'PENALTY', 'permissions' => ['penalties.manage']],
                ['key' => 'report', 'label' => 'REPORTS', 'permissions' => ['reports.view']],
                ['key' => 'saving', 'label' => 'SAVING', 'permissions' => ['savings.manage']],
                ['key' => 'teller', 'label' => 'TELLER', 'permissions' => ['payments.cash']],
            ],
        ],
        [
            'key' => 'general',
            'label' => 'General',
            'items' => [
                ['key' => 'dashboard', 'label' => 'DASHBOARD', 'permissions' => ['dashboard.view']],
                ['key' => 'messages', 'label' => 'MESSAGES', 'permissions' => ['messages.use']],
                ['key' => 'crm', 'label' => 'CRM', 'permissions' => ['crm.use']],
                ['key' => 'goals_view', 'label' => 'GOALS', 'permissions' => ['goals.view']],
                ['key' => 'goals_manage', 'label' => 'SET GOALS', 'permissions' => ['goals.manage']],
            ],
        ],
        [
            'key' => 'loans_payments',
            'label' => 'Loans & Payments',
            'items' => [
                ['key' => 'assign_officer', 'label' => 'REGISTER FOR ANOTHER OFFICER', 'permissions' => ['customers.assign_officer']],
                ['key' => 'all_branches', 'label' => 'ALL BRANCHES', 'permissions' => ['branches.view_all']],
                ['key' => 'prepare_disbursement', 'label' => 'PREPARE DISBURSEMENT', 'permissions' => ['loans.prepare_disbursement']],
                ['key' => 'disburse', 'label' => 'DISBURSE', 'permissions' => ['loans.disburse']],
                ['key' => 'write_off', 'label' => 'WRITE-OFF LOAN', 'permissions' => ['loans.write_off']],
                ['key' => 'reverse_repayment', 'label' => 'REVERSE LOAN REPAYMENT', 'permissions' => ['loans.reverse_repayment']],
                ['key' => 'reverse_disbursement', 'label' => 'REVERSE LOAN DISBURSEMENT', 'permissions' => ['loans.reverse_disbursement']],
                ['key' => 'reverse_penalty_payment', 'label' => 'REVERSE PENALTY PAYMENT', 'permissions' => ['penalties.reverse_payment']],
                ['key' => 'approve_reversals', 'label' => 'APPROVE REVERSAL REQUESTS', 'permissions' => ['reversals.approve']],
                ['key' => 'recover', 'label' => 'RECORD WRITE-OFF RECOVERY', 'permissions' => ['loans.recover']],
                ['key' => 'verify_payments', 'label' => 'VERIFY PAYMENTS', 'permissions' => ['payments.verify']],
                ['key' => 'suspense', 'label' => 'SUSPENSE', 'permissions' => ['payments.suspense']],
                ['key' => 'legacy_import', 'label' => 'OLD SYSTEM IMPORT / EXPORT', 'permissions' => ['legacy_imports.manage']],
                ['key' => 'legacy_import_approve', 'label' => 'APPROVE OLD SYSTEM IMPORTS', 'permissions' => ['legacy_imports.approve']],
            ],
        ],
        [
            'key' => 'headquarters',
            'label' => 'Headquarters',
            'items' => [
                ['key' => 'hq', 'label' => 'HEADQUARTER TRANSACTION', 'permissions' => ['hq.manage']],
                ['key' => 'hq_expenses', 'label' => 'HEADQUARTER EXPENSES APPROVAL', 'permissions' => ['expenses.approve_hq']],
            ],
        ],
        [
            'key' => 'capital_shares',
            'label' => 'Capital & Shares',
            'items' => [
                ['key' => 'capital_view', 'label' => 'CAPITAL', 'permissions' => ['capital.view']],
                ['key' => 'capital_manage', 'label' => 'MANAGE CAPITAL', 'permissions' => ['capital.manage']],
                ['key' => 'shares_view', 'label' => 'SHARES', 'permissions' => ['shares.view']],
                ['key' => 'shares_issue', 'label' => 'ISSUE SHARES', 'permissions' => ['shares.issue']],
                ['key' => 'shares_transfer', 'label' => 'TRANSFER SHARES', 'permissions' => ['shares.transfer']],
                ['key' => 'shares_value', 'label' => 'SHARE VALUATION', 'permissions' => ['shares.value']],
                ['key' => 'shares_manage', 'label' => 'MANAGE SHARES', 'permissions' => ['shares.manage']],
            ],
        ],
        [
            'key' => 'accounting',
            'label' => 'Accounting & Financial Reports',
            'items' => [
                ['key' => 'accounting_view', 'label' => 'ACCOUNTING', 'permissions' => ['accounting.view']],
                ['key' => 'accounting_reverse', 'label' => 'REVERSAL ENTRIES', 'permissions' => ['accounting.reverse']],
                ['key' => 'close_period', 'label' => 'MONTH-END CLOSE', 'permissions' => ['accounting.close_period']],
                ['key' => 'financial_reports', 'label' => 'FINANCIAL REPORTS', 'permissions' => ['reports.financial']],
                ['key' => 'audit', 'label' => 'AUDIT TRAIL', 'permissions' => ['audit.view']],
                ['key' => 'approvals_view', 'label' => 'PENDING APPROVALS', 'permissions' => ['approvals.view']],
                ['key' => 'self_approve', 'label' => 'APPROVE OWN FINANCIAL TRANSACTIONS', 'permissions' => ['approvals.self_approve']],
            ],
        ],
        [
            'key' => 'administration',
            'label' => 'Settings & HRM',
            'items' => [
                ['key' => 'settings', 'label' => 'SETTINGS', 'permissions' => ['settings.manage']],
                ['key' => 'users', 'label' => 'STAFF ACCOUNTS & ROLES', 'permissions' => ['users.manage']],
                ['key' => 'hrm', 'label' => 'HRM', 'permissions' => ['hrm.manage']],
                ['key' => 'staff_privileges', 'label' => 'STAFF PRIVILEGES', 'permissions' => ['hrm.staff_privileges']],
                ['key' => 'staff_reset_password', 'label' => 'RESET STAFF PASSWORD', 'permissions' => ['hrm.staff_reset_password']],
                ['key' => 'payroll_approve', 'label' => 'PAYROLL PREPARATION', 'permissions' => ['payroll.approve']],
                ['key' => 'payroll_pay', 'label' => 'PAYROLL APPROVAL & PAYMENT', 'permissions' => ['payroll.pay']],
            ],
        ],
        [
            'key' => 'shareholder_portal',
            'label' => 'Shareholder Portal (effective only for accounts linked to a shareholder)',
            'items' => [
                ['key' => 'shareholder_portal', 'label' => 'SHAREHOLDER DASHBOARD', 'permissions' => ['shareholder.portal']],
                ['key' => 'shareholder_profile', 'label' => 'MY PROFILE', 'permissions' => ['shareholder.profile']],
                ['key' => 'shareholder_capital_view', 'label' => 'MY CAPITAL & SHARES', 'permissions' => ['shareholder.capital.view']],
                ['key' => 'shareholder_capital_submit', 'label' => 'SUBMIT CAPITAL', 'permissions' => ['shareholder.capital.submit']],
                ['key' => 'shareholder_dividends', 'label' => 'MY DIVIDENDS', 'permissions' => ['shareholder.dividends.view']],
                ['key' => 'shareholder_statements', 'label' => 'MY STATEMENTS', 'permissions' => ['shareholder.statements']],
                ['key' => 'shareholder_directory', 'label' => 'SHAREHOLDER DIRECTORY', 'permissions' => ['shareholder.directory']],
            ],
        ],
    ],
];
