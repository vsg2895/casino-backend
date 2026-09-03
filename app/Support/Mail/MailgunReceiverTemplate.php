<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Site;
use Illuminate\Support\Facades\View;

/**
 * The editable fields behind a Mailgun credential's receiver message, and the
 * renderer that turns them into email HTML.
 *
 * This exists so the admin authors a MESSAGE rather than markup. Before it, the
 * settings modal held a raw <textarea> of HTML: whatever was pasted there went
 * out verbatim, which meant every credential's layout was only as good as the
 * HTML someone had to hand, and a stray unclosed tag reached 100k inboxes.
 *
 * The layout is the promotion email's — same block order (copy first, then the
 * call to action, then the banner, then the fine print), same table-based,
 * inline-styled construction that survives Outlook. The layout itself names no
 * site: it has no logo, no brand and no baked-in copy, and renders only what the
 * fields hold.
 *
 * A credential that has never been configured opens with those fields seeded
 * from a site's Promotion Email — see {@see fromSite()} and
 * {@see sourceSite()} — so an operator starts from approved copy and an approved
 * palette rather than a blank form. That is a starting point held in the form,
 * not a default written into this class: nothing is stored until they save, and
 * every string remains editable.
 *
 * Rendering is one-way and happens at save time: fields → `message_html`. The
 * send path ({@see \App\Mail\MailgunReceiverMessage}) is untouched and still
 * reads `message_html`, so nothing about how mail is sent, paced or unsubscribed
 * changed with this class.
 */
final class MailgunReceiverTemplate
{
    /**
     * Text blocks, escaped and **bold**-converted before they reach the layout.
     *
     * Admin input is never trusted as markup — the same rule
     * {@see \App\Models\SitePromotionEmail::RICH_FIELDS} follows.
     *
     * @var list<string>
     */
    public const array RICH_FIELDS = ['intro_text', 'secondary_text', 'disclaimer_text', 'footer_text'];

    /**
     * Plain fields, escaped by Blade at the point of use.
     *
     * @var list<string>
     */
    public const array PLAIN_FIELDS = [
        'preheader',
        'heading',
        'button_text',
        'button_url',
        'hero_image_url',
        'hero_url',
    ];

    /**
     * The palette the layout paints with.
     *
     * Light by default, unlike the promotion template's dark design: these
     * messages go to an imported customer list from a bare sending domain, and a
     * plain light message is what that context reads as. Every value is editable.
     *
     * @var array<string, string>
     */
    public const array COLOR_DEFAULTS = [
        'background_color'     => '#ffffff',
        'heading_color'        => '#111111',
        'text_color'           => '#333333',
        'secondary_text_color' => '#555555',
        'muted_text_color'     => '#888888',
        'button_color'         => '#2f6fed',
        'accent_color'         => '#2f6fed',
    ];

    /** Button label size in px, matching the promotion CTA. */
    public const int BUTTON_TEXT_DEFAULT_SIZE = 18;
    public const int BUTTON_TEXT_MIN_SIZE = 12;
    public const int BUTTON_TEXT_MAX_SIZE = 24;

