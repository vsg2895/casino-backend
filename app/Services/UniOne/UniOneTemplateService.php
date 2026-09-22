<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\Site;
use App\Services\PromotionEmailService;
use Illuminate\Validation\ValidationException;

/**
 * Renders the promotion template a UniOne run sends.
 *
 * ── Which site's template, and why it is a constant ─────────────────────────
 *
 * Warmup renders `idevaffiliation`'s templates (config `warmup.site_slug`).
 * UniOne sends **viglinksi**'s promotion template — the one brand whose
 * domain is verified on the UniOne account, so the creative and the sending
 * identity match. There is no picker: every run and every test send renders
 * this template, which is the only way a test can prove what a run will do.
 *
 * It is a class constant rather than an env var because the brief forbids
 * adding to `.env`, and because a wrong value here does not degrade — it mails
 * the wrong brand's creative to a real list.
 *
 * ── Isolation ───────────────────────────────────────────────────────────────
 *
 * This READS the existing promotion-template machinery and modifies none of it.
 * `PromotionEmailService::mailFor()` takes plain strings — an address and a
 * token — so nothing here touches the `newsletters` table, which the brief
 * forbids.
 *
 * ── The unsubscribe footer ──────────────────────────────────────────────────
 *
 * The promotion template carries its own unsubscribe link, built for a
 * NEWSLETTER token. A UniOne receiver has no newsletter row, so that link would
 * resolve to nothing — and a dead unsubscribe link is a compliance failure, not
 * a cosmetic one.
 *
 * UniOne appends its own working unsubscribe footer to every message
 * (`skip_unsubscribe` stays 0, which the brief forbids changing). So the
 * template's own link is REMOVED from the rendered HTML and UniOne's footer is
 * the single unsubscribe path. One working link beats one working and one dead.
 */
class UniOneTemplateService
{
    /** The site whose promotion template UniOne sends. */
    public const string SITE_SLUG = 'viglinksi';

    /** Substitution carrying the greeting name — "Dear {{greeting_name}},". */
    public const string NAME_VAR = 'greeting_name';

    /**
     * Substitution carrying the receiver's own address.
     *
     * The promotion template supports an `{{email}}` placeholder. Viglinksi's
     * does not use it today, but an editor may add it at any time — and in a
     * SHARED body that would bake one receiver's address into everybody's mail.
     * So the address is rendered as a sentinel and substituted back per
     * recipient, which is correct whatever the editor types.
     */
    public const string EMAIL_VAR = 'recipient_email';

    /** Greeting for a receiver with no name — what the preview already shows. */
    public const string NAME_FALLBACK = 'there';

    /**
     * Stand-ins rendered into the shared copy, then swapped for substitution
     * placeholders.
     *
     * Deliberately plain: no HTML-special characters (Blade would escape them),
     * no whitespace (the newline flattening would touch them), and nothing an
     * editor could plausibly type into the template by hand.
     */
    private const string NAME_SENTINEL = 'UNIONEGREETINGNAMESENTINEL';

    private const string EMAIL_SENTINEL = 'unione-recipient@sentinel.invalid';

    public function __construct(private readonly PromotionEmailService $promotions) {}

    /** The site, or a clear failure. */
    public function site(): Site
    {
        $site = Site::query()->where('slug', self::SITE_SLUG)->first();

        if ($site === null) {
            throw ValidationException::withMessages([
                'template' => 'The ' . self::SITE_SLUG . ' site is missing, so its promotion template cannot be rendered.',
            ]);
        }

        return $site;
    }

    /**
     * The rendered template, for the admin's preview before a send.
     *
     * Rendered exactly the way a run renders it — same service, same site,
     * same stripping of the dead unsubscribe link — for a stand-in recipient,
     * so what the operator sees is what a receiver gets, greeting aside.
     *
     * @return array{site: string, subject: string, html: string}
     */
    public function preview(): array
    {
        return [
            'site'    => self::SITE_SLUG,
            'subject' => $this->subjectFor(),
            'html'    => $this->renderFor('preview@example.com', 'there'),
        ];
    }

    /** The subject line the template supplies, with placeholders resolved. */
    public function subjectFor(): string
    {
        $site = $this->site();
        $template = $site->promotionEmailOrDefault();

        return (string) ($template->render($this->promotions->context($site, '', ''))['subject'] ?? '');
    }

    /**
     * Render the template to HTML for one recipient.
     *
     * Per-recipient rather than once per run: the template personalises the
     * greeting from the receiver's name, so one shared body would greet everyone
     * the same way.
     *
     * The token is a throwaway — it only shapes a URL that is then stripped. It
     * is NOT persisted and identifies nobody.
     */
    public function renderFor(string $email, ?string $name): string
    {
        $site = $this->site();
        $template = $site->promotionEmailOrDefault();

        $unsubscribeUrl = $template->unsubscribeUrl($site, 'unione-placeholder');

        $html = $this->promotions
            ->mailFor($site, $template, $email, 'unione-placeholder', $name)
            ->render();

        return $this->flattenNewlines(
            $this->stripDeadUnsubscribeLinks($html, $unsubscribeUrl),
        );
    }

    /**
     * The body a RUN sends: ONE rendered copy for the whole chunk.
     *
     * Previously every recipient carried their own full copy of the email in a
     * `body_html` substitution, which was wrong twice over. It shipped the same
     * ~6 KB of markup 500 times per request, and — because the shared body was
     * then only the bare string `{{body_html}}` — UniOne wrapped it in its own
     * `<html><body>` and a complete `<!DOCTYPE html>` document ended up nested
     * inside another one, with UniOne's footer appended after our `</html>`.
     *
     * Nothing about the mail actually varies per recipient except the greeting
     * name, so that is the only thing that stays a substitution. The body is
     * now a single well-formed document, which is what UniOne expects and what
     * every client can parse.
     *
     * @return array{html: string, plaintext: string}
     */
    public function sharedBody(): array
    {
        $html = $this->renderFor(self::EMAIL_SENTINEL, self::NAME_SENTINEL);

        return [
            'html'      => $this->withPlaceholders($html),
            // Derived from the SAME render, so the two parts can never describe
            // different offers.
            'plaintext' => $this->withPlaceholders($this->plaintextFrom($html)),
        ];
    }

