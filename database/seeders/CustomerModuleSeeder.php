<?php

namespace Database\Seeders;

use App\Models\AccountTypeRequirement;
use App\Models\Company;
use App\Models\CustomerCategory;
use App\Models\District;
use App\Models\MasterData\Bank;
use App\Models\MasterData\BusinessSector;
use App\Models\MasterData\BusinessType;
use App\Models\MasterData\College;
use App\Models\MasterData\Course;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\IdType;
use App\Models\MasterData\MaritalStatus;
use App\Models\MasterData\MasterDataModel;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\PensionFund;
use App\Models\MasterData\PrivateCadre;
use App\Models\MasterData\PrivateDepartment;
use App\Models\MasterData\PrivateEmployer;
use App\Models\MasterData\PrivateSector;
use App\Services\Customers\MasterDataRegistry;
use App\Services\Customers\RequirementProfiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Customer Module reference data (idempotent): the five customer types per company, the baseline requirement
 * profile, the kyc_attachment document type, reference lists, the institution registers (from the option trees
 * in database/data/customer-types.json) and Tanzania geography (when no districts are on file yet).
 */
class CustomerModuleSeeder extends Seeder
{
    /**
     * Existing customer_categories.key values mapped to the reference codes.
     *
     * @var array<string, string>
     */
    public const LEGACY_KEYS = [
        'mtumishi_umma' => 'WATUMISHI_WA_UMMA',
        'sekta_binafsi' => 'SEKTA_BINAFSI',
        'mjasiriamali' => 'WAJASIRIAMALI',
        'mwanafunzi' => 'MWANAFUNZI_CHUO',
        'mstaafu' => 'MSTAAFU_UMMA',
    ];

    public const MOBILE_MONEY_PROVIDERS = ['M-Pesa', 'Mixx by Yas', 'Airtel Money', 'HaloPesa'];

    public const ID_TYPES = ['National ID (NIDA)', 'Voter ID', "Driver's Licence", 'Passport', 'Work ID', 'TIN'];

    public const MARITAL_STATUSES = ['Single', 'Married', 'Divorced', 'Widowed'];

    public const PENSION_FUNDS = ['PSSSF', 'NSSF', 'ZSSF (Zanzibar)', 'WCF'];

    public function run(): void
    {
        $this->seedReferenceData();
        $this->seedGeography();

        Company::query()->each(fn (Company $company) => $this->seedCompany($company));
    }

