<?php

namespace Database\Seeders\DevSeed;

use App\Models\Customer;
use App\Models\District;
use App\Models\FaceScan;
use App\Models\MasterData\Bank;
use App\Models\MasterData\BusinessSector;
use App\Models\MasterData\BusinessType;
use App\Models\MasterData\College;
use App\Models\MasterData\Course;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\IdType;
use App\Models\MasterData\MaritalStatus;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\PensionFund;
use App\Models\MasterData\PrivateCadre;
use App\Models\MasterData\PrivateDepartment;
use App\Models\MasterData\PrivateEmployer;
use App\Models\Region;
use Carbon\CarbonImmutable;

/**
 * Customers registered through the Customer Module wizard endpoint (POST /customers) with complete Step 2 answers for
 * their customer type, the Step 4 face scan, and branch-manager approval / rejection.
 *
 * Face scan: the capture is a generated placeholder image labelled "DEVSEED PLACEHOLDER" (there is no camera in a seeder);
 * the scan is recorded through the normal face-verify endpoint with scanner version "devseed-placeholder".
 */
final class CustomerPhase
{
    /** @var array<string, list<string>> */
    private const WARDS = [
        'KB' => ['Kibondo Mjini', 'Bitare', 'Busunzu', 'Misezero'], 'KS' => ['Murufiti', 'Heru Juu', 'Kumsenga', 'Nyansha'],
        'KM' => ['Mwanga Kusini', 'Kibirizi', 'Katubuka', 'Gungu'], 'BH' => ['Munanila', 'Muyama', 'Janda', 'Kajana'],
        'UV' => ['Nguruka', 'Kazuramimba', 'Uvinza Mjini', 'Ilagala'], 'KK' => ['Gwarama', 'Kakonko Mjini', 'Nyamtukuza'],
        'MS' => ['Bunazi', 'Kyaka', 'Kassambya'], 'LN' => ['Mingoyo', 'Rasbura', 'Mtanda'],
    ];

    /** @var list<string> */
    private const BUSINESSES = ['Duka la Rejareja', 'Genge la Mbogamboga', 'Saluni ya Kisasa', 'Mgahawa wa Chakula', 'Duka la Vifaa vya Ujenzi', 'Usafirishaji Bodaboda', 'Kuuza Samaki Wabichi', 'Duka la Nguo', 'Kinu cha Kusaga Nafaka', 'Duka la Dawa Muhimu'];

    /** @var array<string, list<array<int, int>>> */
    private array $masterData = [];

    public function __construct(private readonly Context $ctx) {}

    /**
     * Every customer of the data set, in a stable order (their index decides phone, names and answers).
     *
     * @return list<array{key: string, index: int, phone: string, branch: string, type: string, first: string, middle: string, last: string, gender: string, face: bool, approval: string, registered: CarbonImmutable}>
     */
    public static function specs(): array
    {
        $specs = [];
        $index = 0;

        foreach (Catalog::loans() as $loan) {
            if (isset($loan['topup'])) {
                continue;
            }
            $apply = CarbonImmutable::parse($loan['apply']);
            $specs[] = self::spec(++$index, $loan['code'], $loan['branch'], Catalog::TYPE_OF[$loan['cat']], true, 'approved', $apply->subDays(2)->setTime(10, 0)->addMinutes($index), $loan['name'] ?? null);
        }
        foreach (Catalog::EXTRA_CUSTOMERS as $number => [$branch, $type, $face, $approval, $registered]) {
            $specs[] = self::spec(++$index, 'X'.($number + 1), $branch, $type, $face, $approval, CarbonImmutable::parse($registered), null);
        }

        return $specs;
    }

