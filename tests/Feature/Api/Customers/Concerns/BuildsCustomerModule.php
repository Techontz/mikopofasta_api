<?php

namespace Tests\Feature\Api\Customers\Concerns;

use App\Models\Company;
use App\Models\CustomerCategory;
use App\Models\District;
use App\Models\Employee;
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
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\PensionFund;
use App\Models\MasterData\PrivateCadre;
use App\Models\MasterData\PrivateDepartment;
use App\Models\MasterData\PrivateEmployer;
use App\Models\MasterData\PrivateSector;
use App\Models\Region;
use App\Models\Ward;
use Database\Seeders\CustomerModuleSeeder;
use Illuminate\Support\Str;

/**
 * Fixtures for the Customer Module tests: the five customer types and baseline profile come from
 * CustomerModuleSeeder::seedCompany; master-data rows and geography are small fixtures (the full registers and
 * the 3,643-row geography import are too slow to load in every test).
 */
trait BuildsCustomerModule
{
    /**
     * @var array<string, int>
     */
    protected array $ids = [];

    protected function seedCustomerModule(Employee $admin): void
    {
        (new CustomerModuleSeeder)->seedCompany(Company::findOrFail($admin->company_id));

        $row = fn (string $model, string $name, array $extra = []): int => $model::create(['code' => Str::upper(Str::snake(Str::slug($name, '_'))).'_'.Str::random(4), 'name' => $name] + $extra)->id;

        DocumentType::firstOrCreate(['code' => 'kyc_attachment'], ['name' => 'KYC Attachment']);
        $this->ids['nida'] = $row(IdType::class, 'National ID (NIDA)');
        $this->ids['married'] = $row(MaritalStatus::class, 'Married');
        $this->ids['mpesa'] = $row(MobileMoneyProvider::class, 'M-Pesa');
        $this->ids['crdb'] = $row(Bank::class, 'CRDB Bank');

        $this->ids['body'] = $row(GovernmentBody::class, 'Wizara ya Afya');
        $this->ids['department'] = $row(GovernmentDepartment::class, 'Idara ya Utawala', ['government_body_id' => $this->ids['body']]);
        $this->ids['cadre'] = $row(GovernmentCadre::class, 'Afisa Tawala', ['government_department_id' => $this->ids['department']]);
        $this->ids['otherBody'] = $row(GovernmentBody::class, 'Wizara ya Elimu');
        $this->ids['otherDepartment'] = $row(GovernmentDepartment::class, 'Idara ya Elimu', ['government_body_id' => $this->ids['otherBody']]);

        $this->ids['privateSector'] = $row(PrivateSector::class, 'Benki');
        $this->ids['privateEmployer'] = $row(PrivateEmployer::class, 'NMB', ['private_sector_id' => $this->ids['privateSector']]);
        $this->ids['privateDepartment'] = $row(PrivateDepartment::class, 'Fedha', ['private_sector_id' => $this->ids['privateSector']]);
        $this->ids['privateCadre'] = $row(PrivateCadre::class, 'Mhasibu', ['private_department_id' => $this->ids['privateDepartment']]);

        $this->ids['businessSector'] = $row(BusinessSector::class, 'Biashara ya Rejareja');
        $this->ids['businessType'] = $row(BusinessType::class, 'Duka la Vyakula', ['business_sector_id' => $this->ids['businessSector']]);

        $this->ids['college'] = $row(College::class, 'Chuo Kikuu cha Dar es Salaam');
        $this->ids['course'] = $row(Course::class, 'Uhasibu', ['college_id' => $this->ids['college']]);

        $this->ids['pensionFund'] = $row(PensionFund::class, 'PSSSF');

        $this->ids['region'] = Region::create(['name' => 'Kigoma'])->id;
        $this->ids['district'] = District::create(['region_id' => $this->ids['region'], 'name' => 'Kakonko'])->id;
        $this->ids['ward'] = Ward::create(['district_id' => $this->ids['district'], 'name' => 'Gwarama'])->id;
    }

    protected function type(Employee $admin, string $code): CustomerCategory
    {
        return CustomerCategory::where('company_id', $admin->company_id)->where('code', $code)->firstOrFail();
    }

    protected function employeeWithRole(Employee $admin, string $role, ?int $branchId = null): Employee
    {
        return Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $branchId ?? $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * A complete Step 1 payload (no customer type) that passes the baseline profile.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(Employee $admin, array $overrides = []): array
    {
        return array_replace([
            'branchId' => $admin->branch_id,
            'employeeId' => $admin->id,
            'customerCategoryId' => null,
            'firstName' => 'Asha',
            'middleName' => 'Juma',
            'lastName' => 'Hamisi',
            'dob' => '1990-04-15',
            'gender' => 'female',
            'phone' => '0754'.random_int(100000, 999999),
            'idTypeId' => $this->ids['nida'],
            'idNumber' => '19900415123450000113',
            'maritalStatusId' => $this->ids['married'],
            'dependentsCount' => 2,
            'residenceType' => 'owned',
            'regionId' => $this->ids['region'],
            'districtId' => $this->ids['district'],
            'wardId' => $this->ids['ward'],
            'wardName' => 'Gwarama',
            'streetName' => 'Mtaa wa Soko',
            'dynamicFormData' => [],
            'paymentMethod' => null,
            'bankDetails' => null,
            'nextOfKin' => [['name' => 'Juma Hamisi', 'relationship' => 'spouse', 'phone' => '0754111222', 'address' => null]],
            'guarantors' => [],
            'nidaVerifiedAt' => null,
            'otpVerifiedAt' => null,
            'faceVerifiedAt' => null,
        ], $overrides);
    }

    /**
     * The eleven scanner checks, all passing unless overridden.
     *
     * @return array<string, mixed>
     */
    protected function faceReport(string $status = 'passed', array $overrides = []): array
    {
        return array_replace([
            'status' => $status,
            'qualityScore' => 91,
            'brightnessScore' => 80,
            'blurScore' => 85,
            'distanceScore' => 90,
            'centeringScore' => 88,
            'eyesOpenScore' => 97,
            'scannerVersion' => 'mediapipe-face-landmarker-0.10',
            'livenessPassed' => $status === 'passed' ? 'true' : 'false',
            'poseSequenceCompleted' => $status === 'passed' ? '1' : '0',
            'checks' => array_fill_keys(['oneFaceDetected', 'eyesOpen', 'centered', 'correctDistance', 'goodLighting', 'sharpImage', 'poseStraight', 'poseLeft', 'poseRight', 'poseUp', 'poseDown'], 'true'),
            'captureDevice' => 'FaceTime HD Camera',
            'captureResolution' => '1280x720',
            'captureDurationMs' => 8450,
        ], $overrides);
    }
}