    /**
     * Customer types and the baseline requirement profile of one company.
     */
    public function seedCompany(Company $company): void
    {
        foreach ($this->typeDefinitions() as $definition) {
            $this->seedType($company, $definition);
        }

        AccountTypeRequirement::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_type_id' => null, 'customer_category_id' => null],
            array_diff_key(RequirementProfiles::BASELINE, ['category_documents_enforced_from' => true]),
        );
    }

    /**
     * Global master data: document type, reference lists and institution registers.
     */
    public function seedReferenceData(): void
    {
        $this->upsertRow(DocumentType::class, null, 'kyc_attachment', 'KYC Attachment', 0, "The customer's KYC documents, scanned as a single file.");

        $this->upsertNames(MobileMoneyProvider::class, null, self::MOBILE_MONEY_PROVIDERS);
        $this->upsertNames(IdType::class, null, self::ID_TYPES);
        $this->upsertNames(MaritalStatus::class, null, self::MARITAL_STATUSES);

        $trees = json_decode((string) file_get_contents(database_path('data/customer-types.json')), true)['optionTrees'];

        $this->upsertNames(PensionFund::class, null, [...self::PENSION_FUNDS, ...($trees['MFUKO_HIFADHI'] ?? [])]);
        $this->upsertNames(Bank::class, null, $trees['BANKS'] ?? []);

        $bodies = $this->upsertNames(GovernmentBody::class, null, array_keys($trees['TAASISI'] ?? []));
        foreach ($trees['TAASISI'] ?? [] as $bodyName => $departments) {
            $departmentIds = $this->upsertNames(GovernmentDepartment::class, $bodies[$bodyName], array_keys($departments));
            foreach ($departments as $departmentName => $cadres) {
                $this->upsertNames(GovernmentCadre::class, $departmentIds[$departmentName], $cadres);
            }
        }

        $sectors = $this->upsertNames(PrivateSector::class, null, array_keys($trees['SEKTA_BINAFSI'] ?? []));
        foreach ($trees['SEKTA_BINAFSI'] ?? [] as $sectorName => $sector) {
            $this->upsertNames(PrivateEmployer::class, $sectors[$sectorName], $sector['taasisi'] ?? []);
            $departmentIds = $this->upsertNames(PrivateDepartment::class, $sectors[$sectorName], array_keys($sector['idara'] ?? []));
            foreach ($sector['idara'] ?? [] as $departmentName => $cadres) {
                $this->upsertNames(PrivateCadre::class, $departmentIds[$departmentName], $cadres);
            }
        }

        $businessSectors = $this->upsertNames(BusinessSector::class, null, array_keys($trees['SEKTA'] ?? []));
        foreach ($trees['SEKTA'] ?? [] as $sectorName => $types) {
            $this->upsertNames(BusinessType::class, $businessSectors[$sectorName], $types);
        }

        $colleges = $this->upsertNames(College::class, null, array_keys($trees['VYUO'] ?? []));
        foreach ($trees['VYUO'] ?? [] as $collegeName => $courses) {
            $this->upsertNames(Course::class, $colleges[$collegeName], $courses);
        }
    }

    /**
     * Import Tanzania geography from database/data/tz-geography.csv when no districts are on file.
     */
    public function seedGeography(): void
    {
        if (! District::query()->exists() && is_readable(database_path('data/tz-geography.csv'))) {
            Artisan::call('geography:import');
        }
    }

    /**
     * The five customer types from the reference configuration.
     *
     * @return list<array<string, mixed>>
     */
    public function typeDefinitions(): array
    {
        return json_decode((string) file_get_contents(database_path('data/customer-module-types.json')), true)['customerTypes'];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedType(Company $company, array $definition): void
    {
        $legacyKey = array_search($definition['code'], self::LEGACY_KEYS, true);

        $category = CustomerCategory::withTrashed()->where('company_id', $company->id)->where('code', $definition['code'])->first()
            ?? ($legacyKey === false ? null : CustomerCategory::withTrashed()->where('company_id', $company->id)->whereNull('code')->where('key', $legacyKey)->first());

        $firstTime = $category === null || $category->code === null;
        $category ??= new CustomerCategory([
            'company_id' => $company->id,
            'key' => $legacyKey === false ? Str::lower($definition['code']) : $legacyKey,
            'form_schema' => [],
            'is_active' => $definition['isActive'],
        ]);

        $category->fill([
            'code' => $definition['code'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'form_title' => $definition['formTitle'],
            'sort_order' => $definition['sortOrder'],
            'sector' => $definition['sector'],
            'requires_sector' => $definition['requiresSector'],
            'requires_employer' => $definition['requiresEmployer'],
            'requires_contract' => $definition['requiresContract'],
            'requires_salary' => $definition['requiresSalary'],
            'requires_extra_approval' => $definition['requiresExtraApproval'],
            'dynamic_form_schema' => $definition['dynamicFormSchema'],
            'omitted_standard_fields' => $definition['omittedStandardFields'],
        ]);

        if ($firstTime) {
            $category->fill([
                'risk_tier' => $definition['riskTier'],
                'required_documents' => $definition['requiredDocuments'],
                'optional_documents' => $definition['optionalDocuments'],
            ]);
        }

        if ($category->trashed()) {
            $category->deleted_at = null;
        }

        $category->save();
    }

    /**
     * Find-or-create rows by (parent, code derived from the name), restoring soft-deleted rows.
     *
     * @param  class-string<MasterDataModel>  $model
     * @param  iterable<int, string>  $names
     * @return array<string, int> name => id
     */
    private function upsertNames(string $model, ?int $parentId, iterable $names): array
    {
        $ids = [];
        $order = 0;
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($ids[$name])) {
                continue;
            }
            $ids[$name] = $this->upsertRow($model, $parentId, MasterDataRegistry::codeFor($name), $name, ++$order);
        }

        return $ids;
    }

    /**
     * @param  class-string<MasterDataModel>  $model
     */
    private function upsertRow(string $model, ?int $parentId, string $code, string $name, int $sortOrder, ?string $description = null): int
    {
        $parentColumn = $model::PARENT_COLUMN;

        $row = $model::withTrashed()
            ->when($parentColumn !== null, fn ($query) => $query->where($parentColumn, $parentId))
            ->where('code', $code)
            ->first();

        if ($row === null) {
            $row = new $model(['code' => $code, 'is_active' => true]);
            if ($parentColumn !== null) {
                $row->{$parentColumn} = $parentId;
            }
        }

        $row->fill(['name' => $name, 'sort_order' => $sortOrder]);
        if ($description !== null) {
            $row->description = $description;
        }
        if ($row->trashed()) {
            $row->deleted_at = null;
        }
        if ($row->isDirty() || ! $row->exists) {
            $row->save();
        }

        return $row->id;
    }
}
