<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\Contracts\SenderOverridable;
use App\Mail\ScheduleCustomMessage;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\Site;
use App\Services\SubscriptionEmailService;
use App\Services\VerifyEmailService;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the mailable for ONE recipient of a schedule that does NOT send the
 * promotion template.
 *
 * The promotion path is deliberately absent. Every schedule sent that template
 * until 2026-11-17 and both batch jobs already build it inline, with the
 * per-audience link handling it needs; routing it through here as well would
 * mean rewriting the one path that is in production for all of them, to gain
 * nothing. So this class is only ever asked for the three NEW templates, and
 * refuses the promotion one outright rather than growing a second rendering of
 * it that could quietly drift from the first.
 *
 * Subscribe and verify come from the same per-site services the real streams
 * use, so a scheduled send is byte-for-byte what a subscriber would normally
 * receive — including REAL tokens, which is why both are restricted to the
 * newsletters audience ({@see EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES}) and
 * why this method takes a Newsletter rather than an address.
 */
final class ScheduleMailResolver
{
    public function __construct(
        private readonly SubscriptionEmailService $subscription,
        private readonly VerifyEmailService $verify,
    ) {}

    /**
     * Whether a template is one this resolver renders.
     *
     * The callers use it to decide whether to take their existing promotion
     * path or this one, so "not mine" is a routing answer, never an error.
     */
    public static function handles(string $template): bool
    {
        return in_array($template, [
            EmailSchedule::TEMPLATE_SUBSCRIBE,
            EmailSchedule::TEMPLATE_VERIFY,
            EmailSchedule::TEMPLATE_CUSTOM,
        ], true);
    }

    /**
     * @param  Newsletter|null  $subscriber  The recipient's own subscriber row.
     *                                       Required for subscribe and verify,
     *                                       which are built from its tokens;
     *                                       null for a Custom Receivers or
     *                                       warmup address, which has none.
     * @param  string  $unsubscribeUrl  The opt-out that belongs to THIS
     *                                  recipient's list — the site's for a
     *                                  subscriber, the receiver list's own
     *                                  otherwise. Custom HTML carries it.
     * @param  string  $oneClickUrl  The RFC 8058 POST target for the same.
     */
    public function build(
        EmailSchedule $schedule,
        Site $site,
        string $email,
        ?Newsletter $subscriber = null,
        string $unsubscribeUrl = '',
        string $oneClickUrl = '',
    ): Mailable&SenderOverridable {
        return match ($schedule->templateKey()) {
            EmailSchedule::TEMPLATE_CUSTOM => new ScheduleCustomMessage(
                $schedule->customContent() ?? throw new RuntimeException(
                    "Schedule [{$schedule->id}] sends custom HTML but has no subject or body saved.",
                ),
                $schedule->customFromName(),
                $unsubscribeUrl,
                $oneClickUrl,
            ),

            EmailSchedule::TEMPLATE_SUBSCRIBE => $this->subscription->mailForSubscriber(
                $site,
                $this->requireSubscriber($schedule, $email, $subscriber),
            ),

            EmailSchedule::TEMPLATE_VERIFY => $this->verify->mailForSubscriber(
                $site,
                $this->requireSubscriber($schedule, $email, $subscriber),
            ),

            default => throw new InvalidArgumentException(
                "Template [{$schedule->templateKey()}] is not built by the schedule resolver.",
            ),
        };
    }

    /**
     * The subscriber these two templates are ABOUT.
     *
     * Reaching here without one means a subscriber-only template was saved
     * against a list audience, which validation prevents — so this is a loud
     * failure, not a fallback to a sample token that would mail a dead
     * confirmation link to a real person.
     */
    private function requireSubscriber(EmailSchedule $schedule, string $email, ?Newsletter $subscriber): Newsletter
    {
        return $subscriber ?? throw new RuntimeException(sprintf(
            'Schedule [%d] sends the %s template, which needs a subscriber row, and %s has none.',
            $schedule->id,
            $schedule->templateLabel(),
            $email,
        ));
    }
}
