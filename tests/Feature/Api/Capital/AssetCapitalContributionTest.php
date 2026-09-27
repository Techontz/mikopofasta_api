<?php

namespace Tests\Feature\Api\Capital;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetEvent;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Assets\AssetRegistry;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class AssetCapitalContributionTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private ShareHolder $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00:00'));
        $this->admin = $this->signInAdmin();
        $this->holder = ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => 'ALPHA', 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => 'alpha@example.com', 'date_of_birth' => '1990-01-01']);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function assetTypes(): array
    {
        return [
            'vehicle' => ['vehicle', ['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020, 'chassis_number' => 'CH-1', 'registration_number' => 'T 123 ABC'], ['make', 'model', 'year', 'chassis_number', 'registration_number']],
            'equipment' => ['equipment', ['manufacturer' => 'CAT', 'model' => 'G1', 'serial_number' => 'SN-1'], ['manufacturer', 'model', 'serial_number']],
            'electronics' => ['electronics', ['device_type' => 'Laptop', 'brand' => 'HP', 'model' => '840', 'serial_number' => 'SN-2'], ['device_type', 'brand', 'model', 'serial_number']],
            'property' => ['property', ['property_type' => 'Office', 'address' => 'Mwanza', 'plot_title_number' => 'P-1', 'size' => 120, 'size_unit' => 'sqm'], ['property_type', 'address', 'plot_title_number', 'size', 'size_unit']],
            'land' => ['land', ['plot_number' => 'PL-9', 'size' => 2.5, 'size_unit' => 'acres'], ['plot_number', 'size', 'size_unit']],
            'furniture' => ['furniture', [], []],
            'other' => ['other', ['specification' => 'Solar kit 5kW'], ['specification']],
        ];
    }

    /**
     * @param  array<string, mixed>  $specifications
     * @param  list<string>  $required
     */
    #[DataProvider('assetTypes')]
    public function test_each_asset_type_validates_its_specific_required_fields_and_posts_to_its_account(string $type, array $specifications, array $required): void
    {
        $condition = config("assets.types.{$type}.condition") ? 'used' : null;
        $response = $this->contribute(['asset_type' => $type, 'condition' => $condition, 'specifications' => []]);
        if ($required === []) {
            $response->assertCreated();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors(array_map(fn (string $key): string => "specifications.{$key}", $required));
            $this->assertSame(0, Capital::count());
        }

        $this->contribute(['asset_type' => $type, 'condition' => $condition, 'specifications' => $specifications + ['unknown_field' => 'dropped'], 'idempotency_key' => "type-{$type}"])->assertCreated();

        $asset = Asset::latest('id')->firstOrFail();
        $expectedAccount = Account::from(config("assets.types.{$type}.account"));
        $this->assertSame($expectedAccount->value, $asset->ledger_account);
        $this->assertArrayNotHasKey('unknown_field', $asset->specifications);
        $this->assertSame($asset->capital->receiving_account, $expectedAccount->value);
        $this->assertSame('ASSET', $asset->capital->pay_method);
    }

    public function test_land_requires_location_has_no_condition_and_fixed_quantity(): void
    {
        $land = ['asset_type' => 'land', 'specifications' => ['plot_number' => 'PL-1', 'size' => 1, 'size_unit' => 'hectares']];

        $this->contribute($land + ['location' => null, 'condition' => null, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->contribute($land + ['location' => 'Kigamboni', 'condition' => 'new'])->assertUnprocessable()->assertJsonValidationErrors('condition');
        $this->contribute($land + ['location' => 'Kigamboni', 'condition' => null, 'quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->contribute($land + ['location' => 'Kigamboni', 'condition' => null, 'quantity' => null, 'unit_value' => 40000000, 'total_value' => null])->assertCreated();

        $asset = Asset::sole();
        $this->assertSame([1, null, '40000000.00'], [$asset->quantity, $asset->condition, (string) $asset->contribution_value]);
    }

    public function test_total_is_recomputed_on_the_server_with_decimal_safe_math_and_a_mismatching_client_total_is_rejected(): void
    {
        $this->assertSame('0.30', AssetRegistry::total(3, '0.10'));
        $this->assertSame('2500000.00', AssetRegistry::total(5, 500000));

        $this->contribute(['quantity' => 5, 'unit_value' => 500000, 'total_value' => 2400000])
            ->assertUnprocessable()->assertJsonValidationErrors('total_value');
        $this->contribute(['quantity' => 0, 'unit_value' => 500000])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->contribute(['unit_value' => 0])->assertUnprocessable()->assertJsonValidationErrors('unit_value');
        $this->contribute(['unit_value' => '10.555'])->assertUnprocessable()->assertJsonValidationErrors('unit_value');
        $this->assertSame(0, Capital::count());

        $this->contribute(['quantity' => 3, 'unit_value' => '333333.33', 'total_value' => '999999.99'])->assertCreated()
            ->assertJsonPath('data.contribution_value', 999999.99);
        $this->contribute(['quantity' => 5, 'unit_value' => 500000, 'total_value' => null])->assertCreated()
            ->assertJsonPath('data.contribution_value', 2500000)
            ->assertJsonPath('data.current_value', 2500000);

        $this->assertSame('2500000.00', (string) Capital::latest('id')->first()->amount);
    }

    public function test_asset_ids_are_sequential_per_company_after_insert_and_qr_tokens_are_unique_and_opaque(): void
    {
        $this->contribute(['idempotency_key' => 'a1'])->assertCreated()->assertJsonPath('data.asset_code', 'AST-000001');
        $this->contribute(['idempotency_key' => 'a2'])->assertCreated()->assertJsonPath('data.asset_code', 'AST-000002');
        $this->contribute(['idempotency_key' => 'a2'])->assertOk()->assertJsonPath('data.asset_code', 'AST-000002');

        $this->assertSame(2, Asset::count());
        $this->assertSame(2, Asset::distinct()->count('qr_token'));
        foreach (Asset::all() as $asset) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $asset->qr_token);
            $this->assertStringNotContainsString($asset->asset_code, $asset->qr_token);
        }

        $otherAdmin = $this->signInAdmin();
        $otherHolder = ShareHolder::create(['company_id' => $otherAdmin->company_id, 'first_name' => 'OTHER', 'last_name' => 'CO', 'mobile' => '1', 'email' => 'o@example.com', 'date_of_birth' => '1990-01-01']);
        $this->contribute(['share_id' => $otherHolder->id, 'branch_id' => $otherAdmin->branch_id])->assertCreated()->assertJsonPath('data.asset_code', 'AST-000001');
    }

    public function test_qr_code_and_scan_are_authorised_and_do_not_leak_other_companies_assets(): void
    {
        $asset = $this->createAsset();

        $svg = $this->get("/api/v1/capital/assets/{$asset->id}/qr")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());
        $this->assertStringContainsString('attachment', $this->get("/api/v1/capital/assets/{$asset->id}/qr?download=1")->headers->get('Content-Disposition'));

        $this->getJson("/api/v1/capital/assets/scan/{$asset->qr_token}")->assertOk()
            ->assertJsonPath('data.asset_code', 'AST-000001')
            ->assertJsonPath('data.share_holder', 'ALPHA HOLDER')
            ->assertJsonPath('data.identifiers.Registration Number', 'T 123 ABC');
        $this->getJson('/api/v1/capital/assets/scan/00000000-0000-0000-0000-000000000000')->assertNotFound();

        $finance = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'admin')->value('id')]);
        $this->actingAs($finance)->getJson("/api/v1/capital/assets/scan/{$asset->qr_token}")->assertForbidden()->assertJsonMissing(['asset_code' => 'AST-000001']);
        $this->actingAs($finance)->get("/api/v1/capital/assets/{$asset->id}/qr")->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/capital/assets')->assertForbidden();
        $this->actingAs($finance)->postJson('/api/v1/capital/assets', $this->payload())->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/capital/assets/{$asset->id}/transfer", [])->assertForbidden();

        $this->signInAdmin();
        $this->getJson("/api/v1/capital/assets/scan/{$asset->qr_token}")->assertNotFound()->assertJsonMissing(['asset_code' => 'AST-000001']);
        $this->getJson("/api/v1/capital/assets/{$asset->id}")->assertNotFound();
        $this->get("/api/v1/capital/assets/{$asset->id}/qr")->assertNotFound();
    }

    public function test_journal_debits_the_asset_account_and_credits_capital_without_moving_cash_or_bank(): void
    {
        $asset = $this->createAsset();
        $ledger = app(Ledger::class);
        $companyId = $this->admin->company_id;

        $entry = JournalEntry::with('lines.account')->findOrFail($asset->journal_entry_id);
        $this->assertSame($asset->capital->journal_entry_id, $entry->id);
        $this->assertSame([['motor_vehicles', 30000000.0, 0.0], ['capital', 0.0, 30000000.0]], $entry->lines->map(fn (JournalLine $line): array => [$line->account->key->value, (float) $line->debit, (float) $line->credit])->all());
        $this->assertSame(30000000.0, $ledger->balance($companyId, Account::MotorVehicles));
        $this->assertSame(30000000.0, $ledger->balance($companyId, Account::Capital));
        $this->assertSame(0.0, $ledger->balance($companyId, Account::Company));
        $this->assertSame(0.0, $ledger->balance($companyId, Account::Bank, allBranches: true));

        $sheet = $this->getJson('/api/v1/reports/financial/balance-sheet?branch_id=all')->assertOk()->json('data');
        $this->assertTrue($sheet['balanced']);
        $fixed = collect($sheet['assets'])->firstWhere('group', 'Fixed assets');
        $this->assertSame(30000000, (int) $fixed['total']);
        $this->assertSame('MOTOR VEHICLES', $fixed['lines'][0]['label']);
        $this->assertSame(0, (int) collect($sheet['assets'])->firstWhere('group', 'Cash & bank')['total']);

        $this->assertSame((float) JournalLine::sum('debit'), (float) JournalLine::sum('credit'), 'trial balance: total debits equal total credits');
        $assets = collect($this->getJson('/api/v1/accounting/accounts')->assertOk()->json('data'))->firstWhere('type', 'asset');
        $this->assertNotNull($assets);
        $this->assertStringContainsString('MOTOR VEHICLES', json_encode($assets));

        $cashFlow = $this->getJson('/api/v1/reports/financial/cash-flow?branch_id=all&from=2026-09-14&to=2026-09-14')->assertOk()->json('data');
        $this->assertSame([0.0, 0.0], [(float) $cashFlow['total_inflow'], (float) $cashFlow['cash_held']], 'an asset contribution is not a cash inflow');
    }

    public function test_contribution_totals_include_assets_but_ownership_comes_only_from_shares(): void
    {
        $cash = $this->postJson('/api/v1/capital/capitals', ['share_id' => $this->holder->id, 'amount' => 20000000, 'pay_method' => 'CASH'])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/capitals/{$cash}/approve");
        $beta = ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => 'BETA', 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => 'beta@example.com', 'date_of_birth' => '1990-01-01']);
        $this->postJson('/api/v1/shares/structure', [
            'capital_basis' => '10,000,000', 'total_shares' => 1000, 'authorised_shares' => 5000, 'established_on' => '2026-09-14',
            'allocations' => [
                ['share_holder_id' => $this->holder->id, 'shares' => 500, 'treatment' => 'no_cash'],
                ['share_holder_id' => $beta->id, 'shares' => 500, 'treatment' => 'no_cash'],
            ],
        ])->assertCreated();

        $asset = $this->createAsset();

        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holders.0.total_contributed', 50000000)
            ->assertJsonPath('data.share_holders.0.cash_contributed', 20000000)
            ->assertJsonPath('data.share_holders.0.bank_contributed', 0)
            ->assertJsonPath('data.share_holders.0.asset_contributed', 30000000)
            ->assertJsonPath('data.share_holders.0.ownership_percent', 50)
            ->assertJsonPath('data.contribution_breakdown.asset', 30000000)
            ->assertJsonPath('data.share_holders.0.capitals.1.asset_code', 'AST-000001');
        $this->getJson("/api/v1/capital/share-holders/{$this->holder->id}")->assertOk()->assertJsonPath('data.asset_contributed', 30000000)->assertJsonPath('data.cash_contributed', 20000000);
        $this->getJson("/api/v1/shares/share-holders/{$this->holder->id}")->assertOk()->assertJsonPath('data.contribution_breakdown.total', 50000000)->assertJsonPath('data.contributions.1.asset_code', 'AST-000001');
        $register = collect($this->getJson('/api/v1/shares/register')->assertOk()->json('data.rows'))->keyBy('share_holder_id');
        $this->assertSame([50, 30000000, 20000000], [$register[$this->holder->id]['ownership_percent'], (int) $register[$this->holder->id]['asset_contributed'], (int) $register[$this->holder->id]['cash_contributed']]);

        $this->postJson("/api/v1/capital/assets/{$asset->id}/revaluations", ['new_value' => 90000000, 'valuation_date' => '2026-09-14', 'valuation_method' => 'market_valuation', 'reason' => 'Market'])->assertOk();
        $this->getJson('/api/v1/shares/register')->assertJsonPath('data.rows.0.ownership_percent', 50);

        $journals = JournalEntry::count();
        $options = $this->getJson("/api/v1/shares/options/contributions?share_holder_id={$this->holder->id}")->assertOk()->json('data');
        $this->assertStringContainsString('ASSET AST-000001', collect($options)->firstWhere('value', (string) $asset->capital_id)['label']);

        $this->postJson('/api/v1/shares/issuances', ['share_holder_id' => $this->holder->id, 'type' => 'issuance', 'shares' => 3000, 'payment_treatment' => 'linked_contribution', 'capital_id' => $asset->capital_id, 'price_per_share' => 10000])->assertCreated();

        $this->assertSame($journals, JournalEntry::count(), 'shares issued against the asset contribution post no second journal');
        $this->assertSame($asset->journal_entry_id, ShareTransaction::latest('id')->first()->journal_entry_id);
        $rows = collect($this->getJson('/api/v1/shares/register')->json('data.rows'))->keyBy('share_holder_id');
        $this->assertSame(87.5, (float) $rows[$this->holder->id]['ownership_percent']);
        $this->getJson('/api/v1/capital/capitals')->assertJsonPath('data.share_holders.0.total_contributed', 50000000);
        $this->getJson("/api/v1/capital/assets/{$asset->id}")->assertJsonPath('data.share_transaction_reference', ShareTransaction::latest('id')->value('reference'));

        $this->postJson("/api/v1/capital/assets/{$asset->id}/reverse", ['reason' => 'Wrong'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_valuation_snapshot_is_immutable_and_revaluation_changes_current_value_with_history_only(): void
    {
        $asset = $this->createAsset();
        $journals = JournalEntry::count();

        $this->patchJson("/api/v1/capital/assets/{$asset->id}", ['contribution_value' => 1, 'unit_value' => 1, 'quantity' => 2, 'share_id' => 1, 'branch_id' => 1, 'status' => 'disposed', 'current_value' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['contribution_value', 'unit_value', 'quantity', 'share_id', 'branch_id', 'status', 'current_value']);

        $this->patchJson("/api/v1/capital/assets/{$asset->id}", ['location' => 'Yard B', 'notes' => 'Spare key in safe', 'condition' => 'used', 'specifications' => ['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020, 'chassis_number' => 'CH-1', 'registration_number' => 'T 999 XYZ']])
            ->assertOk()->assertJsonPath('data.location', 'Yard B')->assertJsonPath('data.contribution_value', 30000000);
        $update = AssetEvent::where('event', 'updated')->sole();
        $this->assertSame('Yard A', $update->previous_value['location']);
        $this->assertSame('T 999 XYZ', $update->new_value['specifications']['registration_number']);

        $this->postJson("/api/v1/capital/assets/{$asset->id}/revaluations", ['new_value' => 25000000, 'valuation_date' => '2026-09-14', 'valuation_method' => 'professional_valuation', 'reason' => 'Annual valuation'])
            ->assertOk()->assertJsonPath('data.current_value', 25000000)->assertJsonPath('data.contribution_value', 30000000)->assertJsonPath('data.valuation.contribution_value', 30000000);
        $this->postJson("/api/v1/capital/assets/{$asset->id}/revaluations", ['new_value' => 1, 'valuation_date' => '2026-09-14', 'valuation_method' => 'other', 'reason' => 'x', 'contribution_value' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('contribution_value');
        $this->postJson("/api/v1/capital/assets/{$asset->id}/revaluations", ['new_value' => 1, 'valuation_date' => '2026-09-14', 'valuation_method' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $revaluation = AssetEvent::where('event', 'revalued')->sole();
        $this->assertSame(['30000000.00', '25000000.00', 'Annual valuation'], [(string) $revaluation->amount_before, (string) $revaluation->amount_after, $revaluation->reason]);
        $asset->refresh();
        $this->assertSame(['30000000.00', 'agreed_value', '2026-09-10'], [(string) $asset->contribution_value, $asset->valuation_method, $asset->valuation_date->toDateString()]);
        $this->assertSame('30000000.00', (string) $asset->capital->amount);
        $this->assertSame($journals, JournalEntry::count(), 'memo revaluation posts nothing');

        $this->expectException(LogicException::class);
        $revaluation->update(['reason' => 'tampered']);
    }

    public function test_transfer_and_status_change_record_history(): void
    {
        $asset = $this->createAsset();
        $second = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'ARUSHA']);
        $foreign = Branch::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->postJson("/api/v1/capital/assets/{$asset->id}/transfer", ['to_branch_id' => $second->id, 'transfer_date' => '2026-09-14'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/capital/assets/{$asset->id}/transfer", ['to_branch_id' => $foreign->id, 'transfer_date' => '2026-09-14', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('to_branch_id');
        $this->postJson("/api/v1/capital/assets/{$asset->id}/transfer", ['to_branch_id' => $second->id, 'transfer_date' => '2026-09-14', 'reason' => 'Opening Arusha branch'])
            ->assertOk()->assertJsonPath('data.branch', 'ARUSHA');

        $transfer = AssetEvent::where('event', 'transferred')->sole();
        $this->assertSame([$this->admin->branch_id, $second->id, 'Opening Arusha branch'], [$transfer->previous_value['branch_id'], $transfer->new_value['branch_id'], $transfer->reason]);

        $this->postJson("/api/v1/capital/assets/{$asset->id}/status", ['status' => 'under_maintenance', 'reason' => 'Service'])->assertOk()->assertJsonPath('data.status', 'under_maintenance');
        $this->postJson("/api/v1/capital/assets/{$asset->id}/status", ['status' => 'written_off', 'reason' => 'Accident'])->assertOk()->assertJsonPath('data.status_label', 'Written Off');
        $this->postJson("/api/v1/capital/assets/{$asset->id}/status", ['status' => 'active', 'reason' => 'Undo'])->assertUnprocessable();
        $this->postJson("/api/v1/capital/assets/{$asset->id}/transfer", ['to_branch_id' => $this->admin->branch_id, 'transfer_date' => '2026-09-14', 'reason' => 'x'])->assertUnprocessable();

        $this->assertSame(['created', 'contributed_as_capital', 'allocated_to_branch', 'transferred', 'maintenance', 'written_off'], AssetEvent::orderBy('id')->pluck('event')->all());
        $this->getJson("/api/v1/capital/assets/{$asset->id}")->assertOk()->assertJsonCount(6, 'data.events')->assertJsonPath('data.events.0.event', 'written_off');
        $this->getJson('/api/v1/capital/assets?status=written_off')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/capital/assets?branch_id={$second->id}&asset_type=vehicle&search=T 123")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/capital/assets?asset_type=land')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_documents_are_stored_privately_downloaded_with_authorisation_and_deleted_with_history(): void
    {
        Storage::fake(Asset::DISK);
        $asset = $this->createAsset();

        $this->post("/api/v1/capital/assets/{$asset->id}/documents", ['document_type' => 'photo', 'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post("/api/v1/capital/assets/{$asset->id}/documents", ['document_type' => 'title_deed', 'file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('document_type');
        $this->post("/api/v1/capital/assets/{$asset->id}/documents", ['document_type' => 'photo', 'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->post("/api/v1/capital/assets/{$asset->id}/documents", ['document_type' => 'photo', 'file' => UploadedFile::fake()->image('front.jpg', 400, 300)], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/capital/assets/{$asset->id}/documents", ['document_type' => 'registration_card', 'file' => UploadedFile::fake()->create('card.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();

        $pdf = AssetDocument::where('document_type', 'registration_card')->sole();
        Storage::disk(Asset::DISK)->assertExists($pdf->path);
        $this->assertStringStartsWith("assets/{$this->admin->company_id}/{$asset->id}/", $pdf->path);
        $this->assertSame(['card.pdf', 'application/pdf'], [$pdf->original_name, $pdf->mime]);

        $this->get("/api/v1/capital/assets/{$asset->id}/documents/{$pdf->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $this->get("/api/v1/capital/assets/{$asset->id}/documents/{$pdf->id}?download=1")->headers->get('Content-Disposition'));
        $this->getJson("/api/v1/capital/assets/{$asset->id}")->assertJsonCount(2, 'data.documents');

        $finance = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'admin')->value('id')]);
        $this->actingAs($finance)->get("/api/v1/capital/assets/{$asset->id}/documents/{$pdf->id}")->assertForbidden();
        $this->actingAs($finance)->deleteJson("/api/v1/capital/assets/{$asset->id}/documents/{$pdf->id}")->assertForbidden();

        $this->actingAs($this->admin)->deleteJson("/api/v1/capital/assets/{$asset->id}/documents/{$pdf->id}", ['reason' => 'Expired card'])->assertOk();
        Storage::disk(Asset::DISK)->assertMissing($pdf->path);
        $this->assertModelMissing($pdf);
        $deleted = AssetEvent::where('event', 'document_deleted')->sole();
        $this->assertSame(['card.pdf', 'Expired card'], [$deleted->previous_value['name'], $deleted->reason]);
        $this->assertSame(2, AssetEvent::where('event', 'document_uploaded')->count());
    }

    public function test_a_failure_inside_the_contribution_rolls_back_capital_asset_journal_and_history(): void
    {
        Event::listen('eloquent.creating: '.AssetEvent::class, fn (AssetEvent $event) => $event->event === 'created' ? throw new RuntimeException('forced failure') : null);

        $this->withoutExceptionHandling();
        try {
            $this->contribute();
            $this->fail('the forced failure should propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced failure', $exception->getMessage());
        }

        $this->assertSame([0, 0, 0, 0, 0], [Capital::count(), Asset::count(), AssetEvent::count(), JournalEntry::count(), JournalLine::count()]);
    }

    public function test_a_failure_inside_the_approval_rolls_back_journal_status_and_history(): void
    {
        $asset = Asset::findOrFail($this->contribute()->assertCreated()->json('data.id'));
        Event::listen('eloquent.creating: '.AssetEvent::class, fn (AssetEvent $event) => $event->event === 'allocated_to_branch' ? throw new RuntimeException('forced failure') : null);

        $approver = $this->secondApprover($this->admin);
        try {
            app(AssetRegistry::class)->approve($asset, $approver);
            $this->fail('the forced failure should propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced failure', $exception->getMessage());
        }

        $this->assertSame(['pending', 'pending', null], [$asset->fresh()->status, $asset->capital->fresh()->status, $asset->fresh()->journal_entry_id]);
        $this->assertSame([0, 0, 1], [JournalEntry::count(), JournalLine::count(), AssetEvent::count()]);
    }

    public function test_asset_contribution_is_pending_until_another_user_approves_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00:00'));
        $contributor = $this->contributor();
        $this->actingAs($contributor);
        $response = $this->contribute(['contribution_date' => '2026-09-12'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.status_label', 'Pending Approval')
            ->assertJsonPath('data.can_approve', false)->assertJsonPath('data.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE)
            ->assertJsonPath('data.can_reject', true);
        $asset = Asset::findOrFail($response->json('data.id'));
        $ledger = app(Ledger::class);

        $this->assertSame([0, 'pending', null], [JournalEntry::count(), $asset->capital->status, $asset->journal_entry_id]);
        $this->assertSame(0.0, $ledger->balance($this->admin->company_id, Account::Capital));
        $this->getJson('/api/v1/capital/capitals')->assertJsonPath('data.share_holder_capital', 0)
            ->assertJsonPath('data.share_holders.0.capitals.0.status', 'pending')
            ->assertJsonPath('data.share_holders.0.capitals.0.can_approve', false)
            ->assertJsonPath('data.share_holders.0.capitals.0.approve_blocked_reason', null);
        $this->getJson("/api/v1/shares/options/contributions?share_holder_id={$this->holder->id}")->assertJsonCount(0, 'data');

        // The initiator is blocked; lifecycle changes wait for approval; the capital page cannot approve it.
        $this->postJson("/api/v1/capital/assets/{$asset->id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->postJson("/api/v1/capital/assets/{$asset->id}/revaluations", ['new_value' => 1, 'valuation_date' => '2026-09-15', 'valuation_method' => 'other', 'reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/capital/assets/{$asset->id}/reverse", ['reason' => 'Wrong'])->assertUnprocessable();
        $this->asApprover($contributor, fn () => $this->postJson("/api/v1/capital/capitals/{$asset->capital_id}/approve")->assertUnprocessable());
        $this->assertSame(0, JournalEntry::count());

        $this->approveAsSecondUser($contributor, "/api/v1/capital/assets/{$asset->id}/approve");
        $asset->refresh();
        $entry = JournalEntry::with('lines.account')->findOrFail($asset->journal_entry_id);
        $this->assertSame(['active', 'posted', '2026-09-15'], [$asset->status, $asset->capital->status, $entry->entry_date->toDateString()]);
        $this->assertSame([['motor_vehicles', 30000000.0, 0.0], ['capital', 0.0, 30000000.0]], $entry->lines->map(fn (JournalLine $line): array => [$line->account->key->value, (float) $line->debit, (float) $line->credit])->all());
        $this->assertNotSame($contributor->id, $entry->employee_id, 'posted by the approver');
        $this->assertSame('2026-09-12', $asset->contributed_on->toDateString(), 'the contribution date snapshot is kept');

        $this->asApprover($contributor, fn () => $this->postJson("/api/v1/capital/assets/{$asset->id}/approve")->assertUnprocessable());
        $this->assertSame(1, JournalEntry::count(), 'a second approval posts nothing');
    }

    public function test_rejected_asset_contribution_posts_nothing_and_is_terminal(): void
    {
        $asset = Asset::findOrFail($this->contribute()->assertCreated()->json('data.id'));

        $this->asApprover($this->admin, function (): void {
            $this->postJson('/api/v1/capital/assets/'.Asset::sole()->id.'/reject', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
            $this->postJson('/api/v1/capital/assets/'.Asset::sole()->id.'/reject', ['reason' => 'Valuation not supported'])->assertOk()
                ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Valuation not supported');
        });

        $this->assertSame([0, 'rejected', 'rejected'], [JournalEntry::count(), $asset->fresh()->status, $asset->capital->fresh()->status]);
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/capital/assets/{$asset->id}/approve")->assertUnprocessable());
        $this->postJson("/api/v1/capital/assets/{$asset->id}/transfer", ['to_branch_id' => $this->admin->branch_id, 'transfer_date' => '2026-09-14', 'reason' => 'x'])->assertUnprocessable();
        $this->assertSame(['created', 'contribution_rejected'], AssetEvent::orderBy('id')->pluck('event')->all());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_initiator_self_approves_an_asset_only_with_the_permission_and_the_company_policy(): void
    {
        $contributor = $this->contributor();
        $this->actingAs($contributor);
        $asset = Asset::findOrFail($this->contribute()->assertCreated()->json('data.id'));

        $this->grantSelfApproval($contributor, withCompanyPolicy: false);
        $this->postJson("/api/v1/capital/assets/{$asset->id}/approve")->assertForbidden();

        $this->allowSelfApprovalPolicy($this->admin->company_id, [ApprovalPolicy::ASSET_CONTRIBUTIONS]);
        $this->postJson("/api/v1/capital/assets/{$asset->id}/approve")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_reversal_reverses_the_journal_and_excludes_the_contribution_from_totals(): void
    {
        $asset = $this->createAsset();

        $this->postJson("/api/v1/capital/assets/{$asset->id}/reverse", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/capital/assets/{$asset->id}/reverse", ['reason' => 'Recorded against the wrong shareholder'])->assertOk()
            ->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.contribution_reversed', true);
        $this->postJson("/api/v1/capital/assets/{$asset->id}/reverse", ['reason' => 'again'])->assertUnprocessable();

        $ledger = app(Ledger::class);
        $this->assertSame(0.0, $ledger->balance($this->admin->company_id, Account::MotorVehicles));
        $this->assertSame(0.0, $ledger->balance($this->admin->company_id, Account::Capital));
        $this->assertNotNull($asset->capital->fresh()->reversal_journal_entry_id);
        $this->getJson('/api/v1/capital/capitals')->assertJsonPath('data.share_holder_capital', 0)->assertJsonPath('data.share_holders.0.capitals.0.reversed', true);
        $this->getJson("/api/v1/shares/options/contributions?share_holder_id={$this->holder->id}")->assertJsonCount(0, 'data');
        $this->assertTrue($this->getJson('/api/v1/reports/financial/balance-sheet?branch_id=all')->json('data.balanced'));
    }

    public function test_config_endpoint_serves_the_single_field_configuration(): void
    {
        $config = $this->getJson('/api/v1/capital/assets/config')->assertOk()->json('data');

        $this->assertSame(['vehicle', 'equipment', 'electronics', 'property', 'land', 'furniture', 'other'], array_column($config['types'], 'value'));
        $vehicle = collect($config['types'])->firstWhere('value', 'vehicle');
        $this->assertSame('MOTOR VEHICLES', $vehicle['account_label']);
        $this->assertContains('registration_number', array_column($vehicle['fields'], 'key'));
        $land = collect($config['types'])->firstWhere('value', 'land');
        $this->assertSame([true, false, true], [$land['fixed_quantity'], $land['condition'], $land['location_required']]);
        foreach (config('assets.types') as $key => $type) {
            $this->assertContains(Account::from($type['account']), Account::fixedAssets(), "{$key} maps to a fixed-asset account");
        }
    }

    public function test_cash_and_bank_contributions_are_unchanged(): void
    {
        $this->postJson('/api/v1/capital/capitals', ['share_id' => $this->holder->id, 'amount' => 5000000, 'pay_method' => 'ASSET'])->assertUnprocessable()->assertJsonValidationErrors('pay_method');
        $cash = $this->postJson('/api/v1/capital/capitals', ['share_id' => $this->holder->id, 'amount' => 5000000, 'pay_method' => 'CASH'])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/capitals/{$cash}/approve");

        $this->assertSame(5000000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Company));
        $this->getJson('/api/v1/capital/capitals')->assertJsonPath('data.share_holders.0.cash_contributed', 5000000)->assertJsonPath('data.share_holders.0.capitals.0.asset_code', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'share_id' => $this->holder->id,
            'asset_type' => 'vehicle',
            'name' => 'Toyota Hilux',
            'description' => 'Double cabin pick-up',
            'quantity' => 1,
            'unit_value' => 30000000,
            'total_value' => null,
            'condition' => 'used',
            'contribution_date' => '2026-09-14',
            'branch_id' => $this->admin->branch_id,
            'location' => 'Yard A',
            'valuation_method' => 'agreed_value',
            'valuation_date' => '2026-09-10',
            'valued_by' => 'Board',
            'specifications' => ['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020, 'chassis_number' => 'CH-1', 'registration_number' => 'T 123 ABC'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /**
     * A non-exempt initiator: an Admin granted the capital and share permissions by employee override.
     */
    private function contributor(): Employee
    {
        $contributor = $this->secondApprover($this->admin, 'admin');
        $contributor->permissionOverrides()->createMany([
            ['permission' => 'capital.manage', 'granted' => true],
            ['permission' => 'shares.issue', 'granted' => true],
        ]);

        return $contributor->fresh();
    }

    private function contribute(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/capital/assets', $this->payload($overrides));
    }

    /**
     * Record an asset contribution and have a second authorised user approve it (C6 maker/checker).
     */
    private function createAsset(): Asset
    {
        $id = $this->contribute()->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/assets/{$id}/approve");

        return Asset::findOrFail($id);
    }
}
