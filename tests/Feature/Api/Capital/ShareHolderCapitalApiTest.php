<?php

namespace Tests\Feature\Api\Capital;

use App\Enums\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class ShareHolderCapitalApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    /**
     * @return array<string, string>
     */
    private function holderPayload(array $overrides = []): array
    {
        return array_merge(['first_name' => 'JOHN', 'middle_name' => 'MICHAEL', 'last_name' => 'MWAKALUKA', 'share_mobile' => '0777123456', 'share_email' => 'holder@example.com', 'share_sex' => 'male', 'share_dob' => '1992-12-12'], $overrides);
    }

    public function test_share_holder_crud_with_three_name_parts_and_passport_photo(): void
    {
        Storage::fake(ShareHolder::DISK);
        $this->signInAdmin();

        $this->post('/api/v1/capital/share-holders', $this->holderPayload(['passport_photo' => UploadedFile::fake()->image('john.jpg', 300, 350)]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message', 'Shareholder Registered successfully')
            ->assertJsonPath('data.name', 'JOHN MICHAEL MWAKALUKA');

        $holder = ShareHolder::firstWhere('first_name', 'JOHN');
        $this->assertSame(['JOHN', 'MICHAEL', 'MWAKALUKA'], [$holder->first_name, $holder->middle_name, $holder->last_name]);
        $this->assertSame('JOHN MICHAEL MWAKALUKA', $holder->name);
        Storage::disk(ShareHolder::DISK)->assertExists($holder->passport_photo);
        $firstPhoto = $holder->passport_photo;

        $this->get("/api/v1/capital/share-holders/{$holder->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->post("/api/v1/capital/share-holders/{$holder->id}", $this->holderPayload([
            '_method' => 'PUT', 'first_name' => 'HABAKUKI', 'middle_name' => '', 'passport_photo' => UploadedFile::fake()->image('new.png', 200, 200),
        ]), ['Accept' => 'application/json'])->assertOk();

        $holder->refresh();
        $this->assertNull($holder->middle_name);
        $this->assertNotSame($firstPhoto, $holder->passport_photo);
        Storage::disk(ShareHolder::DISK)->assertMissing($firstPhoto);
        Storage::disk(ShareHolder::DISK)->assertExists($holder->passport_photo);

        $this->putJson("/api/v1/capital/share-holders/{$holder->id}", $this->holderPayload(['first_name' => 'HABAKUKI', 'middle_name' => null]))->assertOk();
        $this->assertSame($holder->passport_photo, $holder->fresh()->passport_photo, 'editing without a new photo keeps the current one');

        $this->getJson('/api/v1/capital/share-holders')->assertOk()
            ->assertJsonPath('data.0.name', 'HABAKUKI MWAKALUKA')
            ->assertJsonPath('data.0.first_name', 'HABAKUKI')
            ->assertJsonPath('data.0.last_name', 'MWAKALUKA')
            ->assertJsonPath('data.0.date_of_birth', '1992-12-12');
        $this->assertStringStartsWith("capital/share-holders/{$holder->id}/photo", $this->getJson('/api/v1/capital/share-holders')->json('data.0.photo_endpoint'));

        $this->post('/api/v1/capital/share-holders', $this->holderPayload(['first_name' => '', 'last_name' => '', 'share_email' => 'bad', 'share_dob' => '', 'passport_photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'share_email', 'share_dob', 'passport_photo']);
        $this->postJson('/api/v1/capital/share-holders', $this->holderPayload())->assertUnprocessable()->assertJsonValidationErrors('passport_photo');
        $this->post('/api/v1/capital/share-holders', $this->holderPayload(['passport_photo' => UploadedFile::fake()->image('big.jpg', 300, 300)->size(4000)]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('passport_photo');

        $photo = $holder->fresh()->passport_photo;
        $this->deleteJson("/api/v1/capital/share-holders/{$holder->id}")->assertOk();
        $this->assertModelMissing($holder);
        Storage::disk(ShareHolder::DISK)->assertMissing($photo);
    }

    public function test_legacy_single_name_share_holders_still_display_and_migrate_safely(): void
    {
        $admin = $this->signInAdmin();
        $legacyId = DB::table('share_holders')->insertGetId(['company_id' => $admin->company_id, 'name' => 'mseti', 'mobile' => '0777', 'email' => 'a@example.com', 'date_of_birth' => '1990-01-01']);

        $this->getJson('/api/v1/capital/share-holders')->assertOk()->assertJsonPath('data.0.name', 'mseti')->assertJsonPath('data.0.photo_endpoint', null);
        $this->getJson("/api/v1/capital/share-holders/{$legacyId}/photo")->assertNotFound();
        $this->getJson('/api/v1/capital/options/share-holders')->assertOk()->assertJsonPath('data.0.label', 'mseti');

        $migration = require database_path('migrations/2026_09_13_141944_split_share_holder_names_and_add_attachments.php');
        $this->assertSame(['first_name' => 'mseti', 'middle_name' => null, 'last_name' => null], $migration::splitName('mseti'));
        $this->assertSame(['first_name' => 'JOHN', 'middle_name' => null, 'last_name' => 'SHAREHOLDER'], $migration::splitName(' JOHN  SHAREHOLDER '));
        $this->assertSame(['first_name' => 'JOHN', 'middle_name' => 'MICHAEL', 'last_name' => 'MWAKALUKA'], $migration::splitName('JOHN MICHAEL MWAKALUKA'));
    }

    public function test_adding_bank_capital_posts_to_the_selected_bank_account_and_blocks_holder_deletion(): void
    {
        $admin = $this->signInAdmin();
        $holder = ShareHolder::create(['company_id' => $admin->company_id, 'name' => 'mseti', 'mobile' => '0777', 'email' => 'a@example.com', 'date_of_birth' => '1990-01-01']);
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);

        $this->postJson('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => 5000000, 'pay_method' => 'BANK', 'recept' => '12', 'chaque_no' => '99'])
            ->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');

        $id = $this->postJson('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => 5000000, 'pay_method' => 'BANK', 'bank_account_id' => $bank->id, 'recept' => '12', 'chaque_no' => '99'])
            ->assertCreated()->assertJsonPath('message', 'Capital Recorded successfully — awaiting approval by another authorised user')->json('data.id');
        $this->assertSame(0.0, app(Ledger::class)->balance($admin->company_id, Account::Capital), 'a pending contribution posts nothing');
        $this->approveAsSecondUser($admin, "/api/v1/capital/capitals/{$id}/approve")->assertJsonPath('message', 'Capital Contribution Approved successfully');

        $ledger = app(Ledger::class);
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::Company));
        $this->assertSame(5000000.0, $ledger->balance($admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(5000000.0, $ledger->balance($admin->company_id, Account::Capital));

        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holders.0.capitals.0.pay_method', 'BANK')
            ->assertJsonPath('data.share_holders.0.capitals.0.receiving_account_label', 'BANK - NMB')
            ->assertJsonPath('data.share_holders.0.total_contributed', 5000000)
            ->assertJsonPath('data.share_holders.0.shares', 0)
            ->assertJsonPath('data.share_holders.0.ownership_percent', 0)
            ->assertJsonPath('data.share_holder_capital', 5000000)
            ->assertJsonPath('data.company_capital', 0)
            ->assertJsonPath('data.bank_balance_total', 5000000);

        $this->deleteJson("/api/v1/capital/share-holders/{$holder->id}")->assertUnprocessable();

        $foreign = ShareHolder::create(['company_id' => Company::factory()->create()->id, 'name' => 'x', 'mobile' => '1', 'email' => 'x@example.com', 'date_of_birth' => '1990-01-01']);
        $this->postJson('/api/v1/capital/capitals', ['share_id' => $foreign->id, 'amount' => 1, 'pay_method' => 'CASH'])->assertUnprocessable()->assertJsonValidationErrors('share_id');
        $this->putJson("/api/v1/capital/share-holders/{$foreign->id}", $this->holderPayload())->assertNotFound();
    }

    public function test_capital_is_hidden_from_roles_without_capital_permissions(): void
    {
        $admin = $this->signInAdmin();
        $finance = Employee::factory()->create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'admin')->value('id'),
        ]);

        $this->actingAs($finance)->getJson('/api/v1/capital/capitals')->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/capital/share-holders')->assertForbidden();
    }

    public function test_capital_receipt_upload_view_and_replacement_follow_capital_permissions(): void
    {
        Storage::fake(ShareHolder::DISK);
        $admin = $this->signInAdmin();
        $holder = ShareHolder::create(['company_id' => $admin->company_id, 'first_name' => 'JOHN', 'last_name' => 'MWAKALUKA', 'mobile' => '0777', 'email' => 'a@example.com', 'date_of_birth' => '1990-01-01']);

        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'CRDB']);

        $this->post('/api/v1/capital/capitals', [
            'share_id' => $holder->id, 'amount' => 250000, 'pay_method' => 'BANK', 'bank_account_id' => $bank->id, 'recept' => 'RC-77', 'chaque_no' => 'CH-12',
            'receipt_file' => UploadedFile::fake()->create('deposit slip.pdf', 300, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $capital = $holder->capitals()->sole();
        $this->approveAsSecondUser($admin, "/api/v1/capital/capitals/{$capital->id}/approve");
        $capital->refresh();
        $this->assertSame('RC-77', $capital->receipt_number);
        $this->assertSame('CH-12', $capital->cheque_number);
        $this->assertSame('deposit slip.pdf', $capital->receipt_file_name);
        Storage::disk(ShareHolder::DISK)->assertExists($capital->receipt_file);
        $this->assertSame(250000.0, app(Ledger::class)->balance($admin->company_id, Account::Capital));

        $row = $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holders.0.name', 'JOHN MWAKALUKA')
            ->assertJsonPath('data.share_holders.0.capitals.0.receipt_file_name', 'deposit slip.pdf')
            ->json('data.share_holders.0.capitals.0');
        $this->assertStringStartsWith("capital/capitals/{$capital->id}/receipt", $row['receipt_endpoint']);

        $this->get("/api/v1/capital/capitals/{$capital->id}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->post('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => 1, 'pay_method' => 'CASH', 'receipt_file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_file');
        $this->post('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => 1, 'pay_method' => 'CASH', 'receipt_file' => UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_file');

        $old = $capital->receipt_file;
        $this->post("/api/v1/capital/capitals/{$capital->id}/receipt", ['receipt_file' => UploadedFile::fake()->image('receipt.jpg', 600, 800)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Receipt Updated successfully');
        $capital->refresh();
        Storage::disk(ShareHolder::DISK)->assertMissing($old);
        Storage::disk(ShareHolder::DISK)->assertExists($capital->receipt_file);
        $this->assertSame(250000.0, (float) $capital->amount);
        $this->assertSame(250000.0, app(Ledger::class)->balance($admin->company_id, Account::Capital), 'replacing the receipt does not touch the ledger');

        $noCapital = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'finance')->value('id')]);
        $this->actingAs($noCapital)->get("/api/v1/capital/capitals/{$capital->id}/receipt", ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($noCapital)->get("/api/v1/capital/share-holders/{$holder->id}/photo", ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($noCapital)->post("/api/v1/capital/capitals/{$capital->id}/receipt", ['receipt_file' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->assertForbidden();

        $foreignHolder = ShareHolder::create(['company_id' => Company::factory()->create()->id, 'first_name' => 'X', 'last_name' => 'Y', 'mobile' => '1', 'email' => 'x@example.com', 'date_of_birth' => '1990-01-01']);
        $this->actingAs($admin)->get("/api/v1/capital/share-holders/{$foreignHolder->id}/photo", ['Accept' => 'application/json'])->assertNotFound();
        $this->get('/api/v1/capital/capitals/999999/receipt', ['Accept' => 'application/json'])->assertNotFound();
    }
}