    /**
     * A complete, blank template — the merge base, and the shape of the whole
     * field set in one place.
     *
     * Content is empty here because this is what an ABSENT field falls back to.
     * What the admin actually opens the form on comes from {@see fromSite()};
     * seeding copy from this method instead would make it impossible to tell a
     * field someone cleared on purpose from one that was never filled in.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'preheader'        => '',
            'heading'          => '',
            'intro_text'       => '',
            'secondary_text'   => '',
            'button_text'      => '',
            'button_url'       => '',
            'hero_image_url'   => '',
            'hero_url'         => '',
            'disclaimer_text'  => '',
            'footer_text'      => '',
            'button_text_font_size' => self::BUTTON_TEXT_DEFAULT_SIZE,
            ...self::COLOR_DEFAULTS,
        ];
    }

    /**
     * A stored template merged over the defaults.
     *
     * Unknown keys are dropped rather than passed through, so a field removed
     * from this class cannot keep arriving in the view from an old json blob.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function merged(?array $stored): array
    {
        $defaults = self::defaults();

        if ($stored === null) {
            return $defaults;
        }

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $stored) || $stored[$key] === null) {
                continue;
            }

            $defaults[$key] = is_int($default) ? (int) $stored[$key] : (string) $stored[$key];
        }

        return $defaults;
    }

    /**
     * Seed a receiver message from a site's Promotion Email.
     *
     * The settings modal opens with these values so an operator starts from the
     * approved design and copy instead of a blank form. Nothing is persisted
     * here — the fields are editable, and only a save writes them.
     *
     * What is taken is the CONTENT AND PALETTE, not the machinery:
     *  - hidden blocks are skipped, so a block switched off in the site template
     *    arrives empty rather than reappearing here;
     *  - raw column values are copied, NOT SitePromotionEmail::render() output —
     *    that method escapes and converts **bold** for immediate display, and
     *    these strings are still going to be edited and escaped again later;
     *  - {{site_url}} and {{year}} are resolved now, because this template's
     *    renderer knows nothing about sites. {{email}} is left alone: it is
     *    substituted per recipient at send time, as it is there.
     *  - the SITE'S NAME is removed rather than resolved — see
     *    {@see stripSiteName()}. These messages go out from a bare Mailgun
     *    sending domain to an imported list, and naming one of the network's
     *    brands in them would claim an identity the envelope does not support.
     *  - the site template's separate footer fields collapse into one free-text
     *    footer, and its copyright line is not imported at all, for the same
     *    reason.
     *
     * @return array{subject: string, template: array<string, mixed>, site_name: string}
     */
    public static function fromSite(Site $site): array
    {
        $promo = $site->promotionEmailOrDefault();
        $visible = $promo->visibleBlocks();

        // site_name is absent on purpose: it is stripped, not substituted.
        $context = [
            'site_url' => 'https://' . $site->domain,
            'year'     => (string) now()->year,
        ];

        $siteName = (string) $site->name;

        $resolve = static function (string $field) use ($promo, $visible, $context, $siteName): string {
            // A block hidden in the site template stays hidden here. `?? true`
            // covers fields that are not optional there, which are always taken.
            if (($visible[$field] ?? true) === false) {
                return '';
            }

            $value = (string) ($promo->{$field} ?? '');

            foreach ($context as $key => $replacement) {
                $value = str_replace(['{{' . $key . '}}', '{{ ' . $key . ' }}'], $replacement, $value);
            }

            return self::stripSiteName($value, $siteName);
        };

        // Footer: the operator's postal address and monitored contact, and
        // NOTHING that names a site.
        //
        // The site name and the copyright line are deliberately not imported.
        // These messages go to an imported customer list from a bare Mailgun
        // sending domain; carrying a brand's name in the footer would have the
        // mail claim an identity its envelope does not support, and the
        // credential is not the site. What is left is the address and contact
        // that commercial mail is expected to carry — plus the unsubscribe link,
        // which the wrapper appends and no template can remove.
        //
        // Both are empty on every site template today, so in practice the footer
        // seeds empty and the message ends at the unsubscribe line.
        $footer = implode("\n", array_filter([
            $resolve('postal_address'),
            $resolve('contact_email'),
        ], static fn (string $line): bool => trim($line) !== ''));

        $size = (int) ($promo->button_text_font_size ?? 0);

        $template = [
            ...self::defaults(),
            'preheader'       => $resolve('preheader'),
            'heading'         => $resolve('heading'),
            'intro_text'      => $resolve('intro_text'),
            'secondary_text'  => $resolve('secondary_text'),
            'button_text'     => $resolve('top_button_text'),
            // Same fallback chain the site layout uses for its top button, so the
            // imported button points where that template's button points.
            'button_url'      => $resolve('top_button_url') ?: ($resolve('cta_button_url') ?: $resolve('hero_url')),
            'hero_image_url'  => $resolve('hero_image_url'),
            'hero_url'        => $resolve('hero_url'),
            'disclaimer_text' => $resolve('disclaimer_text'),
            'footer_text'     => $footer,
            'button_text_font_size' => $size > 0 ? $size : self::BUTTON_TEXT_DEFAULT_SIZE,
        ];

        // Colours carry over field for field — the two templates happen to name
        // them identically. A blank one falls back to this class's own default
        // rather than emitting empty CSS.
        foreach (self::COLOR_DEFAULTS as $field => $default) {
            $value = trim((string) ($promo->{$field} ?? ''));
            $template[$field] = $value !== '' ? $value : $default;
        }

        return [
            // Through $resolve like everything else: the site template's subject
            // is written with {{site_name}} in it, and a subject line that still
            // reads "offer from {{site_name}}" would go out exactly like that.
            'subject'   => $resolve('subject'),
            'template'  => $template,
            // Attribution for the ADMIN UI only — "starting copy came from this
            // site". It is never written into the message, which is why the name
            // is stripped out of every field above but returned here.
            'site_name' => $siteName,
        ];
    }

