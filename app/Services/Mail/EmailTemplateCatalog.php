<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\Contracts\SenderOverridable;
use App\Models\Newsletter;
use App\Models\Site;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use App\Services\VerifyEmailService;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;

/**
 * The single registry of the site email templates that can be rendered on
 * demand for an arbitrary site — currently the Subscribe, Verify and Promotion
 * streams.
 *
 * It is the one place a template type is declared: the admin dropdown, the
 * validation whitelist, and the send path all read from {@see definitions()},
 * so registering a FUTURE template is a single entry here and it appears
 * everywhere automatically — no controller, request or UI change.
 *
 * Each entry's `build` closure returns a fully rendered mailable for a given
 * site + subscriber, delegating to the existing per-stream service so the test
 * email is byte-for-byte what a real send would produce.
 */
final class EmailTemplateCatalog
{
    public const string TYPE_SUBSCRIBE = 'subscribe';
    public const string TYPE_VERIFY = 'verify';
    public const string TYPE_PROMOTION = 'promotion';

    public function __construct(
        private readonly SubscriptionEmailService $subscription,
        private readonly VerifyEmailService $verify,
        private readonly PromotionEmailService $promotion,
    ) {}

    /**
     * Every renderable template, keyed by its stable API value.
     *
     * @return array<string, array{label: string, description: string, build: callable(Site, Newsletter): (Mailable&SenderOverridable)}>
     */
    private function definitions(): array
    {
        return [
            self::TYPE_SUBSCRIBE => [
                'label'       => 'Subscribe (welcome)',
                'description' => 'Sent to a visitor once their subscription is confirmed.',
                'build'       => fn (Site $site, Newsletter $newsletter): Mailable&SenderOverridable
                    => $this->subscription->mailForSubscriber($site, $newsletter),
            ],
            self::TYPE_VERIFY => [
                'label'       => 'Verify email',
                'description' => 'Double opt-in email carrying the confirmation link.',
                'build'       => fn (Site $site, Newsletter $newsletter): Mailable&SenderOverridable
                    => $this->verify->mailForSubscriber($site, $newsletter),
            ],
            self::TYPE_PROMOTION => [
                'label'       => 'Promotion (marketing offer)',
                'description' => 'Marketing campaign template used by scheduled sends.',
                'build'       => fn (Site $site, Newsletter $newsletter): Mailable&SenderOverridable
                    => $this->promotion->mailForSubscriber($site, $site->promotionEmailOrDefault(), $newsletter),
            ],
        ];
    }

    /**
     * The catalog as a flat list for the admin dropdown.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function types(): array
    {
        $types = [];

        foreach ($this->definitions() as $value => $definition) {
            $types[] = [
                'value'       => $value,
                'label'       => $definition['label'],
                'description' => $definition['description'],
            ];
        }

        return $types;
    }

    /**
     * Valid template values — the validation whitelist.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /** Human-readable name, for confirmation messages. */
    public function label(string $type): string
    {
        return $this->definitions()[$type]['label'] ?? $type;
    }

    /**
     * Render the chosen template for the chosen site, addressed to the given
     * subscriber (whose real per-stream tokens make unsubscribe/verify links
     * work end-to-end).
     *
     * @throws InvalidArgumentException when the type is not registered — the
     *         Form Request whitelist means this is a programming error, not
     *         user input.
     */
    public function build(string $type, Site $site, Newsletter $newsletter): Mailable&SenderOverridable
    {
        $definition = $this->definitions()[$type] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown email template type [{$type}].");
        }

        return ($definition['build'])($site, $newsletter);
    }
}