    /**
     * What one receiver contributes to the shared body.
     *
     * NOT `to_name`: that is UniOne's own reserved substitution for the To:
     * header's display name, and giving it the "there" fallback would address
     * the envelope to a person called There.
     *
     * @return array<string, string>
     */
    public function substitutionsFor(string $email, ?string $name): array
    {
        $name = trim((string) $name);

        return [
            self::NAME_VAR  => $name === '' ? self::NAME_FALLBACK : $name,
            self::EMAIL_VAR => $email,
        ];
    }

    /** Swap the rendered stand-ins for the substitution placeholders. */
    private function withPlaceholders(string $text): string
    {
        return str_replace(
            [self::NAME_SENTINEL, self::EMAIL_SENTINEL],
            ['{{' . self::NAME_VAR . '}}', '{{' . self::EMAIL_VAR . '}}'],
            $text,
        );
    }

    /**
     * A real plain-text alternative, derived from the rendered HTML.
     *
     * Without one, UniOne puts the HTML document itself into the `text/plain`
     * part — a delivered message showed `<!DOCTYPE html>...` as its plain-text
     * alternative. Text-only clients render that as raw markup, and a
     * text part that is merely a copy of the HTML is a long-standing spam
     * signal.
     *
     * Derived from the HTML rather than re-assembled from the template's
     * fields, so a block the admin hides disappears from both parts together
     * instead of the two drifting apart.
     */
    private function plaintextFrom(string $html): string
    {
        // Nothing here is spoken content: <style>/<head> are presentation, and
        // the preheader is a hidden line that exists only for an inbox preview.
        $text = preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<div[^>]*display:\s*none[^>]*>.*?</div>#is', ' ', $text) ?? $text;

        // A link's destination has to survive: in plain text there is nothing
        // left to click, so the URL becomes part of the sentence.
        $text = preg_replace_callback(
            '#<a\b[^>]*href=[\'"]([^\'"]*)[\'"][^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $url   = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                // An anchor wrapping only the hero image has no text at all.
                // In plain text there is no image and the CTA above already
                // offers the same destination, so a bare URL on its own line is
                // noise rather than information.
                if ($label === '') {
                    return '';
                }

                // A dead anchor (the stripped unsubscribe link) or a mailto
                // whose address is already the label adds nothing in brackets.
                if ($url === '' || $url === '#' || str_starts_with($url, 'mailto:')) {
                    return $label;
                }

                return $label . ' (' . $url . ')';
            },
            $text,
        ) ?? $text;

        // Block boundaries are the only line breaks plain text gets.
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|h[1-6]|div|td|tr|table|li)>#i', "\n", $text) ?? $text;

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Collapse every line break into a single space.
     *
     * UNIONE MINIFIES THE HTML BEFORE SENDING AND DELETES NEWLINES WITHOUT
     * PUTTING A SPACE IN THEIR PLACE. Confirmed from a delivered message's
     * raw source: the template's
     *
     *     <img
     *         src="https://..." width="600"
     *
     * arrived as `<imgsrc="https://..." width="600"alt="...">` — an element
     * named `imgsrc`, which every client renders as nothing. That is why the
     * hero banner was missing from real sends while the admin preview, which
     * never goes through UniOne, showed it.
     *
     * A glued ATTRIBUTE is survivable — an HTML parser recovers after a quoted
     * value, which is why `rel="..."style="..."` still worked — but a glued TAG
     * NAME is not, so the damage lands on whichever element happens to have a
     * line break after its name. Fixing the one tag in the Blade would leave
     * the next person to add a multi-line tag with the same silent bug, so the
     * repair belongs here, at the boundary with the provider that does it.
     *
     * Safe to do unconditionally: HTML already collapses runs of whitespace to
     * one space, table and block layout ignores inter-element whitespace, and
     * the template has no <pre> or white-space:pre content whose line breaks
     * carry meaning.
     *
     * Only the UniOne path is touched. Every other transport sends this same
     * template verbatim and renders it correctly, so nothing about the Blade or
     * the shared promotion machinery changes.
     */
    private function flattenNewlines(string $html): string
    {
        return preg_replace('/\s*\R\s*/u', ' ', $html) ?? $html;
    }

    /**
     * Remove the template's own unsubscribe anchors.
     *
     * Targeted at the EXACT URL this render produced rather than at anything
     * that looks like an unsubscribe link — a loose regex over a mail template
     * would eventually eat a legitimate link.
     *
     * The anchor's surrounding markup is left in place; only the link becomes
     * inert text, so the layout does not collapse. UniOne's appended footer is
     * what the recipient actually clicks.
     */
    private function stripDeadUnsubscribeLinks(string $html, string $unsubscribeUrl): string
    {
        $oneClick = \App\Models\Unsubscribe::oneClickUrl('unione-placeholder');

        foreach ([$unsubscribeUrl, $oneClick] as $url) {
            // Replace the href value only. Emptying the whole tag would leave a
            // bare "Unsubscribe" word with no explanation; UniOne's own footer
            // sits directly beneath it and reads as the same sentence.
            $html = str_replace(['href="' . $url . '"', "href='" . $url . "'"], 'href="#"', $html);
        }

        return $html;
    }
}
