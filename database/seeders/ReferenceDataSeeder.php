<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MasterData\ContractType;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\IdType;
use App\Models\MasterData\Sector;
use App\Models\MasterData\SectorCategory;
use Illuminate\Database\Seeder;

/**
 * DEMONSTRATION REFERENCE DATA — never run in production.
 *
 * Everything here used to be inserted by a migration, which meant a fresh
 * install arrived believing it lent to public servants at TAMISEMI and
 * accepted six particular identity documents. None of that is this
 * application's to decide. Which employing bodies exist, which documents are
 * accepted, what a contract may be and which files a category must produce are
 * institutional policy, configured at Administration → Master Data.
 *
 * So the migrations now create empty tables, and these rows live here — used
 * by the test suite and by a development database, and by nothing else.
 * `ProductionSeeder` does not include it.
 *
 * The values are EXAMPLES. TAMISEMI, Teachers and Nurses are the ones written
 * into the specification as illustrations; they are not a claim about how any
 * institution is organised.
 *
 * `updateOrCreate` throughout, so re-running changes nothing that is already
 * there and never duplicates.
 */
final class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->identityDocuments();
        $this->contractTerms();
        $this->documentTypes();
        $this->sectors();
    }

    /**
     * The six the registration wizard offered as separate columns before they
     * became one type-plus-number pair.
     */
    private function identityDocuments(): void
    {
        $rows = [
            ['NIDA', 'National ID (NIDA)', 'Tanzanian national identity card.', 10],
            ['VOTER_ID', 'Voter ID', 'National Electoral Commission voter card.', 20],
            ['DRIVER_LICENCE', "Driver's Licence", 'Tanzanian driving licence.', 30],
            ['PASSPORT', 'Passport', 'Tanzanian or foreign passport.', 40],
            ['WORK_ID', 'Work ID', 'Employer-issued identity card.', 50],
            ['TIN', 'TIN', 'Taxpayer identification number.', 60],
        ];

        foreach ($rows as [$code, $name, $description, $order]) {
            IdType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => $order, 'is_active' => true],
            );
        }
    }

    /**
     * Permanent and Temporary.
     *
     * The code `TEMPORARY` is the one RegisterCustomerRequest keys its expiry
     * rule on — an institution may rename or translate the NAME freely, but a
     * type meant to require an expiry date must carry that code.
     */
    private function contractTerms(): void
    {
        $rows = [
            ['PERMANENT', 'Permanent', 'No end date. An expiry date is not collected.', 10],
            ['TEMPORARY', 'Temporary', 'Fixed term. An expiry date is required.', 20],
        ];

        foreach ($rows as [$code, $name, $description, $order]) {
            ContractType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => $order, 'is_active' => true],
            );
        }
    }

    /**
     * The document types the demonstration categories name.
     *
     * A category's `required_documents` holds these codes, so the two lists
     * have to agree — a category requiring a code no type defines produces a
     * slot the officer cannot satisfy.
     */
    private function documentTypes(): void
    {
        $rows = [
            /* The ten the 2026_08_15 migration used to insert. */
            ['driving_license', 'Driving Licence', 'Required for Boda Boda customers.', 10],
            ['motorcycle_registration', 'Motorcycle Registration', 'Ownership card for the financed motorcycle.', 20],
            ['business_license', 'Business Licence', 'Trading licence for an SME customer.', 30],
            ['financial_statement', 'Financial Statement', 'Accounts for a medium SME.', 40],
            ['salary_slip', 'Salary Slip', 'Most recent payslip.', 50],
            ['employer_letter', 'Employer Letter', 'Letter of introduction from the employer.', 60],
            ['national_id', 'National ID', 'A copy of the customer’s national identity card.', 70],
            ['passport_photo', 'Passport Photo', 'A printed photograph, where one is held on paper.', 80],
            ['bank_statement', 'Bank Statement', 'Recent statement supporting declared income.', 90],
            ['contract', 'Contract', 'Signed loan or guarantee contract.', 100],

            ['confirmation_letter', 'Confirmation Letter', 'Employer letter confirming the customer holds the post.', 110],
            ['bank_card', 'Bank Card', 'Copy of the salary account card.', 120],
            ['employee_id', 'Employee ID', 'Employer-issued identity card.', 130],
            ['employment_contract', 'Employment Contract', 'Signed contract of employment.', 140],
            ['student_id', 'Student ID', 'Institution-issued student identity card.', 150],
            ['pension_statement', 'Pension Statement', 'Statement evidencing pension income.', 160],
        ];

        foreach ($rows as [$code, $name, $description, $order]) {
            DocumentType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => $order, 'is_active' => true],
            );
        }
    }

    /**
     * One employing body with two cadres — enough to exercise the
     * sector → cadre cascade, and no more.
     */
    private function sectors(): void
    {
        $sector = Sector::query()->updateOrCreate(
            ['code' => 'TAMISEMI'],
            [
                'name' => 'TAMISEMI',
                'description' => "President's Office — Regional Administration and Local Government.",
                'sort_order' => 10,
                'is_active' => true,
            ],
        );

        foreach ([['TEACHERS', 'Teachers', 10], ['NURSES', 'Nurses', 20]] as [$code, $name, $order]) {
            SectorCategory::query()->updateOrCreate(
                ['sector_id' => $sector->getKey(), 'code' => $code],
                ['name' => $name, 'sort_order' => $order, 'is_active' => true],
            );
        }
    }
}
