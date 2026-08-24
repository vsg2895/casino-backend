<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Models\Unsubscribe;
use Illuminate\Http\JsonResponse;

/**
 * Email-provider-friendly one-click unsubscribe (RFC 8058).
 *
 * This is the target of the List-Unsubscribe / List-Unsubscribe-Post headers, so
 * Gmail / Yahoo / Apple Mail's native "Unsubscribe" button can opt a recipient
 * out with a single POST — no site key and no page interaction required. The
 * opaque per-stream token IS the credential and also names the stream, so no
 * personal data ever travels in the URL.
 *
 * POST-only on purpose: GET links get pre-fetched by scanners/proxies, which
 * would cause accidental unsubscribes. Idempotent, and always returns ok so it
 * never reveals whether an address is on a list.
 */
class UnsubscribeController extends Controller
{
    public function oneClick(string $token): JsonResponse
    {
        // Tokens are 64-char and globally unique, so a single lookup resolves the
        // subscriber AND the template across all sites. The template that carried
        // the token is recorded as the opt-out type — the send-gate itself is
        // global (Unsubscribe::hasAny), so this is purely for attribution.
        if (strlen($token) === 64) {
            $newsletter = Newsletter::findByUnsubscribeToken($token);

            if ($newsletter !== null) {
                $type = $newsletter->unsubscribeTypeForToken($token) ?? Unsubscribe::TYPE_SUBSCRIPTION;

                Unsubscribe::record($newsletter->site_id, $newsletter->email, $type);
            }
        }

        return response()->json(['ok' => true]);
    }
}
