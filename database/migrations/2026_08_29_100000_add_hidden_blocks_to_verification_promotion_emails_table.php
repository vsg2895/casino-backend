<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes every removable block of the post-verification promotion REVERSIBLE.
 *
 * Removing a block used to mean clearing its text, which is destructive: the
 * wording is gone, and "restore" could only ever put back a default rather than
 * what the operator actually wrote. Visibility and content are now independent —
 * the text stays in its own column, and this list decides what renders.
 *
 * ONE JSON COLUMN RATHER THAN ~24 BOOLEANS. This template has 24 optional blocks
 * and will gain more. A column per block would mean a 24-column-wider table and a
 * migration every time a block becomes optional; a list of hidden keys costs one
 * column and needs no schema change ever again. The trade — you cannot index or
 * query "all rows hiding X" — is irrelevant here: the table holds exactly one row.
 *
 * The keys are field names from
 * {@see \App\Models\VerificationPromotionEmail::OPTIONAL_BLOCKS}, which is the one
 * place a block is declared optional. Anything not in that list is ignored, so a
 * stale key left behind by a renamed field is inert rather than breaking a render.
 *
 * Nullable and empty by default, so every existing row shows everything it shows
 * today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->json('hidden_blocks')->nullable()->after('unsubscribe_label');
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('hidden_blocks');
        });
    }
};
