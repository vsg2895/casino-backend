<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Per-site "verify your email" template.
 *
 * Structurally identical to {@see SiteEmailTemplate} (the subscription email) —
 * an independently editable per-site template with the same fields, placeholder
 * substitution and **bold** rich rendering. Managed in the admin (CRUD +
 * preview + test); not yet wired to an automatic send.
 */
class SiteVerifyEmail extends Model
{
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
     * Default copy for a freshly-attached site — verify-flavoured wording.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(Site $site): array
    {
        $domain = (string) config('services.sendgrid.from_domain', 'example.com');

        return [
            'from_name'         => $site->name,
            'from_email'        => 'verify@' . $domain,
            'subject'           => 'Verify your email for {{site_name}}',
            'header_title'      => 'Verify Your Email',
            'header_subtitle'   => 'One quick step to confirm it’s really you.',
            'heading'           => 'Confirm your email address',
            'intro_text'        => 'Please confirm your email address to activate your **{{site_name}}** subscription.',
            'offer_text'        => 'This makes sure we send your offers to the right inbox.',
            'spam_notice'       => "If you didn't request this, you can safely ignore this email.",
            'footer_note'       => 'You received this email because an address was registered at {{site_name}}.',
            'unsubscribe_label' => 'Unsubscribe',
            'copyright_text'    => '© {{year}} {{site_name}}. All rights reserved.',
            'accent_color'      => '#4f1d96',
            'active'            => true,
        ];
    }

    /**
     * Resolve this template into render-ready strings for the Blade view.
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

    /** Absolute unsubscribe URL for a subscriber on this site (opaque token only). */
    public function unsubscribeUrl(Site $site, string $token): string
    {
        return $site->frontendBaseUrl() . '/unsubscribe/' . Str::of($token)->trim();
    }
}
