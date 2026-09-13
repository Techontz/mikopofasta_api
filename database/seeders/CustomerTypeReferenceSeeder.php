<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\RiskTier;
use App\Models\CustomerCategory;
use App\Models\MasterData\Bank;
use App\Models\MasterData\BusinessSector;
use App\Models\MasterData\BusinessType;
use App\Models\MasterData\College;
use App\Models\MasterData\Course;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\PensionFund;
use App\Models\MasterData\PrivateCadre;
use App\Models\MasterData\PrivateDepartment;
use App\Models\MasterData\PrivateEmployer;
use App\Models\MasterData\PrivateSector;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The five customer types and the register they ask from.
 *
 * SOURCE OF TRUTH. `Documents/customer-types.json`, generated from
 * `Documents/mikopofast.html` by `Documents/tools/extract_customer_types.mjs`.
 * That HTML is the authoritative specification: it is the only document
 * carrying exactly these five types with exactly this wording. Nothing here is
 * typed by hand — labels, requirements, option lists and the shape of every
 * cascade are read out of the generated file, so re-running the extractor and
 * this seeder is how a change to the document reaches the application.
 *
 * THE TYPES ARE ROWS, AND SO ARE THEIR QUESTIONS. Each type's Step 2 is its
 * `dynamic_form_schema`, which is what the wizard already renders and what
 * DynamicFormValidator already enforces — there is no code anywhere that knows
 * what a Mstaafu is asked, and there must never be. A sixth type, or a new
 * question on an existing one, is an administrator's edit.
 *
 * THE EXISTING TWO ARE RENAMED, NOT REPLACED. WATUMISHI WA UMMA becomes
 * "Mtumishi wa Umma" and WAJASIRIAMALI becomes "Mjasiriamali/Mfanyabiashara",
 * keeping their ids so every customer already filed under them keeps their
 * classification. Their KYC document requirements and risk tier are left
 * alone; only the naming and the questions are rewritten.
 *
 * IDEMPOTENT. Every list entry is matched on (parent, code) and every type on
 * its code, so running it again updates in place and creates nothing twice.
 */
final class CustomerTypeReferenceSeeder extends Seeder
{
    /**
     * Questions the document asks that this application asks elsewhere.
     *
     * NOT a disagreement with the document — every one of these is collected,
     * just not twice on one screen:
     *
     *   bank, account, kadi_muda   the document's "Taarifa za Benki" block.
     *                              The Payment Account chooser on step two now
     *                              asks MNO or Bank and then for that side's
     *                              provider and number, and writes the same
     *                              columns. Keeping both put two bank
     *                              dropdowns on the screen at once.
     *   mshahara                   duplicated "Basic Salary" beside it.
     *
     * Listed by key and applied when the schema is built, so re-running the
     * extractor cannot quietly reintroduce them — and removing a line here is
     * all it takes to ask one again.
     */
    private const array ASKED_ELSEWHERE = ['bank', 'account', 'kadi_muda', 'mshahara'];

    /**
     * Standard questions each type declines, by the standard field's key.
     *
     * The standard block is written for the common case. A retiree has no
     * Place of Employment, Basic Salary or Take Home; a trader asked for
     * "Jina la Biashara" does not need "Business Name" in English beside it.
     * Omitting is not deleting — every one is still a column and still
     * editable from the profile; registration stops ASKING.
     *
     * Mtumishi wa Umma omits nothing: its standard fields all apply.
     */
    private const array OMITTED_STANDARD = [
        'sekta_binafsi' => ['place_of_employment', 'check_number', 'monthly_income'],
        /* "Mapato ya Wastani kwa Mwezi (TZS)" is this type's own monthly income,
           so the standard box asked the same question in English. */
        'mjasiriamali' => ['business_name', 'business_type', 'business_address', 'monthly_income'],
        'mstaafu' => ['place_of_employment', 'check_number', 'basic_salary', 'take_home', 'monthly_income'],
    ];

