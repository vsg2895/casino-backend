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
        // subscriber AND the stream across all sites.
        if (strlen($token) === 64) {
            $newsletter = Newsletter::where('unsubscribe_token', $token)
                ->orWhere('promotion_unsubscribe_token', $token)
                ->first();

            if ($newsletter !== null) {
                $type = hash_equals((string) $newsletter->unsubscribe_token, $token)
                    ? Unsubscribe::TYPE_SUBSCRIPTION
                    : Unsubscribe::TYPE_PROMOTION;

                Unsubscribe::record($newsletter->site_id, $newsletter->email, $type);
            }
        }

        return response()->json(['ok' => true]);
    }
}
