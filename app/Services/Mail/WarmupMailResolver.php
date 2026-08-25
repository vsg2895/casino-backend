<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\Contracts\SenderOverridable;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use App\Services\VerifyEmailService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;

/**
 * Renders a site email template for a WARMUP address.
 *
 * Why this exists instead of {@see EmailTemplateCatalog::build()}: that method
 * needs a {@see \App\Models\Newsletter} model, and the only way to get one for an
 * arbitrary address is `Newsletter::firstOrCreate()` — which is what the
 * SendGrid/Mailgun test buttons do. Reusing it here would insert EVERY warmup
 * address into the subscriber list of the chosen site, corrupting the audience of
 * every future campaign. Warmup must never write to `newsletters`.
 *
 * Each mail service already exposes a builder that needs only a Site, its stored
 * template and an address, minting its own well-formed sample unsubscribe token.
 * That is the seam used here — no subscriber row, no query, no side effects.
 *
 * Nothing in the promotion send path is touched: this class only READS the same
 * per-site template services the scheduled campaign reads.
 */
final class WarmupMailResolver
{
    /**
     * Templates a warmup send may use.
     *
     * All four site templates are permitted. VERIFY carries a caveat worth
     * knowing before selecting it: its payload is a confirmation link for a
     * double opt-in that a seed address does not have, so the call to action
     * resolves to nothing, and a "confirm your email" message to someone who
     * never subscribed has the shape filters score as phishing. It is available
     * because an operator asked for it; prefer SUBSCRIBE or PROMOTION for routine
     * warming.
     *
     * @return list<string>
     */
    public const array ALLOWED_TEMPLATES = [
        EmailTemplateCatalog::TYPE_SUBSCRIBE,
        EmailTemplateCatalog::TYPE_PROMOTION,
        EmailTemplateCatalog::TYPE_PROMOTION_AFTER_VERIFICATION,
        EmailTemplateCatalog::TYPE_VERIFY,
    ];

    public function __construct(
        private readonly SubscriptionEmailService $subscription,
        private readonly PromotionEmailService $promotion,
        private readonly VerifyEmailService $verify,
        private readonly PostVerificationPromotionEmailService $postVerification,
    ) {}

    public static function supports(string $type): bool
    {
        return in_array($type, self::ALLOWED_TEMPLATES, true);
    }

    /**
     * Site templates already resolved during this batch, keyed "{siteId}:{type}".
     *
     * Without it, `…OrDefault()` ran once PER RECIPIENT: a `firstOrCreate` query
     * and a freshly hydrated model for every address, so a 100-address batch paid
     * 100 redundant SELECTs and left 100 model graphs for the collector. The
     * template cannot change mid-batch anyway — a run should render one consistent
     * version of it — so resolving once is both cheaper and more correct.
     *
     * Scoped to the resolver instance, which the container builds per job, so the
     * cache dies with the batch and never leaks across runs.
     *
     * @var array<string, Model>
     */
    private array $templates = [];

    /**
     * The fully rendered mailable for one warmup address.
     *
     * Uses each site's STORED template (`…OrDefault()`), so what a warmup send
     * puts on the wire is byte-for-byte what a real send of that template would
     * produce — which is the point: warming a mailbox with mail that looks
     * nothing like your real traffic teaches the receiving side nothing useful.
     */
    public function build(string $type, Site $site, string $email): Mailable&SenderOverridable
    {
        return match ($type) {
            EmailTemplateCatalog::TYPE_SUBSCRIBE => $this->subscription->previewMail(
                $site,
                $this->template($site, $type, static fn (Site $s): Model => $s->emailTemplateOrDefault()),
                $email,
            ),
            EmailTemplateCatalog::TYPE_PROMOTION => $this->promotion->previewMail(
                $site,
                $this->template($site, $type, static fn (Site $s): Model => $s->promotionEmailOrDefault()),
                $email,
            ),
            EmailTemplateCatalog::TYPE_VERIFY => $this->verify->previewMail(
                $site,
                $this->template($site, $type, static fn (Site $s): Model => $s->verifyEmailOrDefault()),
                $email,
            ),
            // The one GLOBAL template here: not per-site, so the cache key is the
            // type alone and the Site only resolves placeholders.
            EmailTemplateCatalog::TYPE_PROMOTION_AFTER_VERIFICATION => $this->postVerification->previewMail(
                $site,
                $this->template($site, $type, static fn (Site $s): Model => VerificationPromotionEmail::current()),
                $email,
            ),
            default => throw new InvalidArgumentException(
                "Template [{$type}] cannot be used for a warmup send.",
            ),
        };
    }

    /**
     * Release the cached templates.
     *
     * Called by the batch job once its recipients are done. The resolver itself is
     * short-lived, but a template row carries the full rendered copy of an email,
     * and holding it until the container drops the instance keeps that alive for
     * the rest of the job's teardown for no reason.
     */
    public function flushTemplates(): void
    {
        $this->templates = [];
    }

    /** @param callable(Site): Model $resolve */
    private function template(Site $site, string $type, callable $resolve): Model
    {
        return $this->templates[$site->id . ':' . $type] ??= $resolve($site);
    }
}
