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
use RuntimeException;
use Tests\TestCase;

/**
 * 2026_09_14_180000: loan_categories.customer_category_id is backfilled from the main category's customer type (WATUMISHI products
 * → Mtumishi wa Umma, WAJASILIAMALI / VIKUNDI / GROUP → Mjasiriamali/Mfanyabiashara), becomes NOT NULL, main_category_id is
 * dropped, loans are untouched, a second run changes nothing, and orphans or cross-company links abort the migration.
 * DDL commits implicitly in MySQL: the schema is rebuilt before the test and the next RefreshDatabase test migrates afresh.
 */
class LoanCategoryCustomerTypeMigrationTest extends TestCase
{
    private Migration $migration;

    private int $companyId;

    /**
     * @var array<string, int>
     */
    private array $types = [];

    /**
     * @var array<string, int>
     */
    private array $mains = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;

        $this->migration = require database_path('migrations/2026_09_14_180000_link_loan_categories_directly_to_customer_types.php');
        $this->migration->down();
        $this->assertTrue(Schema::hasColumn('loan_categories', 'main_category_id'));
        $this->assertFalse(Schema::hasColumn('loan_categories', 'customer_category_id'));

        $this->companyId = Company::factory()->create()->id;
        foreach ([['WATUMISHI_WA_UMMA', 'Mtumishi wa Umma', 'ent'], ['WAJASIRIAMALI', 'Mjasiriamali/Mfanyabiashara', 'ser'], ['SEKTA_BINAFSI', 'Sekta Binafsi', 'SEKTA_BINAFSI']] as $order => [$code, $name, $mainCode]) {
            $this->types[$code] = $this->insertType($this->companyId, $code, $name, $order);
            $this->mains[$code] = DB::table('main_categories')->insertGetId(['company_id' => $this->companyId, 'customer_category_id' => $this->types[$code], 'code' => $mainCode, 'name' => $name, 'is_enabled' => true]);
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function insertType(int $companyId, string $code, string $name, int $order = 0): int
    {
        return DB::table('customer_categories')->insertGetId([
            'company_id' => $companyId, 'key' => strtolower($code), 'code' => $code, 'name' => $name, 'sort_order' => $order,
            'required_documents' => '[]', 'form_schema' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertProduct(string $name, int $mainCategoryId, ?int $companyId = null): int
    {
        return DB::table('loan_categories')->insertGetId(['company_id' => $companyId ?? $this->companyId, 'main_category_id' => $mainCategoryId, 'name' => $name, 'amount_from' => 20000, 'amount_to' => 2000000, 'interest_rate' => 30]);
    }

    public function test_backfill_maps_products_to_their_customer_type_keeps_loans_and_is_idempotent(): void
    {
        $products = [];
        foreach (['WATUMISHI 2', 'NEW WATUMISHI 1', 'VIP DESK'] as $name) {
            $products[$name] = [$this->insertProduct($name, $this->mains['WATUMISHI_WA_UMMA']), 'Mtumishi wa Umma'];
        }
        foreach (['WAJASILIAMALI', 'VIKUNDI 1', 'GROUP LOAN'] as $name) {
            $products[$name] = [$this->insertProduct($name, $this->mains['WAJASIRIAMALI']), 'Mjasiriamali/Mfanyabiashara'];
        }
        $branchId = Branch::factory()->create(['company_id' => $this->companyId])->id;
        $customer = Customer::factory()->create(['company_id' => $this->companyId, 'branch_id' => $branchId]);
        $loanIds = collect([$products['WATUMISHI 2'][0], $products['GROUP LOAN'][0], $products['GROUP LOAN'][0]])
            ->map(fn (int $categoryId): int => Loan::factory()->create(['customer_id' => $customer->id, 'loan_category_id' => $categoryId])->id);
        $loansBefore = DB::table('loans')->orderBy('id')->pluck('loan_category_id', 'id')->all();

        $this->migration->up();
        $this->migration->up();

        $mapped = DB::table('loan_categories')->join('customer_categories', 'customer_categories.id', '=', 'loan_categories.customer_category_id')->selectRaw('loan_categories.name AS product, customer_categories.name AS type_name')->pluck('type_name', 'product')->all();
        foreach ($products as $name => [, $typeName]) {
            $this->assertSame($typeName, $mapped[$name], $name);
        }
        $this->assertFalse(Schema::hasColumn('loan_categories', 'main_category_id'));
        $this->assertFalse(collect(Schema::getColumns('loan_categories'))->firstWhere('name', 'customer_category_id')['nullable']);
        $this->assertSame($loansBefore, DB::table('loans')->orderBy('id')->pluck('loan_category_id', 'id')->all());
        $this->assertCount(3, $loanIds);
        $this->assertTrue(Schema::hasTable('main_categories'), 'main_categories is kept as history.');

        $this->migration->down();
        $this->assertSame($this->mains['WAJASIRIAMALI'], (int) DB::table('loan_categories')->where('id', $products['GROUP LOAN'][0])->value('main_category_id'));
        $this->assertFalse(Schema::hasColumn('loan_categories', 'customer_category_id'));
        $this->migration->up();
        $this->assertSame($this->types['WAJASIRIAMALI'], (int) DB::table('loan_categories')->where('id', $products['GROUP LOAN'][0])->value('customer_category_id'));
        $this->assertSame($loansBefore, DB::table('loans')->orderBy('id')->pluck('loan_category_id', 'id')->all());
    }

    public function test_migration_fails_loudly_on_a_loan_category_without_a_customer_type(): void
    {
        $this->insertProduct('WATUMISHI 2', $this->mains['WATUMISHI_WA_UMMA']);
        Schema::table('loan_categories', function ($table) {
            $table->dropForeign(['main_category_id']);
        });
        Schema::table('loan_categories', function ($table) {
            $table->unsignedBigInteger('main_category_id')->nullable()->change();
        });
        $orphan = DB::table('loan_categories')->insertGetId(['company_id' => $this->companyId, 'main_category_id' => null, 'name' => 'ORPHAN', 'amount_from' => 1, 'amount_to' => 2, 'interest_rate' => 1]);

        try {
            $this->migration->up();
            $this->fail('The migration must refuse loan categories without a customer type.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("#{$orphan} ORPHAN", $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('loan_categories', 'main_category_id'), 'Nothing is dropped when the backfill is incomplete.');
    }

    public function test_migration_fails_loudly_on_a_customer_type_of_another_company(): void
    {
        $otherCompany = Company::factory()->create()->id;
        $foreignMain = DB::table('main_categories')->insertGetId(['company_id' => $otherCompany, 'customer_category_id' => $this->types['WAJASIRIAMALI'], 'code' => 'ser', 'name' => 'X', 'is_enabled' => true]);
        $mismatch = $this->insertProduct('CROSS COMPANY', $foreignMain, $otherCompany);

        try {
            $this->migration->up();
            $this->fail('The migration must refuse a customer type of another company.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("#{$mismatch} CROSS COMPANY", $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('loan_categories', 'main_category_id'));
    }
}
