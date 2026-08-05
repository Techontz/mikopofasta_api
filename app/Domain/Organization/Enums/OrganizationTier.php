<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

/**
 * Where somebody sits in the institution.
 *
 *     SUPER ADMIN  →  HEAD OFFICE  →  ZONES  →  BRANCHES
 *
 * The tiers already existed in the data — `branches.is_head_office`,
 * `branches.zone_id`, `users.branch_id` / `zone_id` / `region_id`, and
 * BranchScope's rules about who sees how far. What did not exist was a name for
 * the answer, so every screen that wanted to behave differently for a Zone
 * Manager than for a Branch Manager re-derived it from role names.
 *
 * That is what this enum stops. The tier is computed once, in
 * OrganizationHierarchy, from the same facts BranchScope uses — so what a user
 * SEES and where they are SAID to sit can never disagree.
 *
 * ## Tier is not authority
 *
 * A tier says where you stand, not what you may do. Two Head Office employees —
 * a Credit Officer and a Cashier — sit at the same tier and hold almost nothing
 * in common. Permissions remain the only thing that decides what happens when
 * somebody presses a button.
 */
enum OrganizationTier: string
{
    /** Governs the institution. Configures; does not process loans. */
    case SuperAdmin = 'super_admin';

    /**
     * The operational centre.
     *
     * Head Office is a working office, not just a governance layer: it has its
     * own manager, loan officers, tellers, credit officers, accountant,
     * cashier, recovery officer, customer care, HR and auditor — the same jobs
     * a branch has, done for the centre.
     */
    case HeadOffice = 'head_office';

    /** Supervises several branches. Approves, reports, monitors — no counter. */
    case Zone = 'zone';

    /** Where customers are met and loans are originated. */
    case Branch = 'branch';

    /**
     * The automation. Not a place, and not a person — see RoleName::System.
     */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::HeadOffice => 'Head Office',
            self::Zone => 'Zone',
            self::Branch => 'Branch',
            self::System => 'System',
        };
    }

    /**
     * How far this tier can see, as a sentence an administrator can read.
     */
    public function scopeDescription(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Every branch, zone and region in the institution.',
            self::HeadOffice => 'Every branch — the centre reports on the whole book.',
            self::Zone => 'The branches in this zone.',
            self::Branch => 'This branch and any sub-branches under it.',
            self::System => 'Unscoped, and unreachable — the automation holds no session.',
        };
    }

    /**
     * Whether this tier meets customers.
     *
     * False at Super Admin and Zone: the client was explicit that Super Admin
     * does no operational loan processing and that Zone Managers have no teller
     * functions. Used to decide which dashboard somebody lands on, never to
     * authorise anything.
     */
    public function isOperational(): bool
    {
        return $this === self::HeadOffice || $this === self::Branch;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
