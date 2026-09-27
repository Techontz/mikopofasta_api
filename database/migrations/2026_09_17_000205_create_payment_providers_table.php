<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings → Payment Channels: the few banks and mobile networks the COMPANY receives customer payments through. They are
     * names only — not the company's bank accounts that hold company funds (Bank → Register Account), and not the full Master
     * Data bank list, which is for customers' own accounts. Each existing company starts with the active Master Data networks.
     */
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'name']);
        });

        $networks = DB::table('mobile_money_providers')->where('is_active', true)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('name')->pluck('name');
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('payment_providers')->insert($networks->map(fn (string $name): array => [
                'company_id' => $companyId, 'channel' => 'MNO', 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
