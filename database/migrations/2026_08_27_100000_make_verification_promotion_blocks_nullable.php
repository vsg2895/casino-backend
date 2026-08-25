<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the post-verification promotion's CONTENT blocks removable.
 *
 * This is the fix for a 500 on save: clearing "Secondary text" (or any block
 * below) in the admin and pressing Save returned a server error instead of
 * removing the block.
 *
 * WHY IT FAILED. The admin sends the cleared field as "", Laravel's global
 * ConvertEmptyStringsToNull middleware turns that into null,
 * {@see \App\Http\Requests\Admin\UpdateVerificationPromotionEmailRequest} already
 * validates every one of these as `nullable` so the request passed — and then the
 * UPDATE hit a NOT NULL column and MySQL rejected it. Nothing downstream was
 * wrong: the Blade guards each of these blocks with `@if (! empty(...))` and
 * VerificationPromotionEmail::render() casts null to '', so removal has always
 * been the intended behaviour. Only the schema disagreed.
 *
 * WHY THE COLUMNS WERE NOT NULL. `verification_promotion_emails` was created on
 * 2026-08-19 as a copy of `site_promotion_emails` ("same columns, no site_id") —
 * but it copied that table's ORIGINAL shape and missed the two migrations that had
 * already relaxed it: 2026_07_31_100000 (CTA fields) and 2026_07_31_120000
 * (content blocks). This applies the same change to the newer table.
 *
 * DELIBERATELY LEFT NOT NULL, matching 2026_07_31_120000's reasoning:
 *  - from_name / from_email / subject — an email cannot be sent without them.
 *  - unsubscribe_label — the opt-out link is a legal requirement (CAN-SPAM /
 *    GDPR) and must never be removable from a marketing email.
 *  - the colour palette + active / delay_minutes / provider — structural
 *    styling and settings, not content, and every one carries a default.
 *
 * Existing rows keep their values; nothing is rewritten.
 */
return new class extends Migration
{
    private const string TABLE = 'verification_promotion_emails';

    /**
     * Column definitions must be restated verbatim when changing nullability,
     * or ->change() would silently redefine the column's type and length.
     *
     * @var array<string, array{0: string, 1: int|null}>
     */
    private const array BLOCKS = [
        'preheader'       => ['string', 250],
        'heading'         => ['string', 150],
        // Nullable on site_promotion_emails since 2026_07_31_100000; the Blade
        // already falls back to the site URL (`$t['hero_url'] ?: $siteUrl`).
        'hero_url'        => ['string', 500],
        'intro_text'      => ['text', null],
        'secondary_text'  => ['text', null],
        'disclaimer_text' => ['text', null],
    ];

    public function up(): void
    {
        $this->setNullable(true);
    }

    public function down(): void
    {
        // Blank out nulls first — the columns become NOT NULL again, and rows
        // written while this migration was applied may legitimately hold null.
        foreach (array_keys(self::BLOCKS) as $column) {
            DB::table(self::TABLE)
                ->whereNull($column)
                ->update([$column => '']);
        }

        $this->setNullable(false);
    }

    private function setNullable(bool $nullable): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) use ($nullable): void {
            foreach (self::BLOCKS as $column => [$type, $length]) {
                $definition = $type === 'text'
                    ? $table->text($column)
                    : $table->string($column, (int) $length);

                $definition->nullable($nullable)->change();
            }
        });
    }
};
