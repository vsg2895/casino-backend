<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\Contracts\SenderOverridable;
use App\Mail\ScheduleCustomMessage;
use App\Models\Contracts\TokenAddressable;
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
 * nothing. So this class is only ever asked for the three OTHER templates, and
 * refuses the promotion one outright rather than growing a second rendering of
 * it that could quietly drift from the first.
 *
 * Subscribe and verify come from the same per-site services the real streams
 * use, so a scheduled send is byte-for-byte what a subscriber would normally
 * receive — including REAL tokens.
 *
 * The two differ in who they can be ABOUT, which is the one asymmetry here:
 *
 *   subscribe → any audience. Since 2026-12 every global list row carries its
 *               own token ({@see TokenAddressable}), so a welcome message is
 *               built from ITS token, with the opt-out pointing at the list
 *               that actually mailed them.
 *   verify    → newsletters only. It asks the recipient to confirm an address
 *               they submitted; an operator-entered list address has no such
 *               claim, so the button would have nothing to confirm. Enforced
 *               before a schedule can be saved
 *               ({@see EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES}); the throw
 *               here is the backstop for a row that predates that rule.
 *
 * The recipient argument is what tells a subscriber from a list row, and one of
 * them is always required: these templates are ABOUT their recipient, and there
 * is no sample-token fallback that would be anything but a dead link in a real
 * person's inbox.
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
     * @param  Newsletter|TokenAddressable|null  $recipient  The recipient's own
     *                   row: a subscriber for the newsletters audience, a list
     *                   row for Custom Receivers or warmup. Required for
     *                   subscribe and verify, which are built from its tokens.
     *                   Custom HTML is about nobody, so it accepts null.
     * @param  string  $unsubscribeUrl  The opt-out that belongs to THIS
     *                                  recipient's list. Custom HTML carries it,
     *                                  because its body is typed by an admin and
     *                                  has no template to resolve one from.
     * @param  string  $oneClickUrl  The RFC 8058 POST target for the same.
     */
    public function build(
        EmailSchedule $schedule,
        Site $site,
        string $email,
        Newsletter|TokenAddressable|null $recipient = null,
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

            EmailSchedule::TEMPLATE_SUBSCRIBE => $this->dispatchByRecipient(
                $schedule,
                $email,
                $recipient,
                fn (Newsletter $subscriber) => $this->subscription->mailForSubscriber($site, $subscriber),
                fn (TokenAddressable $member) => $this->subscription->mailForListMember($site, $member),
            ),

            EmailSchedule::TEMPLATE_VERIFY => $this->dispatchByRecipient(
                $schedule,
                $email,
                $recipient,
                fn (Newsletter $subscriber) => $this->verify->mailForSubscriber($site, $subscriber),
                // A list row cannot be sent this template at all — see the class
                // docblock. Refused loudly rather than mailed a dead button.
                fn (TokenAddressable $member) => throw new RuntimeException(sprintf(
                    'Schedule [%d] sends the verify template, which only a subscriber can receive, and %s is on a global list.',
                    $schedule->id,
                    $email,
                )),
            ),

            default => throw new InvalidArgumentException(
                "Template [{$schedule->templateKey()}] is not built by the schedule resolver.",
            ),
        };
    }

    /**
     * Route one recipient to the builder for the kind of row they are.
     *
     * Reaching the throw means a subscriber-or-member template was built with
     * neither, which the batch jobs do not do — so it is a loud failure, not a
     * fallback to a sample token that would mail a dead confirmation link to a
     * real person.
     *
     * @param  callable(Newsletter): (Mailable&SenderOverridable)        $forSubscriber
     * @param  callable(TokenAddressable): (Mailable&SenderOverridable)  $forMember
     */
    private function dispatchByRecipient(
        EmailSchedule $schedule,
        string $email,
        Newsletter|TokenAddressable|null $recipient,
        callable $forSubscriber,
        callable $forMember,
    ): Mailable&SenderOverridable {
        return match (true) {
            $recipient instanceof Newsletter       => $forSubscriber($recipient),
            $recipient instanceof TokenAddressable => $forMember($recipient),
            default                                => throw new RuntimeException(sprintf(
                'Schedule [%d] sends the %s template, which is built from the recipient\'s own row, and %s has none.',
                $schedule->id,
                $schedule->templateLabel(),
                $email,
            )),
        };
    }
}