    /**
     * Document questions a type does not ask after all, by document key.
     *
     * `sb_taasisi_other` is the "Andika Jina la Taasisi/Kampuni" box the
     * document shows when the company is "Nyingine (andika)".
     */
    private const array OMITTED_DOCUMENT = [
        'sekta_binafsi' => ['sb_taasisi_other', 'sb_tarehe_ajira'],
        /* The standard "TIN Number (Optional)" asks the same thing and writes
           a real column, so the document's own TIN box was the second of two
           side by side. */
        'mjasiriamali' => ['tin'],
        /* A retiree is off the payroll, so the payroll's check number is not
           theirs to give — the pension fund and its number below are what
           identifies them. The standard "Check Number" is omitted for this
           type too; see OMITTED_STANDARD. */
        'mstaafu' => ['mstaafu_check'],
    ];

    /**
     * A standard question this type asks in its own words.
     *
     * Declared with the STANDARD key and column, so the merge drops the
     * standard copy and draws this one — the answer still lands in
     * `monthly_income`, so nothing downstream has to learn a new name for it.
     * A student's income is a Boom, which is what the officer asking for it
     * calls it.
     */
    private const array RELABELLED = [
        'mwanafunzi' => [
            ['key' => 'monthly_income', 'label' => 'Boom', 'type' => 'currency', 'required' => false, 'storesIn' => 'monthlyIncome'],
        ],
    ];

    /** Which list a `tree|path|take` signature from the document becomes. */
    private const array SOURCES = [
        'TAASISI||keys' => ['government-bodies', GovernmentBody::class],
        'TAASISI|f|keys' => ['government-departments', GovernmentDepartment::class],
        'TAASISI|f,f|values' => ['government-cadres', GovernmentCadre::class],
        'SEKTA_BINAFSI||keys' => ['private-sectors', PrivateSector::class],
        'SEKTA_BINAFSI|f,p:taasisi|values' => ['private-employers', PrivateEmployer::class],
        'SEKTA_BINAFSI|f,p:idara|keys' => ['private-departments', PrivateDepartment::class],
        'SEKTA_BINAFSI|f,p:idara,f|values' => ['private-cadres', PrivateCadre::class],
        'SEKTA||keys' => ['business-sectors', BusinessSector::class],
        'SEKTA|f|values' => ['business-types', BusinessType::class],
        'VYUO||keys' => ['colleges', College::class],
        'VYUO|f|values' => ['courses', Course::class],
    ];

    /** The two flat lists a select may name directly. */
    private const array FLAT_SOURCES = [
        'BANKS' => 'banks',
        'MFUKO_HIFADHI' => 'pension-funds',
    ];

    /**
     * Which wizard heading each type sits under, and which of the FIRST-CLASS
     * blocks it wants. All four are false throughout: the document lists every
     * question each type asks, so switching on the standard employment or
     * salary block as well would draw a second, differently-worded copy.
     */
    private const array TYPE_META = [
        'mtumishi_umma' => ['code' => 'WATUMISHI_WA_UMMA', 'sector' => 'employment', 'risk' => 'low'],
        'sekta_binafsi' => ['code' => 'SEKTA_BINAFSI', 'sector' => 'employment', 'risk' => 'medium'],
        'mjasiriamali' => ['code' => 'WAJASIRIAMALI', 'sector' => 'business', 'risk' => 'medium'],
        'mwanafunzi' => ['code' => 'MWANAFUNZI_CHUO', 'sector' => 'other', 'risk' => 'high'],
        'mstaafu' => ['code' => 'MSTAAFU_UMMA', 'sector' => 'employment', 'risk' => 'low'],
    ];

    /**
     * Document questions whose `required` the business has since relaxed.
     *
     * Empty today — `tin` was the only entry and that question is no longer
     * asked at all (see OMITTED_DOCUMENT). Kept because relaxing a rule is a
     * different act from dropping a question, and the next one will want it.
     *
     * A typed property rather than a constant: an empty constant is analysed
     * as the literal `[]`, which would make every lookup "impossible".
     *
     * @var array<string, list<string>>
     */
    private static array $madeOptional = [];

