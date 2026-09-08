<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SubscribeNewsletterRequest;
use App\Http\Requests\Public\UnsubscribeNewsletterRequest;
use App\Jobs\ProcessNewsletterSubscription;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use Illuminate\Http\JsonResponse;

class NewsletterController extends Controller
{
    public function store(SubscribeNewsletterRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        // Persisting + confirming happen on the HIGH-priority queue so the
        // public request returns instantly. The (site_id, email) unique index
        // keeps this idempotent; the confirmation email is sent only for new
        // subscriptions (see ProcessNewsletterSubscription).
        ProcessNewsletterSubscription::dispatch(
            $site->id,
            $request->validated('email'),
            $request->validated('full_name'),
        );

        // `email_sent` so the form can tell the visitor the truth. Without it the
        // success message still promises "check your inbox" on a site whose
        // sending is switched off, which is a worse failure than not collecting
        // the address at all — the visitor waits for mail that never comes.
        return response()->json([
            'ok'         => true,
            'email_sent' => (bool) $site->newsletter_emails_enabled,
        ], 202);
    }

    /**
     * One-click unsubscribe via the subscriber's opaque per-stream token.
     *
     * The token alone identifies both the subscriber AND which TEMPLATE
     * (subscription, verify, promotion, promotion-after-verification) prompted
     * the opt-out — no email, id or other personal data is ever sent in the URL.
     * The template is recorded for detection; the opt-out itself is global, so
     * one click stops all further mail to that address. Scoped to the current
     * site and idempotent: an unknown/already-removed token still returns ok,
     * never revealing whether an address is on the list.
     */
    public function unsubscribe(UnsubscribeNewsletterRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $token = $request->validated('token');

        $newsletter = Newsletter::findByUnsubscribeToken($token, $site->id);

        if ($newsletter !== null) {
            // The template that carried the token is recorded as the opt-out
            // type (for detection); the opt-out itself is global.
            $type = $newsletter->unsubscribeTypeForToken($token) ?? Unsubscribe::TYPE_SUBSCRIPTION;

            Unsubscribe::record($site->id, $newsletter->email, $type);
        }

        return response()->json(['ok' => true]);
    }
}
