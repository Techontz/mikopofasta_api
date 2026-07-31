<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

/**
 * The eleven roles from backend spec §14.
 *
 * This list mirrors the frontend's `ROLES` in types/auth.ts exactly and must
 * never drift from it — the frontend renders role labels and gates navigation
 * off these literal strings.
 */
enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Finance = 'finance';
    case BranchManager = 'branch_manager';
    case LoanOfficer = 'loan_officer';
    case CreditOfficer = 'credit_officer';
    case Hr = 'hr';
    case ZoneManager = 'zone_manager';
    case RegionalManager = 'regional_manager';
    case Teller = 'teller';
    case Auditor = 'auditor';

    /**
     * Human-readable label, mirroring the frontend's ROLE_LABELS.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Finance => 'Finance Officer',
            self::BranchManager => 'Branch Manager',
            self::LoanOfficer => 'Loan Officer',
            self::CreditOfficer => 'Credit Officer',
            self::Hr => 'HR Officer',
            self::ZoneManager => 'Zone Manager',
            self::RegionalManager => 'Regional Manager',
            self::Teller => 'Teller',
            self::Auditor => 'Auditor',
        };
    }

    /**
     * Mirrors the frontend's ROLE_DESCRIPTIONS.
     */
    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full, unrestricted access to every module and permission.',
            self::Admin => 'Org setup, user management, and operational approvals — no ledger reversal approval.',
            self::Finance => 'Disbursement, payment confirmation, reconciliation, and payroll finalization.',
            self::BranchManager => 'Loan approval, staff advance approval, and branch expense entry — own branch.',
            self::LoanOfficer => 'Loan application and KYC capture — own branch, cannot approve own submissions.',
            self::CreditOfficer => 'Telco verification and credit review — strictly branch-scoped.',
            self::Hr => 'Staff registration and payroll generation — finalization is Finance\'s job.',
            self::ZoneManager => 'Branch performance oversight for one zone, plus commission override.',
            self::RegionalManager => 'Branch performance oversight for one region.',
            self::Teller => 'Cash payment entry only — own branch.',
            self::Auditor => 'Read-only, cross-branch access to ledger, treasury, HR, and the full audit trail.',
        };
    }

    /**
     * Super Admin's permission set is not editable through the permission
     * matrix — otherwise an administrator could lock every user out of the
     * system, including themselves.
     */
    public function isEditable(): bool
    {
        return $this !== self::SuperAdmin;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
