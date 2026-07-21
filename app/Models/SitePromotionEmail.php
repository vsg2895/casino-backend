<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SitePromotionEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Per-site promotion (marketing offer) email template.
 *
 * Sibling of {@see SiteEmailTemplate} but for the promotional "welcome offer"
 * blast: a dark, hero-image-led design with two CTA buttons pointing at the
 * affiliate offer. Every editable string lives here; structured fields are
 * rendered into the fixed mail.promotion.offer Blade layout via {@see render()}.
 */
class SitePromotionEmail extends Model
{
    /** @use HasFactory<SitePromotionEmailFactory> */
    use HasFactory;

    /** Body fields that support a light **bold** syntax when rendered to HTML. */
    public const array RICH_FIELDS = ['intro_text', 'secondary_text', 'disclaimer_text'];

    /**
     * Plain text/URL fields: placeholders are substituted but no markup is
     * allowed (Blade escapes them at render time).
     */
    private const array PLAIN_FIELDS = [
        'from_name', 'from_email', 'subject', 'preheader', 'hero_image_url',
        'hero_url', 'top_button_text', 'heading', 'cta_button_text', 'unsubscribe_label',
    ];

    protected $fillable = [
        'site_id',
        'from_name',
        'from_email',
        'subject',
        'preheader',
        'hero_image_url',
        'hero_url',
        'top_button_text',
        'heading',
        'intro_text',
        'secondary_text',
        'cta_button_text',
        'disclaimer_text',
        'unsubscribe_label',
        'button_color',
        'accent_color',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Default copy for a freshly-attached site. Mirrors the approved promotion
     * design (dark hero → offer copy → green CTA → unsubscribe footer).
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(Site $site): array
    {
        $domain = (string) config('services.sendgrid.from_domain', 'example.com');

        return [
            'from_name'         => $site->name,
            'from_email'        => 'offers@' . $domain,
            'subject'           => 'A special welcome offer from {{site_name}}',
            'preheader'         => 'Your welcome package is ready at {{site_name}} — register today and claim it.',
            'hero_image_url'    => 'https://cdn.mcauto-images-production.sendgrid.net/5b9fb463f9d4c1ad/a93f454a-209a-46af-aa6d-a264d7b2a7d1/1600x568.jpeg',
            'hero_url'          => '{{site_url}}',
            'top_button_text'   => 'View Details',
            'heading'           => 'Welcome to {{site_name}}',
            'intro_text'        => 'Join our platform and receive **100 FS** as part of your welcome package. **No deposit required** — just register and start playing.',
            'secondary_text'    => 'A trusted, licensed platform built for players who value transparency, security, and seamless gameplay.',
            'cta_button_text'   => 'Register Your Account',
            'disclaimer_text'   => "This is a one-time invitation to join {{site_name}}. If you're not interested, you can simply disregard this message.",
            'unsubscribe_label' => 'Unsubscribe',
            'button_color'      => '#75B636',
            'accent_color'      => '#f3a333',
            'active'            => true,
        ];
    }

    /**
     * Resolve this template into render-ready strings for the Blade view.
     *
     * Placeholders ({{site_name}}, {{site_url}}, {{email}}, {{year}},
     * {{unsubscribe_url}}) are substituted everywhere; RICH_FIELDS additionally
     * get HTML-escaped and a minimal **bold** → <strong> conversion so admins
     * cannot inject markup. Plain/URL fields are left for Blade to escape.
     *
     * @param  array<string, string>  $context
     * @return array<string, string>
     */
    public function render(array $context): array
    {
        $replace = static function (string $value) use ($context): string {
            foreach ($context as $key => $val) {
                $value = str_replace('{{' . $key . '}}', $val, $value);
                $value = str_replace('{{ ' . $key . ' }}', $val, $value);
            }

            return $value;
        };

        $out = [];

        foreach (self::PLAIN_FIELDS as $field) {
            $out[$field] = $replace((string) $this->{$field});
        }

        foreach (self::RICH_FIELDS as $field) {
            $out[$field] = self::richToHtml($replace((string) $this->{$field}));
        }

        $out['button_color'] = $this->button_color;
        $out['accent_color'] = $this->accent_color;

        return $out;
    }

    /** Escape HTML, then convert a minimal **bold** syntax to <strong>. */
    private static function richToHtml(string $value): string
    {
        $escaped = e($value);

        return (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    }

    /**
     * Build the absolute unsubscribe URL for a subscriber on this site.
     *
     * The URL carries only the opaque per-stream token — never the email or any
     * other subscriber data. Base resolves to the site's real public https URL
     * via Site::frontendBaseUrl().
     */
    public function unsubscribeUrl(Site $site, string $token): string
    {
        return $site->frontendBaseUrl() . '/unsubscribe/' . Str::of($token)->trim();
    }
}