    /**
     * Remove the source site's name from imported copy, and repair the sentence.
     *
     * Deleting a name mid-sentence leaves debris — "Welcome to", "invitation to
     * join .", "is ready at  — register" — so removal alone is not enough. The
     * repair is three steps, in order:
     *
     *  1. drop the {{site_name}} placeholder AND any literally-typed occurrence
     *     of the name, since a template may carry either;
     *  2. drop a connector left dangling where the name used to be, repeatedly:
     *     "invitation to join ." loses "join", then "to". Only connectors that
     *     plausibly introduce a name are listed, and only where one now sits
     *     immediately before punctuation, a dash or the end of the string — so
     *     an "of" or "for" in the middle of a sentence is never touched;
     *  3. tidy the spacing the removals leave: no space before punctuation, no
     *     double spaces.
     *
     * Horizontal whitespace only is collapsed — authored line breaks survive,
     * because the renderer turns them into <br> and losing them would reflow
     * someone's copy.
     */
    private static function stripSiteName(string $value, string $siteName): string
    {
        $value = str_replace(['{{site_name}}', '{{ site_name }}'], '', $value);

        if (trim($siteName) !== '') {
            $value = str_ireplace($siteName, '', $value);
        }

        $value = (string) preg_replace('/[ \t]+/u', ' ', $value);

        // Repeat until stable: one pass strips "join", the next the "to" it
        // exposed. Bounded, because each pass can only shorten the string.
        do {
            $before = $value;
            $value = (string) preg_replace(
                // /m so end-of-LINE counts too: a name removed from the end of
                // one line of a multi-line field leaves the same debris as one
                // removed from the end of the string.
                '/\b(?:to|at|from|join|with|for|by|of)\b[ \t]*(?=[.,!?;:—–-]|$)/imu',
                '',
                $value,
            );
        } while ($value !== $before);

        $value = (string) preg_replace('/[ \t]+([.,!?;:])/u', '$1', $value);
        $value = (string) preg_replace('/[ \t]{2,}/u', ' ', $value);
        // Per LINE, not just per string: a name removed from the end of one line
        // of a multi-line field leaves trailing space the renderer would keep.
        $value = (string) preg_replace('/[ \t]+$/mu', '', $value);

        return trim($value);
    }

    /**
     * The site whose promotion template seeds a new receiver message.
     *
     * Resolved from config, never from a slug written into code — see
     * `config/mail.php`. Falls back to the first active site so a fresh install
     * still opens with a real template, and to null when there are no sites.
     */
    public static function sourceSite(): ?Site
    {
        $slug = trim((string) config('mail.receiver_template_site'));

        if ($slug !== '') {
            $site = Site::where('slug', $slug)->first();

            if ($site !== null) {
                return $site;
            }
        }

        return Site::where('active', true)->orderBy('id')->first();
    }