    public function run(): void
    {
        $path = base_path('../Documents/customer-types.json');

        if (! is_file($path)) {
            $this->command?->warn("customer-types.json not found at {$path} — run Documents/tools/extract_customer_types.mjs first.");

            return;
        }

        /** @var array{types: list<array<string, mixed>>, optionTrees: array<string, mixed>} $doc */
        $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $ids = $this->seedRegister($doc['optionTrees']);
        $this->seedTypes($doc['types']);

        $this->command?->info(sprintf(
            'Register: %s',
            implode(', ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($ids), $ids)),
        ));
    }

    // -----------------------------------------------------------------------
    // The register
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $trees
     * @return array<string, int>
     */
    private function seedRegister(array $trees): array
    {
        $counts = [];

        // ---- Wizara / Taasisi -> Idara -> Cheo
        foreach ($trees['TAASISI'] as $body => $departments) {
            $bodyId = $this->put(GovernmentBody::class, null, null, (string) $body);
            foreach ($departments as $department => $cadres) {
                $departmentId = $this->put(GovernmentDepartment::class, 'government_body_id', $bodyId, (string) $department);
                foreach ($cadres as $cadre) {
                    $this->put(GovernmentCadre::class, 'government_department_id', $departmentId, (string) $cadre);
                }
            }
        }

        // ---- Sekta -> Kampuni, and Sekta -> Kitengo -> Cheo
        foreach ($trees['SEKTA_BINAFSI'] as $sector => $block) {
            $sectorId = $this->put(PrivateSector::class, null, null, (string) $sector);

            foreach ($block['taasisi'] as $employer) {
                $this->put(PrivateEmployer::class, 'private_sector_id', $sectorId, (string) $employer);
            }

            /* The document offers this alongside the named companies, and the
               "Andika Jina la Taasisi/Kampuni" box appears when it is chosen.
               It is a row like any other so that the chosen value is a real id
               the validator can check. */
            $this->put(PrivateEmployer::class, 'private_sector_id', $sectorId, 'Nyingine (andika)', 'NYINGINE');

            foreach ($block['idara'] as $department => $cadres) {
                $departmentId = $this->put(PrivateDepartment::class, 'private_sector_id', $sectorId, (string) $department);
                foreach ($cadres as $cadre) {
                    $this->put(PrivateCadre::class, 'private_department_id', $departmentId, (string) $cadre);
                }
            }
        }

        // ---- Sekta ya Biashara -> Aina Maalum
        foreach ($trees['SEKTA'] as $sector => $types) {
            $sectorId = $this->put(BusinessSector::class, null, null, (string) $sector);
            foreach ($types as $type) {
                $this->put(BusinessType::class, 'business_sector_id', $sectorId, (string) $type);
            }
        }

        // ---- Chuo -> Kozi
        foreach ($trees['VYUO'] as $college => $courses) {
            $collegeId = $this->put(College::class, null, null, (string) $college);
            foreach ($courses as $course) {
                $this->put(Course::class, 'college_id', $collegeId, (string) $course);
            }
        }

        // ---- Flat
        foreach ($trees['MFUKO_HIFADHI'] as $fund) {
            $this->put(PensionFund::class, null, null, (string) $fund);
        }

        /* Banks already exist as a list; the document names more of them. Added,
           never removed — an institution may have its own. */
        foreach ($trees['BANKS'] as $bank) {
            $this->put(Bank::class, null, null, (string) $bank);
        }

        foreach ([GovernmentBody::class, GovernmentDepartment::class, GovernmentCadre::class,
            PrivateSector::class, PrivateEmployer::class, PrivateDepartment::class, PrivateCadre::class,
            BusinessSector::class, BusinessType::class, College::class, Course::class,
            PensionFund::class, Bank::class] as $model) {
            $counts[class_basename($model)] = $model::query()->count();
        }

        return $counts;
    }

    /**
     * Create or update one list entry, matched on (parent, code).
     *
     * @param class-string<\App\Models\MasterData\MasterDataModel> $model
     */
    private function put(string $model, ?string $parentColumn, ?int $parentId, string $name, ?string $code = null): int
    {
        $code ??= $this->code($name);

        $query = $model::query()->withTrashed()->where('code', $code);
        if ($parentColumn !== null) {
            $query->getQuery()->where($parentColumn, $parentId);
        }

        $row = $query->first() ?? new $model;
        $row->name = $name;
        $row->is_active = true;
        if ($parentColumn !== null) {
            $row->{$parentColumn} = $parentId;
        }
        $row->code = $code;
        $row->deleted_at = null;
        $row->save();

        return (int) $row->getKey();
    }

    /** A stable code from a name: upper snake, ASCII, inside the column. */
    private function code(string $name): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $code = trim($code, '_');

        /* Names longer than the column are shortened with a hash of the whole
           name, so two long names that share a prefix stay distinct. */
        return strlen($code) <= 60 ? $code : substr($code, 0, 51).'_'.substr(md5($name), 0, 8);
    }

