<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared shape of a per-site content override row.
 *
 * Every column is optional and NULL means "inherit the default content".
 * Values are normalised on the way in, so the resolver, the sitemap query and
 * the admin can all treat NULL as the one and only "no override" state —
 * an editor's empty `<p><br></p>` or a whitespace-only title never reaches
 * the database as a value that looks filled but says nothing.
 */
trait IsSiteContent
{
    /** @var list<string> */
    public const array CONTENT_FIELDS = ['description', 'meta_title', 'meta_description', 'noindex'];

    public function initializeIsSiteContent(): void
    {
        $this->mergeFillable(['site_id', ...self::CONTENT_FIELDS]);
        $this->mergeCasts(['noindex' => 'boolean']);
    }

    public static function bootIsSiteContent(): void
    {
        static::saving(static function (self $row): void {
            $row->description = self::cleanHtml($row->description);
            $row->meta_title = self::cleanText($row->meta_title);
            $row->meta_description = self::cleanText($row->meta_description);
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** True when the row overrides nothing and should not exist. */
    public function overridesNothing(): bool
    {
        return self::cleanHtml($this->description) === null
            && self::cleanText($this->meta_title) === null
            && self::cleanText($this->meta_description) === null
            && $this->noindex === null;
    }

    /** Rich text with no visible words is no text at all. */
    public static function cleanHtml(?string $html): ?string
    {
        $html = trim((string) $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) === '' ? null : $html;
    }

    public static function cleanText(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
