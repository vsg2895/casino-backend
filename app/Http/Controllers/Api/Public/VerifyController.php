<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Models\VerificationPromotionEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Double opt-in email verification landing target.
 *
 * The subscriber's opaque token (the same one their one-click unsubscribe uses)
 * IS the credential, so no site key and no personal data travel in the URL.
 * Keyless like {@see UnsubscribeController}: any site's public front-end can
 * call it, and the global 64-char token resolves whoever it belongs to.
 *
 * It resolves `newsletters` and nothing else, deliberately. Double opt-in is a
 * SUBSCRIBER concept: somebody typed their address into a form, and this
 * endpoint is how they prove the address is theirs before the site mails them
 * again. The addresses on a global list — Mailgun receivers, SendGrid
 * receivers, warmup — were entered by an operator who already knows them, so
 * there is no claim to confirm; they carry an unsubscribe token so they can
 * always opt OUT, which is a different right and a different route. That is
 * also why the verify template stays newsletters-only
 * ({@see \App\Models\EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES}) — sending it to
 * a list would mail a confirmation button with nothing to confirm.
 *
 * Idempotent and always returns ok — it never reveals whether a token exists,
 * and re-clicking a verified link is harmless.
 */
class VerifyController extends Controller
{
    public function verify(string $token): JsonResponse
    {
        $justVerified = false;

        // A token is always 64 chars; anything else cannot match a column and
        // is rejected before it reaches an index.
        if (strlen($token) === 64) {
            $newsletter = Newsletter::where('unsubscribe_token', $token)->first();

            // Guarded by `! verified` so the timestamp records the FIRST
            // click. Re-clicking the link must not move it forward, or the
            // post-verification promotion delay would restart every time.
            if ($newsletter !== null && ! $newsletter->verified) {
                $newsletter->forceFill([
                    'verified'    => true,
                    'verified_at' => Carbon::now(),
                ])->save();

                $justVerified = true;
            }
        }

        return response()->json([
            'ok' => true,

            // Whether the landing page may tell this subscriber a bonus email is
            // on its way. Only the click that actually verified them triggers a
            // send, and only while the feature is active — re-opening the link,
            // an unknown token, or a switched-off template must not promise an
            // email that no job will ever send.
            //
            // Short-circuited so the template row is only touched on a genuine
            // first verification.
            'bonus_email_expected' => $justVerified
                && VerificationPromotionEmail::current()->active,
        ]);
    }
}
