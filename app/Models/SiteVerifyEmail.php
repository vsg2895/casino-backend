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
        'verify_button_text',
        'footer_text_color',
        'button_text_font_size',
        'copyright_text',
        'accent_color',
        'active',
    ];

    /**
     * Button label sizing, in px.
     *
     * DEFAULT 15, not 16: this template's button has always rendered at 15px,
     * and the fallback must reproduce what the layout already did or every
     * existing site's verify email changes size on deploy.
     *
     * Declared here so the Form Request rule, the render fallback and the
     * admin's number input all read ONE source.
     */
    /**
     * The button label when the admin has not set one, or has cleared it.
     *
     * Clearing restores this rather than removing the button: the button IS the
     * email's purpose, and a verify message with no visible call to action fails
     * silently — subscribers simply never confirm, and nothing logs an error.
     */
    public const string DEFAULT_BUTTON_TEXT = 'Verify My Email';

    /**
     * Footer text colour when the admin has not chosen one.
     *
     * This is the grey the three footer lines have always rendered at, so an
     * untouched row is unchanged. Links in the footer keep `accent_color` —
     * see the migration for why they are not covered by this.
     */
    public const string DEFAULT_FOOTER_TEXT_COLOR = '#9ca3af';

    public const int BUTTON_TEXT_DEFAULT_SIZE = 15;
    public const int BUTTON_TEXT_MIN_SIZE = 12;
    public const int BUTTON_TEXT_MAX_SIZE = 24;

    protected function casts(): array
    {
        return [
            'unsubscribe_enabled' => 'boolean',
            'hidden_blocks'       => 'array',
            'button_text_font_size' => 'integer',
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
            'verify_button_text' => self::DEFAULT_BUTTON_TEXT,
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

        foreach (['from_name', 'from_email', 'subject', 'header_title', 'header_subtitle', 'heading', 'unsubscribe_label', 'verify_button_text', 'postal_address', 'contact_email', 'copyright_text'] as $field) {
            $out[$field] = $replace((string) $this->{$field});
        }

        foreach (self::RICH_FIELDS as $field) {
            $out[$field] = self::richToHtml($replace((string) $this->{$field}));
        }

        $out['accent_color'] = $this->accent_color;

        // Colours never take placeholders. Falls back to the design default so
        // an unsaved preview — and a row written before the column existed —
        // still emits a real colour rather than empty CSS.
        $footerColor = trim((string) $this->footer_text_color);
        $out['footer_text_color'] = $footerColor !== '' ? $footerColor : self::DEFAULT_FOOTER_TEXT_COLOR;

        // Coalesced AFTER placeholder substitution, so a label of only
        // whitespace — or one whose placeholders resolved to nothing — still
        // falls back to a real caption instead of an empty button.
        $label = trim($out['verify_button_text'] ?? '');
        $out['verify_button_text'] = $label !== '' ? $label : self::DEFAULT_BUTTON_TEXT;

        // Coalesced here so the Blade never emits an empty font-size, and an
        // untouched row keeps the 15px it has always rendered at.
        $size = (int) ($this->button_text_font_size ?? 0);
        $out['button_text_font_size'] = $size > 0 ? $size : self::BUTTON_TEXT_DEFAULT_SIZE;

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
