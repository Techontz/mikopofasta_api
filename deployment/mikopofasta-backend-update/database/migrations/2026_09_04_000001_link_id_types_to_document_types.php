<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which document evidences an identity type.
 *
 * WHAT WAS MISSING. Registration asks the officer WHICH identity document they
 * saw — an ID type — and then asks them to upload the file. Nothing connected
 * the two. The officer chose "National ID", reached the documents step, and had
 * to work out for themselves which of a dozen document types was the one their
 * answer meant, from a list that also carried payslips and bank cards. The
 * relationship existed in the officer's head and nowhere else, so the wizard
 * could not name the slot and the file could be filed under anything.
 *
 * ON `id_types`, NOT ON `document_types`. The question the form asks has
 * exactly one answer — "the customer showed a National ID; which document do I
 * need from them?" — and a column on the ID type answers it directly. The
 * reverse (`document_types.id_type_id`) would let two document types both claim
 * to evidence one ID type, and the form would then have no rule for choosing
 * between them. A mapping table would model a many-to-many that does not exist.
 *
 * NULLABLE, AND NULL IS A NORMAL STATE. An institution may accept an identity
 * type it does not take a copy of; a fresh installation has both tables empty
 * and no links at all. Where the link is absent the registration form asks for
 * the type and the number and no upload slot appears, which is exactly what it
 * did before this column existed.
 *
 * nullOnDelete rather than restrict: retiring a document type must not be
 * blocked by an ID type pointing at it, and must not take the ID type with it.
 * The link simply goes, and the form falls back to asking for no upload.
 *
 * NO ROWS ARE CREATED OR LINKED HERE. Which documents an institution accepts,
 * and which one evidences which identity type, is its own decision.
 * Administration → Master Data → ID Types is where the link is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_types', function (Blueprint $table): void {
            $table->foreignId('document_type_id')->nullable()->after('description')
                ->constrained('document_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('id_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_type_id');
        });
    }
};
