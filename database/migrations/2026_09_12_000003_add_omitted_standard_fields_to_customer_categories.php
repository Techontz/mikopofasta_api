<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the STANDARD registration questions a customer type does not ask.
 *
 * WHY THIS IS DATA. Step two draws a customer type's own configured questions
 * and then a standard block behind them — employment, business or neither.
 * That block is written for the common case, and the common case is not every
 * case: a retiree has no Place of Employment, a Basic Salary or a Take Home,
 * and a trader registered under their own questions does not need a second
 * "Business Name" in English beside "Jina la Biashara".
 *
 * The alternative was a list of category codes inside the frontend deciding
 * who sees what, which is the one thing customer types exist to avoid — a type
 * created this afternoon would get whatever the code happened to say about
 * types it had never heard of. So the exception lives on the type, next to the
 * questions it already owns.
 *
 * NAMED BY KEY, the same key the standard field declares
 * (`place_of_employment`, `monthly_income`, …). A key that matches nothing is
 * inert rather than an error: the block it referred to may simply not be drawn
 * for this type.
 *
 * IT HIDES A QUESTION, NOT A COLUMN. Everything omitted here is still a real
 * column and still editable from the customer's profile; registration just
 * stops asking for it. Because the same list drives rendering AND validation,
 * an omitted field is not required and is not submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->json('omitted_standard_fields')->nullable()->after('dynamic_form_schema');
        });
    }

    public function down(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->dropColumn('omitted_standard_fields');
        });
    }
};
