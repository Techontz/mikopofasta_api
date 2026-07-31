<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HqAccount;
use App\Support\Money;
use Database\Seeders\Legacy\LegacySource;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The seven headquarters accounts, with the balances the legacy system shows.
 *
 * Every value comes from LegacySource::hqAccounts(); this class holds no data
 * of its own, which is the point — there is exactly one place a legacy value is
 * written down, and it is annotated with the screen it was read from.
 *
 * No transfers are seeded. The legacy Headquater Transaction screens were both
 * captured with no rows, so their history is unknown, and a plausible-looking
 * invented one is precisely what this rewrite exists to remove.
 */
final class HqAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = LegacySource::hqAccounts();

        $this->assertTranscriptionFoots($accounts);

        foreach ($accounts as $account) {
            HqAccount::query()->updateOrCreate(
                ['name' => $account['name']],
                ['balance' => $account['balance']],
            );
        }
    }

    /**
     * Check the seven balances against the total the legacy screen prints.
     *
     * This is a transcription check, not a business rule. A digit misread while
     * copying from a screenshot is the most likely way this data goes wrong,
     * and it is silent — the seed still runs and the figures still look
     * plausible. Comparing against the printed total catches it at the moment
     * it is introduced rather than when someone eventually reconciles by hand.
     *
     * @param list<array{name: string, balance: string}> $accounts
     */
    private function assertTranscriptionFoots(array $accounts): void
    {
        $sum = Money::sum(array_map(
            static fn (array $account): Money => Money::of($account['balance']),
            $accounts,
        ));

        $printed = Money::of(LegacySource::hqAccountsTotal());

        if (! $sum->equals($printed)) {
            throw new RuntimeException(sprintf(
                'HQ account transcription does not foot: the seven balances sum to %s, but the legacy screen prints %s. One of them was misread.',
                $sum->toDecimalString(),
                $printed->toDecimalString(),
            ));
        }
    }
}
