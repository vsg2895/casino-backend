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

        return response()->json(['ok' => true], 202);
    }

    /**
     * One-click unsubscribe via the subscriber's opaque per-stream token.
     *
     * The token alone identifies both the subscriber AND which stream
     * (subscription vs promotion) they are opting out of — no email, id or other
     * personal data is ever sent in the URL. Scoped to the current site and
     * idempotent: an unknown/already-removed token still returns ok, never
     * revealing whether an address is on the list. Opting out of one stream
     * leaves the subscriber (and the other stream) untouched.
     */
    public function unsubscribe(UnsubscribeNewsletterRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $token = $request->validated('token');

        $newsletter = Newsletter::where('site_id', $site->id)
            ->where(function ($query) use ($token): void {
                $query->where('unsubscribe_token', $token)
                    ->orWhere('promotion_unsubscribe_token', $token);
            })
            ->first();

        if ($newsletter !== null) {
            $type = hash_equals((string) $newsletter->unsubscribe_token, $token)
                ? Unsubscribe::TYPE_SUBSCRIPTION
                : Unsubscribe::TYPE_PROMOTION;

            Unsubscribe::record($site->id, $newsletter->email, $type);
        }

        return response()->json(['ok' => true]);
    }
}
