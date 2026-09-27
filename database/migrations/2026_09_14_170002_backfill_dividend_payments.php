<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data backfill for the partial-payment dividend flow (idempotent — safe to run again):
     *
     *  - declarations: declared_at = created_at, as_of_date = declaration day, total_shares from the allocations,
     *    profit_source "manual" (the old form accepted a typed profit amount);
     *  - allocations: legacy status "pending" → "unpaid";
     *  - every allocation paid by the old whole-allocation flow (status "paid" without payment rows) gets ONE posted
     *    payment row with its original method, account, reference, payer, date and journal entry (the entry whose source
     *    is the allocation), and paid_amount = entitlement. No journal entry is created or changed.
     */
    public function up(): void
    {
        DB::table('dividend_declarations')->whereNull('declared_at')->orderBy('id')->each(function (object $declaration): void {
            DB::table('dividend_declarations')->where('id', $declaration->id)->update([
                'declared_at' => $declaration->created_at,
                'as_of_date' => $declaration->as_of_date ?? ($declaration->created_at === null ? null : substr((string) $declaration->created_at, 0, 10)),
                'total_shares' => $declaration->total_shares ?? DB::table('dividend_allocations')->where('dividend_declaration_id', $declaration->id)->max('total_shares'),
                'profit_source' => $declaration->profit_source ?? 'manual',
            ]);
        });

        DB::table('dividend_allocations')->where('status', 'pending')->update(['status' => 'unpaid']);

        DB::table('dividend_allocations')
            ->where('status', 'paid')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('dividend_payments')->whereColumn('dividend_payments.dividend_allocation_id', 'dividend_allocations.id'))
            ->orderBy('id')
            ->each(function (object $allocation): void {
                $entryId = DB::table('journal_entries')
                    ->where('source_type', 'App\\Models\\DividendAllocation')
                    ->where('source_id', $allocation->id)
                    ->whereNull('reversal_of_id')
                    ->orderBy('id')
                    ->value('id');
                $bank = $allocation->pay_method === 'BANK';
                $paidAt = $allocation->paid_at ?? $allocation->updated_at ?? now();

                DB::table('dividend_payments')->insert([
                    'company_id' => $allocation->company_id,
                    'dividend_allocation_id' => $allocation->id,
                    'share_holder_id' => $allocation->share_holder_id,
                    'amount' => $allocation->amount,
                    'pay_method' => $bank ? 'BANK' : 'CASH',
                    'source_account' => $bank ? 'bank' : 'company_cash',
                    'bank_account_id' => $bank ? $allocation->bank_account_id : null,
                    'reference' => $allocation->reference,
                    'paid_at' => $paidAt,
                    'paid_by' => $allocation->paid_by,
                    'journal_entry_id' => $entryId,
                    'status' => 'posted',
                    'created_at' => $paidAt,
                    'updated_at' => $paidAt,
                ]);

                DB::table('dividend_allocations')->where('id', $allocation->id)->update(['paid_amount' => $allocation->amount]);
            });
    }

    /**
     * Backfilled payment rows are kept (they describe real, posted payments).
     */
    public function down(): void
    {
        //
    }
};
