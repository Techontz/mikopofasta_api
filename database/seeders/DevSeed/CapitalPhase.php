<?php

namespace Database\Seeders\DevSeed;

use App\Models\Asset;
use App\Models\BankTransfer;
use App\Models\Capital;
use App\Models\DividendAllocation;
use App\Models\DividendDeclaration;
use App\Models\DividendPayment;
use App\Models\FloatTransfer;
use App\Models\ShareHolder;
use App\Models\ShareStructure;
use App\Models\ShareTransaction;
use App\Models\ShareValuation;
use Carbon\CarbonImmutable;

/**
 * Shareholders and their cash / bank / asset capital contributions, company cash ↔ bank transfers, floats to the branches,
 * the asset register (transfer, status change, revaluation), the share register (revaluation, issuances linked to the
 * contributions, a transfer) and a partially paid dividend declaration for June 2026.
 */
final class CapitalPhase
{
    public const DIVIDEND_PERIOD = '2026-06-01';

    public function __construct(private readonly Context $ctx) {}

    public function register(): void
    {
        $this->shareholders();
        $this->funding();
        $this->assets();
        $this->shares();
        $this->dividends();
    }

    private function shareholders(): void
    {
        foreach (Catalog::SHAREHOLDERS as $key => [$first, $middle, $last, $gender, $dob]) {
            $this->ctx->timeline->at(CarbonImmutable::parse('2026-03-30 10:00')->addMinutes(15 * (int) substr($key, 2)), "shareholder {$first} {$last}", function () use ($key, $first, $middle, $last, $gender, $dob): void {
                $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', 'capital/share-holders', [
                    'first_name' => strtoupper($first),
                    'middle_name' => strtoupper($middle),
                    'last_name' => strtoupper($last),
                    'share_mobile' => Context::shareholderPhone($key),
                    'share_email' => strtolower("{$first}.{$last}@devseed.test"),
                    'share_sex' => $gender,
                    'share_dob' => $dob,
                ], ['passport_photo' => Images::passport($first[0].$last[0])]);
            }, fn (): bool => ShareHolder::where('company_id', $this->ctx->company->id)->where('mobile', Context::shareholderPhone($key))->exists());
        }

        foreach (Catalog::CONTRIBUTIONS as [$reference, $holder, $amount, $method, $bank, $at]) {
            $key = Catalog::MARKER."-{$reference}";
            $this->ctx->timeline->at($at, "capital {$reference}", function () use ($holder, $amount, $method, $bank, $at, $key, $reference): void {
                $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', 'capital/capitals', [
                    'share_id' => $this->ctx->shareholder($holder)->id,
                    'amount' => $amount,
                    'pay_method' => $method,
                    'bank_account_id' => $bank === null ? null : $this->ctx->bank($bank)->id,
                    'contributed_at' => CarbonImmutable::parse($at)->toDateTimeString(),
                    'recept' => $reference,
                    'idempotency_key' => $key,
                ]);
            }, fn (): bool => Capital::where('idempotency_key', $key)->exists());
        }
    }

    private function funding(): void
    {
        $finance = fn () => $this->ctx->staff('HQ_FIN1');

        foreach ([['CFT-001', 'bank_to_company', 'NMB', 13000000, '2026-04-02 08:30'], ['CFT-002', 'company_to_bank', 'NMB', 8000000, '2026-06-10 10:00'], ['CFT-003', 'company_to_bank', 'CRDB', 5000000, '2026-07-25 10:00']] as [$reference, $direction, $bank, $amount, $at]) {
            $key = Catalog::MARKER."-{$reference}";
            $this->ctx->timeline->at($at, "company funds {$reference}", function () use ($finance, $direction, $bank, $amount, $key): void {
                $this->ctx->api->call($finance(), 'POST', 'bank/company-transfers', ['direction' => $direction, 'bank_account_id' => $this->ctx->bank($bank)->id, 'amount' => $amount, 'reference' => $key, 'idempotency_key' => $key]);
            }, fn (): bool => BankTransfer::where('idempotency_key', $key)->exists());
        }

        foreach (['KB' => 9000000, 'KS' => 8000000, 'KM' => 4000000, 'BH' => 9000000, 'UV' => 8000000] as $branch => $amount) {
            $this->float('2026-04-02 09:00', 'company_to_branch', null, $branch, $amount, function () use ($finance, $branch, $amount): void {
                $this->ctx->api->call($finance(), 'POST', 'capital/floats', ['blanch_id' => $this->ctx->branch($branch)->id, 'blanch_amount' => $amount]);
            });
        }

        foreach ([['CRDB', 'KM', 5000000, 3000], ['NMB', 'KK', 5000000, 2000], ['NMB', 'MS', 3000000, 2000], ['NMB', 'LN', 4000000, 2000]] as [$bank, $branch, $amount, $charge]) {
            $this->ctx->timeline->at('2026-04-02 10:00', "bank {$bank} to {$branch}", function () use ($finance, $bank, $branch, $amount, $charge): void {
                $this->ctx->api->call($finance(), 'POST', 'bank/to-branch', ['from_account' => $this->ctx->bank($bank)->id, 'to_blanch' => $this->ctx->branch($branch)->id, 'amount' => $amount, 'charger_fee' => $charge]);
            }, fn (): bool => BankTransfer::where('company_id', $this->ctx->company->id)->where('type', 'bank_to_branch')->where('branch_id', $this->ctx->branch($branch)->id)->where('amount', $amount)->whereDate('transfer_date', '2026-04-02')->exists());
        }

        foreach (['KK', 'MS', 'LN', ...Catalog::NEW_BRANCHES] as $branch) {
            $this->float('2026-04-02 11:00', 'account_to_account', $branch, $branch, 300000, function () use ($finance, $branch): void {
                $this->ctx->api->call($finance(), 'POST', 'capital/floats/accounts', ['blanch_id' => $this->ctx->branch($branch)->id, 'from_acc' => 'PR', 'to_acc' => 'INT', 'amount' => 300000]);
            });
        }

        $this->float('2026-05-12 10:00', 'branch_to_branch', 'KS', 'BH', 1000000, function () use ($finance): void {
            $this->ctx->api->call($finance(), 'POST', 'capital/floats/branch', ['from_blanch_id' => $this->ctx->branch('KS')->id, 'to_blanch_id' => $this->ctx->branch('BH')->id, 'trans_amount' => 1000000]);
        });
        $this->ctx->timeline->at('2026-05-12 14:00', 'approve float KS to BH', function () use ($finance): void {
            $this->ctx->api->call($finance(), 'POST', "capital/floats/branch/{$this->floatTransfer('branch_to_branch', 'KS', 'BH', 1000000, '2026-05-12')->id}/approve");
        }, fn (): bool => $this->floatTransfer('branch_to_branch', 'KS', 'BH', 1000000, '2026-05-12')?->status === 'approved');

        $this->float('2026-07-20 10:00', 'branch_to_branch', 'KB', 'UV', 500000, function () use ($finance): void {
            $this->ctx->api->call($finance(), 'POST', 'capital/floats/branch', ['from_blanch_id' => $this->ctx->branch('KB')->id, 'to_blanch_id' => $this->ctx->branch('UV')->id, 'trans_amount' => 500000]);
        });
    }

    private function float(string $at, string $type, ?string $from, string $to, int $amount, \Closure $run): void
    {
        $this->ctx->timeline->at($at, "float {$type} {$from}→{$to}", $run, fn (): bool => $this->floatTransfer($type, $from, $to, $amount, substr($at, 0, 10)) !== null);
    }

    private function floatTransfer(string $type, ?string $from, string $to, int $amount, string $date): ?FloatTransfer
    {
        return FloatTransfer::where('company_id', $this->ctx->company->id)
            ->where('type', $type)
            ->when($from !== null, fn ($query) => $query->where('from_branch_id', $this->ctx->branch($from)->id))
            ->where('to_branch_id', $this->ctx->branch($to)->id)
            ->where('amount', $amount)
            ->whereDate('transfer_date', $date)
            ->first();
    }

    private function assets(): void
    {
        $assets = [
            ['AST-001', 'SH2', 'other', 'Sefu ya chuma ya kuhifadhia fedha', 'Sefu kubwa isiyoshika moto kwa ajili ya tawi la Uvinza', 1, 2500000, 'UV', '2026-04-15 10:00', ['brand' => 'Chubbsafes', 'model' => 'Duoguard 2', 'serial_number' => 'CS-DG2-88412', 'specification' => 'Sefu ya kilo 180, kufuli mbili, isiyoshika moto kwa dakika 60']],
            ['AST-002', 'SH3', 'electronics', 'Kompyuta mpakato HP ProBook 450 G10', 'Laptop tano kwa maafisa mikopo wa tawi la Kibondo', 5, 1800000, 'KB', '2026-04-20 11:00', ['device_type' => 'Laptop', 'brand' => 'HP', 'model' => 'ProBook 450 G10', 'serial_number' => '5CD4127HPX']],
            ['AST-003', 'SH4', 'vehicle', 'Toyota Noah ya ofisi', 'Gari la kufuatilia wateja na kusafirisha fedha - Kigoma Mjini', 1, 18000000, 'KM', '2026-05-04 10:00', ['make' => 'Toyota', 'model' => 'Noah', 'year' => 2014, 'chassis_number' => 'ZRR80-0112457', 'engine_number' => '3ZR-4417821', 'registration_number' => 'T 482 DXK', 'mileage' => 142000]],
            ['AST-004', 'SH5', 'furniture', 'Meza na viti vya ofisi', 'Seti kumi za meza na viti kwa tawi la Buhigwe', 10, 150000, 'BH', '2026-05-05 10:00', ['brand' => 'Afri Furniture', 'model' => 'Executive Set']],
            ['AST-005', 'SH5', 'equipment', 'Mashine ya kuhesabu fedha', 'Mashine mbili za kuhesabu na kutambua noti bandia', 2, 650000, 'KS', '2026-05-05 11:00', ['manufacturer' => 'Glory', 'model' => 'GFS-120', 'serial_number' => 'GL120-55917']],
        ];

        foreach ($assets as [$reference, $holder, $type, $name, $description, $quantity, $unit, $branch, $at, $specifications]) {
            $key = Catalog::MARKER."-{$reference}";
            $this->ctx->timeline->at($at, "asset {$name}", function () use ($holder, $type, $name, $description, $quantity, $unit, $branch, $at, $specifications, $key): void {
                $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', 'capital/assets', [
                    'share_id' => $this->ctx->shareholder($holder)->id,
                    'asset_type' => $type,
                    'name' => $name,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_value' => $unit,
                    'total_value' => $quantity * $unit,
                    'condition' => $type === 'vehicle' ? 'used' : 'new',
                    'contribution_date' => substr($at, 0, 10),
                    'branch_id' => $this->ctx->branch($branch)->id,
                    'location' => 'Ofisi ya tawi la '.Catalog::BRANCHES[$branch][0],
                    'valuation_method' => $type === 'vehicle' ? 'professional_valuation' : 'purchase_value',
                    'valuation_date' => substr($at, 0, 10),
                    'valued_by' => $type === 'vehicle' ? 'Mthamini Magari Ltd' : 'Ankara ya ununuzi',
                    'valuation_reference' => "{$key}-VAL",
                    'specifications' => $specifications,
                    'idempotency_key' => $key,
                ]);
            }, fn (): bool => Capital::where('idempotency_key', $key)->exists());
        }

        $asset = fn (string $reference): Asset => Asset::whereHas('capital', fn ($query) => $query->where('idempotency_key', Catalog::MARKER."-{$reference}"))->firstOrFail();
        $admin = fn () => $this->ctx->demo('super_admin');

        $this->ctx->timeline->at('2026-08-03 10:00', 'asset transfer laptops Kibondo → Kasulu', function () use ($asset, $admin): void {
            $this->ctx->api->call($admin(), 'POST', "capital/assets/{$asset('AST-002')->id}/transfer", ['to_branch_id' => $this->ctx->branch('KS')->id, 'transfer_date' => '2026-08-03', 'reason' => 'Maafisa mikopo wapya wa Kasulu wanahitaji kompyuta.']);
        }, fn (): bool => $asset('AST-002')->branch_id === $this->ctx->branch('KS')->id);

        $this->ctx->timeline->at('2026-08-20 09:00', 'asset money counter under maintenance', function () use ($asset, $admin): void {
            $this->ctx->api->call($admin(), 'POST', "capital/assets/{$asset('AST-005')->id}/status", ['status' => 'under_maintenance', 'reason' => 'Mashine moja inakataa noti mpya za 10,000; imepelekwa kwa fundi.']);
        }, fn (): bool => $asset('AST-005')->status === 'under_maintenance');

        $this->ctx->timeline->at('2026-09-01 10:00', 'asset revaluation Toyota Noah', function () use ($asset, $admin): void {
            $this->ctx->api->call($admin(), 'POST', "capital/assets/{$asset('AST-003')->id}/revaluations", ['new_value' => 16500000, 'valuation_date' => '2026-09-01', 'valuation_method' => 'market_valuation', 'valued_by' => 'Mthamini Magari Ltd', 'valuation_reference' => Catalog::MARKER.'-AST-003-REVAL', 'reason' => 'Uthamini wa soko baada ya kilomita 150,000.']);
        }, fn (): bool => abs((float) $asset('AST-003')->current_value - 16500000) < 0.01);
    }

    private function shares(): void
    {
        $admin = fn () => $this->ctx->demo('super_admin');
        $valuationKey = Catalog::MARKER.'-SHV-001';

        $linked = array_values(array_filter(Catalog::CONTRIBUTIONS, fn (array $row): bool => $row[6] !== null));
        $structureKey = Catalog::MARKER.'-SHARE-STRUCTURE';

        // A database without a share structure (e.g. a fresh install) gets one whose initial allocation is the linked
        // contributions; an existing structure is revalued and the contributions are issued as new shares instead.
        $this->ctx->timeline->at('2026-09-14 12:55', 'share structure (only when none exists)', function () use ($admin, $linked, $structureKey): void {
            $this->ctx->api->call($admin(), 'POST', 'shares/structure', [
                'capital_basis' => array_sum(array_column($linked, 2)),
                'total_shares' => array_sum(array_column($linked, 6)),
                'authorised_shares' => 2000,
                'established_on' => '2026-09-14',
                'notes' => 'Muundo wa hisa: michango ya mtaji ya Aprili 2026 kwa thamani ya TZS 100,000 kwa hisa.',
                'idempotency_key' => $structureKey,
                'allocations' => array_map(fn (array $row): array => [
                    'share_holder_id' => $this->ctx->shareholder($row[1])->id,
                    'shares' => $row[6],
                    'treatment' => ShareTransaction::TREATMENT_LINKED,
                    'capital_id' => Capital::where('idempotency_key', Catalog::MARKER."-{$row[0]}")->value('id'),
                ], $linked),
            ]);
        }, fn (): bool => ShareStructure::where('company_id', $this->ctx->company->id)->exists());

        $this->ctx->timeline->at('2026-09-14 13:00', 'share revaluation 100,000', function () use ($admin, $valuationKey): void {
            $this->ctx->api->call($admin(), 'POST', 'shares/valuations', ['new_value' => 100000, 'valuation_date' => '2026-09-14', 'reason' => 'Uthamini wa hisa baada ya matokeo ya nusu mwaka 2026 (April - Juni).', 'idempotency_key' => $valuationKey]);
        }, fn (): bool => ShareValuation::where('idempotency_key', $valuationKey)->exists() || ShareStructure::where('company_id', $this->ctx->company->id)->where('idempotency_key', $structureKey)->exists());

        foreach (Catalog::CONTRIBUTIONS as $number => [$reference, $holder, $amount, , , , $shares]) {
            if ($shares === null) {
                continue;
            }
            $key = Catalog::MARKER.'-SHI-'.substr($reference, 4);
            $this->ctx->timeline->at(CarbonImmutable::parse('2026-09-14 13:10')->addMinutes(10 * $number), "share issuance {$holder} {$shares}", function () use ($admin, $holder, $shares, $reference, $key): void {
                $this->ctx->api->call($admin(), 'POST', 'shares/issuances', [
                    'share_holder_id' => $this->ctx->shareholder($holder)->id,
                    'type' => 'issuance',
                    'payment_treatment' => ShareTransaction::TREATMENT_LINKED,
                    'shares' => $shares,
                    'price_per_share' => 100000,
                    'issue_date' => '2026-09-14',
                    'capital_id' => Capital::where('idempotency_key', Catalog::MARKER."-{$reference}")->value('id'),
                    'notes' => 'Hisa zilizolipwa kwa mchango wa mtaji wa Aprili 2026.',
                    'idempotency_key' => $key,
                ]);
            }, fn (): bool => ShareTransaction::where('idempotency_key', $key)->exists()
                || ShareTransaction::where('capital_id', Capital::where('idempotency_key', Catalog::MARKER."-{$reference}")->value('id'))->where('status', ShareTransaction::COMPLETED)->exists());
        }

        $transferKey = Catalog::MARKER.'-SHT-001';
        $this->ctx->timeline->at('2026-09-14 14:30', 'share transfer SH1 → SH5', function () use ($admin, $transferKey): void {
            $this->ctx->api->call($admin(), 'POST', 'shares/transfers', ['from_share_holder_id' => $this->ctx->shareholder('SH1')->id, 'to_share_holder_id' => $this->ctx->shareholder('SH5')->id, 'shares' => 20, 'consideration_per_share' => 100000, 'transfer_date' => '2026-09-14', 'notes' => 'Mauzo ya hisa 20 kati ya wanahisa.', 'idempotency_key' => $transferKey]);
        }, fn (): bool => ShareTransaction::where('idempotency_key', $transferKey)->exists());
    }

    private function dividends(): void
    {
        $admin = fn () => $this->ctx->demo('super_admin');
        $declaration = fn (): ?DividendDeclaration => DividendDeclaration::where('company_id', $this->ctx->company->id)->whereDate('period', self::DIVIDEND_PERIOD)->first();

        $this->ctx->timeline->at('2026-09-14 15:00', 'dividend declaration June 2026', function () use ($admin): void {
            $this->ctx->api->call($admin(), 'POST', 'capital/dividends', ['period' => '2026-06']);
        }, fn (): bool => $declaration() !== null);

        foreach ([['SH1', 'half', 'BANK', 'NMB', '001'], ['SH2', 'full', 'CASH', null, '002']] as $number => [$holder, $portion, $method, $bank, $reference]) {
            $key = Catalog::MARKER."-DIV-PAY-{$reference}";
            $this->ctx->timeline->at(CarbonImmutable::parse('2026-09-14 15:30')->addMinutes(10 * $number), "dividend payment {$holder} ({$portion})", function () use ($admin, $declaration, $holder, $portion, $method, $bank, $reference, $key): void {
                $allocation = DividendAllocation::where('dividend_declaration_id', $declaration()->id)->where('share_holder_id', $this->ctx->shareholder($holder)->id)->firstOrFail();
                $amount = $portion === 'full' ? (float) $allocation->amount : floor((float) $allocation->amount / 2 * 100) / 100;

                $this->ctx->api->call($admin(), 'POST', "capital/dividends/allocations/{$allocation->id}/pay", [
                    'amount' => number_format($amount, 2, '.', ''),
                    'pay_method' => $method,
                    'bank_account_id' => $bank === null ? null : $this->ctx->bank($bank)->id,
                    'reference' => Catalog::MARKER."-DIV-{$reference}",
                    'idempotency_key' => $key,
                ]);
            }, fn (): bool => DividendPayment::where('idempotency_key', $key)->exists());
        }
    }
}
