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

        return $this->stripDeadUnsubscribeLinks($html, $unsubscribeUrl);
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
