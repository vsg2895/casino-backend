<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes every CONTENT block of the three site email templates removable.
 *
 * Each of these columns backs one visual block (a heading, a paragraph, a
 * header strip, a footer note). Making them nullable is what lets the admin
 * clear a field and have the Blade layout drop that block entirely, so an
 * email can be composed of only the parts a brand actually wants.
 *
 * Deliberately left NOT NULL, because they are structural rather than content:
 *  - from_name / from_email / subject — an email cannot be sent without them.
 *  - unsubscribe_label — the opt-out link is a legal requirement (CAN-SPAM /
 *    GDPR) and must never be removable from a marketing email.
 *  - accent_color / button_color / active — styling + on-off switch.
 *
 * Existing rows keep their values; nothing is rewritten.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const array BLOCKS = [
        'site_promotion_emails' => [
            'preheader', 'heading', 'intro_text', 'secondary_text', 'disclaimer_text',
        ],
        'site_email_templates' => [
            'header_title', 'header_subtitle', 'heading', 'intro_text',
            'offer_text', 'spam_notice', 'footer_note', 'copyright_text',
        ],
        'site_verify_emails' => [
            'header_title', 'header_subtitle', 'heading', 'intro_text',
            'offer_text', 'spam_notice', 'footer_note', 'copyright_text',
        ],
    ];

    /** Column definitions must be restated verbatim when changing nullability. */
    private const array TYPES = [
        'preheader'       => ['string', 250],
        'header_title'    => ['string', 150],
        'header_subtitle' => ['string', 250],
        'heading'         => ['string', 150],
        'copyright_text'  => ['string', 200],
        'intro_text'      => ['text', null],
        'secondary_text'  => ['text', null],
        'disclaimer_text' => ['text', null],
        'offer_text'      => ['text', null],
        'spam_notice'     => ['text', null],
        'footer_note'     => ['text', null],
    ];

    public function up(): void
    {
        $this->setNullable(true);
    }

    public function down(): void
    {
        // Blank out nulls first — the columns become NOT NULL again.
        foreach (self::BLOCKS as $table => $columns) {
            foreach ($columns as $column) {
                \Illuminate\Support\Facades\DB::table($table)
                    ->whereNull($column)
                    ->update([$column => '']);
            }
        }

        $this->setNullable(false);
    }

    private function setNullable(bool $nullable): void
    {
        foreach (self::BLOCKS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $nullable): void {
                foreach ($columns as $column) {
                    [$type, $length] = self::TYPES[$column];

                    $definition = $type === 'text'
                        ? $blueprint->text($column)
                        : $blueprint->string($column, $length);

                    $definition->nullable($nullable)->change();
                }
            });
        }
    }
};
