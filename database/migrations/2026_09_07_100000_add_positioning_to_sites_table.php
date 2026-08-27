<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One short sentence saying what makes THIS brand different.
 *
 * WHY A COLUMN and not a slug-keyed map in code: the standard legal pages are
 * generated from one template for every site, so all eleven of them shipped a
 * meta description that differed only by the brand name — the same string on
 * four domains, which is cross-site duplicate content. Varying it needs a per-site
 * editorial angle, and an angle is DATA. Keying it by slug inside
 * `LegalPageContent` would hardcode site slugs into shared code, which this
 * codebase deliberately does not do (see the root CLAUDE.md), and would leave the
 * operator unable to change their own wording without a deploy.
 *
 * NULLABLE, and null means "behave exactly as before": every generated
 * description falls back to the current generic sentence. Nothing needs
 * backfilling for the migration itself to be safe.
 *
 * Deliberately NOT part of `settings` (the existing JSON blob): this is indexed
 * copy that reaches a <meta name="description">, so it earns a real column the
 * admin can validate and display, rather than a key nothing enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // 200 chars: long enough for a clause that must still fit INSIDE a
            // ~160-character meta description alongside the sentence it follows,
            // and short enough that it cannot become a paragraph.
            $table->string('positioning', 200)->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('positioning');
        });
    }
};
