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
use App\Services\Validation\SubscribeValidationGate;
use App\Support\Validation\ValidationMessage;
use Illuminate\Http\JsonResponse;

class NewsletterController extends Controller
{
    public function __construct(private readonly SubscribeValidationGate $gate) {}

    public function store(SubscribeNewsletterRequest $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $email = (string) $request->validated('email');

        // ─────────────────────────────────────────────────────────────────────
        // THE GATE. Address validation happens HERE, in the request, and not
        // inside ProcessNewsletterSubscription — because the job runs after the
        // response has already gone out, and by then neither the subscriber row
        // nor the verify email can be called back.
        //
        // Everything downstream of this line is unchanged. Everything that
        // creates a subscriber or sends a verification email is downstream of
        // it: the job below is the only caller of ProcessNewsletterSubscription,
        // and that job is the only caller of SendNewsletterWelcomeEmail.
        //
        // Returns TRUE for both "allowed" and "failed open" — the gate owns that
        // distinction and records it; the controller only needs to know whether
        // to proceed.
        // ─────────────────────────────────────────────────────────────────────
        $decision = $this->gate->decide($site, $email);

        if (! $decision->permitsSubscribe()) {
            $result = $this->gate->lastResult();

            // 422, with the wording chosen by the rule that fired.
            //
            // The verdict, the score, the checks and the reason CODE all stay
            // server-side — the visitor gets a plain sentence and an instruction,
            // never a SendGrid internal and never something an admin filter
            // depends on. See ValidationMessage for why one generic line was
            // dropped: it was safer against probing, and it also left everybody
            // who mistyped their own address with no idea what to do.
            $message = ValidationMessage::forReason($decision->reason);

            return response()->json([
                'ok'      => false,
                'message' => $message,
                'errors'  => ['email' => [$message]],
                // Present only when SendGrid proposed a domain correction. The
                // form renders "did you mean …?" with a one-click fix.
                'suggestion'           => $result?->suggestion,
                'suggested_email'      => $this->suggestedEmail($email, $result?->suggestion),
            ], 422);
        }

        // Persisting + confirming happen on the HIGH-priority queue so the
        // public request returns instantly. The (site_id, email) unique index
        // keeps this idempotent; the confirmation email is sent only for new
        // subscriptions (see ProcessNewsletterSubscription).
        $verdict = $this->gate->lastResult();

        ProcessNewsletterSubscription::dispatch(
            $site->id,
            $email,
            $request->validated('full_name'),
            $verdict?->verdict,
            $verdict?->score,
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
     * The same local part on SendGrid's suggested domain.
     *
     * Built server-side so the form does not have to reassemble an address from
     * a bare domain and get the edge cases wrong. Null unless there is a
     * suggestion AND the original parses — never guess at a malformed address.
     */
    private function suggestedEmail(string $email, ?string $suggestion): ?string
    {
        if ($suggestion === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local] = explode('@', $email, 2);

        return $local === '' ? null : $local . '@' . $suggestion;
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
