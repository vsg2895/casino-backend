<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Newsletter;
use App\Models\Site;

/**
 * The subscriber an admin "Send test" renders for — WITHOUT creating one.
 *
 * Its own concern rather than part of {@see SendsAdminTestEmail}, because the
 * two SendGrid/Mailgun key tests deliberately do not share that send path (they
 * must go through the specific stored credential being verified) yet need this
 * rule just as much. A guard that only covered three of the five buttons would
 * be no guard at all.
 *
 * WHY. Every test button used to call `Newsletter::firstOrCreate()` to obtain a
 * model carrying real unsubscribe tokens, so checking a template silently
 * signed the test address up. The newsletter list is a record of people who
 * ASKED to hear from a site; only a public subscribe form and the Newsletter
 * section's import and manual add may extend it. An admin checking a layout is
 * none of those, and the list filled with colleagues' and developers'
 * addresses — who then received real campaigns.
 *
 * An address already on the list is reused, so the unsubscribe link and the RFC
 * 8058 one-click header keep working end-to-end for the case that matters most.
 * Anyone else gets a transient model that is never saved.
 */
trait ResolvesTestSubscriber
{
    protected function testSubscriberFor(Site $site, string $email): Newsletter
    {
        $existing = Newsletter::query()
            ->where('site_id', $site->id)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $newsletter = new Newsletter([
            'site_id' => $site->id,
            'email'   => $email,
        ]);

        /*
         * `creating` fills these, and it never fires for a model that is not
         * saved — so they are generated here instead. Without them the template
         * renders an unsubscribe URL with an empty token.
         *
         * The links then point at tokens no row holds, so following one lands on
         * "already unsubscribed" rather than opting anybody out. That is the
         * honest trade for not writing to the list: the test proves the
         * template, the layout and the transport, and stops short of proving the
         * opt-out round-trip for an address that was never subscribed.
         */
        foreach (Newsletter::tokenColumns() as $column) {
            $newsletter->{$column} = Newsletter::generateUnsubscribeToken();
        }

        return $newsletter;
    }
}