    // -----------------------------------------------------------------------
    // The types
    // -----------------------------------------------------------------------

    /** @param list<array<string, mixed>> $types */
    private function seedTypes(array $types): void
    {
        foreach ($types as $index => $type) {
            $meta = self::TYPE_META[$type['key']];

            $category = CustomerCategory::query()->withTrashed()->firstOrNew(['code' => $meta['code']]);

            $category->name = $type['label'];
            $category->form_title = $type['sectionTitle'];
            $category->sector = CategorySector::from($meta['sector']);
            $category->is_active = true;
            $category->sort_order = $index + 1;
            $category->deleted_at = null;

            /* Only set on a type being created: an institution may have tuned
               these on the two that already existed. */
            if (! $category->exists) {
                $category->risk_tier = RiskTier::from($meta['risk']);
                $category->required_documents = [];
                $category->optional_documents = [];
                $category->requires_extra_approval = false;
            }

            /* The document lists every question, so none of the standard
               blocks are switched on — see the note on TYPE_META. */
            $category->requires_sector = false;
            $category->requires_employer = false;
            $category->requires_contract = false;
            $category->requires_salary = false;

            $dropped = array_merge(self::ASKED_ELSEWHERE, self::OMITTED_DOCUMENT[$type['key']] ?? []);
            $optional = self::$madeOptional[$type['key']] ?? [];

            $schema = array_values(array_map(
                function (array $f) use ($optional): array {
                    $built = $this->field($f);

                    if (in_array($f['key'], $optional, true)) {
                        $built['required'] = false;
                    }

                    return $built;
                },
                array_filter(
                    $type['fields'],
                    static fn (array $f): bool => ! in_array($f['key'], $dropped, true),
                ),
            ));

            $category->dynamic_form_schema = array_merge($schema, self::RELABELLED[$type['key']] ?? []);
            $category->omitted_standard_fields = self::OMITTED_STANDARD[$type['key']] ?? [];
            $category->save();
        }
    }

    /**
     * One extracted field as the `DynamicFormField` the wizard renders.
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function field(array $f): array
    {
        $field = [
            'key' => $f['key'],
            'label' => $f['label'],
            'type' => $this->type($f),
            'required' => (bool) $f['required'],
        ];

        if ($f['fullWidth'] ?? false) {
            $field['fullWidth'] = true;
        }

        if (($f['dependsOn'] ?? null) !== null) {
            $field['dependsOn'] = $f['dependsOn'];
        }

        if (($f['requiredWhen'] ?? null) !== null) {
            /* Compared against the CODE of the referenced answer — see
               DynamicFormValidator — so the label is converted to the code the
               register holds it under. */
            $field['required'] = false;
            $field['requiredWhen'] = [
                'field' => $f['requiredWhen']['field'],
                'equals' => array_map($this->code(...), $f['requiredWhen']['equals']),
            ];
        }

        $source = $f['optionSource'] ?? null;

        if ($source !== null) {
            if ($source['kind'] === 'fixed') {
                $field['options'] = $source['options'];
            } elseif ($source['kind'] === 'flat') {
                $field['dataSource'] = self::FLAT_SOURCES[$source['tree']];
            } elseif ($source['kind'] === 'tree') {
                $field['dataSource'] = self::SOURCES[$this->signature($source)][0]
                    ?? throw new RuntimeException("No list for {$this->signature($source)}");
            }
        }

        return $field;
    }

    /**
     * `TAASISI|f,f|values` — the shape of a path into one of the trees.
     *
     * @param array<string, mixed> $source
     */
    private function signature(array $source): string
    {
        $path = array_map(
            static fn (array $s): string => isset($s['property']) ? 'p:'.$s['property'] : 'f',
            $source['path'],
        );

        return $source['tree'].'|'.implode(',', $path).'|'.$source['take'];
    }

    /**
     * The document's control and input type as one of DYNAMIC_FIELD_TYPES.
     *
     * @param array<string, mixed> $f
     */
    private function type(array $f): string
    {
        if ($f['control'] === 'select') {
            return 'select';
        }

        return match ($f['inputType']) {
            'number' => 'number',
            'date' => 'date',
            default => 'text',
        };
    }
}
