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
        'postal_address',
        'contact_email',
        'unsubscribe_label',
        'unsubscribe_enabled',
        'hidden_blocks',
        'copyright_text',
        'accent_color',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'unsubscribe_enabled' => 'boolean',
            'hidden_blocks'       => 'array',
            'active'              => 'boolean',
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
            'postal_address'    => '123 Example Street, City 00000, Country',
            // A MONITORED mailbox that accepts replies — never no-reply@.
            'contact_email'     => 'info@' . $site->domain,
            'unsubscribe_label' => 'Unsubscribe',
            // The footer link is shown by default; the admin can remove and
            // restore it without losing the label above.
            'unsubscribe_enabled' => true,
            'copyright_text'    => '© {{year}} {{site_name}}. All rights reserved.',
            'accent_color'      => '#4f1d96',
            'active'            => true,
        ];
    }

    /**
     * Blocks an admin may hide without losing their content.
     *
     * Same convention as both promotion templates: `hidden_blocks` lists what is
     * switched off, each field keeps its own text, so hiding is reversible and
     * restoring is a toggle rather than a retype.
     *
     * @var list<string>
     */
    public const array OPTIONAL_BLOCKS = [
        'postal_address',
        'contact_email',
        'copyright_text',
    ];

    /**
     * Visibility flag per optional block, for the Blade layout.
     *
     * Everything not explicitly hidden is visible, so an existing row — and the
     * live preview's unsaved model, where the attribute is simply absent —
     * renders exactly as it does today. Unknown keys are ignored.
     *
     * @return array<string, bool>
     */
    public function visibleBlocks(): array
    {
        $hidden = array_flip(array_filter(
            (array) ($this->hidden_blocks ?? []),
            static fn (mixed $key): bool => is_string($key),
        ));

        $flags = [];

        foreach (self::OPTIONAL_BLOCKS as $block) {
            $flags[$block] = ! isset($hidden[$block]);
        }

        return $flags;
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

        foreach (['from_name', 'from_email', 'subject', 'header_title', 'header_subtitle', 'heading', 'unsubscribe_label', 'postal_address', 'contact_email', 'copyright_text'] as $field) {
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

    /**
     * Whether the footer unsubscribe LINK is rendered in the body.
     *
     * Coalesced rather than read directly: the live preview builds an UNSAVED
     * model from the request payload, where an absent key leaves the attribute
     * unset (null, not false). Legacy rows written before the column existed
     * behave the same way. Both must mean "shown", which is the pre-existing
     * behaviour.
     *
     * This gates the visible link ONLY. The List-Unsubscribe headers and the
     * unsubscribe endpoint are deliberately untouched — a recipient can always
     * opt out through their mail client.
     */
    public function showsUnsubscribeLink(): bool
    {
        return (bool) ($this->unsubscribe_enabled ?? true);
    }

    /** Absolute unsubscribe URL for a subscriber on this site (opaque token only). */
    public function unsubscribeUrl(Site $site, string $token): string
    {
        return $site->frontendBaseUrl() . '/unsubscribe/' . Str::of($token)->trim();
    }

    /**
     * Absolute double opt-in verify URL for a subscriber on this site. Reuses the
     * subscriber's opaque subscription token as the credential; clicking it lands
     * on the public /verify/{token} page which marks them verified.
     */
    public function verifyUrl(Site $site, string $token): string
    {
        return $site->frontendBaseUrl() . '/verify/' . Str::of($token)->trim();
    }
}
