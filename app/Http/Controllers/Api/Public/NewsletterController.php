<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SubscribeNewsletterRequest;
use App\Http\Requests\Public\UnsubscribeNewsletterRequest;
use App\Jobs\ProcessNewsletterSubscription;
use App\Models\Newsletter;
use App\Models\Site;
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
        ProcessNewsletterSubscription::dispatch($site->id, $request->validated('email'));

        return response()->json(['ok' => true], 202);
    }

    /**
     * One-click unsubscribe via the subscriber's unguessable token.
     *
     * Scoped to the current site (the token must belong to it) and idempotent:
     * an unknown/already-removed token still returns ok, never revealing whether
     * an address is on the list.
     */
    public function unsubscribe(UnsubscribeNewsletterRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $newsletter = Newsletter::where('site_id', $site->id)
            ->where('unsubscribe_token', $request->validated('token'))
            ->first();

        $newsletter?->delete();

        return response()->json(['ok' => true]);
    }
}
