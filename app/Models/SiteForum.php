<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One site's player-forum page configuration.
 *
 * Every editable string is nullable in the database and falls back to a constant
 * here. That is the whole reason the accessors exist: an editor who clears the
 * heading box gets the shipped heading back, not a page with an empty <h1>.
 * Read through {@see resolved()} — never straight off the column — or the
 * fallback is bypassed.
 */
class SiteForum extends Model
{
    public const string DEFAULT_TITLE = 'Player Forum';
    public const string DEFAULT_EYEBROW = 'Straight From The Players';
    public const string DEFAULT_INTRO = 'Every review below was written by a visitor and checked before it went live. Nothing is edited, and nothing is removed for being unflattering.';
    public const string DEFAULT_META_TITLE = 'Player Forum — Casino Reviews From Real Players';
    public const string DEFAULT_META_DESCRIPTION = 'Player-written reviews of every casino listed here, grouped by operator. Payouts, verification and support, in the words of the people who played there.';
    public const string DEFAULT_EMPTY_TITLE = 'No reviews have been published yet';
    public const string DEFAULT_EMPTY_BODY = 'The forum fills up as players write about the casinos listed here. Open any casino and use the review form at the bottom of its page — every submission is read before it appears.';
    public const string DEFAULT_EMPTY_CTA_LABEL = 'Browse casinos';
    public const string DEFAULT_EMPTY_CTA_URL = '/casinos';

    /**
     * The site's own note, in its own voice.
     *
     * Wording deliberately describes the POLICY — how reviews are handled — and
     * makes no claim about any operator. That is what keeps it editorial rather
     * than a disguised endorsement.
     */
    public const string DEFAULT_EDITORIAL_TITLE = 'About these reviews';
    public const string DEFAULT_EDITORIAL_BODY = "Every review on this page was written by a visitor and checked before it went live. We publish criticism as readily as praise, and we do not edit a review's wording or remove one for being unflattering.\n\nReviews are personal experiences, not our assessment of an operator. Where we have checked something ourselves — a licence, a withdrawal limit, a safer-play tool — we say so on the casino's own page.";

    public const int DEFAULT_THREADS_PER_PAGE = 8;
    public const int DEFAULT_PREVIEW_REVIEWS = 3;

    /** Hard ceilings the admin form also enforces — see UpdateSiteForumRequest. */
    public const int MAX_THREADS_PER_PAGE = 30;
    public const int MAX_PREVIEW_REVIEWS = 10;

    protected $fillable = [
        'site_id',
        'enabled',
        'title',
        'eyebrow',
        'show_eyebrow',
        'intro',
        'meta_title',
        'meta_description',
        'noindex',
        'empty_title',
        'empty_body',
        'empty_cta_label',
        'empty_cta_url',
        'show_stats',
        'editorial_enabled',
        'editorial_title',
        'editorial_body',
        'threads_per_page',
        'preview_reviews',
    ];

    protected function casts(): array
    {
        return [
            'enabled'          => 'boolean',
            'show_eyebrow'      => 'boolean',
            'noindex'           => 'boolean',
            'editorial_enabled' => 'boolean',
            'show_stats'       => 'boolean',
            'threads_per_page' => 'integer',
            'preview_reviews'  => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The settings as the public site should read them: every blank replaced by
     * its default, every number clamped into range.
     *
     * ONE method, used by both the public payload and anything else that needs
     * the effective values, so the fallback cannot be applied in one place and
     * forgotten in another.
     *
     * @return array<string, mixed>
     */
    public function resolved(): array
    {
        return [
            'enabled'          => (bool) $this->enabled,
            'title'            => self::orDefault($this->title, self::DEFAULT_TITLE),
            // Hidden by its own switch, not by an empty box. Clearing the
            // field cannot mean "show none": the global
            // ConvertEmptyStringsToNull middleware rewrites "" to null on the
            // way in, and null is what selects the default.
            'eyebrow'          => $this->show_eyebrow === false ? '' : self::orDefault($this->eyebrow, self::DEFAULT_EYEBROW),
            'intro'            => self::orDefault($this->intro, self::DEFAULT_INTRO),
            'meta_title'       => self::orDefault($this->meta_title, self::DEFAULT_META_TITLE),
            'meta_description' => self::orDefault($this->meta_description, self::DEFAULT_META_DESCRIPTION),
            'noindex'          => (bool) $this->noindex,
            'empty_title'      => self::orDefault($this->empty_title, self::DEFAULT_EMPTY_TITLE),
            'empty_body'       => self::orDefault($this->empty_body, self::DEFAULT_EMPTY_BODY),
            'empty_cta_label'  => self::orDefault($this->empty_cta_label, self::DEFAULT_EMPTY_CTA_LABEL),
            'empty_cta_url'    => self::orDefault($this->empty_cta_url, self::DEFAULT_EMPTY_CTA_URL),
            'show_stats'       => (bool) $this->show_stats,
            // Clearing the note reverts it to the shipped wording, the same
            // as every other field here — so in practice the body is never
            // blank. The emptiness check is a floor rather than a live branch:
            // it guarantees the front end can trust this one boolean and never
            // render a heading with nothing under it.
            'editorial_enabled' => (bool) $this->editorial_enabled
                && trim((string) self::orDefault($this->editorial_body, self::DEFAULT_EDITORIAL_BODY)) !== '',
            'editorial_title'   => self::orDefault($this->editorial_title, self::DEFAULT_EDITORIAL_TITLE),
            'editorial_body'    => self::orDefault($this->editorial_body, self::DEFAULT_EDITORIAL_BODY),
            'threads_per_page' => $this->clamp($this->threads_per_page, self::DEFAULT_THREADS_PER_PAGE, self::MAX_THREADS_PER_PAGE),
            'preview_reviews'  => $this->clamp($this->preview_reviews, self::DEFAULT_PREVIEW_REVIEWS, self::MAX_PREVIEW_REVIEWS),
        ];
    }

    private static function orDefault(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * Guard the query builder against a nonsense page size.
     *
     * The form request validates the same bounds, but this runs on the READ
     * path: a row written before those rules existed, or edited straight in the
     * database, must not be able to ask for 0 or 255 casinos per page.
     */
    private function clamp(?int $value, int $default, int $max): int
    {
        if ($value === null || $value < 1) {
            return $default;
        }

        return min($value, $max);
    }
}
