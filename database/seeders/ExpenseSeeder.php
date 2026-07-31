<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Expenses\Actions\CreateExpenseCategoryAction;
use App\Domain\Expenses\Actions\DecideExpenseRequestAction;
use App\Domain\Expenses\Actions\RequestExpenseAction;
use App\Domain\Expenses\DTOs\ExpenseCategoryData;
use App\Domain\Expenses\DTOs\ExpenseRequestData;
use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Enums\ExpenseScope;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The expense registers and a working queue of requests.
 *
 * Everything is created through the module's own actions rather than by
 * inserting rows, which is what makes the seed worth having: each category gets
 * its 6xxx ledger account minted the way a real one would, and each approved
 * request posts a real double entry through LedgerService. A seeded trial
 * balance that includes these expenses is therefore testing the posting engine,
 * not a fixture.
 *
 * Provenance of the values, since this codebase draws a hard line between
 * transcribed and invented:
 *
 *   - The three branch names — umeme, MAJI, SODA — and the four branch requests
 *     totalling 92,000 come from the legacy Expenses screens by way of the
 *     frontend's lib/mock-data/operations.ts, which records them as read off
 *     those screenshots. They are not in LegacySource because that file holds
 *     only what was transcribed directly into this repository; the captures
 *     themselves are the frontend team's.
 *   - Rent and Usafiri are named in the business documentation (ACCOUNT
 *     OVERVIEW §G, "Super Admin ata-create categories: Umeme, Rent, Usafiri").
 *   - MISHAHARA, Bank Charges and Stationery are the headquarters register from
 *     the same frontend fixture.
 *
 * Nothing here is invented beyond the descriptions attached to the demo
 * requests, which exist so the screens have something readable in them.
 */
final class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $actor = $this->administrator();

        $branch = $this->categories($actor);

        // Requests need somewhere to post to. Without a chart of accounts the
        // approvals below would fail deep inside LedgerService with a message
        // about a missing system account, which is a confusing way to learn
        // that a seeder ran out of order.
        if (! $this->chartIsSeeded()) {
            $this->command?->warn('ExpenseSeeder: chart of accounts not seeded, skipping demo requests.');

            return;
        }

        $this->requests($branch, $actor);
    }

    /**
     * Both registers.
     *
     * @return array<string, ExpenseCategory> the branch register, keyed by name
     */
    private function categories(User $actor): array
    {
        $create = app(CreateExpenseCategoryAction::class);

        $branch = [];

        foreach (['umeme', 'MAJI', 'SODA', 'Rent', 'Usafiri'] as $name) {
            $branch[$name] = $this->firstOrCreate($create, $name, ExpenseScope::Branch, $actor);
        }

        foreach (['MISHAHARA', 'Bank Charges', 'Stationery'] as $name) {
            $this->firstOrCreate($create, $name, ExpenseScope::Headquarters, $actor);
        }

        return $branch;
    }

    /**
     * The four legacy branch requests, plus two decided ones so the Approved
     * screen is not empty and the ledger has real expense postings in it.
     *
     * The four pending ones are left pending deliberately — that is the state
     * the legacy screen was captured in, and their amounts sum to the 92,000
     * its footer prints.
     *
     * @param array<string, ExpenseCategory> $categories
     */
    private function requests(array $categories, User $actor): void
    {
        $request = app(RequestExpenseAction::class);
        $decide = app(DecideExpenseRequestAction::class);

        $rows = [
            ['umeme', 'Head Office', '50000', 'Umeme wa ofisi', '2025-10-16', null],
            ['MAJI', 'Kakonko', '25000', 'Nimelipa bill ya maji ofisini', '2026-05-03', null],
            ['umeme', 'Kakonko', '5000', 'Nimenunua umeme ofisini', '2026-05-03', null],
            ['SODA', 'Kakonko', '12000', 'Soda', '2025-01-08', null],

            // Decided, so the Approved screen has rows and the expense accounts
            // carry a balance.
            ['MAJI', 'Missenyi', '18000', 'Water bill, March', '2026-03-14', ExpenseRequestStatus::Approved],
            ['Rent', 'NEW KALENGE', '32000', 'Office rent', '2026-04-02', ExpenseRequestStatus::Approved],
            ['Usafiri', 'Kakonko', '9000', 'Field visit transport', '2026-04-11', ExpenseRequestStatus::Rejected],
        ];

        // §14: a request may not be approved by whoever raised it, and that
        // rule is enforced in the action, not just the controller — so the seed
        // needs two identities or every approval below would be refused.
        $approver = $this->approver($actor);

        foreach ($rows as [$categoryName, $branchName, $amount, $description, $date, $decision]) {
            $branch = Branch::query()->where('name', $branchName)->first();

            if ($branch === null) {
                continue;
            }

            $category = $categories[$categoryName];

            $filed = $request->handle(
                $category,
                new ExpenseRequestData(
                    categoryId: (int) $category->getKey(),
                    branchId: (int) $branch->getKey(),
                    // Paid out of the branch till, like every legacy expense —
                    // Bank → Register Bank Expenses is the screen that names an
                    // account instead, and it seeds nothing.
                    bankAccountId: null,
                    amount: $amount,
                    description: $description,
                    comment: null,
                    requestedOn: $date,
                ),
                $actor,
            );

            if ($decision !== null) {
                $decide->handle(
                    $filed,
                    $decision,
                    $decision === ExpenseRequestStatus::Approved
                        ? 'Within the monthly budget.'
                        : 'Not a business cost.',
                    $approver,
                );
            }
        }
    }

    private function firstOrCreate(
        CreateExpenseCategoryAction $create,
        string $name,
        ExpenseScope $scope,
        User $actor,
    ): ExpenseCategory {
        $existing = ExpenseCategory::query()
            ->where('scope', $scope)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        return $existing ?? $create->handle(new ExpenseCategoryData($name, $scope), $actor);
    }

    private function administrator(): User
    {
        $user = User::query()->whereHas('role', fn ($q) => $q->where('name', RoleName::SuperAdmin->value))->first()
            ?? User::query()->oldest('id')->first();

        if ($user === null) {
            throw new RuntimeException('ExpenseSeeder needs at least one user. Run UserSeeder first.');
        }

        return $user;
    }

    /** Anyone but the requester — see §14 above. */
    private function approver(User $requester): User
    {
        return User::query()->whereKeyNot($requester->getKey())->oldest('id')->first() ?? $requester;
    }

    private function chartIsSeeded(): bool
    {
        return \App\Models\ChartOfAccount::query()->where('is_system', true)->exists();
    }
}
