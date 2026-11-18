<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\WarmupEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One-click unsubscribe for the warmup list. Keyless — the token IS the
 * credential — and outside `verify.site`, like every other opt-out endpoint.
 *
 * The same two-route shape as {@see MailgunUnsubscribeController}, for the same
 * reason: POST is the RFC 8058 target and acts immediately, GET is the visible
 * in-body link and only renders a confirm page, because mail clients and
 * security appliances prefetch GET links and an acting GET would unsubscribe
 * people who never clicked.
 *
 * It does NOT extend that controller, and the difference is deliberate:
 *
 *  - a warmup opt-out is NOT written to `mailgun_suppressions`. That list is
 *    the Custom Receivers' shared stop list; warmup addresses are seed
 *    mailboxes, a separate pool, and suppressing one there would silently
 *    remove a person from two lists they were never on;
 *  - there is no `is_active` to clear and no daily claim to release.
 *
 * What is left is the part that matters — `unsubscribed_at` — and
 * {@see WarmupEmail::scopeSendable()} is what every selection path reads, so
 * the address drops out of warmup runs and of any schedule aimed at the list.
 *
 * The confirm/done pages are shared with the receiver lists: one opt-out page
 * for the whole platform is also what a recipient should see.
 */
class WarmupUnsubscribeController extends Controller
{
    /** RFC 8058 one-click target. Acts immediately; always 200 to the provider. */
    public function oneClick(string $token): Response
    {
        $this->unsubscribe($token);

        // Never leak whether a token was real: a 404 would turn this endpoint
        // into an oracle for probing valid tokens.
        return response('', Response::HTTP_OK);
    }

    /** Human-facing confirmation page. Renders only — never acts. */
    public function show(string $token): Response
    {
        $address = $this->find($token);

        return response()->view('unsubscribe.mailgun-confirm', [
            'email'      => $address?->email,
            'alreadyOff' => $address?->unsubscribed_at !== null,
            'confirmUrl' => url('/api/v1/' . WarmupEmail::unsubscribePath() . '/' . $token . '/confirm'),
        ]);
    }

    /** Target of the confirm page's form. */
    public function confirm(Request $request, string $token): Response
    {
        return response()->view('unsubscribe.mailgun-done', ['done' => $this->unsubscribe($token)]);
    }

    /**
     * Record the decision, once.
     *
     * Guarded on null so re-posting — which a provider may legitimately do —
     * keeps the FIRST timestamp rather than moving it forward.
     */
    private function unsubscribe(string $token): bool
    {
        $address = $this->find($token);

        if ($address === null) {
            return false;
        }

        if ($address->unsubscribed_at === null) {
            $address->forceFill(['unsubscribed_at' => now()])->save();
        }

        return true;
    }

    private function find(string $token): ?WarmupEmail
    {
        // A short or malformed token cannot match a 64-char column; checking
        // first keeps a probe off the index entirely.
        if (strlen($token) !== 64) {
            return null;
        }

        return WarmupEmail::where('unsubscribe_token', $token)->first();
    }
}