    /**
     * Customer phone of a loan (the refinancing top-up uses its original loan's customer).
     */
    public static function phoneForLoan(string $code): string
    {
        foreach (Catalog::loans() as $loan) {
            if ($loan['code'] === $code && isset($loan['topup'])) {
                $code = $loan['topup'];
            }
        }

        foreach (self::specs() as $spec) {
            if ($spec['key'] === $code) {
                return $spec['phone'];
            }
        }

        throw new \RuntimeException("No customer for loan {$code}");
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}|null  $name
     * @return array{key: string, index: int, phone: string, branch: string, type: string, first: string, middle: string, last: string, gender: string, face: bool, approval: string, registered: CarbonImmutable}
     */
    private static function spec(int $index, string $key, string $branch, string $type, bool $face, string $approval, CarbonImmutable $registered, ?array $name): array
    {
        $gender = $name[3] ?? ($index % 2 === 0 ? 'female' : 'male');
        $firsts = $gender === 'female' ? Catalog::FEMALE_NAMES : Catalog::MALE_NAMES;

        return [
            'key' => $key,
            'index' => $index,
            'phone' => sprintf('07579000%02d', $index),
            'branch' => $branch,
            'type' => $type,
            'first' => $name[0] ?? $firsts[$index % count($firsts)],
            'middle' => $name[1] ?? Catalog::MALE_NAMES[($index * 7) % count(Catalog::MALE_NAMES)],
            'last' => $name[2] ?? Catalog::LAST_NAMES[($index * 5) % count(Catalog::LAST_NAMES)],
            'gender' => $gender,
            'face' => $face,
            'approval' => $approval,
            'registered' => $registered,
        ];
    }

