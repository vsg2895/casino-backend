<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One site's image + link overrides for the post-verification promotion.
 *
 * Deliberately NARROW. The promotion is one global template shared by every
 * site — subject, copy, colours, delay and transport all live on
 * {@see VerificationPromotionEmail} and stay there. This model may only change
 * the hero image and where the links point, which is why it has no text column
 * of any kind: the request was explicitly that titles and copy stay as they
 * are, and a model that cannot hold a title cannot accidentally change one.
 */
class VerificationPromotionOverride extends Model
{
    protected $table = 'verification_promotion_overrides';

    /**
     * The overridable keys, in the shape the rendered template uses.
     *
     * THE list. The service iterates it, the admin form is built from it and
     * the validator checks it, so the three cannot drift apart.
     *
     * @var list<string>
     */
    public const array LINK_FIELDS = [
        'hero_url',
        'top_button_url',
        'cta_button_url',
        'email_preferences_url',
    ];

    /** @var list<string> */
    public const array FIELDS = [
        'hero_image_url',
        ...self::LINK_FIELDS,
        'footer_link_urls',
    ];

    protected $fillable = [
        'site_id',
        'hero_image_url',
        'hero_url',
        'top_button_url',
        'cta_button_url',
        'email_preferences_url',
        'footer_link_urls',
    ];

    protected function casts(): array
    {
        return [
            'footer_link_urls' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The overrides actually set on this row, ready to merge over a rendered
     * template.
     *
     * An empty string is treated as "not set", not as "blank it out". An
     * editor who clears a field means "go back to the default" — every other
     * template field in this application behaves that way, and a literally
     * empty href would render a link to nowhere.
     *
     * Footer links are NOT here — they are positional targets that have to be
     * merged into the existing list so each keeps its label. See
     * footerLinkTargets().
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $out = [];

        foreach (self::LINK_FIELDS as $field) {
            $value = trim((string) $this->{$field});

            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        $image = trim((string) $this->hero_image_url);

        if ($image !== '') {
            $out['hero_image_url'] = $image;
        }

        return $out;
    }

    /**
     * Footer link TARGETS, by position, for links this site re-points.
     *
     * Returns `[0 => 'https://…', 2 => 'https://…']` — index into the
     * template's own footer links. A blank entry is absent from the result,
     * which is what leaves that link on its default target.
     *
     * Kept separate from overrides() because it is not a straight replacement:
     * the caller has to merge it INTO the existing list so each link keeps its
     * label. See PostVerificationPromotionEmailService::withSiteOverrides.
     *
     * @return array<int, string>
     */
    public function footerLinkTargets(): array
    {
        $out = [];

        foreach ($this->footer_link_urls ?? [] as $i => $url) {
            $url = trim((string) $url);

            if ($url !== '') {
                $out[(int) $i] = $url;
            }
        }

        return $out;
    }
}
