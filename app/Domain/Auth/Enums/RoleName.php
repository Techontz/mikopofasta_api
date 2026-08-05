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

    /*
     * The operational roles the enterprise structure names.
     *
     * ## Why these are roles and "Head Office X" is not
     *
     * The client's list reads "Head Office Loan Officers", "Head Office
     * Tellers", "Head Office Accountant" — and separately "Loan Officers",
     * "Tellers", "Accountant" at each branch. Those are the SAME job done at
     * different offices, not different jobs.
     *
     * A branch-scoped system already expresses that: a Head Office Teller is a
     * `teller` whose `branch_id` is the Head Office. Minting `ho_teller`
     * alongside `teller` would double the role list, double the permission
     * matrix, and put the office into two places at once — the role and the
     * posting — free to disagree.
     *
     * `head_office_manager` IS a distinct role, because it is a distinct job:
     * a Branch Manager runs one branch, and the Head Office Manager runs the
     * operational centre and sees across the institution.
     */
    case HeadOfficeManager = 'head_office_manager';
    case Accountant = 'accountant';
    case Cashier = 'cashier';
    case RecoveryOfficer = 'recovery_officer';
    case CustomerCare = 'customer_care';

    /*
     * The automation's role — client Decision 4. Holds NO permissions.
     *
     * Scheduled work does not pass through policies; it runs as code that has
     * already decided what it is doing. What it needs is an identity the ledger
     * can name, not authority. Zero permissions is therefore strictly safer
     * than borrowing Admin's or Super Admin's — an identity nobody can log in
     * as and that could do nothing if they did.
     */
    case System = 'system';

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
            self::HeadOfficeManager => 'Head Office Manager',
            self::Accountant => 'Accountant',
            self::Cashier => 'Cashier',
            self::RecoveryOfficer => 'Recovery Officer',
            self::CustomerCare => 'Customer Care',
            self::System => 'System',
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
            self::ZoneManager => 'Zone-tier loan approval and branch performance oversight for one zone, plus commission override.',
            self::RegionalManager => 'Branch performance oversight for one region.',
            self::Teller => 'Cash payment entry only — own branch.',
            self::HeadOfficeManager => 'Runs the Head Office as an operational centre: loan approval, staff oversight and institution-wide visibility.',
            self::Accountant => 'Ledger, reconciliation and the accounting close — books only, no lending decisions.',
            self::Cashier => 'Counter cash: receipts, deposits and the branch till.',
            self::RecoveryOfficer => 'Chases arrears and defaults, and records what is recovered.',
            self::CustomerCare => 'Customer enquiries and record upkeep — sees the book, decides nothing on it.',
            self::Auditor => 'Read-only, cross-branch access to ledger, treasury, HR, and the full audit trail.',
            self::System => 'Automated processing only. Cannot log in and holds no permissions — it exists so the books can name who posted a scheduled entry.',
        };
    }

    /**
     * Super Admin's permission set is not editable through the permission
     * matrix — otherwise an administrator could lock every user out of the
     * system, including themselves.
     */
    public function isEditable(): bool
    {
        /*
         * System is uneditable for the opposite reason to Super Admin: not
         * because granting it everything is dangerous, but because granting it
         * anything is. It is an attribution identity, and the moment somebody
         * gives it a permission it becomes a way to act without a person
         * attached.
         */
        return $this !== self::SuperAdmin && $this !== self::System;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * The roles a human may be given.
     *
     * Everything except System. That account is created by the seeder, holds no
     * permissions, and cannot log in — letting an administrator assign its role
     * to a real person would produce a user who exists, is powerless, and looks
     * like the automation in every audit row they appear in.
     *
     * @return list<string>
     */
    public static function assignable(): array
    {
        return array_values(array_map(
            static fn (self $role): string => $role->value,
            array_filter(self::cases(), static fn (self $role): bool => $role !== self::System),
        ));
    }
}