    public function register(): void
    {
        foreach (self::specs() as $spec) {
            $label = "{$spec['first']} {$spec['last']} ({$spec['branch']})";
            $existing = fn (): ?Customer => $this->ctx->customer($spec['phone']);

            $this->ctx->timeline->at($spec['registered'], "register customer {$label}", function () use ($spec): void {
                $officer = $this->ctx->branchActor($spec['branch'], 'loan_officer');
                $this->ctx->api->call($officer, 'POST', 'customers', $this->payload($spec, $officer->id));
            }, fn (): bool => $existing() !== null);

            if ($spec['face']) {
                $this->ctx->timeline->at($spec['registered']->addMinutes(25), "face scan {$label}", function () use ($spec, $existing): void {
                    $this->ctx->api->call($this->ctx->branchActor($spec['branch'], 'loan_officer'), 'POST', "customers/{$existing()->id}/face-verify", $this->faceReport($spec['index']), ['capture' => Images::faceCapture()]);
                }, fn (): bool => $existing()?->face_verified_at !== null);
            }

            if ($spec['approval'] === 'approved') {
                $this->ctx->timeline->at($spec['registered']->addDay()->setTime(15, 0)->addMinutes($spec['index']), "approve customer {$label}", function () use ($spec, $existing): void {
                    $this->ctx->api->call($this->ctx->branchActor($spec['branch'], 'branch_manager'), 'POST', "customers/{$existing()->id}/approve");
                }, fn (): bool => $existing()?->approval_status !== 'pending');
            }
            if ($spec['approval'] === 'rejected') {
                $this->ctx->timeline->at($spec['registered']->addDay()->setTime(11, 0), "reject customer {$label}", function () use ($spec, $existing): void {
                    $this->ctx->api->call($this->ctx->branchActor($spec['branch'], 'branch_manager'), 'POST', "customers/{$existing()->id}/reject", [
                        'reason' => ['SEKTA_BINAFSI' => 'Barua ya ajira haijathibitishwa na mwajiri.', 'WAJASIRIAMALI' => 'Leseni ya biashara imekwisha muda wake.', 'MWANAFUNZI_CHUO' => 'Mdhamini hajathibitisha udhamini.'][$spec['type']] ?? 'Taarifa hazijakamilika.',
                    ]);
                }, fn (): bool => $existing()?->approval_status !== 'pending');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function payload(array $spec, int $officerId): array
    {
        $i = $spec['index'];
        $branch = $this->ctx->branch($spec['branch']);
        $districtName = Catalog::DISTRICTS[$spec['branch']];
        $district = District::where('region_id', $branch->region_id)->where('name', $districtName)->firstOrFail();
        $ward = self::WARDS[$spec['branch']][$i % count(self::WARDS[$spec['branch']])];
        $nida = sprintf('%s%05d%05d%02d', CarbonImmutable::parse($this->dob($spec['type'], $i))->format('Ymd'), 17000 + $i, 90000 + $i, $i % 97);
        $fullName = strtoupper("{$spec['first']} {$spec['middle']} {$spec['last']}");
        $salaried = in_array($spec['type'], ['WATUMISHI_WA_UMMA', 'SEKTA_BINAFSI', 'MSTAAFU_UMMA'], true);
        $bank = Bank::query()->where('name', $i % 3 === 0 ? 'CRDB Bank' : 'NMB Bank')->firstOrFail();
        $provider = MobileMoneyProvider::query()->orderBy('id')->get()[$i % 4];

        $payload = [
            'branchId' => $branch->id,
            'employeeId' => $officerId,
            'customerCategoryId' => $this->ctx->customerType($spec['type'])->id,
            'firstName' => $spec['first'],
            'middleName' => $spec['middle'],
            'lastName' => $spec['last'],
            'dob' => $this->dob($spec['type'], $i),
            'gender' => $spec['gender'],
            'phone' => $spec['phone'],
            'alternativePhone' => sprintf('06529100%02d', $i),
            'idTypeId' => IdType::query()->where('name', 'National ID (NIDA)')->value('id'),
            'idNumber' => $nida,
            'nidaNumber' => $nida,
            'maritalStatusId' => MaritalStatus::query()->where('name', $spec['type'] === 'MWANAFUNZI_CHUO' ? 'Single' : (['Married', 'Married', 'Single', 'Widowed'][$i % 4]))->value('id'),
            'dependentsCount' => $spec['type'] === 'MWANAFUNZI_CHUO' ? 0 : ($i % 5) + 1,
            'residenceType' => $i % 3 === 0 ? 'rented' : 'owned',
            'nationality' => 'Mtanzania',
            'regionId' => $branch->region_id ?? Region::where('name', Catalog::BRANCHES[$spec['branch']][3])->value('id'),
            'districtId' => $district->id,
            'wardName' => $ward,
            'streetName' => 'Mtaa wa '.['Amani', 'Tumaini', 'Mapinduzi', 'Uhuru', 'Majengo'][$i % 5],
            'houseNumber' => (string) (100 + $i * 3),
            'landmark' => 'Karibu na '.['msikiti', 'kanisa la Katoliki', 'shule ya msingi', 'soko kuu', 'kituo cha afya'][$i % 5],
            'registrationSource' => 'branch',
            'createdDevice' => 'DevelopmentTestDataSeeder',
            'nextOfKin' => [
                ['name' => strtoupper(Catalog::FEMALE_NAMES[($i + 3) % 30].' '.$spec['last']), 'relationship' => $spec['type'] === 'MWANAFUNZI_CHUO' ? 'parent' : ($i % 2 === 0 ? 'spouse' : 'sibling'), 'phone' => sprintf('07139200%02d', $i), 'address' => "{$ward}, {$districtName}"],
            ],
            'guarantors' => $spec['type'] === 'WAJASIRIAMALI' ? [
                ['name' => strtoupper(Catalog::MALE_NAMES[($i + 11) % 30].' '.Catalog::LAST_NAMES[($i + 13) % count(Catalog::LAST_NAMES)]), 'phone' => sprintf('07849300%02d', $i), 'nidaNumber' => sprintf('1979%04d%012d', 101 + $i, 500000 + $i), 'relationship' => 'friend', 'address' => "{$ward}, {$districtName}", 'occupation' => 'Mfanyabiashara'],
            ] : [],
            'dynamicFormData' => $this->answers($spec, $districtName, $ward),
        ];

        if ($i % 4 === 1) {
            $payload['nextOfKin'][] = ['name' => strtoupper(Catalog::MALE_NAMES[($i + 5) % 30].' '.Catalog::LAST_NAMES[($i + 2) % count(Catalog::LAST_NAMES)]), 'relationship' => 'relative', 'phone' => sprintf('07669400%02d', $i), 'address' => $districtName];
        }

        if ($salaried) {
            $payload += [
                'paymentMethod' => 'bank',
                'bankId' => $bank->id,
                'bankBranch' => $districtName,
                'bankDetails' => ['bankName' => $bank->name, 'accountNumber' => sprintf('%s%08d', $bank->name === 'CRDB Bank' ? '0152' : '4071', 30000000 + $i * 7), 'accountName' => $fullName],
            ];
        } else {
            $payload += ['paymentMethod' => 'mno', 'mobileMoneyProviderId' => $provider->id, 'walletNumber' => $spec['phone']];
        }

        if (in_array($spec['type'], ['WATUMISHI_WA_UMMA', 'SEKTA_BINAFSI'], true)) {
            $payload['basicSalary'] = 650000 + ($i % 6) * 110000;
            $payload['takeHome'] = (int) round($payload['basicSalary'] * 0.68, -3);
            $payload['occupation'] = $spec['type'] === 'WATUMISHI_WA_UMMA' ? 'Mtumishi wa Umma' : 'Mfanyakazi Sekta Binafsi';
        }
        if ($spec['type'] === 'MWANAFUNZI_CHUO') {
            $payload['monthlyIncome'] = 150000 + ($i % 3) * 50000;
        }

        return $payload;
    }

    /**
     * Step 2 answers of the customer type (dynamic_form_schema of database/data/customer-module-types.json).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function answers(array $spec, string $district, string $ward): array
    {
        $i = $spec['index'];

        return match ($spec['type']) {
            'WATUMISHI_WA_UMMA' => (function () use ($i, $district): array {
                [$body, $department, $cadre] = $this->pick('government', $i);

                return ['taasisi' => $body, 'idara' => $department, 'cheo' => $cadre, 'kituo' => ['Hospitali ya Wilaya ', 'Shule ya Sekondari ', 'Ofisi ya Halmashauri '][$i % 3].$district, 'check_number' => sprintf('CHK%07d', 1120000 + $i * 13), 'aina_ajira' => 'Ajira ya Kudumu'];
            })(),
            'SEKTA_BINAFSI' => (function () use ($i, $district): array {
                [$sector, $employer, $department, $cadre] = $this->pick('private', $i);

                return ['sb_sekta' => $sector, 'sb_taasisi' => $employer, 'sb_idara' => $department, 'sb_cheo' => $cadre, 'sb_kituo' => "Tawi la {$district}", 'sb_aina_mkataba' => $i % 3 === 0 ? 'Mkataba wa Muda' : 'Ajira ya Kudumu', 'sb_kitambulisho' => sprintf('EMP-%05d', 4100 + $i)];
            })(),
            'WAJASIRIAMALI' => (function () use ($i, $spec, $district, $ward): array {
                [$sector, $type] = $this->pick('business', $i);

                return ['sekta' => $sector, 'aina' => $type, 'jina_biashara' => self::BUSINESSES[$i % count(self::BUSINESSES)].' '.$spec['first'], 'muda_biashara' => ($i % 9) + 1, 'mapato' => 350000 + ($i % 7) * 125000, 'wafanyakazi' => $i % 4, 'mahali_biashara' => "Soko la {$ward}, {$district}"];
            })(),
            'MWANAFUNZI_CHUO' => (function () use ($i, $spec): array {
                [$college, $course] = $this->pick('college', $i);

                return ['chuo' => $college, 'kozi' => $course, 'level' => ['Stashahada (Diploma)', 'Shahada (Degree)', 'Astashahada (Certificate)'][$i % 3], 'mwaka' => 'Mwaka wa '.(($i % 3) + 1), 'email_chuo' => strtolower("{$spec['first']}.{$spec['last']}@students.devseed.test"), 'mdhamini' => strtoupper(Catalog::MALE_NAMES[($i + 9) % 30].' '.$spec['last']), 'mdhamini_simu' => sprintf('07549500%02d', $i)];
            })(),
            'MSTAAFU_UMMA' => (function () use ($i, $district, $ward): array {
                [$body, $department, $cadre] = $this->pick('government', $i + 3);

                return ['mstaafu_taasisi' => $body, 'mstaafu_idara' => $department, 'mstaafu_cheo' => $cadre, 'makazi' => "{$ward}, {$district}", 'pensheni' => 420000 + ($i % 5) * 60000, 'mfuko' => PensionFund::query()->where('name', 'PSSSF')->value('id'), 'namba_mfuko' => sprintf('PSSSF-%08d', 20450000 + $i)];
            })(),
        };
    }

    /**
     * A valid parent/child chain of master data, rotating by $index.
     *
     * @return array<int, int>
     */
    private function pick(string $list, int $index): array
    {
        $this->masterData[$list] ??= match ($list) {
            'government' => GovernmentCadre::query()->orderBy('id')->limit(400)->get()
                ->map(fn (GovernmentCadre $cadre): array => [(int) GovernmentDepartment::query()->whereKey($cadre->government_department_id)->value('government_body_id'), (int) $cadre->government_department_id, (int) $cadre->id])
                ->unique(fn (array $chain): string => $chain[0].'-'.$chain[1])->values()->all(),
            'private' => PrivateCadre::query()->orderBy('id')->limit(400)->get()
                ->map(function (PrivateCadre $cadre): ?array {
                    $sector = (int) PrivateDepartment::query()->whereKey($cadre->private_department_id)->value('private_sector_id');
                    $employer = PrivateEmployer::query()->where('private_sector_id', $sector)->orderBy('id')->value('id');

                    return $employer === null ? null : [$sector, (int) $employer, (int) $cadre->private_department_id, (int) $cadre->id];
                })->filter()->unique(fn (array $chain): string => $chain[0].'-'.$chain[2])->values()->all(),
            'business' => BusinessType::query()->whereIn('business_sector_id', BusinessSector::query()->pluck('id'))->orderBy('id')->get()
                ->map(fn (BusinessType $type): array => [(int) $type->business_sector_id, (int) $type->id])->values()->all(),
            'college' => Course::query()->whereIn('college_id', College::query()->pluck('id'))->orderBy('id')->get()
                ->unique('college_id')->map(fn (Course $course): array => [(int) $course->college_id, (int) $course->id])->values()->all(),
        };

        return $this->masterData[$list][$index % count($this->masterData[$list])];
    }

    private function dob(string $type, int $index): string
    {
        [$from, $span] = match ($type) {
            'MWANAFUNZI_CHUO' => [2001, 4],
            'MSTAAFU_UMMA' => [1956, 8],
            'SEKTA_BINAFSI' => [1985, 12],
            'WATUMISHI_WA_UMMA' => [1978, 14],
            default => [1972, 22],
        };

        return sprintf('%04d-%02d-%02d', $from + ($index % $span), ($index % 12) + 1, ($index % 27) + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function faceReport(int $index): array
    {
        return [
            'status' => 'passed',
            'scannerVersion' => 'devseed-placeholder',
            'livenessPassed' => 'true',
            'poseSequenceCompleted' => 'true',
            'qualityScore' => 86 + $index % 10,
            'brightnessScore' => 80 + $index % 15,
            'blurScore' => 84 + $index % 12,
            'distanceScore' => 88 + $index % 8,
            'centeringScore' => 85 + $index % 11,
            'eyesOpenScore' => 90 + $index % 9,
            'checks' => array_fill_keys(FaceScan::CHECKS, 'true'),
            'captureDevice' => 'DevelopmentTestDataSeeder (no camera — placeholder capture)',
            'captureResolution' => '640x480',
            'captureDurationMs' => 4200 + $index * 10,
        ];
    }
}
