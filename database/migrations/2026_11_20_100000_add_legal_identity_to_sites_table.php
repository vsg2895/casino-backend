<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who legally stands behind a site, as admin-editable facts.
 *
 * Until now these lived in two places that could disagree: bracketed
 * placeholders inside the seeded legal pages (filled by text replacement, which
 * once wrote a postcode where the company name belongs) and a hardcoded postal
 * address in the front end's footer. Legal pages, footer and the Organization
 * JSON-LD now all read these columns, and an empty one hides its line rather
 * than printing a placeholder — see App\Support\LegalIdentity.
 *
 * All nullable with no default: nothing here may be guessed. A site that has not
 * filled them in simply publishes less.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = [
        'legal_entity_name', 'registered_address', 'company_number', 'governing_law',
        'contact_email', 'support_email', 'privacy_email',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'legal_entity_name')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->string('legal_entity_name', 200)->nullable()->after('methodology_page_slug');
            $table->string('registered_address', 500)->nullable()->after('legal_entity_name');
            $table->string('company_number', 60)->nullable()->after('registered_address');
            $table->string('governing_law', 120)->nullable()->after('company_number');
            $table->string('contact_email', 190)->nullable()->after('governing_law');
            $table->string('support_email', 190)->nullable()->after('contact_email');
            $table->string('privacy_email', 190)->nullable()->after('support_email');
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(self::COLUMNS, static fn (string $c): bool => Schema::hasColumn('sites', $c)));

        if ($existing !== []) {
            Schema::table('sites', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
