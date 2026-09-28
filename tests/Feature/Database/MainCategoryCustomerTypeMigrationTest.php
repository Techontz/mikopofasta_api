<?php

namespace Tests\Feature\Database;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_14_140000: legacy main categories (ent / ser) are mapped to customer types by code with their ids kept, the other
 * customer types get a main category, names come from the customer type, and running it again changes nothing. Since
 * 2026_09_14_180000 the main category only carries history: loan categories reference the customer type directly.
 * The migrations run DDL (implicit commit in MySQL), so no wrapping transaction: the schema is rebuilt before the test and
 * the next RefreshDatabase test migrates afresh.
 */
class MainCategoryCustomerTypeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function migration(string $name): Migration
    {
        return require database_path("migrations/{$name}.php");
    }

    public function test_legacy_main_categories_map_by_code_keep_ids_and_the_migration_is_idempotent(): void
    {
        $link = $this->migration('2026_09_14_140000_link_main_categories_to_customer_types');
        $requireMain = $this->migration('2026_09_14_140001_require_main_category_on_loan_categories');
        $backfill = $this->migration('2026_09_14_140003_backfill_customer_types_from_loan_categories');
        $direct = $this->migration('2026_09_14_180000_link_loan_categories_directly_to_customer_types');

        $direct->down();
        $requireMain->down();
        $link->down();
        $this->assertFalse(Schema::hasColumn('main_categories', 'customer_category_id'));

        $companyId = Company::factory()->create()->id;
        $branchId = Branch::factory()->create(['company_id' => $companyId])->id;
        $types = [];
        foreach ([['WATUMISHI_WA_UMMA', 'Mtumishi wa Umma'], ['SEKTA_BINAFSI', 'Sekta Binafsi'], ['WAJASIRIAMALI', 'Mjasiriamali/Mfanyabiashara'], ['MWANAFUNZI_CHUO', 'Mwanafunzi wa Chuo'], ['MSTAAFU_UMMA', 'Mstaafu (Umma)']] as $order => [$code, $name]) {
            $types[$code] = DB::table('customer_categories')->insertGetId([
                'company_id' => $companyId, 'key' => strtolower($code), 'code' => $code, 'name' => $name, 'sort_order' => $order + 1,
                'required_documents' => '[]', 'form_schema' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $watumishi = DB::table('main_categories')->insertGetId(['company_id' => $companyId, 'code' => 'ent', 'name' => 'WATUMISHI', 'is_enabled' => true]);
        $wajasiliamali = DB::table('main_categories')->insertGetId(['company_id' => $companyId, 'code' => 'ser', 'name' => 'WAJASILIAMALI', 'is_enabled' => true]);
        $product = DB::table('loan_categories')->insertGetId(['company_id' => $companyId, 'main_category_id' => $wajasiliamali, 'name' => 'WAJASILIAMALI', 'amount_from' => 20000, 'amount_to' => 2000000, 'interest_rate' => 30]);
        $borrower = Customer::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId])->id;
        $noLoans = Customer::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId])->id;
        Loan::factory()->create(['customer_id' => $borrower, 'loan_category_id' => $product]);

        $link->up();
        $link->up();
        $requireMain->up();
        $backfill->up();
        $direct->up();

        $mains = DB::table('main_categories')->where('company_id', $companyId)->get()->keyBy('customer_category_id');
        $this->assertCount(5, $mains);
        $this->assertSame($watumishi, (int) $mains[$types['WATUMISHI_WA_UMMA']]->id);
        $this->assertSame($wajasiliamali, (int) $mains[$types['WAJASIRIAMALI']]->id);
        $this->assertSame('Mtumishi wa Umma', $mains[$types['WATUMISHI_WA_UMMA']]->name);
        $this->assertSame('Mjasiriamali/Mfanyabiashara', $mains[$types['WAJASIRIAMALI']]->name);
        $this->assertSame($types['WAJASIRIAMALI'], (int) DB::table('loan_categories')->where('id', $product)->value('customer_category_id'));
        $this->assertFalse(Schema::hasColumn('loan_categories', 'main_category_id'));
        $this->assertSame($types['WAJASIRIAMALI'], (int) DB::table('customers')->where('id', $borrower)->value('customer_category_id'));
        $this->assertNull(DB::table('customers')->where('id', $noLoans)->value('customer_category_id'));
        $this->assertFalse(Schema::hasTable('customer_category_loan_category'));
        $this->assertFalse(Schema::hasColumn('customer_categories', 'max_loan_amount'));
    }
}
