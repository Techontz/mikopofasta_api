<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Shareholders\ShareholderAccounts;
use Illuminate\Console\Command;

/**
 * Creates (or links) Shareholder Portal logins for existing shareholders without one — the CLI twin of Capital →
 * Shareholders → Login accounts → Generate accounts. Capital, shares, dividends and journals are never touched.
 * Temporary passwords are printed once (never with --dry-run) and never stored.
 */
class CreateShareholderAccounts extends Command
{
    protected $signature = 'shareholders:create-accounts
        {--company= : Company id (required when there is more than one company)}
        {--shareholder=* : Only these shareholder ids}
        {--dry-run : Only report what would be done}';

    protected $description = 'Create Shareholder Portal login accounts for existing shareholders that have none';

    public function handle(ShareholderAccounts $accounts): int
    {
        $company = $this->option('company') !== null
            ? Company::find((int) $this->option('company'))
            : (Company::count() === 1 ? Company::first() : null);

        if ($company === null) {
            $this->error('Pass --company=<id> (the company was not found or there is more than one company).');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('shareholder'))));
        $rows = $accounts->rows($company->id)->when($ids !== [], fn ($rows) => $rows->whereIn('id', $ids));
        $overview = $accounts->overview($company->id);

        $this->info("Company #{$company->id} {$company->name}");
        $this->table(['Total', 'Linked', 'Not linked', 'Missing phone', 'Invalid phone', 'Missing email', 'Phone conflicts', 'Eligible'], [[
            $overview['total'], $overview['linked'], $overview['not_linked'], $overview['missing_phone'], $overview['invalid_phone'], $overview['missing_email'], $overview['phone_conflicts'], $overview['eligible'],
        ]]);

        $candidates = $rows->where('linked', false)->values();
        $this->table(['ID', 'Shareholder', 'Phone', 'Action', 'Note'], $candidates->map(fn (array $row): array => [
            $row['id'], $row['name'], $row['mobile'], $row['will'] ?? 'skip', $row['conflict'] ?? ($row['valid_phone'] ? '' : 'Missing or invalid phone'),
        ])->all());

        if ($this->option('dry-run')) {
            $this->comment(sprintf('Dry run: %d account(s) would be created or linked. Nothing was changed.', $candidates->where('eligible', true)->count()));

            return self::SUCCESS;
        }

        $actor = Employee::where('company_id', $company->id)->whereHas('role', fn ($query) => $query->where('key', 'super_admin'))->orderBy('id')->first();
        if ($actor === null) {
            $this->error('No Super Admin found to record as the acting user.');

            return self::FAILURE;
        }

        $result = $accounts->generate($company->id, $ids === [] ? null : $ids, $actor);

        $this->info(sprintf('%d created, %d linked to staff logins, %d skipped.', count($result['created']), count($result['linked']), count($result['skipped'])));
        if ($result['created'] !== []) {
            $this->warn('Temporary passwords are shown ONCE and are not stored. Hand them over securely; each must be changed at first login.');
            $this->table(['ID', 'Shareholder', 'Login phone', 'Temporary password'], array_map(fn (array $row): array => [$row['share_holder_id'], $row['name'], $row['login'], $row['temporary_password']], $result['created']));
        }
        foreach ($result['linked'] as $row) {
            $this->line("Linked #{$row['share_holder_id']} {$row['name']} to the staff login of {$row['employee']} ({$row['login']}).");
        }
        foreach ($result['skipped'] as $row) {
            $this->line("Skipped #{$row['share_holder_id']} {$row['name']}: {$row['reason']}");
        }

        return self::SUCCESS;
    }
}
