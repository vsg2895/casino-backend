<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteEmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Per-site subscription confirmation email template.
 *
 * Holds every editable string for the "you're subscribed" email. The from
 * identity is per-site but always lands on the SendGrid-verified domain
 * (see config('services.sendgrid.from_domain')). Placeholders in any text are
 * resolved at send time via {@see self::render()}.
 */
class SiteEmailTemplate extends Model
{
    /** @use HasFactory<SiteEmailTemplateFactory> */
    use HasFactory;

    /** Body fields that support a light **bold** syntax when rendered to HTML. */
    public const array RICH_FIELDS = ['intro_text', 'offer_text', 'spam_notice', 'footer_note'];

    protected $fillable = [
        'site_id',
        'from_name',
        'from_email',
        'subject',
        'header_title',
        'header_subtitle',
        'heading',
        'intro_text',
        'offer_text',
        'spam_notice',
        'footer_note',
        'unsubscribe_label',
        'copyright_text',
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
     * Default copy for a freshly-attached site. Mirrors the approved design
     * (purple "Subscription Confirmed" band → body → unsubscribe footer).
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(Site $site): array
    {
        $domain = (string) config('services.sendgrid.from_domain', 'example.com');

        return [
            'from_name'         => $site->name,
            'from_email'        => 'offers@' . $domain,
            'subject'           => 'Thanks for subscribing to {{site_name}} offers',
            'header_title'      => 'Subscription Confirmed',
            'header_subtitle'   => 'Thanks for joining — your custom offer is on the way.',
            'heading'           => 'Thank you for subscribing!',
            'intro_text'        => "You're all set. We'll deliver your custom offer within **24 hours**.",
            'offer_text'        => 'Your offer will include a **No-Deposit Registration Bonus** for {{site_name}}.',
            'spam_notice'       => "If you don't see the email, please check your spam or junk folder.",
            'footer_note'       => 'You received this email because you subscribed at {{site_name}}.',
            'unsubscribe_label' => 'Unsubscribe',
            'copyright_text'    => '© {{year}} {{site_name}}. All rights reserved.',
            'accent_color'      => '#4f1d96',
            'active'            => true,
        ];
    }

    /**
     * Resolve this template into render-ready strings for the Blade view.
     *
     * Placeholders ({{site_name}}, {{site_url}}, {{email}}, {{year}},
     * {{unsubscribe_url}}) are substituted everywhere; RICH_FIELDS additionally
     * get HTML-escaped and a minimal **bold** → <strong> conversion so admins
     * cannot inject markup.
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

        foreach (['from_name', 'from_email', 'subject', 'header_title', 'header_subtitle', 'heading', 'unsubscribe_label', 'copyright_text'] as $field) {
            $out[$field] = $replace((string) $this->{$field});
        }

        foreach (self::RICH_FIELDS as $field) {
            $out[$field] = self::richToHtml($replace((string) $this->{$field}));
        }

        $out['accent_color'] = $this->accent_color;

        return $out;
    }

    /** Escape HTML, then convert a minimal **bold** syntax to <strong>. */
    private static function richToHtml(string $value): string
    {
        $escaped = e($value);

        return (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    }

    /** Build the absolute unsubscribe URL for a subscriber on this site. */
    public function unsubscribeUrl(Site $site, string $token): string
    {
        $base = rtrim('https://' . $site->domain, '/');

        return $base . '/unsubscribe/' . Str::of($token)->trim();
    }
}