    /**
     * Validation rules, keyed under `message_template.*`.
     *
     * Declared here rather than in the Form Request so the field list has one
     * home: adding a block means editing this class and the Blade view, and the
     * request picks it up unchanged.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return [
            'message_template'                  => ['sometimes', 'array'],
            'message_template.preheader'        => ['nullable', 'string', 'max:255'],
            // Required alongside the body when sending is switched on, so an
            // enabled credential can never mail an empty shell.
            'message_template.heading'          => ['required_if:send_enabled,true', 'nullable', 'string', 'max:255'],
            'message_template.intro_text'       => ['required_if:send_enabled,true', 'nullable', 'string', 'max:20000'],
            'message_template.secondary_text'   => ['nullable', 'string', 'max:20000'],
            'message_template.button_text'      => ['nullable', 'string', 'max:80'],
            'message_template.button_url'       => ['nullable', 'string', 'url', 'max:2048'],
            'message_template.hero_image_url'   => ['nullable', 'string', 'url', 'max:2048'],
            'message_template.hero_url'         => ['nullable', 'string', 'url', 'max:2048'],
            'message_template.disclaimer_text'  => ['nullable', 'string', 'max:20000'],
            'message_template.footer_text'      => ['nullable', 'string', 'max:20000'],
            'message_template.button_text_font_size' => [
                'nullable', 'integer',
                'min:' . self::BUTTON_TEXT_MIN_SIZE,
                'max:' . self::BUTTON_TEXT_MAX_SIZE,
            ],
            ...array_fill_keys(
                array_map(static fn (string $c): string => 'message_template.' . $c, array_keys(self::COLOR_DEFAULTS)),
                $hex,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'message_template.heading.required_if'    => 'A heading is required before sending can be enabled.',
            'message_template.intro_text.required_if' => 'Message text is required before sending can be enabled.',
        ];
    }

    /**
     * Render the template to the HTML stored in `message_html`.
     *
     * The result is a FRAGMENT, not a document: `mail.mailgun-receiver-message`
     * supplies <html>/<body> and appends the unsubscribe block that no template
     * may remove. Emitting a second <html> here would nest two documents in one
     * message, which several clients render by discarding one of them.
     *
     * {{name}} and {{email}} pass through untouched — htmlspecialchars leaves
     * braces alone — and are substituted per recipient at send time.
     *
     * @param  array<string, mixed>|null  $template
     */
    public static function render(?array $template): string
    {
        $t = self::merged($template);

        foreach (self::RICH_FIELDS as $field) {
            $t[$field] = self::richToHtml((string) $t[$field]);
        }

        $size = (int) ($t['button_text_font_size'] ?? 0);
        $t['button_text_font_size'] = $size > 0 ? $size : self::BUTTON_TEXT_DEFAULT_SIZE;

        return trim(View::make('mail.mailgun-receiver-promotion', ['t' => $t])->render());
    }

    /**
     * True when the template has nothing worth sending.
     *
     * Used instead of `message_html === ''` for the "is this credential ready"
     * question: a blank template still renders a non-empty HTML shell, so the
     * shell alone must not count as a configured message.
     *
     * @param  array<string, mixed>|null  $template
     */
    public static function isEmpty(?array $template): bool
    {
        $t = self::merged($template);

        return trim((string) $t['heading']) === '' && trim((string) $t['intro_text']) === '';
    }

    /** Escape HTML, convert a minimal **bold** syntax, keep authored line breaks. */
    private static function richToHtml(string $value): string
    {
        $escaped = e($value);
        $bolded = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);

        return nl2br($bolded, false);
    }
}
