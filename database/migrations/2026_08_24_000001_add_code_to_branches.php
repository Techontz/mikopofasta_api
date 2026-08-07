<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A short, stable code for every branch.
 *
 * The client's customer reference format is `MF-YYYY-BRANCHCODE-000001`, and
 * branches have never had a code — only a name. A name cannot be used: it is
 * long, it contains spaces and Swahili diacritics, and it is editable, which
 * would silently change the shape of references already printed on receipts and
 * quoted by customers paying in.
 *
 * ## Why it is backfilled rather than left null
 *
 * Every existing branch can originate a loan the moment this deploys, and a
 * branch without a code could not produce a reference at all. Failing at credit
 * approval — after an officer, a manager, a zone and a credit reviewer have all
 * signed off — is the worst possible moment to discover a missing code.
 *
 * The derivation is deterministic and readable: initials for a multi-word name
 * (Head Office → HO), the first three letters otherwise (Mwanza → MWA), and a
 * numeric suffix if two branches would collide. It is a starting point an
 * administrator can correct, not a final answer — which is why the column is
 * editable and unique rather than generated.
 *
 * ## Why unique, and why not null
 *
 * Two branches sharing a code would produce two different loans with the same
 * customer reference, and the repayment matcher would have no way to tell which
 * one a payment belonged to. That is a wrong-account posting, so the database
 * refuses it rather than the application hoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('code', 12)->nullable()->after('name');
        });

        $this->backfill();

        // Only after every row has one. Adding the constraint first would fail
        // on any installation that already has branches.
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('code', 12)->nullable(false)->change();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    /**
     * Derives a code for every branch that has none, keeping them distinct.
     */
    private function backfill(): void
    {
        /** @var array<int, string> $taken */
        $taken = [];

        $branches = DB::table('branches')->orderBy('id')->get(['id', 'name']);

        foreach ($branches as $branch) {
            $code = $this->deriveCode((string) $branch->name);

            /*
             * Suffixed on collision rather than skipped. Two branches named
             * "Mwanza Mjini" and "Mwanza Mkoa" both derive MM, and leaving the
             * second null would defeat the point of backfilling at all.
             */
            $candidate = $code;
            $suffix = 2;

            while (in_array($candidate, $taken, true)) {
                $candidate = substr($code, 0, 11).$suffix;
                $suffix++;
            }

            $taken[] = $candidate;

            DB::table('branches')->where('id', $branch->id)->update(['code' => $candidate]);
        }
    }

    /**
     * Initials for a multi-word name, first three letters otherwise.
     *
     * ASCII-folded first, so a name carrying a diacritic does not produce a code
     * that cannot be typed at a teller window.
     */
    private function deriveCode(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($name)) ?? '';
        $words = array_values(array_filter(explode(' ', $clean), fn (string $w): bool => $w !== ''));

        if ($words === []) {
            return 'BR';
        }

        $code = count($words) > 1
            ? implode('', array_map(fn (string $w): string => strtoupper($w[0]), array_slice($words, 0, 4)))
            : strtoupper(substr($words[0], 0, 3));

        return substr($code, 0, 12);
    }
};
